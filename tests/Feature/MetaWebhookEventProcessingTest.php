<?php

namespace Tests\Feature;

use App\Jobs\ProcessBookingConversationReply;
use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaWebhookEventProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_text_event_creates_tenant_scoped_customer_conversation_and_encrypted_message(): void
    {
        Queue::fake();
        $tenant = $this->tenant('processor-workspace');
        $event = $this->inboundEvent($tenant->id, 'wamid.processor-1');

        (new ProcessMetaWebhookEvent($event->id))->handle();

        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'processed', 'attempts' => 1]);
        TenantContext::runForTenant($tenant->id, function (): void {
            $customer = Customer::query()->firstOrFail();
            $this->assertSame('923001112233', $customer->whatsapp_wa_id);
            $this->assertSame('Ayesha', $customer->display_name);

            $conversation = Conversation::query()->firstOrFail();
            $this->assertSame($customer->id, $conversation->customer_id);
            $this->assertSame('AUTO_ACTIVE', $conversation->automation_state);
            $this->assertSame('open', $conversation->status);

            $message = Message::query()->firstOrFail();
            $this->assertSame('inbound', $message->direction);
            $this->assertSame('text', $message->message_type);
            $this->assertSame('wamid.processor-1', $message->provider_message_id);
            $this->assertSame('Hello from WhatsApp', $message->body);
            $this->assertSame('received', $message->delivery_status);
            $this->assertStringNotContainsString('Hello from WhatsApp', (string) $message->getRawOriginal('body'));
        });
        Queue::assertPushed(ProcessBookingConversationReply::class, 1);
    }

    public function test_webhook_job_uses_event_tenant_and_restores_preexisting_tenant_context(): void
    {
        Queue::fake();
        $ownerTenant = $this->tenant('processor-owner-context');
        $otherTenant = $this->tenant('processor-other-context');
        $event = $this->inboundEvent($ownerTenant->id, 'wamid.owner-context');

        TenantContext::runForTenant($otherTenant->id, function () use ($event, $ownerTenant, $otherTenant): void {
            (new ProcessMetaWebhookEvent($event->id))->handle();

            $this->assertSame($otherTenant->id, app(TenantContext::class)->id());
            $this->assertSame(0, Customer::query()->count());
        });

        $this->assertFalse(app()->bound(TenantContext::class));
        TenantContext::runForTenant($ownerTenant->id, function (): void {
            $this->assertSame(1, Customer::query()->count());
            $this->assertSame(1, Conversation::query()->count());
            $this->assertSame(1, Message::query()->count());
        });
        $this->assertDatabaseHas('webhook_events', [
            'id' => $event->id,
            'tenant_id' => $ownerTenant->id,
            'status' => 'processed',
        ]);
        Queue::assertPushed(ProcessBookingConversationReply::class, 1);
    }

    public function test_processing_the_same_provider_message_twice_does_not_duplicate_it(): void
    {
        Queue::fake();
        $tenant = $this->tenant('processor-idempotent');
        $first = $this->inboundEvent($tenant->id, 'wamid.same-message');
        $second = $this->inboundEvent($tenant->id, 'wamid.same-message');

        (new ProcessMetaWebhookEvent($first->id))->handle();
        (new ProcessMetaWebhookEvent($second->id))->handle();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('webhook_events', ['id' => $second->id, 'status' => 'processed']);
        Queue::assertPushed(ProcessBookingConversationReply::class, 1);
    }

    public function test_message_status_event_updates_existing_message_delivery_state(): void
    {
        $tenant = $this->tenant('processor-status');
        $message = TenantContext::runForTenant($tenant->id, function (): Message {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create(['customer_id' => $customer->id]);
            return Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Outbound',
                'provider_message_id' => 'wamid.outbound-1',
                'delivery_status' => 'sent',
            ]);
        });

        $event = WebhookEvent::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', 'status-event'),
            'phone_number_id' => '123456789012345',
            'event_type' => 'message.status',
            'payload' => ['id' => 'wamid.outbound-1', 'status' => 'delivered'],
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        (new ProcessMetaWebhookEvent($event->id))->handle();

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'delivery_status' => 'delivered']);
        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'processed']);
    }


    public function test_status_event_retries_if_the_outbound_message_has_not_committed_yet(): void
    {
        $tenant = $this->tenant('processor-status-race');
        $event = WebhookEvent::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', 'early-status-event'),
            'event_type' => 'message.status',
            'payload' => ['id' => 'wamid.early-status', 'status' => 'delivered'],
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        try {
            (new ProcessMetaWebhookEvent($event->id))->handle();
            $this->fail('A status without its outbound message must be retried.');
        } catch (\UnexpectedValueException) {
            $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'pending', 'attempts' => 1]);
        }

        TenantContext::runForTenant($tenant->id, function (): void {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923009998877']);
            $conversation = Conversation::query()->create(['customer_id' => $customer->id]);
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Hello',
                'provider_message_id' => 'wamid.early-status',
                'delivery_status' => 'sent',
            ]);
        });

        (new ProcessMetaWebhookEvent($event->id))->handle();
        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'wamid.early-status',
            'delivery_status' => 'delivered',
        ]);
        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'processed']);
    }

    public function test_event_without_a_tenant_is_not_processed(): void
    {
        $event = WebhookEvent::query()->create([
            'tenant_id' => null,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', 'unmatched-event'),
            'event_type' => 'message.received',
            'payload' => ['from' => '923001112233', 'id' => 'wamid.unmatched'],
            'status' => 'unmatched',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        (new ProcessMetaWebhookEvent($event->id))->handle();

        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'unmatched']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_out_of_order_meta_status_events_never_downgrade_delivery_progress(): void
    {
        $tenant = $this->tenant('processor-monotonic-status');
        $message = TenantContext::runForTenant($tenant->id, function (): Message {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create(['customer_id' => $customer->id]);

            return Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Hello',
                'provider_message_id' => 'wamid.monotonic-status',
                'delivery_status' => 'sent',
            ]);
        });

        foreach (['read', 'delivered', 'sent', 'failed'] as $index => $status) {
            $event = WebhookEvent::query()->create([
                'tenant_id' => $tenant->id,
                'provider' => 'meta_whatsapp',
                'provider_event_key' => hash('sha256', 'monotonic-status-'.$index),
                'event_type' => 'message.status',
                'payload' => ['id' => 'wamid.monotonic-status', 'status' => $status],
                'status' => 'pending',
                'attempts' => 0,
                'received_at' => now(),
            ]);

            (new ProcessMetaWebhookEvent($event->id))->handle();
        }

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'delivery_status' => 'read']);
    }

    public function test_failed_delivery_is_not_replaced_by_a_late_sent_event(): void
    {
        $tenant = $this->tenant('processor-failed-terminal');
        $message = TenantContext::runForTenant($tenant->id, function (): Message {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create(['customer_id' => $customer->id]);

            return Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Hello',
                'provider_message_id' => 'wamid.failed-terminal',
                'delivery_status' => 'pending',
            ]);
        });

        foreach (['failed', 'sent'] as $index => $status) {
            $event = WebhookEvent::query()->create([
                'tenant_id' => $tenant->id,
                'provider' => 'meta_whatsapp',
                'provider_event_key' => hash('sha256', 'failed-terminal-'.$index),
                'event_type' => 'message.status',
                'payload' => ['id' => 'wamid.failed-terminal', 'status' => $status],
                'status' => 'pending',
                'attempts' => 0,
                'received_at' => now(),
            ]);

            (new ProcessMetaWebhookEvent($event->id))->handle();
        }

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'delivery_status' => 'failed']);
    }

    public function test_late_failed_queue_callback_does_not_overwrite_a_processed_event(): void
    {
        Queue::fake();
        $tenant = $this->tenant('processor-late-failed-callback');
        $event = $this->inboundEvent($tenant->id, 'wamid.processed-before-failed-callback');

        $job = new ProcessMetaWebhookEvent($event->id);
        $job->handle();
        $job->failed(new \RuntimeException('Late queue callback after successful commit'));

        $this->assertDatabaseHas('webhook_events', [
            'id' => $event->id,
            'status' => 'processed',
        ]);
        TenantContext::runForTenant($tenant->id, function (): void {
            $this->assertDatabaseCount('messages', 1);
        });
    }

    private function inboundEvent(string $tenantId, string $providerMessageId): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'tenant_id' => $tenantId,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', $providerMessageId.'-'.WebhookEvent::query()->count()),
            'phone_number_id' => '123456789012345',
            'event_type' => 'message.received',
            'payload' => [
                'from' => '923001112233',
                'id' => $providerMessageId,
                'timestamp' => '1795280000',
                'type' => 'text',
                'text' => ['body' => 'Hello from WhatsApp'],
                '_contact_name' => 'Ayesha',
            ],
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
        ]);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Processor Workspace',
            'slug' => $slug,
        ]);
    }
}
