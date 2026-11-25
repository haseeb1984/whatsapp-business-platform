<?php

namespace App\Services\AI;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GroqProvider implements AiProviderInterface
{
    public function generate(array $messages, array $options = []): string
    {
        $apiKey = config('services.groq.api_key');
        $model = config('services.groq.model');
        $baseUrl = rtrim((string) config('services.groq.base_url'), '/');

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($model) || $model === '' || $baseUrl === '') {
            throw new AiProviderException('Groq is not configured.', false);
        }

        if (Cache::get('groq:rate_limit_cooldown', false) === true) {
            app(AiUsageRecorder::class)->record('groq', $model, 'rate_limited_cooldown');
            Log::warning('Groq request skipped during rate-limit cooldown.', [
                'provider' => 'groq',
                'model' => $model,
                'failure' => 'rate_limit_cooldown',
                'retryable' => true,
            ]);
            throw new AiProviderException('Groq is temporarily rate-limited.', true);
        }

        if (! app(AiUsageQuota::class)->reserveRequest()) {
            app(AiUsageRecorder::class)->record('groq', $model, 'quota_exceeded');
            Log::warning('Groq request blocked by tenant daily quota.', [
                'provider' => 'groq',
                'model' => $model,
                'failure' => 'tenant_daily_quota',
            ]);
            throw new AiProviderException('The workspace AI daily request limit has been reached.', false);
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('services.groq.timeout', 15))
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $options['temperature'] ?? 0,
                    'max_tokens' => $options['max_tokens'] ?? 256,
                ]);
        } catch (ConnectionException) {
            app(AiUsageRecorder::class)->record(
                'groq',
                $model,
                'timeout',
                null,
                $this->durationMilliseconds($startedAt),
            );
            Log::warning('Groq request failed.', [
                'provider' => 'groq',
                'model' => $model,
                'failure' => 'connection',
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ]);
            throw new AiProviderException('Groq request timed out.', true);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;
            if ($status === 429) {
                $cooldownSeconds = max(0, min(3600, (int) config('services.groq.rate_limit_cooldown_seconds', 60)));
                if ($cooldownSeconds > 0) {
                    Cache::put('groq:rate_limit_cooldown', true, now()->addSeconds($cooldownSeconds));
                }
            }
            app(AiUsageRecorder::class)->record(
                'groq',
                $model,
                $status === 429 ? 'rate_limited' : 'provider_error',
                $status,
                $this->durationMilliseconds($startedAt),
            );
            Log::warning('Groq request failed.', [
                'provider' => 'groq',
                'model' => $model,
                'status' => $status,
                'retryable' => $retryable,
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ]);
            throw new AiProviderException(
                'Groq could not complete the request.',
                $retryable,
            );
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            app(AiUsageRecorder::class)->record(
                'groq',
                $model,
                'malformed_response',
                $response->status(),
                $this->durationMilliseconds($startedAt),
            );
            Log::warning('Groq response malformed.', [
                'provider' => 'groq',
                'model' => $model,
                'status' => $response->status(),
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ]);
            throw new AiProviderException('Groq returned an empty or malformed response.', false);
        }

        $usage = $response->json('usage', []);
        $tokenCount = static fn (mixed $value): ?int => is_numeric($value) ? max(0, (int) $value) : null;

        app(AiUsageRecorder::class)->record(
            'groq',
            $model,
            'succeeded',
            $response->status(),
            $this->durationMilliseconds($startedAt),
            $tokenCount(is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null),
            $tokenCount(is_array($usage) ? ($usage['completion_tokens'] ?? null) : null),
        );

        Log::info('Groq completion completed.', [
            'provider' => 'groq',
            'model' => $model,
            'status' => $response->status(),
            'duration_ms' => $this->durationMilliseconds($startedAt),
            'prompt_tokens' => $tokenCount(is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null),
            'completion_tokens' => $tokenCount(is_array($usage) ? ($usage['completion_tokens'] ?? null) : null),
            'total_tokens' => $tokenCount(is_array($usage) ? ($usage['total_tokens'] ?? null) : null),
        ]);

        return trim($content);
    }

    private function durationMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
