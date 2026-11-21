<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWebhookEventProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_text_event_creates_tenant_scoped_customer_conversation_and_encrypted_message(): void
    {
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
    }

    public function test_processing_the_same_provider_message_twice_does_not_duplicate_it(): void
    {
        $tenant = $this->tenant('processor-idempotent');
        $first = $this->inboundEvent($tenant->id, 'wamid.same-message');
        $second = $this->inboundEvent($tenant->id, 'wamid.same-message');

        (new ProcessMetaWebhookEvent($first->id))->handle();
        (new ProcessMetaWebhookEvent($second->id))->handle();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('webhook_events', ['id' => $second->id, 'status' => 'processed']);
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

    private function inboundEvent(string $tenantId, string $providerMessageId): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'tenant_id' => $tenantId,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', $providerMessageId.'-'.uniqid('', true)),
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
