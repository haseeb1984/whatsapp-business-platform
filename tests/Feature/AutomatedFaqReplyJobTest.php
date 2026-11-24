<?php

namespace Tests\Feature;

use App\Contracts\AiProviderInterface;
use App\Jobs\GenerateAutomatedFaqReply;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Faq;
use App\Models\Message;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AI\BusinessIntentRouter;
use App\Services\AI\BusinessToolAssistant;
use App\Services\AI\GroundedFaqAssistant;
use App\Services\Messaging\WhatsAppMessageService;
use App\Notifications\ConversationHandoverNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class AutomatedFaqReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_approved_faq_is_sent_as_a_correlated_automated_reply(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.automated-faq-1']],
            ], 200),
        ]);

        [$tenant, $conversation, $inbound] = $this->conversation('auto-faq-sent', 'AUTO_ACTIVE', 'What is your refund policy?');
        TenantContext::runForTenant($tenant->id, fn () => Faq::query()->create([
            'question' => 'What is your refund policy?',
            'answer' => 'Refunds are reviewed by our team within five business days.',
            'locale' => 'en',
            'status' => 'published',
            'published_at' => now(),
        ]));

        (new GenerateAutomatedFaqReply($tenant->id, $conversation->id, $inbound->id))->handle(
            app(GroundedFaqAssistant::class),
            app(BusinessIntentRouter::class),
            app(WhatsAppMessageService::class),
        );

        TenantContext::runForTenant($tenant->id, function () use ($inbound): void {
            $reply = Message::query()->where('direction', 'outbound')->firstOrFail();
            $this->assertSame($inbound->id, $reply->reply_to_message_id);
            $this->assertSame('Refunds are reviewed by our team within five business days.', $reply->body);
            $this->assertSame('sent', $reply->delivery_status);
            $this->assertSame('wamid.automated-faq-1', $reply->provider_message_id);
        });

        Http::assertSentCount(1);
    }

    public function test_missing_approved_faq_moves_conversation_to_waiting_for_staff_without_guessing(): void
    {
        Http::fake();
        [$tenant, $conversation, $inbound] = $this->conversation('auto-faq-handoff', 'AUTO_ACTIVE', 'What are your opening hours?');
        $admin = User::query()->create(['name' => 'Workspace Admin', 'email' => 'auto-faq-admin@example.test', 'password' => 'test-password']);
        $tenant->users()->attach($admin->id, ['role' => 'business_admin', 'status' => 'active']);
        Notification::fake();

        (new GenerateAutomatedFaqReply($tenant->id, $conversation->id, $inbound->id))->handle(
            app(GroundedFaqAssistant::class),
            app(BusinessIntentRouter::class),
            app(WhatsAppMessageService::class),
        );

        TenantContext::runForTenant($tenant->id, function () use ($conversation): void {
            $this->assertSame('WAITING_FOR_STAFF', Conversation::query()->findOrFail($conversation->id)->automation_state);
            $this->assertDatabaseCount('messages', 1);
        });
        Http::assertNothingSent();
        Notification::assertSentTo($admin, ConversationHandoverNotification::class);
    }

    public function test_queued_faq_job_does_nothing_after_staff_has_taken_over(): void
    {
        Http::fake();
        [$tenant, $conversation, $inbound] = $this->conversation('auto-faq-human-active', 'HUMAN_ACTIVE', 'What are your opening hours?');
        TenantContext::runForTenant($tenant->id, fn () => Faq::query()->create([
            'question' => 'What are your opening hours?',
            'answer' => 'We are open from 9 AM to 6 PM.',
            'locale' => 'en',
            'status' => 'published',
            'published_at' => now(),
        ]));

        (new GenerateAutomatedFaqReply($tenant->id, $conversation->id, $inbound->id))->handle(
            app(GroundedFaqAssistant::class),
            app(BusinessIntentRouter::class),
            app(WhatsAppMessageService::class),
        );

        TenantContext::runForTenant($tenant->id, function () use ($conversation): void {
            $this->assertSame('HUMAN_ACTIVE', Conversation::query()->findOrFail($conversation->id)->automation_state);
            $this->assertDatabaseCount('messages', 1);
        });
        Http::assertNothingSent();
    }

    public function test_reprocessing_same_faq_job_does_not_send_a_second_whatsapp_message(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.automated-faq-once']],
            ], 200),
        ]);

        [$tenant, $conversation, $inbound] = $this->conversation('auto-faq-idempotent', 'AUTO_ACTIVE', 'What is your refund policy?');
        TenantContext::runForTenant($tenant->id, fn () => Faq::query()->create([
            'question' => 'What is your refund policy?',
            'answer' => 'Refunds are reviewed by our team within five business days.',
            'locale' => 'en',
            'status' => 'published',
            'published_at' => now(),
        ]));

        $job = new GenerateAutomatedFaqReply($tenant->id, $conversation->id, $inbound->id);
        $job->handle(app(GroundedFaqAssistant::class), app(BusinessIntentRouter::class), app(WhatsAppMessageService::class));
        $job->handle(app(GroundedFaqAssistant::class), app(BusinessIntentRouter::class), app(WhatsAppMessageService::class));

        TenantContext::runForTenant($tenant->id, function () use ($inbound): void {
            $this->assertDatabaseCount('messages', 2);
            $this->assertDatabaseHas('messages', [
                'reply_to_message_id' => $inbound->id,
                'provider_message_id' => 'wamid.automated-faq-once',
            ]);
        });
        Http::assertSentCount(1);
    }

    public function test_structured_service_pricing_is_answered_from_tenant_data_before_faq_ai(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.business-fact-1']],
            ], 200),
        ]);

        [$tenant, $conversation, $inbound] = $this->conversation('auto-business-fact', 'AUTO_ACTIVE', 'How much does a consultation cost?');
        TenantContext::runForTenant($tenant->id, fn () => Service::query()->create([
            'name' => 'Consultation',
            'price_amount' => '1500.00',
            'currency' => 'PKR',
            'duration_minutes' => 30,
            'active' => true,
        ]));

        $assistant = Mockery::mock(GroundedFaqAssistant::class);
        $assistant->shouldNotReceive('answer');

        (new GenerateAutomatedFaqReply($tenant->id, $conversation->id, $inbound->id))->handle(
            $assistant,
            app(BusinessIntentRouter::class),
            app(WhatsAppMessageService::class),
        );

        TenantContext::runForTenant($tenant->id, function () use ($inbound): void {
            $reply = Message::query()->where('direction', 'outbound')->firstOrFail();
            $this->assertSame($inbound->id, $reply->reply_to_message_id);
            $this->assertSame('sent', $reply->delivery_status);
            $this->assertStringContainsString('Consultation — 1500.00 PKR (30 minutes)', $reply->body);
        });
        Http::assertSentCount(1);
    }

    public function test_queued_faq_job_cannot_read_another_tenants_conversation(): void
    {
        Http::fake();
        [$ownerTenant, $conversation, $inbound] = $this->conversation('auto-faq-owner-tenant', 'AUTO_ACTIVE', 'What are your opening hours?');
        $otherTenant = Tenant::query()->create([
            'name' => 'Different Workspace',
            'slug' => 'auto-faq-other-tenant',
        ]);

        (new GenerateAutomatedFaqReply($otherTenant->id, $conversation->id, $inbound->id))->handle(
            app(GroundedFaqAssistant::class),
            app(BusinessIntentRouter::class),
            app(WhatsAppMessageService::class),
        );

        $this->assertDatabaseCount('messages', 1);
        Http::assertNothingSent();
        $this->assertFalse(app()->bound(TenantContext::class), 'A completed background job must not leak tenant context.');
    }


    public function test_ai_selected_read_only_service_tool_can_answer_a_question_missed_by_deterministic_intents(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.business-tool-reply']],
            ], 200),
        ]);

        [$tenant, $conversation, $inbound] = $this->conversation('auto-business-tool', 'AUTO_ACTIVE', 'Do you have balayage?');
        TenantContext::runForTenant($tenant->id, fn () => Service::query()->create([
            'name' => 'Balayage',
            'price_amount' => '4500.00',
            'currency' => 'PKR',
            'duration_minutes' => 120,
            'active' => true,
        ]));

        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('generate')->once()->andReturn(json_encode([
            'decision' => 'tool',
            'tool' => 'search_services',
            'arguments' => ['query' => 'balayage'],
        ], JSON_THROW_ON_ERROR));
        app()->instance(AiProviderInterface::class, $provider);

        (new GenerateAutomatedFaqReply($tenant->id, $conversation->id, $inbound->id))->handle(
            app(GroundedFaqAssistant::class),
            app(BusinessIntentRouter::class),
            app(WhatsAppMessageService::class),
            app(BusinessToolAssistant::class),
        );

        TenantContext::runForTenant($tenant->id, function () use ($inbound): void {
            $reply = Message::query()->where('direction', 'outbound')->firstOrFail();
            $this->assertSame($inbound->id, $reply->reply_to_message_id);
            $this->assertStringContainsString('Balayage — 4500.00 PKR (120 minutes)', $reply->body);
            $this->assertSame('sent', $reply->delivery_status);
        });
        Http::assertSentCount(1);
    }

    private function conversation(string $slug, string $state, string $body): array
    {
        $tenant = Tenant::query()->create(['name' => 'Automated FAQ Workspace', 'slug' => $slug]);

        return TenantContext::runForTenant($tenant->id, function () use ($tenant, $state, $body): array {
            WhatsAppConnection::query()->create([
                'phone_number_id' => '123456789012345',
                'waba_id' => '987654321098765',
                'access_token' => 'test-long-lived-access-token',
                'status' => 'active',
                'verified_at' => now(),
            ]);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => $state,
            ]);
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => $body,
                'provider_message_id' => 'wamid.inbound-'.str_replace('-', '', $tenant->id),
                'delivery_status' => 'received',
                'sent_at' => now(),
            ]);

            return [$tenant, $conversation, $inbound];
        });
    }
}
