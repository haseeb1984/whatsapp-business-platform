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
            throw new AiProviderException('Groq request timed out.', true);
        }

        if (! $response->successful()) {
            $status = $response->status();
            throw new AiProviderException(
                'Groq could not complete the request.',
                $status === 429 || $status >= 500,
            );
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new AiProviderException('Groq returned an empty or malformed response.', false);
        }

        return trim($content);
    }
}
