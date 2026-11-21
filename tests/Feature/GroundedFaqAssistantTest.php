<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Tenant;
use App\Services\AI\GroundedFaqAssistant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroundedFaqAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_published_faq_returns_the_approved_answer_without_calling_groq(): void
    {
        $tenant = $this->tenant('faq-ai-exact');
        $faq = $this->faq($tenant->id, 'What time do you open?', 'We open at 9 AM.');

        $result = TenantContext::runForTenant($tenant->id, fn () => app(GroundedFaqAssistant::class)->answer(
            'What time do you open?',
            'en',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertSame('We open at 9 AM.', $result->answer);
        $this->assertSame([$faq->id], $result->faqIds);
        $this->assertSame('exact_faq', $result->source);
        Http::assertNothingSent();
    }

    public function test_unpublished_and_other_tenant_faqs_are_never_retrieved(): void
    {
        $tenantA = $this->tenant('faq-ai-tenant-a');
        $tenantB = $this->tenant('faq-ai-tenant-b');
        $this->faq($tenantA->id, 'What are your prices?', 'Our service starts at 1000.', 'draft');
        $this->faq($tenantB->id, 'What are your prices?', 'Tenant B price is 500.', 'published');

        $result = TenantContext::runForTenant($tenantA->id, fn () => app(GroundedFaqAssistant::class)->answer(
            'What are your prices?',
            'en',
        ));

        $this->assertSame('handoff', $result->status);
        $this->assertNull($result->answer);
        Http::assertNothingSent();
    }

    public function test_groq_selects_only_retrieved_faq_ids_and_backend_returns_exact_approved_answers(): void
    {
        config([
            'services.groq.api_key' => 'test-groq-key',
            'services.groq.model' => 'test-model',
            'services.groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $tenant = $this->tenant('faq-ai-groq');
        $this->faq($tenant->id, 'What services do you offer?', 'We offer salon services.');
        $selected = $this->faq($tenant->id, 'Do you provide hair coloring?', 'Hair coloring is available by appointment.');

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'decision' => 'answer',
                            'faq_ids' => [$selected->id],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => app(GroundedFaqAssistant::class)->answer(
            'Do you offer hair coloring services?',
            'en',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertSame('Hair coloring is available by appointment.', $result->answer);
        $this->assertSame([$selected->id], $result->faqIds);
        $this->assertSame('groq_faq_selection', $result->source);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-groq-key')
            && $request['model'] === 'test-model');
    }

    public function test_groq_cannot_inject_an_unretrieved_faq_id_into_the_answer(): void
    {
        config([
            'services.groq.api_key' => 'test-groq-key',
            'services.groq.model' => 'test-model',
            'services.groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $tenant = $this->tenant('faq-ai-invalid-id');
        $this->faq($tenant->id, 'Do you provide hair coloring?', 'Hair coloring is available.');

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"decision":"answer","faq_ids":["not-a-retrieved-faq"]}',
                    ],
                ]],
            ], 200),
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => app(GroundedFaqAssistant::class)->answer(
            'Hair coloring service appointment',
            'en',
        ));

        $this->assertSame('handoff', $result->status);
        $this->assertNull($result->answer);
    }

    public function test_rate_limit_uses_only_a_high_confidence_deterministic_faq_fallback(): void
    {
        config([
            'services.groq.api_key' => 'test-groq-key',
            'services.groq.model' => 'test-model',
            'services.groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $tenant = $this->tenant('faq-ai-rate-limit');
        $faq = $this->faq($tenant->id, 'Hair coloring service', 'Hair coloring is available by appointment.');

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => app(GroundedFaqAssistant::class)->answer(
            'Hair coloring service salon',
            'en',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertSame('Hair coloring is available by appointment.', $result->answer);
        $this->assertSame([$faq->id], $result->faqIds);
        $this->assertSame('deterministic_fallback', $result->source);
    }

    public function test_explicit_groq_handoff_is_not_overridden_by_a_weak_keyword_match(): void
    {
        config([
            'services.groq.api_key' => 'test-groq-key',
            'services.groq.model' => 'test-model',
            'services.groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $tenant = $this->tenant('faq-ai-handoff');
        $this->faq($tenant->id, 'Hair coloring service', 'Hair coloring is available by appointment.');

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"decision":"handoff","faq_ids":[]}'],
                ]],
            ], 200),
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => app(GroundedFaqAssistant::class)->answer(
            'Hair coloring service appointment',
            'en',
        ));

        $this->assertSame('handoff', $result->status);
        $this->assertNull($result->answer);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create(['name' => 'FAQ AI Workspace', 'slug' => $slug]);
    }

    private function faq(string $tenantId, string $question, string $answer, string $status = 'published'): Faq
    {
        return TenantContext::runForTenant($tenantId, fn () => Faq::query()->create([
            'question' => $question,
            'answer' => $answer,
            'locale' => 'en',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]));
    }
}
