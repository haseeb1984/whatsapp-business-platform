<?php

namespace App\Jobs;

use App\Exceptions\MetaWhatsAppApiException;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Meta\MetaWhatsAppGraphApi;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverWhatsAppMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A provider request is never automatically repeated. If the worker dies
     * after Meta accepted a request but before the response was persisted, the
     * stale "sending" record is reconciled to "unknown", not resent.
     */
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $messageId,
    ) {
    }

    public function handle(MetaWhatsAppGraphApi $graphApi): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($graphApi): void {
            $context = DB::transaction(function (): ?array {
                $message = Message::query()->lockForUpdate()->find($this->messageId);
                if (! $message || $message->direction !== 'outbound' || $message->delivery_status !== 'pending') {
                    return null;
                }

                $metadata = $message->metadata ?? [];
                $conversation = Conversation::query()->lockForUpdate()->find($message->conversation_id);
                if (! $conversation || $conversation->status !== 'open') {
                    $this->failBeforeSend($message, 'conversation_unavailable');
                    return null;
                }

                $reminder = null;
                if ($message->reminder_id !== null) {
                    $reminderSnapshot = AppointmentReminder::query()->find($message->reminder_id);
                    $appointment = $reminderSnapshot
                        ? Appointment::query()->lockForUpdate()->find($reminderSnapshot->appointment_id)
                        : null;
                    $reminder = AppointmentReminder::query()->lockForUpdate()->find($message->reminder_id);

                    if (! $appointment || ! $reminder || $appointment->status !== 'confirmed' || $reminder->status !== 'processing') {
                        $this->failBeforeSend($message, 'reminder_no_longer_eligible');
                        if ($reminder && $reminder->status === 'processing') {
                            $reminder->forceFill(['status' => 'cancelled', 'last_error' => 'reminder_no_longer_eligible'])->save();
                        }
                        return null;
                    }
                }

                $connectionId = $metadata['whatsapp_connection_id'] ?? null;
                $connection = is_string($connectionId)
                    ? WhatsAppConnection::query()->where('status', 'active')->find($connectionId)
                    : null;

                if (! $connection) {
                    $this->failBeforeSend($message, 'active_whatsapp_connection_unavailable');
                    if ($reminder) {
                        $reminder->forceFill(['status' => 'failed', 'last_error' => 'active_whatsapp_connection_unavailable'])->save();
                    }
                    return null;
                }

                $customer = $conversation->customer()->first();
                if (! $customer) {
                    $this->failBeforeSend($message, 'customer_unavailable');
                    if ($reminder) {
                        $reminder->forceFill(['status' => 'failed', 'last_error' => 'customer_unavailable'])->save();
                    }
                    return null;
                }

                if ($message->message_type === 'text') {
                    $actor = $metadata['actor'] ?? 'automation';
                    $allowedStates = $actor === 'staff'
                        ? ['HUMAN_ACTIVE', 'WAITING_FOR_STAFF']
                        : ['AUTO_ACTIVE'];

                    if (! in_array($conversation->automation_state, $allowedStates, true)
                        || ($actor === 'staff' && isset($metadata['staff_user_id'])
                            && $conversation->assigned_user_id !== (int) $metadata['staff_user_id'])) {
                        $this->failBeforeSend($message, 'conversation_sender_state_changed');
                        return null;
                    }

                    $lastInbound = Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('direction', 'inbound')
                        ->orderByDesc('sent_at')
                        ->orderByDesc('created_at')
                        ->first();

                    if (! $lastInbound?->sent_at || $lastInbound->sent_at->lt(now()->subHours(24))) {
                        $this->failBeforeSend($message, 'customer_service_window_closed');
                        return null;
                    }

                    if ($message->reply_to_message_id !== null) {
                        $latestInbound = Message::query()
                            ->where('conversation_id', $conversation->id)
                            ->where('direction', 'inbound')
                            ->orderByDesc('created_at')
                            ->first();

                        if ($latestInbound?->id !== $message->reply_to_message_id) {
                            $this->failBeforeSend($message, 'reply_is_no_longer_for_latest_inbound');
                            return null;
                        }
                    }
                } elseif ($message->message_type === 'template') {
                    if ($conversation->automation_state !== 'AUTO_ACTIVE') {
                        $this->failBeforeSend($message, 'conversation_under_human_control');
                        if ($reminder) {
                            $reminder->forceFill(['status' => 'blocked_handover', 'last_error' => 'conversation_under_human_control'])->save();
                        }
                        return null;
                    }
                } else {
                    $this->failBeforeSend($message, 'unsupported_outbound_message_type');
                    return null;
                }

                $message->forceFill(['delivery_status' => 'sending'])->save();

                return [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'recipient' => $customer->whatsapp_wa_id,
                    'body' => $message->body,
                    'message_type' => $message->message_type,
                    'metadata' => $metadata,
                    'connection' => $connection,
                ];
            });

            if (! $context) {
                return;
            }

            try {
                if ($context['message_type'] === 'text') {
                    $providerMessageId = $graphApi->sendText(
                        $context['connection'],
                        $context['recipient'],
                        $context['body'],
                    );
                } else {
                    $template = WhatsAppTemplate::query()
                        ->where('whatsapp_connection_id', $context['connection']->id)
                        ->whereKey($context['metadata']['whatsapp_template_id'] ?? '')
                        ->where('status', 'APPROVED')
                        ->first();

                    if (! $template) {
                        $this->complete('failed', null, null, 'approved_template_unavailable');
                        return;
                    }

                    $parameters = $context['metadata']['template_parameters'] ?? [];
                    $components = $parameters === [] ? [] : [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $value): array => ['type' => 'text', 'text' => $value],
                            $parameters,
                        ),
                    ]];

                    $providerMessageId = $graphApi->sendTemplate(
                        $context['connection'],
                        $context['recipient'],
                        $template->name,
                        $template->language,
                        $components,
                    );
                }

                $this->complete('sent', $providerMessageId, now());
            } catch (ConnectionException) {
                $this->complete('unknown', null, null, 'provider_connection_outcome_unknown');
            } catch (MetaWhatsAppApiException $exception) {
                $this->complete(
                    $exception->outcomeUnknown ? 'unknown' : 'failed',
                    null,
                    null,
                    $exception->outcomeUnknown ? 'provider_outcome_unknown' : 'provider_rejected_request',
                );
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        TenantContext::runForTenant($this->tenantId, function (): void {
            $this->complete('unknown', null, null, 'delivery_worker_terminated_during_provider_request');
        });
    }

    private function failBeforeSend(Message $message, string $reason): void
    {
        $message->forceFill([
            'delivery_status' => 'failed',
            'metadata' => array_merge($message->metadata ?? [], ['delivery_error' => $reason]),
        ])->save();
    }

    private function complete(string $status, ?string $providerMessageId, mixed $sentAt = null, ?string $error = null): void
    {
        DB::transaction(function () use ($status, $providerMessageId, $sentAt, $error): void {
            $message = Message::query()->lockForUpdate()->find($this->messageId);
            if (! $message || ! in_array($message->delivery_status, ['sending', 'unknown'], true)) {
                return;
            }

            $metadata = $message->metadata ?? [];
            $message->forceFill([
                'delivery_status' => $status,
                'provider_message_id' => $providerMessageId,
                'sent_at' => $sentAt,
                'metadata' => $error === null
                    ? $metadata
                    : array_merge($metadata, ['delivery_error' => $error]),
            ])->save();

            Conversation::query()->whereKey($message->conversation_id)->update(['last_message_at' => now()]);

            if ($message->reminder_id !== null) {
                $reminderStatus = match ($status) {
                    'sent' => 'sent',
                    'unknown' => 'unknown',
                    default => 'failed',
                };

                AppointmentReminder::query()
                    ->whereKey($message->reminder_id)
                    ->where('status', 'processing')
                    ->update([
                        'status' => $reminderStatus,
                        'last_error' => $error,
                        'provider_message_id' => $providerMessageId,
                        'sent_at' => $sentAt,
                    ]);
            }
        });
    }
}
