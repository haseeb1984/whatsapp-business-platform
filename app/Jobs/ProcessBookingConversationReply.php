<?php

namespace App\Jobs;

use App\Enums\MessageActor;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Booking\AppointmentCalendarOrchestrator;
use App\Services\Booking\BookingConversationHandler;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessBookingConversationReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $inboundMessageId,
    ) {
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(
        BookingConversationHandler $booking,
        AppointmentCalendarOrchestrator $calendar,
        WhatsAppMessageService $messenger,
    ): void {
        TenantContext::runForTenant($this->tenantId, function () use ($booking, $calendar, $messenger): void {
            $context = DB::transaction(function (): ?array {
                $conversation = Conversation::query()->lockForUpdate()->find($this->conversationId);
                if (! $conversation || $conversation->status !== 'open' || $conversation->automation_state !== 'AUTO_ACTIVE') {
                    return null;
                }

                $inbound = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'inbound')
                    ->whereKey($this->inboundMessageId)
                    ->first();

                $latest = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'inbound')
                    ->orderByDesc('created_at')
                    ->first();

                if (! $inbound || $latest?->id !== $inbound->id || $inbound->message_type !== 'text' || ! is_string($inbound->body)) {
                    return null;
                }

                return ['conversation' => $conversation, 'inbound' => $inbound];
            });

            if (! $context) {
                return;
            }

            $reply = $booking->handle(
                $context['conversation'],
                $context['inbound']->body,
                $context['inbound']->id,
            );

            if ($reply === null) {
                GenerateAutomatedFaqReply::dispatch(
                    $this->tenantId,
                    $this->conversationId,
                    $this->inboundMessageId,
                );

                return;
            }

            $session = $context['conversation']->bookingSession()->first();
            $appointment = $session?->appointment_id
                ? Appointment::query()->find($session->appointment_id)
                : null;

            if ($appointment && $appointment->status === 'pending_hold') {
                try {
                    $calendar->confirm($appointment->id);
                } catch (Throwable) {
                    // The lifecycle records definite failures or ambiguous outcomes.
                    // The customer-facing status below is re-read from persisted state.
                }

                $reply = $booking->handle(
                    $context['conversation'],
                    $context['inbound']->body,
                    $context['inbound']->id,
                ) ?? $reply;
            }

            $messenger->sendText(
                $context['conversation'],
                $reply,
                MessageActor::Automation,
                $this->inboundMessageId,
            );
        });
    }
}
