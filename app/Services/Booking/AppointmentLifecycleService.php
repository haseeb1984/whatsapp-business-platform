<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentLifecycleService
{
    public function markCalendarPending(string $appointmentId): Appointment
    {
        $result = DB::transaction(function () use ($appointmentId): array {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status === 'calendar_pending') {
                return ['expired' => false, 'appointment' => $appointment];
            }
            if ($appointment->status !== 'pending_hold') {
                throw ValidationException::withMessages(['appointment' => 'Only an active pending hold can be sent to calendar synchronization.']);
            }

            if (! $appointment->hold_expires_at || $appointment->hold_expires_at->lessThanOrEqualTo(now())) {
                $this->transition($appointment, 'expired', 'hold_expired', ['reason' => 'The temporary booking hold expired before calendar synchronization.']);

                return ['expired' => true, 'appointment' => $appointment->refresh()];
            }

            $appointment->forceFill([
                'status' => 'calendar_pending',
                'calendar_sync_status' => 'pending',
                'hold_expires_at' => null,
            ])->save();

            $this->event($appointment, 'calendar_sync_started');

            return ['expired' => false, 'appointment' => $appointment->refresh()];
        });

        if ($result['expired']) {
            throw ValidationException::withMessages(['appointment' => 'The temporary booking hold has expired.']);
        }

        return $result['appointment'];
    }

    /**
     * Call only after Google Calendar has returned a successful event creation/update.
     */
    public function confirmAfterCalendarSync(string $appointmentId, string $externalEventId): Appointment
    {
        if (trim($externalEventId) === '' || mb_strlen($externalEventId) > 255) {
            throw ValidationException::withMessages(['external_event_id' => 'A valid external calendar event ID is required.']);
        }

        return DB::transaction(function () use ($appointmentId, $externalEventId): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status === 'confirmed' && $appointment->external_event_id === $externalEventId) {
                return $appointment;
            }
            if ($appointment->status !== 'calendar_pending') {
                throw ValidationException::withMessages(['appointment' => 'An appointment can only be confirmed after calendar synchronization has started.']);
            }

            $appointment->forceFill([
                'status' => 'confirmed',
                'calendar_sync_status' => 'synced',
                'external_event_id' => $externalEventId,
                'hold_expires_at' => null,
            ])->save();

            $this->event($appointment, 'confirmed', ['external_event_id' => $externalEventId]);

            return $appointment->refresh();
        });
    }


    public function markCalendarSyncUnknown(string $appointmentId, string $reasonCode): Appointment
    {
        return DB::transaction(function () use ($appointmentId, $reasonCode): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status !== 'calendar_pending') {
                throw ValidationException::withMessages(['appointment' => 'Only an appointment awaiting calendar synchronization can have an unknown outcome.']);
            }

            if ($appointment->calendar_sync_status === 'unknown') {
                return $appointment;
            }

            $appointment->forceFill(['calendar_sync_status' => 'unknown'])->save();
            $this->event($appointment, 'calendar_sync_unknown', ['reason_code' => mb_substr($reasonCode, 0, 80)]);

            return $appointment->refresh();
        });
    }

    public function failCalendarSync(string $appointmentId, string $reasonCode): Appointment
    {
        return DB::transaction(function () use ($appointmentId, $reasonCode): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status === 'failed') {
                return $appointment;
            }
            if (! in_array($appointment->status, ['pending_hold', 'calendar_pending'], true)) {
                throw ValidationException::withMessages(['appointment' => 'This appointment is no longer awaiting calendar synchronization.']);
            }

            $appointment->forceFill([
                'status' => 'failed',
                'calendar_sync_status' => 'failed',
                'hold_expires_at' => null,
            ])->save();

            $this->event($appointment, 'calendar_sync_failed', ['reason_code' => mb_substr($reasonCode, 0, 80)]);

            return $appointment->refresh();
        });
    }

    /**
     * Call only after the external calendar event has been successfully deleted.
     */

    public function markCalendarDeletionUnknown(string $appointmentId, string $reasonCode): Appointment
    {
        return DB::transaction(function () use ($appointmentId, $reasonCode): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status !== 'confirmed') {
                throw ValidationException::withMessages(['appointment' => 'Only a confirmed appointment can have an ambiguous calendar deletion.']);
            }

            if ($appointment->calendar_sync_status === 'unknown') {
                return $appointment;
            }

            $appointment->forceFill(['calendar_sync_status' => 'unknown'])->save();
            $this->event($appointment, 'calendar_deletion_unknown', ['reason_code' => mb_substr($reasonCode, 0, 80)]);

            return $appointment->refresh();
        });
    }


    public function markCalendarDeletionFailed(string $appointmentId, string $reasonCode): Appointment
    {
        return DB::transaction(function () use ($appointmentId, $reasonCode): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status !== 'confirmed') {
                throw ValidationException::withMessages(['appointment' => 'Only a confirmed appointment can have a failed calendar deletion.']);
            }

            $appointment->forceFill(['calendar_sync_status' => 'failed'])->save();
            $this->event($appointment, 'calendar_deletion_failed', ['reason_code' => mb_substr($reasonCode, 0, 80)]);

            return $appointment->refresh();
        });
    }

    public function cancelAfterCalendarDeletion(string $appointmentId): Appointment
    {
        return DB::transaction(function () use ($appointmentId): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status === 'cancelled') {
                return $appointment;
            }
            if ($appointment->status !== 'confirmed' || ! $appointment->external_event_id) {
                throw ValidationException::withMessages(['appointment' => 'Only a calendar-confirmed appointment can be cancelled through calendar deletion.']);
            }

            $appointment->forceFill([
                'status' => 'cancelled',
                'calendar_sync_status' => 'synced',
            ])->save();

            $this->event($appointment, 'cancelled_after_calendar_deletion');

            return $appointment->refresh();
        });
    }


    public function cancelPendingHold(string $appointmentId, string $reason = 'cancelled_by_staff'): Appointment
    {
        return DB::transaction(function () use ($appointmentId, $reason): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if ($appointment->status === 'cancelled') {
                return $appointment;
            }
            if ($appointment->status !== 'pending_hold') {
                throw ValidationException::withMessages(['appointment' => 'Only a temporary pending hold can be cancelled locally.']);
            }

            $appointment->forceFill([
                'status' => 'cancelled',
                'calendar_sync_status' => 'not_required',
                'hold_expires_at' => null,
            ])->save();

            $this->event($appointment, 'hold_cancelled', ['reason' => mb_substr($reason, 0, 80)]);

            return $appointment->refresh();
        });
    }

    public function expireHoldsForTenant(string $tenantId, int $limit = 100): int
    {
        return TenantContext::runForTenant($tenantId, function () use ($limit): int {
            $boundedLimit = max(1, min($limit, 500));
            $ids = Appointment::query()
                ->where('status', 'pending_hold')
                ->where('hold_expires_at', '<=', now())
                ->orderBy('hold_expires_at')
                ->limit($boundedLimit)
                ->pluck('id');

            $expired = 0;
            foreach ($ids as $id) {
                DB::transaction(function () use ($id, &$expired): void {
                    $appointment = Appointment::query()->lockForUpdate()->find($id);
                    if (! $appointment || $appointment->status !== 'pending_hold'
                        || ! $appointment->hold_expires_at
                        || $appointment->hold_expires_at->greaterThan(now())) {
                        return;
                    }

                    $appointment->forceFill(['status' => 'expired', 'calendar_sync_status' => 'not_required'])->save();
                    $this->event($appointment, 'hold_expired');
                    $expired++;
                });
            }

            return $expired;
        });
    }

    private function transition(Appointment $appointment, string $status, string $eventType, array $metadata = []): void
    {
        $appointment->forceFill([
            'status' => $status,
            'calendar_sync_status' => $status === 'expired' ? 'not_required' : 'pending',
            'hold_expires_at' => null,
        ])->save();

        $this->event($appointment, $eventType, $metadata);
    }

    private function event(Appointment $appointment, string $eventType, array $metadata = []): void
    {
        AppointmentEvent::query()->create([
            'appointment_id' => $appointment->id,
            'event_type' => $eventType,
            'actor_type' => 'system',
            'metadata' => $metadata,
        ]);
    }
}
