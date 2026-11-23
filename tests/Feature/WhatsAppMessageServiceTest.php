<?php

namespace Tests\Feature;

use App\Enums\MessageActor;
use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppMessageService;
use App\Services\Meta\MetaWhatsAppGraphApi;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_can_send_text_inside_the_customer_service_window(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Queue::fake();
        Http::fake(function () {
            // RefreshDatabase keeps one outer test transaction open. The
            // sender's own transaction must already be committed before HTTP.
            $this->assertSame(1, DB::transactionLevel());

            return Http::response([
                'messages' => [['id' => 'wamid.outbound-1']],
            ], 200);
        });

        $tenant = $this->workspace('send-text-success');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subHour());

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText($conversation, 'Thanks for contacting us.'));

        $this->assertSame('pending', $message->delivery_status);
        Queue::assertPushed(DeliverWhatsAppMessage::class, fn (DeliverWhatsAppMessage $job): bool => $job->tenantId === $tenant->id && $job->messageId === $message->id);
        $deliveryJob = new DeliverWhatsAppMessage($tenant->id, $message->id);
        $deliveryJob->handle(app(MetaWhatsAppGraphApi::class));
        $deliveryJob->handle(app(MetaWhatsAppGraphApi::class));
        $message->refresh();
        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('wamid.outbound-1', $message->provider_message_id);
        Http::assertSentCount(1);
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
        Queue::fake();
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

        $this->assertSame('pending', $message->delivery_status);
        TenantContext::runForTenant($tenant->id, function () use ($conversation): void {
            $conversation->forceFill([
                'automation_state' => 'AUTO_ACTIVE',
                'assigned_user_id' => null,
            ])->save();
        });
        (new DeliverWhatsAppMessage($tenant->id, $message->id))->handle(app(MetaWhatsAppGraphApi::class));
        $message->refresh();
        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('wamid.staff-reply-1', $message->provider_message_id);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_queued_automation_reply_is_cancelled_if_staff_takes_over_before_delivery(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Queue::fake();
        Http::fake();

        $tenant = $this->workspace('send-text-handover-before-delivery');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subMinutes(5));

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)
            ->sendText($conversation, 'Automated reply queued'));

        TenantContext::runForTenant($tenant->id, function () use ($conversation): void {
            $conversation->forceFill(['automation_state' => 'HUMAN_ACTIVE'])->save();
        });

        (new DeliverWhatsAppMessage($tenant->id, $message->id))->handle(app(MetaWhatsAppGraphApi::class));

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'delivery_status' => 'failed',
        ]);
        Http::assertNothingSent();
    }

    public function test_ambiguous_meta_timeout_is_recorded_without_automatic_resend(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Queue::fake();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $tenant = $this->workspace('send-text-timeout');
        $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subMinutes(5));

        $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)->sendText($conversation, 'May have been sent'));

        $this->assertSame('pending', $message->delivery_status);
        (new DeliverWhatsAppMessage($tenant->id, $message->id))->handle(app(MetaWhatsAppGraphApi::class));
        $message->refresh();
        $this->assertSame('unknown', $message->delivery_status);
        $this->assertNull($message->provider_message_id);
        $this->assertDatabaseCount('messages', 2);
    }


    public function test_provider_rejections_and_ambiguous_responses_keep_safe_delivery_states(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Queue::fake();

        $sequence = Http::sequence()
            ->push(['error' => ['message' => 'Invalid parameter']], 400)
            ->push(['error' => ['message' => 'Rate limited']], 429)
            ->push(['error' => ['message' => 'Temporary provider failure']], 503)
            ->push(['messages' => []], 200);
        Http::fake(['https://graph.facebook.com/v24.0/*' => $sequence]);

        $cases = [
            ['provider-400', 'failed'],
            ['provider-429', 'unknown'],
            ['provider-503', 'unknown'],
            ['provider-missing-id', 'unknown'],
        ];

        foreach ($cases as [$slug, $expectedStatus]) {
            $tenant = $this->workspace($slug);
            $conversation = $this->conversation($tenant->id, 'AUTO_ACTIVE', now()->subMinutes(5));
            $message = TenantContext::runForTenant($tenant->id, fn () => app(WhatsAppMessageService::class)
                ->sendText($conversation, 'Delivery classification check'));

            (new DeliverWhatsAppMessage($tenant->id, $message->id))->handle(app(MetaWhatsAppGraphApi::class));

            $this->assertDatabaseHas('messages', [
                'id' => $message->id,
                'delivery_status' => $expectedStatus,
            ]);
        }

        Http::assertSentCount(4);
    }

    public function test_approved_template_can_be_sent_with_exact_body_parameter_mapping(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Queue::fake();
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
        $this->assertSame('pending', $message->delivery_status);
        (new DeliverWhatsAppMessage($tenant->id, $message->id))->handle(app(MetaWhatsAppGraphApi::class));
        $message->refresh();
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

        TenantContext::runForTenant($tenant->id, fn () => $template->forceFill(['status' => 'APPROVED'])->save());
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
