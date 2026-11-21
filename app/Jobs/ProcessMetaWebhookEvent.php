<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMetaWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $eventId)
    {
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(): void
    {
        $event = WebhookEvent::query()->find($this->eventId);
        if (! $event || $event->status !== 'pending' || ! $event->tenant_id) {
            return;
        }

        $event->forceFill([
            'status' => 'processing',
            'attempts' => $event->attempts + 1,
            'error_summary' => null,
        ])->save();

        try {
            TenantContext::runForTenant($event->tenant_id, function () use ($event): void {
                if ($event->event_type === 'message.received') {
                    $this->processInboundMessage($event->payload);
                } elseif ($event->event_type === 'message.status') {
                    $this->processMessageStatus($event->payload);
                }
            });

            $event->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'error_summary' => null,
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'pending',
                'error_summary' => 'Webhook event processing failed; the job will retry.',
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $event = WebhookEvent::query()->find($this->eventId);
        if (! $event) {
            return;
        }

        $event->forceFill([
            'status' => 'failed',
            'error_summary' => 'Webhook event processing exhausted its retry attempts.',
        ])->save();
    }

    private function processInboundMessage(array $payload): void
    {
        $waId = $payload['from'] ?? null;
        $providerMessageId = $payload['id'] ?? null;

        if (! is_string($waId) || $waId === '' || ! is_string($providerMessageId) || $providerMessageId === '') {
            throw new \UnexpectedValueException('Inbound Meta message is missing its sender or provider ID.');
        }

        $customer = Customer::query()->firstOrNew(['whatsapp_wa_id' => $waId]);
        $contactName = $payload['_contact_name'] ?? null;
        if (is_string($contactName) && $contactName !== '') {
            $customer->display_name = mb_substr($contactName, 0, 160);
        }
        $customer->save();

        $timestamp = $payload['timestamp'] ?? null;
        $sentAt = is_string($timestamp) && ctype_digit($timestamp)
            ? CarbonImmutable::createFromTimestampUTC((int) $timestamp)
            : now();

        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'open')
            ->orderByDesc('last_message_at')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
                'last_message_at' => $sentAt,
            ]);
        }

        $messageType = is_string($payload['type'] ?? null) ? mb_substr($payload['type'], 0, 32) : 'unknown';
        $body = $messageType === 'text' && is_string(data_get($payload, 'text.body'))
            ? data_get($payload, 'text.body')
            : null;

        Message::query()->firstOrCreate(
            ['provider_message_id' => $providerMessageId],
            [
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => $messageType,
                'body' => $body,
                'delivery_status' => 'received',
                'metadata' => $payload,
                'sent_at' => $sentAt,
            ],
        );

        $conversation->forceFill(['last_message_at' => $sentAt])->save();
    }

    private function processMessageStatus(array $payload): void
    {
        $providerMessageId = $payload['id'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_string($providerMessageId) || ! is_string($status)) {
            return;
        }

        if (! in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $message = Message::query()->where('provider_message_id', $providerMessageId)->first();
        if ($message) {
            $message->forceFill(['delivery_status' => $status])->save();
        }
    }
}
