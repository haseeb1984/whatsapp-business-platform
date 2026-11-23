<?php

namespace App\Services\Booking;

use App\Exceptions\GoogleCalendarBusyException;
use App\Models\Appointment;
use App\Models\AppointmentReschedule;
use App\Services\Google\GoogleCalendarService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AppointmentCalendarOrchestrator
{
    public function __construct(
        private readonly AppointmentLifecycleService $lifecycle,
        private readonly GoogleCalendarService $calendar,
    ) {
    }

    public function confirm(string $appointmentId): Appointment
    {
        $existing = Appointment::query()->findOrFail($appointmentId);
        if ($existing->status === 'confirmed') {
            if ($existing->calendar_sync_status !== 'synced') {
                throw ValidationException::withMessages(['appointment' => 'This confirmed appointment has an unresolved calendar update. Reconciliation must finish before another calendar operation.']);
            }

            return $existing;
        }

        $pending = $this->lifecycle->markCalendarPending($appointmentId);
        if ($pending->status === 'confirmed') {
            return $pending;
        }

        try {
            $eventId = $this->calendar->findAppointmentEvent($pending);
            if ($eventId === null) {
                $this->calendar->assertAppointmentCalendarAvailable($pending);
                $eventId = $this->calendar->createAppointmentEvent($pending);
            }

            return $this->lifecycle->confirmAfterCalendarSync($pending->id, $eventId);
        } catch (Throwable $exception) {
            if ($pending->calendar_sync_status === 'unknown') {
                $this->lifecycle->markCalendarSyncUnknown($pending->id, 'calendar_reconciliation_still_ambiguous');
                throw $exception;
            }

            if ($exception instanceof GoogleCalendarBusyException) {
                $this->lifecycle->failCalendarSync($pending->id, 'calendar_busy');
                throw ValidationException::withMessages(['appointment' => 'The staff Google Calendar is busy at this time. The temporary hold has been released.']);
            }

            if ($this->isDefiniteFailure($exception)) {
                $this->lifecycle->failCalendarSync($pending->id, 'calendar_api_rejected');
            } else {
                $this->lifecycle->markCalendarSyncUnknown($pending->id, 'calendar_operation_ambiguous');
            }

            throw $exception;
        }
    }

    public function cancel(string $appointmentId): Appointment
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);
        if ($appointment->status === 'cancelled') {
            return $appointment;
        }

        if ($appointment->status !== 'confirmed' || ! $appointment->external_event_id
            || $appointment->calendar_sync_status !== 'synced') {
            throw ValidationException::withMessages(['appointment' => 'Only a fully synchronized confirmed appointment can be cancelled here.']);
        }

        if (AppointmentReschedule::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', ['pending', 'unknown'])
            ->exists()) {
            throw ValidationException::withMessages(['appointment' => 'This appointment has a reschedule awaiting calendar confirmation. Resolve it before cancellation.']);
        }

        // Atomically reserve the appointment before the external call so a
        // concurrent reschedule cannot update the same Calendar event.
        $this->lifecycle->beginCalendarDeletion($appointmentId);

        try {
            $this->calendar->deleteAppointmentEvent($appointment);
        } catch (Throwable $exception) {
            $this->lifecycle->markCalendarDeletionUnknown($appointment->id, 'calendar_delete_ambiguous');
            throw $exception;
        }

        return $this->lifecycle->cancelAfterCalendarDeletion($appointment->id);
    }

    private function isDefiniteFailure(Throwable $exception): bool
    {
        if ($exception instanceof ValidationException) {
            return true;
        }

        if ($exception instanceof RuntimeException
            && preg_match('/HTTP ([45][0-9]{2})/', $exception->getMessage(), $matches) === 1) {
            return (int) $matches[1] < 500 && ! in_array((int) $matches[1], [408, 429], true);
        }

        return false;
    }
}
