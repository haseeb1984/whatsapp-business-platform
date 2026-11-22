<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\AppointmentReminder;

class AppointmentReminderService
{
    public function scheduleFor(Appointment $appointment): void
    {
        foreach (['24_hours' => 24, '2_hours' => 2] as $type => $hoursBefore) {
            $scheduledAt = $appointment->starts_at->subHours($hoursBefore);
            if ($scheduledAt->lessThanOrEqualTo(now())) {
                continue;
            }

            $key = 'appointment:'.$appointment->id.':reminder:'.$type.':'.$appointment->starts_at->timestamp;
            $existing = AppointmentReminder::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                continue;
            }

            AppointmentReminder::query()->create([
                'appointment_id' => $appointment->id,
                'channel' => 'whatsapp',
                'reminder_type' => $type,
                'scheduled_at' => $scheduledAt,
                'status' => 'pending',
                'idempotency_key' => $key,
            ]);
        }
    }

    public function cancelPendingFor(Appointment $appointment): int
    {
        return AppointmentReminder::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', ['pending', 'queued', 'processing', 'blocked_template_required', 'blocked_handover'])
            ->update([
                'status' => 'cancelled',
                'queued_at' => null,
                'last_error' => null,
            ]);
    }
}
