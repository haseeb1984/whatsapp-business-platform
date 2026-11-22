<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\Conversation;
use App\Models\ConversationBookingSession;
use App\Models\Service;
use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingConversationHandler
{
    public function __construct(
        private readonly AppointmentAvailabilityService $availability,
        private readonly AppointmentBookingService $booking,
        private readonly AppointmentLifecycleService $lifecycle,
    ) {
    }

    public function handle(Conversation $conversation, string $message, string $inboundMessageId): ?string
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        $session = $this->sessionFor($conversation, $message);
        if (! $session) {
            return null;
        }

        if ($session->expires_at->isPast() && $session->state !== 'hold_created') {
            $session = $this->resetToServiceSelection($conversation, $session);
            return $this->serviceOptions($session);
        }

        if (preg_match('/^\s*(cancel|stop|nevermind|never mind)\s*$/i', $message) === 1) {
            return $this->cancelSession($session);
        }

        if ($session->state === 'hold_created') {
            return $this->holdStatus($session);
        }

        return match ($session->state) {
            'awaiting_service' => $this->selectService($session, $message),
            'awaiting_date' => $this->selectDate($session, $message),
            'awaiting_staff' => $this->selectStaff($session, $message),
            'awaiting_slot' => $this->selectSlot($conversation, $session, $message, $inboundMessageId),
            default => 'I could not continue that booking. Send “book” to start again.',
        };
    }

    private function sessionFor(Conversation $conversation, string $message): ?ConversationBookingSession
    {
        return DB::transaction(function () use ($conversation, $message): ?ConversationBookingSession {
            $session = ConversationBookingSession::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if ($session) {
                $canRestart = in_array($session->state, ['cancelled', 'expired'], true);

                if ($session->state === 'hold_created' && $session->appointment_id) {
                    $appointment = Appointment::query()->find($session->appointment_id);
                    $canRestart = $appointment !== null
                        && in_array($appointment->status, ['confirmed', 'failed', 'expired', 'cancelled'], true);
                }

                if ($canRestart && $this->isBookingIntent($message)) {
                    return $this->resetToServiceSelection($conversation, $session);
                }

                return $session;
            }

            if (! $this->isBookingIntent($message)) {
                return null;
            }

            $services = Service::query()->where('active', true)->orderBy('name')->limit(10)->get();
            $session = ConversationBookingSession::query()->create([
                'conversation_id' => $conversation->id,
                'state' => 'awaiting_service',
                'offered_services' => $services->pluck('id')->all(),
                'expires_at' => now()->addMinutes(30),
            ]);

            return $session;
        });
    }

    private function isBookingIntent(string $message): bool
    {
        return preg_match('/^\s*(book|booking|appointment|schedule|reserve)\s*$/i', $message) === 1
            || preg_match('/\b(book|reserve|schedule)\s+(an?\s+)?(appointment|slot|visit)\b/i', $message) === 1
            || preg_match('/\b(i want|i need|i would like|i\'d like|can i|could i|please)\b.{0,40}\b(book|booking|appointment|schedule|reserve)\b/i', $message) === 1
            || preg_match('/\b(need|want|looking for)\s+(an?\s+)?appointment\b/i', $message) === 1;
    }

    private function serviceOptions(ConversationBookingSession $session): string
    {
        $services = Service::query()->whereIn('id', $session->offered_services ?? [])->where('active', true)->orderBy('name')->get();
        if ($services->isEmpty()) {
            return 'Online booking is not configured yet. I will ask a team member to help you.';
        }

        $lines = ['Sure — which service would you like to book?'];
        foreach ($services as $index => $service) {
            $lines[] = ($index + 1).'. '.$service->name.' — '.$service->price_amount.' '.$service->currency;
        }
        $lines[] = 'Reply with the service number.';

        return implode("\n", $lines);
    }

    private function selectService(ConversationBookingSession $session, string $message): string
    {
        $services = Service::query()->whereIn('id', $session->offered_services ?? [])->where('active', true)->orderBy('name')->get();
        $index = $this->choiceIndex($message, $services->count());
        if ($index === null) {
            return $this->serviceOptions($session);
        }

        $service = $services->values()->get($index);
        $session->forceFill([
            'state' => 'awaiting_date',
            'service_id' => $service->id,
            'offered_services' => null,
            'expires_at' => now()->addMinutes(30),
        ])->save();

        return 'You selected '.$service->name.'. What date would you prefer? Please reply in YYYY-MM-DD format.';
    }

    private function selectDate(ConversationBookingSession $session, string $message): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $message, app(TenantContext::class)->tenant()->timezone ?: 'UTC');
        if (! $date || $date->format('Y-m-d') !== $message) {
            return 'Please send the date in YYYY-MM-DD format, for example 2026-12-05.';
        }

        $service = Service::query()->where('active', true)->find($session->service_id);
        if (! $service) {
            return $this->resetToServiceSelection($session->conversation, $session) ? $this->serviceOptions($session) : 'Please start your booking again by sending “book”.';
        }

        $staff = Staff::query()->where('active', true)
            ->whereHas('serviceAssignments', fn ($query) => $query->where('service_id', $service->id))
            ->orderBy('name')
            ->limit(10)
            ->get();

        if ($staff->isEmpty()) {
            return 'There are no team members configured for this service yet. Please contact the business directly.';
        }

        $session->forceFill([
            'state' => 'awaiting_staff',
            'local_date' => $date->toDateString(),
            'offered_staff' => $staff->pluck('id')->all(),
            'expires_at' => now()->addMinutes(30),
        ])->save();

        $lines = ['Would you like a particular team member?'];
        $lines[] = '0. Any available team member';
        foreach ($staff as $index => $member) {
            $lines[] = ($index + 1).'. '.$member->name;
        }
        $lines[] = 'Reply with a number.';

        return implode("\n", $lines);
    }

    private function selectStaff(ConversationBookingSession $session, string $message): string
    {
        $staff = Staff::query()->whereIn('id', $session->offered_staff ?? [])->where('active', true)->orderBy('name')->get();
        $choice = strtolower(trim($message));
        if (in_array($choice, ['any', 'anyone', '0'], true)) {
            $staffId = null;
        } else {
            $index = $this->choiceIndex($message, $staff->count());
            if ($index === null) {
                return 'Please choose a team member number, or reply “any” for the first available person.';
            }

            $staffId = $staff->values()->get($index)?->id;
        }

        $session->forceFill([
            'state' => 'awaiting_slot',
            'staff_id' => $staffId,
            'offered_staff' => null,
            'expires_at' => now()->addMinutes(30),
        ])->save();

        return $this->slotOptions($session);
    }

    private function slotOptions(ConversationBookingSession $session): string
    {
        try {
            $slots = $this->availability->availableSlots(
                $session->service_id,
                $session->local_date->format('Y-m-d'),
                $session->staff_id,
            );
        } catch (ValidationException) {
            return 'I could not verify the calendar availability just now. Please try again in a moment.';
        }

        $slots = array_slice($slots, 0, 8);
        if ($slots === []) {
            $session->forceFill([
                'state' => 'awaiting_date',
                'staff_id' => null,
                'offered_slots' => null,
                'expires_at' => now()->addMinutes(30),
            ])->save();

            return 'There are no available times on that date. Please send another date in YYYY-MM-DD format.';
        }

        $session->forceFill([
            'offered_slots' => $slots,
            'expires_at' => now()->addMinutes(30),
        ])->save();

        $lines = ['These times are currently available:'];
        foreach ($slots as $index => $slot) {
            $lines[] = ($index + 1).'. '.$slot['local_start'].'–'.$slot['local_end'].' '.$slot['utc_offset'].' with '.$slot['staff_name'];
        }
        $lines[] = 'Reply with the time number you prefer. Availability will be checked again before a temporary hold is created.';

        return implode("\n", $lines);
    }

    private function selectSlot(Conversation $conversation, ConversationBookingSession $session, string $message, string $inboundMessageId): string
    {
        $slots = $session->offered_slots ?? [];
        $index = $this->choiceIndex($message, count($slots));
        if ($index === null || ! isset($slots[$index])) {
            return 'Please reply with one of the listed time numbers, or send “cancel” to stop.';
        }

        $slot = $slots[$index];
        $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
        $localStart = CarbonImmutable::parse($slot['starts_at'])->setTimezone($timezone);

        try {
            $appointment = $this->booking->createHold(
                $conversation->customer_id,
                $session->service_id,
                $slot['staff_id'],
                $localStart,
                'whatsapp-booking:'.$inboundMessageId,
                'whatsapp',
            );
        } catch (ValidationException) {
            $session->forceFill([
                'state' => 'awaiting_slot',
                'offered_slots' => null,
                'expires_at' => now()->addMinutes(30),
            ])->save();

            return 'That time is no longer available. I will refresh the available times; please send the date again in YYYY-MM-DD format.';
        }

        $session->forceFill([
            'state' => 'hold_created',
            'appointment_id' => $appointment->id,
            'offered_slots' => null,
            'expires_at' => now()->addDay(),
        ])->save();

        $displayTime = $appointment->starts_at->setTimezone($timezone)->format('D, d M Y H:i');
        return 'I have placed a temporary hold for '.$appointment->service_name.' on '.$displayTime.' with '.$slot['staff_name'].'. This is not confirmed yet; the calendar check is in progress. I will share the booking status once the business calendar confirms it.';
    }

    private function holdStatus(ConversationBookingSession $session): string
    {
        $appointment = Appointment::query()->find($session->appointment_id);
        if (! $appointment) {
            return 'I could not find that booking request. Send “book” to start again.';
        }

        if ($appointment->status === 'confirmed') {
            $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
            return 'Your appointment is confirmed for '.$appointment->starts_at->setTimezone($timezone)->format('D, d M Y H:i').'.';
        }

        if (in_array($appointment->status, ['failed', 'expired', 'cancelled'], true)) {
            $session->forceFill(['state' => 'cancelled'])->save();
            return 'That booking request was not confirmed. Send “book” if you would like to choose another time.';
        }

        return 'Your appointment request is still being checked against the business calendar. It is not confirmed yet.';
    }

    private function cancelSession(ConversationBookingSession $session): string
    {
        if ($session->appointment_id) {
            $appointment = Appointment::query()->find($session->appointment_id);
            if ($appointment && $appointment->status === 'pending_hold') {
                $this->lifecycle->cancelPendingHold($appointment->id);
            } elseif ($appointment && $appointment->status === 'calendar_pending') {
                return 'The calendar check is already in progress. I cannot safely cancel it automatically; please ask a team member to help.';
            } elseif ($appointment && $appointment->status === 'confirmed') {
                return 'Your appointment is confirmed. Please ask a team member to cancel it so the calendar event can be removed safely.';
            }
        }

        $session->forceFill(['state' => 'cancelled', 'expires_at' => now()])->save();

        return 'Your booking request has been cancelled.';
    }

    private function resetToServiceSelection(Conversation $conversation, ConversationBookingSession $session): ConversationBookingSession
    {
        $services = Service::query()->where('active', true)->orderBy('name')->limit(10)->get();
        $session->forceFill([
            'state' => 'awaiting_service',
            'service_id' => null,
            'staff_id' => null,
            'local_date' => null,
            'offered_services' => $services->pluck('id')->all(),
            'offered_staff' => null,
            'offered_slots' => null,
            'appointment_id' => null,
            'expires_at' => now()->addMinutes(30),
        ])->save();

        return $session;
    }

    private function choiceIndex(string $message, int $count): ?int
    {
        if (preg_match('/^\s*(\d{1,2})\s*$/', $message, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];
        if ($number < 1 || $number > $count) {
            return null;
        }

        return $number - 1;
    }
}
