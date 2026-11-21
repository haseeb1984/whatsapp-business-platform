<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\BookingPolicy;
use App\Models\Customer;
use App\Models\GoogleCalendarConnection;
use App\Jobs\SyncAppointmentCalendarEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function __construct(private readonly AppointmentAvailabilityService $availability)
    {
    }

    public function createHold(
        string $customerId,
        string $serviceId,
        string $staffId,
        CarbonImmutable $localStart,
        string $idempotencyKey,
        string $source = 'whatsapp',
    ): Appointment {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid idempotency key is required.']);
        }

        if (! in_array($source, ['whatsapp', 'dashboard', 'api'], true)) {
            throw ValidationException::withMessages(['source' => 'The booking source is invalid.']);
        }

        $tenantId = app(TenantContext::class)->id();
        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $requestedStart = $localStart->setTimezone($timezone)->utc();

        $existing = Appointment::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->returnIdempotentMatch($existing, $customerId, $serviceId, $staffId, $requestedStart);
        }

        // External FreeBusy is checked before the local transaction so no DB lock is held during Google API I/O.
        $this->availability->assertSlotAvailable($serviceId, $staffId, $requestedStart->setTimezone($timezone), true);

        $appointment = DB::transaction(function () use ($tenantId, $timezone, $requestedStart, $customerId, $serviceId, $staffId, $idempotencyKey, $source): Appointment {
            Tenant::query()->lockForUpdate()->findOrFail($tenantId);

            $existing = Appointment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->returnIdempotentMatch($existing, $customerId, $serviceId, $staffId, $requestedStart);
            }

            $customer = Customer::query()->findOrFail($customerId);
            $service = Service::query()->where('active', true)->findOrFail($serviceId);
            $staff = Staff::query()->where('active', true)->lockForUpdate()->findOrFail($staffId);

            $assigned = $staff->serviceAssignments()->where('service_id', $service->id)->exists();
            if (! $assigned) {
                throw ValidationException::withMessages(['staff_id' => 'The selected staff member cannot provide this service.']);
            }

            $start = $requestedStart->setTimezone($timezone);
            if ($start->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                throw ValidationException::withMessages(['starts_at' => 'Choose a future appointment time.']);
            }

            // Recheck local holds under the tenant lock; do not perform external HTTP inside this transaction.
            $this->availability->assertSlotAvailable($service->id, $staff->id, $start, false);

            $end = $start->addMinutes((int) $service->duration_minutes);
            $reservedStart = $start->subMinutes((int) $service->buffer_before_minutes);
            $reservedEnd = $end->addMinutes((int) $service->buffer_after_minutes);

            $appointment = Appointment::query()->create([
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'service_name' => $service->name,
                'price_amount' => $service->price_amount,
                'currency' => $service->currency,
                'duration_minutes' => $service->duration_minutes,
                'starts_at' => $start->utc(),
                'ends_at' => $end->utc(),
                'reserved_starts_at' => $reservedStart->utc(),
                'reserved_ends_at' => $reservedEnd->utc(),
                'status' => 'pending_hold',
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'calendar_sync_status' => 'pending',
                'hold_expires_at' => now()->addMinutes(BookingPolicy::query()->first()?->hold_minutes ?? 10),
            ]);

            AppointmentEvent::query()->create([
                'appointment_id' => $appointment->id,
                'event_type' => 'hold_created',
                'actor_type' => 'system',
                'metadata' => [
                    'timezone' => $timezone,
                    'local_start' => $start->format('Y-m-d H:i:s'),
                    'hold_expires_at' => $appointment->hold_expires_at?->toIso8601String(),
                ],
            ]);

            return $appointment;
        });

        if ($appointment->status === 'pending_hold'
            && GoogleCalendarConnection::query()->where('status', 'connected')->exists()) {
            SyncAppointmentCalendarEvent::dispatch($tenantId, $appointment->id);
        }

        return $appointment->refresh();
    }

    private function returnIdempotentMatch(
        Appointment $existing,
        string $customerId,
        string $serviceId,
        string $staffId,
        CarbonImmutable $requestedStart,
    ): Appointment {
        if ($existing->customer_id !== $customerId
            || $existing->service_id !== $serviceId
            || $existing->staff_id !== $staffId
            || ! $existing->starts_at?->equalTo($requestedStart)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for a different booking request.']);
        }

        return $existing;
    }
}
