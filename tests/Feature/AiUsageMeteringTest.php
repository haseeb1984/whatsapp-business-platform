<?php

namespace Tests\Feature;

use App\Exceptions\AiProviderException;
use App\Models\Tenant;
use App\Services\AI\GroqProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiUsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.groq.api_key' => 'test-groq-secret',
            'services.groq.model' => 'test-model',
            'services.groq.base_url' => 'https://api.groq.com/openai/v1',
            'services.groq.timeout' => 5,
            'services.groq.rate_limit_cooldown_seconds' => 60,
            'cache.default' => 'array',
        ]);
        Cache::flush();
    }

    public function test_successful_provider_usage_is_attributed_to_the_active_tenant_without_storing_content(): void
    {
        $tenant = $this->tenant('ai-meter-success');
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Approved answer']]],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20],
            ]),
        ]);

        TenantContext::runForTenant($tenant->id, fn () => app(GroqProvider::class)->generate([
            ['role' => 'user', 'content' => 'private customer message must never be stored'],
        ]));

        $this->assertDatabaseHas('ai_usage_events', [
            'tenant_id' => $tenant->id,
            'provider' => 'groq',
            'model' => 'test-model',
            'outcome' => 'succeeded',
            'http_status' => 200,
            'prompt_tokens' => 80,
            'completion_tokens' => 20,
        ]);

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('ai_usage_events');
        $this->assertNotContains('prompt', $columns);
        $this->assertNotContains('completion', $columns);
        $this->assertNotContains('customer_message', $columns);
        $this->assertDatabaseHas('ai_usage_daily', [
            'tenant_id' => $tenant->id,
            'usage_date' => now()->toDateString(),
            'requests' => 1,
            'tokens' => 100,
        ]);
    }

    public function test_rate_limited_provider_attempt_is_metered_and_cooldown_attempts_are_counted(): void
    {
        $tenant = $this->tenant('ai-meter-rate-limit');
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response(['error' => ['message' => 'private provider body']], 429),
        ]);

        TenantContext::runForTenant($tenant->id, function (): void {
            foreach ([1, 2] as $attempt) {
                try {
                    app(GroqProvider::class)->generate([
                        ['role' => 'user', 'content' => 'private question '.$attempt],
                    ]);
                    $this->fail('The rate-limited provider must not return a completion.');
                } catch (AiProviderException $exception) {
                    $this->assertTrue($exception->retryable);
                }
            }
        });

        $this->assertDatabaseHas('ai_usage_events', [
            'tenant_id' => $tenant->id,
            'outcome' => 'rate_limited',
            'http_status' => 429,
        ]);
        $this->assertDatabaseHas('ai_usage_events', [
            'tenant_id' => $tenant->id,
            'outcome' => 'rate_limited_cooldown',
        ]);
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('ai_usage_events')->where('tenant_id', $tenant->id)->count());
        Http::assertSentCount(1);
    }

    public function test_usage_events_are_isolated_between_tenants(): void
    {
        $tenantA = $this->tenant('ai-meter-tenant-a');
        $tenantB = $this->tenant('ai-meter-tenant-b');
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Answer']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        foreach ([$tenantA, $tenantB] as $tenant) {
            TenantContext::runForTenant($tenant->id, fn () => app(GroqProvider::class)->generate([
                ['role' => 'user', 'content' => 'hello'],
            ]));
        }

        $this->assertDatabaseCount('ai_usage_events', 2);
        $this->assertDatabaseHas('ai_usage_events', ['tenant_id' => $tenantA->id, 'prompt_tokens' => 10]);
        $this->assertDatabaseHas('ai_usage_events', ['tenant_id' => $tenantB->id, 'prompt_tokens' => 10]);
    }

    public function test_configured_daily_quota_blocks_provider_calls_after_the_tenant_limit(): void
    {
        config(['services.groq.daily_request_limit' => 2]);
        $tenant = $this->tenant('ai-meter-quota');
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Approved answer']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        TenantContext::runForTenant($tenant->id, function (): void {
            app(GroqProvider::class)->generate([['role' => 'user', 'content' => 'first']]);
            app(GroqProvider::class)->generate([['role' => 'user', 'content' => 'second']]);

            try {
                app(GroqProvider::class)->generate([['role' => 'user', 'content' => 'third']]);
                $this->fail('The third request must be blocked by the daily tenant quota.');
            } catch (AiProviderException $exception) {
                $this->assertFalse($exception->retryable);
                $this->assertStringContainsString('daily request limit', $exception->getMessage());
            }
        });

        $this->assertDatabaseHas('ai_usage_daily', [
            'tenant_id' => $tenant->id,
            'usage_date' => now()->toDateString(),
            'requests' => 2,
            'tokens' => 30,
        ]);
        $this->assertDatabaseHas('ai_usage_events', [
            'tenant_id' => $tenant->id,
            'outcome' => 'quota_exceeded',
        ]);
        Http::assertSentCount(2);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create(['name' => 'AI Usage Workspace', 'slug' => $slug]);
    }
}
