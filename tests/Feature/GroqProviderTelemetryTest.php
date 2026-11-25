<?php

namespace Tests\Feature;

use App\Exceptions\AiProviderException;
use App\Services\AI\GroqProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GroqProviderTelemetryTest extends TestCase
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

    public function test_success_logs_latency_and_token_counts_without_customer_content(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Approved answer']]],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 24,
                    'total_tokens' => 144,
                ],
            ]),
        ]);
        Log::spy();

        $answer = app(GroqProvider::class)->generate([
            ['role' => 'user', 'content' => 'private customer question must not be logged'],
        ]);

        $this->assertSame('Approved answer', $answer);
        Log::shouldHaveReceived('info')->once()->with(
            'Groq completion completed.',
            Mockery::on(function (array $context): bool {
                return ($context['provider'] ?? null) === 'groq'
                    && ($context['model'] ?? null) === 'test-model'
                    && ($context['status'] ?? null) === 200
                    && is_int($context['duration_ms'] ?? null)
                    && ($context['prompt_tokens'] ?? null) === 120
                    && ($context['completion_tokens'] ?? null) === 24
                    && ($context['total_tokens'] ?? null) === 144
                    && ! array_key_exists('messages', $context)
                    && ! array_key_exists('prompt', $context)
                    && ! array_key_exists('response', $context)
                    && ! array_key_exists('content', $context);
            }),
        );
    }

    public function test_rate_limit_logs_only_status_and_retryability(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'provider diagnostic must not be logged'],
            ], 429),
        ]);
        Log::spy();

        try {
            app(GroqProvider::class)->generate([
                ['role' => 'user', 'content' => 'another private customer question'],
            ]);
            $this->fail('A rate-limited provider response must raise an exception.');
        } catch (AiProviderException $exception) {
            $this->assertTrue($exception->retryable);
        }

        Log::shouldHaveReceived('warning')->once()->with(
            'Groq request failed.',
            Mockery::on(function (array $context): bool {
                return ($context['provider'] ?? null) === 'groq'
                    && ($context['model'] ?? null) === 'test-model'
                    && ($context['status'] ?? null) === 429
                    && ($context['retryable'] ?? null) === true
                    && is_int($context['duration_ms'] ?? null)
                    && ! array_key_exists('messages', $context)
                    && ! array_key_exists('error', $context)
                    && ! array_key_exists('response', $context);
            }),
        );
    }
    public function test_retry_after_header_extends_groq_cooldown_beyond_the_configured_default(): void
    {
        $frozen = \Illuminate\Support\Carbon::parse('2026-11-25 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($frozen);
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'provider rate limit'],
            ], 429, ['Retry-After' => '120']),
        ]);

        $provider = app(GroqProvider::class);
        try {
            try {
                $provider->generate([['role' => 'user', 'content' => 'private question']]);
                $this->fail('The first request must be rate limited.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->retryable);
            }

            \Illuminate\Support\Carbon::setTestNow($frozen->copy()->addSeconds(61));

            try {
                $provider->generate([['role' => 'user', 'content' => 'another private question']]);
                $this->fail('The provider Retry-After header must keep the cooldown active.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->retryable);
            }

            Http::assertSentCount(1);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_http_date_retry_after_header_is_respected(): void
    {
        $frozen = \Illuminate\Support\Carbon::parse('2026-11-25 12:00:00 UTC');
        \Illuminate\Support\Carbon::setTestNow($frozen);
        $retryAt = $frozen->copy()->addSeconds(120)->toRfc7231String();
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'provider rate limit'],
            ], 429, ['Retry-After' => $retryAt]),
        ]);

        $provider = app(GroqProvider::class);
        try {
            try {
                $provider->generate([['role' => 'user', 'content' => 'private question']]);
                $this->fail('The first request must be rate limited.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->retryable);
            }

            \Illuminate\Support\Carbon::setTestNow($frozen->copy()->addSeconds(61));

            try {
                $provider->generate([['role' => 'user', 'content' => 'another private question']]);
                $this->fail('The HTTP-date Retry-After value must keep the cooldown active.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->retryable);
            }

            Http::assertSentCount(1);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_rate_limit_cooldown_suppresses_repeated_groq_requests(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'provider rate limit'],
            ], 429),
        ]);

        $provider = app(GroqProvider::class);
        foreach ([1, 2] as $attempt) {
            try {
                $provider->generate([
                    ['role' => 'user', 'content' => 'customer question '.$attempt],
                ]);
                $this->fail('Groq must report a retryable rate-limit condition.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->retryable);
            }
        }

        Http::assertSentCount(1);
        $this->assertTrue(Cache::get('groq:rate_limit_cooldown'));
    }

}
