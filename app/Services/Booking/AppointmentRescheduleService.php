<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentReschedule;
use App\Models\Tenant;
use App\Jobs\SyncAppointmentReschedule;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AppointmentRescheduleService
{
    public function __construct(
        private readonly AppointmentAvailabilityService $availability,
        private readonly AppointmentReminderService $reminders,
        private readonly GoogleCalendarService $calendar,
    ) {
    }

    public function reschedule(string $appointmentId, CarbonImmutable $requestedLocalStart, string $idempotencyKey): Appointment
    {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid reschedule idempotency key is required.']);
        }

        $tenantId = app(TenantContext::class)->id();
        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $newStart = $requestedLocalStart->setTimezone($timezone);
        if ($newStart->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a future appointment time.']);
        }

        $existing = AppointmentReschedule::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->appointment_id !== $appointmentId) {
                throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key belongs to a different appointment.']);
            }
            if (! $existing->new_starts_at->equalTo($newStart->utc())) {
                throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for a different reschedule time.']);
            }
            if ($existing->status === 'synced') {
                return Appointment::query()->findOrFail($appointmentId);
            }
            if (in_array($existing->status, ['failed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['reschedule' => 'This reschedule request is no longer retryable. Use a new idempotency key.']);
            }
            $reschedule = $existing;
        } else {
            $appointmentSnapshot = Appointment::query()->findOrFail($appointmentId);
            $this->assertReschedulable($appointmentSnapshot);

            $duration = (int) $appointmentSnapshot->duration_minutes;
            $newEnd = $newStart->addMinutes($duration);
            $newReservedStart = $newStart->subMinutes((int) ($appointmentSnapshot->service?->buffer_before_minutes ?? 0));
            $newReservedEnd = $newEnd->addMinutes((int) ($appointmentSnapshot->service?->buffer_after_minutes ?? 0));

            // The current event remains in Google Calendar, so a target overlapping it is conservatively rejected.
            $this->availability->assertSlotAvailable(
                $appointmentSnapshot->service_id,
                $appointmentSnapshot->staff_id,
                $newStart,
                true,
            );

            $reschedule = DB::transaction(function () use (
                $tenantId,
                $appointmentId,
                $idempotencyKey,
                $newStart,
                $newEnd,
                $newReservedStart,
                $newReservedEnd,
            ): AppointmentReschedule {
                Tenant::query()->lockForUpdate()->findOrFail($tenantId);

                // A concurrent request may have passed the optimistic lookup before
                // another request committed the same idempotency key.
                $concurrentRequest = AppointmentReschedule::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($concurrentRequest) {
                    if ($concurrentRequest->appointment_id !== $appointmentId) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key belongs to a different appointment.']);
                    }
                    if (! $concurrentRequest->new_starts_at->equalTo($newStart->utc())) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for a different reschedule time.']);
                    }

                    return $concurrentRequest;
                }

                $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
                $this->assertReschedulable($appointment);

                if (AppointmentReschedule::query()
                    ->where('appointment_id', $appointmentId)
                    ->whereIn('status', ['pending', 'unknown'])
                    ->exists()) {
                    throw ValidationException::withMessages(['reschedule' => 'Another reschedule is already awaiting calendar confirmation.']);
                }

                $this->availability->assertSlotAvailable(
                    $appointment->service_id,
                    $appointment->staff_id,
                    $newStart,
                    false,
                );

                $request = AppointmentReschedule::query()->create([
                    'appointment_id' => $appointment->id,
                    'old_starts_at' => $appointment->starts_at,
                    'old_ends_at' => $appointment->ends_at,
                    'old_reserved_starts_at' => $appointment->reserved_starts_at,
                    'old_reserved_ends_at' => $appointment->reserved_ends_at,
                    'new_starts_at' => $newStart->utc(),
                    'new_ends_at' => $newEnd->utc(),
                    'new_reserved_starts_at' => $newReservedStart->utc(),
                    'new_reserved_ends_at' => $newReservedEnd->utc(),
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                ]);

                $appointment->forceFill(['calendar_sync_status' => 'pending'])->save();
                AppointmentEvent::query()->create([
                    'appointment_id' => $appointment->id,
                    'event_type' => 'reschedule_requested',
                    'actor_type' => 'system',
                    'metadata' => [
                        'reschedule_id' => $request->id,
                        'new_starts_at' => $newStart->utc()->toIso8601String(),
                    ],
                ]);

                return $request;
            });
        }

        if ($reschedule->status === 'synced') {
            return Appointment::query()->findOrFail($appointmentId);
        }

        $appointment = Appointment::query()->findOrFail($appointmentId);
        try {
            $this->calendar->updateAppointmentEvent(
                $appointment,
                $reschedule->new_starts_at,
                $reschedule->new_ends_at,
            );
        } catch (Throwable $exception) {
            $definite = $this->recordCalendarFailure($appointmentId, $reschedule->id, $exception);
            if (! $definite) {
                SyncAppointmentReschedule::dispatch($tenantId, $reschedule->id);
            }
            throw $exception;
        }

        return DB::transaction(function () use ($appointmentId, $reschedule): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            $request = AppointmentReschedule::query()->lockForUpdate()->findOrFail($reschedule->id);

            if ($request->status === 'synced') {
                return $appointment;
            }
            if (! in_array($request->status, ['pending', 'unknown'], true)) {
                throw ValidationException::withMessages(['reschedule' => 'The reschedule request is no longer active.']);
            }

            $appointment->forceFill([
                'starts_at' => $request->new_starts_at,
                'ends_at' => $request->new_ends_at,
                'reserved_starts_at' => $request->new_reserved_starts_at,
                'reserved_ends_at' => $request->new_reserved_ends_at,
                'calendar_sync_status' => 'synced',
            ])->save();

            $request->forceFill(['status' => 'synced', 'last_error' => null])->save();
            AppointmentEvent::query()->create([
                'appointment_id' => $appointment->id,
                'event_type' => 'rescheduled',
                'actor_type' => 'system',
                'metadata' => [
                    'reschedule_id' => $request->id,
                    'old_starts_at' => $request->old_starts_at->toIso8601String(),
                    'new_starts_at' => $request->new_starts_at->toIso8601String(),
                ],
            ]);

            $appointment = $appointment->refresh();
            $this->reminders->cancelPendingFor($appointment);
            $this->reminders->scheduleFor($appointment);

            return $appointment;
        });
    }

    private function assertReschedulable(Appointment $appointment): void
    {
        if ($appointment->status !== 'confirmed'
            || $appointment->calendar_sync_status !== 'synced'
            || ! $appointment->external_event_id) {
            throw ValidationException::withMessages(['appointment' => 'Only a calendar-synchronized confirmed appointment can be rescheduled.']);
        }
    }

    private function recordCalendarFailure(string $appointmentId, string $rescheduleId, Throwable $exception): bool
    {
        $definite = $exception instanceof ValidationException
            || ($exception instanceof RuntimeException
                && preg_match('/HTTP ([45][0-9]{2})/', $exception->getMessage(), $matches) === 1
                && (int) $matches[1] < 500
                && ! in_array((int) $matches[1], [408, 429], true));

        DB::transaction(function () use ($appointmentId, $rescheduleId, $exception, $definite): void {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            $request = AppointmentReschedule::query()->lockForUpdate()->findOrFail($rescheduleId);
            $request->forceFill([
                'status' => $definite ? 'failed' : 'unknown',
                'last_error' => $definite ? 'calendar_update_rejected' : 'calendar_update_outcome_unknown',
            ])->save();
            $appointment->forceFill(['calendar_sync_status' => $definite ? 'synced' : 'unknown'])->save();

            AppointmentEvent::query()->create([
                'appointment_id' => $appointmentId,
                'event_type' => $definite ? 'reschedule_calendar_update_failed' : 'reschedule_calendar_update_unknown',
                'actor_type' => 'system',
                'metadata' => ['reschedule_id' => $rescheduleId, 'reason_code' => $definite ? 'provider_rejected' : 'provider_outcome_unknown'],
            ]);
        });

        return $definite;
    }
}
