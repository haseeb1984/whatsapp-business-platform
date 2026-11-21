<?php

namespace App\Services\Messaging;

use App\Enums\MessageActor;
use App\Exceptions\MetaWhatsAppApiException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\Meta\MetaWhatsAppGraphApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppMessageService
{
    public function __construct(private readonly MetaWhatsAppGraphApi $graphApi)
    {
    }

    public function sendText(Conversation $conversation, string $body, MessageActor $actor = MessageActor::Automation, ?string $replyToMessageId = null, ?int $staffUserId = null): Message
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4096) {
            throw ValidationException::withMessages([
                'message' => 'Text messages must contain between 1 and 4096 characters.',
            ]);
        }

        return DB::transaction(function () use ($conversation, $body, $actor, $replyToMessageId, $staffUserId): Message {
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

            $customer = $lockedConversation->customer()->firstOrFail();
            $message = Message::query()->create([
                'conversation_id' => $lockedConversation->id,
                'reply_to_message_id' => $replyToMessageId,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => $body,
                'delivery_status' => 'pending',
            ]);

            try {
                $providerMessageId = $this->graphApi->sendText(
                    $connection,
                    $customer->whatsapp_wa_id,
                    $body,
                );
            } catch (ConnectionException) {
                $message->forceFill(['delivery_status' => 'unknown'])->save();
                $lockedConversation->forceFill(['last_message_at' => now()])->save();

                return $message;
            } catch (MetaWhatsAppApiException $exception) {
                $message->forceFill([
                    'delivery_status' => $exception->outcomeUnknown ? 'unknown' : 'failed',
                ])->save();

                $lockedConversation->forceFill(['last_message_at' => now()])->save();

                return $message;
            }

            $message->forceFill([
                'provider_message_id' => $providerMessageId,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ])->save();

            $lockedConversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });
    }
}
