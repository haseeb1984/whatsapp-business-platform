<?php

namespace App\Services\AI;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
