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

            $reportedTokens = max(0, $promptTokens ?? 0) + max(0, $completionTokens ?? 0);
            if ($tenantId !== null && $outcome === 'succeeded' && $reportedTokens > 0) {
                $usageDate = now()->toDateString();
                DB::table('ai_usage_daily')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'usage_date' => $usageDate,
                    'requests' => 0,
                    'tokens' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('ai_usage_daily')
                    ->where('tenant_id', $tenantId)
                    ->where('usage_date', $usageDate)
                    ->increment('tokens', $reportedTokens, ['updated_at' => now()]);
            }
        } catch (Throwable $exception) {
            Log::warning('AI usage event could not be persisted.', [
                'provider' => $provider,
                'failure' => class_basename($exception),
            ]);
        }
    }
}
