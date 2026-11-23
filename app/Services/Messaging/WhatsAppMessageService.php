<?php

namespace App\Services\Messaging;

use App\Enums\MessageActor;
use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Models\WhatsAppConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppMessageService
{

    public function sendText(Conversation $conversation, string $body, MessageActor $actor = MessageActor::Automation, ?string $replyToMessageId = null, ?int $staffUserId = null, ?string $reminderId = null): Message
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4096) {
            throw ValidationException::withMessages([
                'message' => 'Text messages must contain between 1 and 4096 characters.',
            ]);
        }

        return DB::transaction(function () use ($conversation, $body, $actor, $replyToMessageId, $staffUserId, $reminderId): Message {
            if ($reminderId !== null) {
                $existingReminderMessage = Message::query()->where('reminder_id', $reminderId)->first();
                if ($existingReminderMessage) {
                    if ($existingReminderMessage->conversation_id !== $conversation->id) {
                        throw ValidationException::withMessages(['reminder' => 'This reminder was already sent to a different conversation.']);
                    }

                    return $existingReminderMessage;
                }

                $reminder = AppointmentReminder::query()->lockForUpdate()->findOrFail($reminderId);
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($reminder->appointment_id);
                if ($reminder->status !== 'processing' || $appointment->status !== 'confirmed') {
                    throw ValidationException::withMessages(['reminder' => 'This appointment reminder is no longer eligible to send.']);
                }

                $existingReminderMessage = Message::query()->where('reminder_id', $reminderId)->first();
                if ($existingReminderMessage) {
                    if ($existingReminderMessage->conversation_id !== $conversation->id) {
                        throw ValidationException::withMessages(['reminder' => 'This reminder was already sent to a different conversation.']);
                    }

                    return $existingReminderMessage;
                }
            }

            $lockedConversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($lockedConversation->status !== 'open') {
                throw ValidationException::withMessages(['conversation' => 'This conversation is closed.']);
            }

            $allowedStates = $actor === MessageActor::Automation
                ? ['AUTO_ACTIVE']
                : ['HUMAN_ACTIVE', 'WAITING_FOR_STAFF'];

            if ($actor === MessageActor::Staff && $staffUserId !== null && $lockedConversation->assigned_user_id !== $staffUserId) {
                throw ValidationException::withMessages(['conversation' => 'Only the assigned team member may reply.']);
            }

            if (! in_array($lockedConversation->automation_state, $allowedStates, true)) {
                throw ValidationException::withMessages([
                    'conversation' => 'The conversation handover state does not allow this sender to reply.',
                ]);
            }

            $lastInbound = Message::query()
                ->where('conversation_id', $lockedConversation->id)
                ->where('direction', 'inbound')
                ->orderByDesc('sent_at')
                ->orderByDesc('created_at')
                ->first();

            if ($replyToMessageId !== null) {
                $latestInbound = Message::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->where('direction', 'inbound')
                    ->orderByDesc('created_at')
                    ->first();

                if ($actor !== MessageActor::Automation || ! $latestInbound || $latestInbound->id !== $replyToMessageId) {
                    throw ValidationException::withMessages(['message' => 'An automated reply must reference the latest inbound customer message.']);
                }

                $existingReply = Message::query()->where('reply_to_message_id', $replyToMessageId)->first();
                if ($existingReply) {
                    return $existingReply;
                }
            }

            if (! $lastInbound?->sent_at || $lastInbound->sent_at->lt(now()->subHours(24))) {
                throw ValidationException::withMessages([
                    'message' => 'The 24-hour customer service window is closed. An approved WhatsApp template is required.',
                ]);
            }

            $connection = WhatsAppConnection::query()
                ->where('status', 'active')
                ->first();

            if (! $connection) {
                throw ValidationException::withMessages([
                    'connection' => 'No verified active WhatsApp connection is configured for this business.',
                ]);
            }

            $message = Message::query()->create([
                'conversation_id' => $lockedConversation->id,
                'reply_to_message_id' => $replyToMessageId,
                'reminder_id' => $reminderId,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => $body,
                'delivery_status' => 'pending',
                'metadata' => [
                    'whatsapp_connection_id' => $connection->id,
                    'actor' => $actor->value,
                    'staff_user_id' => $staffUserId,
                ],
            ]);

            DeliverWhatsAppMessage::dispatch(app(TenantContext::class)->id(), $message->id)
                ->afterCommit();

            return $message;
        });
    }

    public function sendTemplate(Conversation $conversation, WhatsAppTemplate $template, array $parameters, ?string $reminderId = null): Message
    {
        if ($template->status !== 'APPROVED') {
            throw ValidationException::withMessages(['template' => 'Only Meta-approved WhatsApp templates can be sent.']);
        }

        $body = collect($template->components ?? [])->first(
            fn ($component): bool => is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY',
        );
        $bodyText = is_string($body['text'] ?? null) ? $body['text'] : '';
        preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $bodyText, $allPlaceholders);
        foreach ($allPlaceholders[1] ?? [] as $placeholder) {
            if (! ctype_digit(trim($placeholder))) {
                throw ValidationException::withMessages(['template' => 'Only sequential numeric BODY placeholders are supported for this sender.']);
            }
        }
        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
        $indexes = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($indexes);

        foreach ($template->components ?? [] as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                continue;
            }

            if (preg_match('/\{\{\s*[^}]+\s*\}\}/', (string) ($component['text'] ?? ''))) {
                throw ValidationException::withMessages(['template' => 'This template uses unsupported non-body variables.']);
            }
        }

        $expectedIndexes = $indexes === [] ? [] : range(1, count($indexes));
        if ($indexes !== $expectedIndexes || count($parameters) !== count($indexes)) {
            throw ValidationException::withMessages([
                'parameters' => 'Provide exactly one value for each sequential BODY placeholder in the approved template.',
            ]);
        }

        $normalizedParameters = [];
        foreach (array_values($parameters) as $parameter) {
            if (! is_scalar($parameter) || trim((string) $parameter) === '' || mb_strlen((string) $parameter) > 1024) {
                throw ValidationException::withMessages(['parameters' => 'Template parameters must be non-empty text values up to 1024 characters.']);
            }
            $normalizedParameters[] = trim((string) $parameter);
        }

        return DB::transaction(function () use ($conversation, $template, $normalizedParameters, $reminderId): Message {
            if ($reminderId !== null) {
                $existing = Message::query()->where('reminder_id', $reminderId)->first();
                if ($existing) {
                    if ($existing->conversation_id !== $conversation->id) {
                        throw ValidationException::withMessages(['reminder' => 'This reminder was already sent to a different conversation.']);
                    }
                    return $existing;
                }

                // Keep lock ordering consistent with appointment lifecycle operations:
                // appointment first, then reminder. This avoids lock-order inversion
                // with cancellation/rescheduling transactions.
                $reminderSnapshot = AppointmentReminder::query()->findOrFail($reminderId);
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($reminderSnapshot->appointment_id);
                $reminder = AppointmentReminder::query()->lockForUpdate()->findOrFail($reminderId);
                if ($reminder->status !== 'processing' || $appointment->status !== 'confirmed'
                    || $appointment->customer_id !== $conversation->customer_id) {
                    throw ValidationException::withMessages(['reminder' => 'This appointment reminder is no longer eligible to send.']);
                }
            }

            $lockedConversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($lockedConversation->status !== 'open' || $lockedConversation->automation_state !== 'AUTO_ACTIVE') {
                throw ValidationException::withMessages(['conversation' => 'The conversation is closed or under human control.']);
            }

            $connection = $template->connection()->where('status', 'active')->first();
            if (! $connection) {
                throw ValidationException::withMessages(['connection' => 'The template is not attached to a verified active WhatsApp connection.']);
            }

            $customer = $lockedConversation->customer()->firstOrFail();
            $message = Message::query()->create([
                'conversation_id' => $lockedConversation->id,
                'reminder_id' => $reminderId,
                'direction' => 'outbound',
                'message_type' => 'template',
                'body' => 'WhatsApp template: '.$template->name,
                'delivery_status' => 'pending',
                'metadata' => [
                    'whatsapp_connection_id' => $connection->id,
                    'whatsapp_template_id' => $template->id,
                    'template_parameters' => $normalizedParameters,
                    'template_name' => $template->name,
                    'template_language' => $template->language,
                ],
            ]);

            DeliverWhatsAppMessage::dispatch(app(TenantContext::class)->id(), $message->id)
                ->afterCommit();

            return $message;
        });
    }


}
