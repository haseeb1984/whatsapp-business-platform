<?php

namespace App\Services\AI;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiUsageRecorder
{
    public function record(
        string $provider,
        string $model,
        string $outcome,
        ?int $httpStatus = null,
        ?int $durationMs = null,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
    ): void {
        $tenantId = app()->bound(TenantContext::class)
            ? app(TenantContext::class)->id()
            : null;

        try {
            DB::table('ai_usage_events')->insert([
                'tenant_id' => $tenantId,
                'provider' => mb_substr($provider, 0, 32),
                'model' => mb_substr($model, 0, 128),
                'outcome' => mb_substr($outcome, 0, 32),
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs === null ? null : max(0, $durationMs),
                'prompt_tokens' => $promptTokens === null ? null : max(0, $promptTokens),
                'completion_tokens' => $completionTokens === null ? null : max(0, $completionTokens),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('AI usage event could not be persisted.', [
                'provider' => $provider,
                'failure' => class_basename($exception),
            ]);
        }
    }
}
