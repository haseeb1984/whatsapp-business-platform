<?php

namespace App\Services\Booking;

use App\Exceptions\GoogleCalendarBusyException;
use App\Models\Appointment;
use App\Services\Google\GoogleCalendarService;
use Illuminate\Support\Facades\DB;
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
        $pending = $this->lifecycle->markCalendarPending($appointmentId);
        if ($pending->status === 'confirmed') {
            return $pending;
        }

        try {
            $this->calendar->assertAppointmentCalendarAvailable($pending);
            $eventId = $this->calendar->createAppointmentEvent($pending);

            return $this->lifecycle->confirmAfterCalendarSync($pending->id, $eventId);
        } catch (Throwable $exception) {
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

        if ($appointment->status !== 'confirmed' || ! $appointment->external_event_id) {
            throw ValidationException::withMessages(['appointment' => 'Only a confirmed calendar appointment can be cancelled here.']);
        }

        $this->calendar->deleteAppointmentEvent($appointment);

        return $this->lifecycle->cancelAfterCalendarDeletion($appointment->id);
    }

    private function isDefiniteFailure(Throwable $exception): bool
    {
        if ($exception instanceof ValidationException) {
            return true;
        }

        if ($exception instanceof RuntimeException
            && preg_match('/HTTP ([45][0-9]{2})/', $exception->getMessage(), $matches) === 1) {
            return (int) $matches[1] < 500;
        }

        return false;
    }
}
