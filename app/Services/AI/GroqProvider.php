<?php

namespace App\Services\AI;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;

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
                'provider' => 'groq', 'model' => $model,
                'failure' => 'rate_limit_cooldown', 'retryable' => true,
            ]);
            throw new AiProviderException('Groq is temporarily rate-limited.', true);
        }

        $maxTokens = max(1, min(8192, (int) ($options['max_tokens'] ?? 256)));
        $reservedTokens = $this->estimateTokenBudget($messages, $maxTokens);
        if (! app(AiUsageQuota::class)->reserveRequest($reservedTokens)) {
            app(AiUsageRecorder::class)->record('groq', $model, 'quota_exceeded');
            Log::warning('Groq request blocked by tenant daily quota.', [
                'provider' => 'groq', 'model' => $model, 'failure' => 'tenant_daily_quota',
            ]);
            throw new AiProviderException('The workspace AI daily request limit or token budget has been reached.', false);
        }

        $reservationFinalized = false;
        $startedAt = hrtime(true);

        try {
            try {
                $response = Http::withToken($apiKey)->acceptJson()
                    ->timeout((int) config('services.groq.timeout', 15))
                    ->post($baseUrl.'/chat/completions', [
                        'model' => $model, 'messages' => $messages,
                        'temperature' => $options['temperature'] ?? 0, 'max_tokens' => $maxTokens,
                    ]);
            } catch (ConnectionException) {
                app(AiUsageRecorder::class)->record('groq', $model, 'timeout', null, $this->durationMilliseconds($startedAt));
                Log::warning('Groq request failed.', [
                    'provider' => 'groq', 'model' => $model, 'failure' => 'connection',
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                ]);
                throw new AiProviderException('Groq request timed out.', true);
            }

            if (! $response->successful()) {
                $status = $response->status();
                $retryable = $status === 429 || $status >= 500;
                if ($status === 429) {
                    $cooldownSeconds = $this->rateLimitCooldownSeconds($response);
                    if ($cooldownSeconds > 0) {
                        Cache::put('groq:rate_limit_cooldown', true, now()->addSeconds($cooldownSeconds));
                    }
                }
                app(AiUsageRecorder::class)->record(
                    'groq', $model, $status === 429 ? 'rate_limited' : 'provider_error',
                    $status, $this->durationMilliseconds($startedAt),
                );
                Log::warning('Groq request failed.', [
                    'provider' => 'groq', 'model' => $model, 'status' => $status,
                    'retryable' => $retryable, 'duration_ms' => $this->durationMilliseconds($startedAt),
                ]);
                throw new AiProviderException('Groq could not complete the request.', $retryable);
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                app(AiUsageRecorder::class)->record(
                    'groq', $model, 'malformed_response', $response->status(),
                    $this->durationMilliseconds($startedAt),
                );
                Log::warning('Groq response malformed.', [
                    'provider' => 'groq', 'model' => $model, 'status' => $response->status(),
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                ]);
                throw new AiProviderException('Groq returned an empty or malformed response.', false);
            }

            $usage = $response->json('usage', []);
            $tokenCount = static fn (mixed $value): ?int => is_numeric($value) ? max(0, (int) $value) : null;
            $promptTokens = $tokenCount(is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null);
            $completionTokens = $tokenCount(is_array($usage) ? ($usage['completion_tokens'] ?? null) : null);
            $usageReported = $promptTokens !== null || $completionTokens !== null;

            app(AiUsageRecorder::class)->record(
                'groq', $model, 'succeeded', $response->status(),
                $this->durationMilliseconds($startedAt), $promptTokens, $completionTokens,
            );
            app(AiUsageQuota::class)->finalizeTokenReservation(
                $reservedTokens,
                $usageReported ? max(0, ($promptTokens ?? 0) + ($completionTokens ?? 0)) : null,
            );
            $reservationFinalized = true;

            Log::info('Groq completion completed.', [
                'provider' => 'groq', 'model' => $model, 'status' => $response->status(),
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens,
                'total_tokens' => $tokenCount(is_array($usage) ? ($usage['total_tokens'] ?? null) : null),
            ]);

            return trim($content);
        } finally {
            if (! $reservationFinalized) {
                app(AiUsageQuota::class)->finalizeTokenReservation($reservedTokens);
            }
        }
    }

    private function estimateTokenBudget(array $messages, int $maxTokens): int
    {
        $serialized = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $promptEstimate = $serialized === false ? 0 : (int) ceil(strlen($serialized) / 4);

        return min(1_000_000, max(0, $promptEstimate) + $maxTokens);
    }

    private function rateLimitCooldownSeconds(\Illuminate\Http\Client\Response $response): int
    {
        $retryAfter = trim((string) $response->header('Retry-After'));
        if ($retryAfter !== '') {
            $seconds = null;
            if (ctype_digit($retryAfter)) {
                $seconds = (int) $retryAfter;
            } else {
                try {
                    $seconds = CarbonImmutable::parse($retryAfter)->timestamp - now()->timestamp;
                } catch (\Throwable) {
                    $seconds = null;
                }
            }

            if ($seconds !== null) {
                return max(1, min(3600, $seconds));
            }
        }

        return max(0, min(3600, (int) config('services.groq.rate_limit_cooldown_seconds', 60)));
    }

    private function durationMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
