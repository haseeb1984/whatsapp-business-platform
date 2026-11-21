<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\Customer;
use App\Models\GoogleCalendarConnection;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Booking\AppointmentAvailabilityService;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Booking\AppointmentCalendarOrchestrator;
use App\Services\Booking\AppointmentLifecycleService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(): Response
    {
        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $policy = BookingPolicy::query()->first();

        $appointments = Appointment::query()
            ->with(['customer', 'service', 'staff', 'reminders'])
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        return Inertia::render('Business/Appointments/Index', [
            'appointments' => $appointments->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'customer' => $appointment->customer?->display_name ?: $appointment->customer?->whatsapp_wa_id,
                'service' => $appointment->service_name,
                'staff' => $appointment->staff?->name,
                'starts_at' => $appointment->starts_at?->setTimezone($timezone)->toIso8601String(),
                'starts_at_label' => $appointment->starts_at?->setTimezone($timezone)->format('D, d M Y H:i'),
                'ends_at' => $appointment->ends_at?->setTimezone($timezone)->toIso8601String(),
                'status' => $appointment->status,
                'calendar_sync_status' => $appointment->calendar_sync_status,
                'hold_expires_at' => $appointment->hold_expires_at?->setTimezone($timezone)->toIso8601String(),
                'hold_expires_label' => $appointment->hold_expires_at?->setTimezone($timezone)->format('H:i'),
                'price' => $appointment->price_amount.' '.$appointment->currency,
                'reminders' => $appointment->reminders->map(fn ($reminder): array => [
                    'type' => $reminder->reminder_type,
                    'status' => $reminder->status,
                    'scheduled_at' => $reminder->scheduled_at?->setTimezone($timezone)->toIso8601String(),
                ])->all(),
            ])->all(),
            'services' => Service::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::query()->orderByDesc('created_at')->limit(100)->get(['id', 'display_name', 'whatsapp_wa_id'])
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'label' => $customer->display_name ?: $customer->whatsapp_wa_id,
                ])->all(),
            'staff' => Staff::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Staff $member): array => ['id' => $member->id, 'name' => $member->name])->all(),
            'today_local' => CarbonImmutable::now($timezone)->format('Y-m-d'),
            'timezone' => $timezone,
            'booking_policy' => [
                'minimum_notice_minutes' => $policy?->minimum_notice_minutes ?? 60,
                'booking_horizon_days' => $policy?->booking_horizon_days ?? 60,
                'slot_interval_minutes' => $policy?->slot_interval_minutes ?? 15,
                'hold_minutes' => $policy?->hold_minutes ?? 10,
            ],
            'current_user_role' => app(TenantContext::class)->role(),
            'calendar_connected' => GoogleCalendarConnection::query()->where('status', 'connected')->exists(),
        ]);
    }

    public function availability(Request $request, AppointmentAvailabilityService $availability): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'service_id' => ['required', 'uuid', Rule::exists('services', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'uuid', Rule::exists('staff_members', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
        ]);

        return response()->json([
            'slots' => $availability->availableSlots($validated['service_id'], $validated['date'], $validated['staff_id'] ?? null),
            'timezone' => app(TenantContext::class)->tenant()->timezone ?: 'UTC',
            'calendar_connected' => GoogleCalendarConnection::query()->where('status', 'connected')->exists(),
        ]);
    }

    public function createHold(Request $request, AppointmentBookingService $booking): JsonResponse|RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'service_id' => ['required', 'uuid', Rule::exists('services', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'staff_id' => ['required', 'uuid', Rule::exists('staff_members', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('active', true))],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'source' => ['nullable', Rule::in(['whatsapp', 'dashboard', 'api'])],
        ]);

        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $localStart = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $validated['starts_at'], 'UTC');
        if (! $localStart || $localStart->format('Y-m-d\\TH:i:sP') !== $validated['starts_at']) {
            throw ValidationException::withMessages(['starts_at' => 'Provide the selected slot as an ISO-8601 timestamp with an explicit UTC offset.']);
        }

        $appointment = $booking->createHold(
            $validated['customer_id'],
            $validated['service_id'],
            $validated['staff_id'],
            $localStart,
            $validated['idempotency_key'],
            $validated['source'] ?? 'dashboard',
        );

        $appointment->refresh();

        $message = match ($appointment->status) {
            'confirmed' => 'Appointment confirmed after Google Calendar synchronization.',
            'failed' => 'Google Calendar could not confirm this appointment. The slot has been released.',
            default => 'Temporary hold created. This is not a confirmed booking until calendar synchronization succeeds.',
        };

        if ($request->expectsJson()) {
            $responseStatus = $appointment->status === 'confirmed' ? 201 : ($appointment->status === 'failed' ? 422 : 202);
            return response()->json([
                'appointment' => [
                    'id' => $appointment->id,
                    'status' => $appointment->status,
                    'calendar_sync_status' => $appointment->calendar_sync_status,
                    'hold_expires_at' => $appointment->hold_expires_at?->toIso8601String(),
                ],
                'message' => $message,
            ], $responseStatus);
        }

        return back()->with($appointment->status === 'failed' ? 'error' : 'success', $message);
    }

    public function confirmCalendar(string $appointmentId, AppointmentCalendarOrchestrator $calendar): RedirectResponse
    {
        $calendar->confirm($appointmentId);

        return back()->with('success', 'Appointment confirmed after Google Calendar synchronization.');
    }

    public function cancelConfirmed(string $appointmentId, AppointmentCalendarOrchestrator $calendar): RedirectResponse
    {
        $calendar->cancel($appointmentId);

        return back()->with('success', 'Appointment cancelled after Google Calendar deletion.');
    }

    public function cancelHold(string $appointmentId, AppointmentLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->cancelPendingHold($appointmentId);

        return back()->with('success', 'Temporary appointment hold cancelled.');
    }
}
