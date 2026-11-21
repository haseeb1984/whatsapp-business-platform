<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Models\WhatsAppConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_get_verification_returns_the_challenge_for_the_configured_token(): void
    {
        config(['services.meta.webhook_verify_token' => 'test-verify-token']);

        $this->get('/api/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSeeText('challenge-123');
    }

    public function test_meta_get_verification_rejects_an_invalid_token(): void
    {
        config(['services.meta.webhook_verify_token' => 'test-verify-token']);

        $this->get('/api/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123')
            ->assertForbidden();
    }

    public function test_signed_message_event_is_encrypted_routed_and_persisted_idempotently(): void
    {
        config(['services.meta.app_secret' => 'test-meta-app-secret']);
        $tenant = Tenant::query()->create([
            'name' => 'Webhook Workspace',
            'slug' => 'webhook-workspace',
        ]);

        TenantContext::runForTenant($tenant->id, function (): void {
            WhatsAppConnection::query()->create([
                'phone_number_id' => '123456789012345',
                'waba_id' => '987654321098765',
                'access_token' => 'long-lived-access-token',
                'status' => 'active',
            ]);
        });

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '987654321098765',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '123456789012345'],
                        'messages' => [[
                            'from' => '923001112233',
                            'id' => 'wamid.test-message-1',
                            'timestamp' => '1795280000',
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'test-meta-app-secret');

        $this->call('POST', '/api/webhooks/meta/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw)->assertOk();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta_whatsapp',
            'event_type' => 'message.received',
            'status' => 'pending',
        ]);

        $event = WebhookEvent::query()->firstOrFail();
        $this->assertSame('Hello', $event->payload['text']['body']);
        $this->assertStringNotContainsString('Hello', (string) $event->getRawOriginal('payload'));

        $this->call('POST', '/api/webhooks/meta/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw)->assertOk();

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_invalid_signature_is_rejected_before_event_persistence(): void
    {
        config(['services.meta.app_secret' => 'test-meta-app-secret']);
        $raw = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/webhooks/meta/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        ], $raw)->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_events_from_unknown_phone_numbers_are_quarantined_as_unmatched(): void
    {
        config(['services.meta.app_secret' => 'test-meta-app-secret']);
        $raw = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '999999999999999'],
                        'messages' => [['id' => 'wamid.unknown', 'type' => 'text']],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'test-meta-app-secret');

        $this->call('POST', '/api/webhooks/meta/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw)->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'tenant_id' => null,
            'phone_number_id' => '999999999999999',
            'status' => 'unmatched',
        ]);
    }
}
