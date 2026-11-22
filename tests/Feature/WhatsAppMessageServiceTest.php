<?php

namespace Tests\Feature;

use App\Enums\MessageActor;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_can_send_text_inside_the_customer_service_window(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.outbound-1']],
            ], 200),
        ]);

        $tenant = $this->workspace('send-text-success');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subHour());

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText($conversation, 'Thanks for contacting us.'));

        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('wamid.outbound-1', $message->provider_message_id);
        $this->assertSame('Thanks for contacting us.', $message->body);
        $this->assertSame('outbound', $message->direction);
        $this->assertStringNotContainsString('Thanks for contacting us.', (string) $message->getRawOriginal('body'));

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v24.0/123456789012345/messages'
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '923001112233'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Thanks for contacting us.');
    }

    public function test_free_form_text_is_blocked_after_the_24_hour_window(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake();

        $tenant = $this->workspace('send-text-expired');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subHours(25));

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText($conversation, 'Late reply'));
            $this->fail('An expired customer service window must require an approved template.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('message', $exception->errors());
        }

        $this->assertDatabaseCount('messages', 1);
        Http::assertNothingSent();
    }

    public function test_automation_cannot_send_after_human_handover_but_staff_can_reply(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.staff-reply-1']],
            ], 200),
        ]);

        $tenant = $this->workspace('send-text-handover');
        $conversation = $this->conversation($tenant->id, 'HUMAN_ACTIVE', now()->subMinutes(30));

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText($conversation, 'AI reply'));
            $this->fail('Automation must be blocked during human handover.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conversation', $exception->errors());
        }

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText(
            $conversation,
            'Staff reply',
            MessageActor::Staff,
        ));

        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('wamid.staff-reply-1', $message->provider_message_id);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_ambiguous_meta_timeout_is_recorded_without_automatic_resend(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $tenant = $this->workspace('send-text-timeout');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subMinutes(5));

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText($conversation, 'May have been sent'));

        $this->assertSame('unknown', $message->delivery_status);
        $this->assertNull($message->provider_message_id);
        $this->assertDatabaseCount('messages', 2);
    }


    public function test_approved_template_can_be_sent_with_exact_body_parameter_mapping(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.template-1']],
            ], 200),
        ]);

        $tenant = $this->workspace('send-template-success');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subDays(3));
        $template = TenantContext::runForTenant($tenant->id, function () {
            $connection = WhatsAppConnection::query()->firstOrFail();
            return WhatsAppTemplate::query()->create([
                'whatsapp_connection_id' => $connection->id,
                'provider_template_id' => 'meta-template-1',
                'name' => 'appointment_reminder',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, your {{2}} appointment is coming up.']],
                'synced_at' => now(),
            ]);
        });

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)
            ->sendTemplate($conversation, $template, ['Amina', 'Consultation']));

        $this->assertSame('template', $message->message_type);
        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('wamid.template-1', $message->provider_message_id);
        $this->assertSame('appointment_reminder', $message->metadata['template_name']);

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'appointment_reminder'
            && $request['template']['language']['code'] === 'en_US'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Amina'
            && $request['template']['components'][0]['parameters'][1]['text'] === 'Consultation');
    }

    public function test_template_send_rejects_unapproved_templates_and_incorrect_parameter_count(): void
    {
        Http::fake();
        $tenant = $this->workspace('send-template-invalid');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subDays(3));
        $template = TenantContext::runForTenant($tenant->id, function () {
            $connection = WhatsAppConnection::query()->firstOrFail();
            return WhatsAppTemplate::query()->create([
                'whatsapp_connection_id' => $connection->id,
                'provider_template_id' => 'meta-template-2',
                'name' => 'appointment_reminder',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'status' => 'PENDING',
                'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, your {{2}} appointment is coming up.']],
                'synced_at' => now(),
            ]);
        });

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)
                ->sendTemplate($conversation, $template, ['Amina', 'Consultation']));
            $this->fail('Unapproved templates must not be sent.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('template', $exception->errors());
        }

        $template->forceFill(['status' => 'APPROVED'])->save();
        try {
            TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)
                ->sendTemplate($conversation, $template, ['Amina']));
            $this->fail('Every body placeholder must have a parameter.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parameters', $exception->errors());
        }

        Http::assertNothingSent();
    }

    private function conversation(string $tenantId, string $automationState, $inboundAt): Conversation
    {
        return TenantContext::runForTenant($tenantId, function () use ($automationState, $inboundAt): Conversation {
            WhatsAppConnection::query()->create([
                'phone_number_id' => '123456789012345',
                'waba_id' => '987654321098765',
                'access_token' => 'outbound-long-lived-access-token',
                'status' => 'active',
            ]);

            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => $automationState,
                'last_message_at' => $inboundAt,
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'Hello',
                'provider_message_id' => 'wamid.inbound-'.str_replace('.', '-', (string) $inboundAt->timestamp),
                'delivery_status' => 'received',
                'sent_at' => $inboundAt,
            ]);

            return $conversation;
        });
    }

    private function workspace(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Outbound Workspace',
            'slug' => $slug,
        ]);
    }
}
