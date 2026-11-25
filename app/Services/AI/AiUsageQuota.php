<?php

namespace App\Services\AI;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiUsageQuota
{
    /** Atomically reserve a provider request and its estimated token ceiling. */
    public function reserveRequest(int $estimatedTokens = 0): bool
    {
        if (! app()->bound(TenantContext::class)) {
            return true;
        }

        $tenantId = app(TenantContext::class)->id();
        $requestLimit = max(0, (int) config('services.groq.daily_request_limit', 0));
        $tokenLimit = max(0, (int) config('services.groq.daily_token_limit', 0));
        $estimatedTokens = max(0, $estimatedTokens);
        $date = now()->toDateString();

        return DB::transaction(function () use ($tenantId, $requestLimit, $tokenLimit, $estimatedTokens, $date): bool {
            DB::table('ai_usage_daily')->insertOrIgnore([
                'tenant_id' => $tenantId, 'usage_date' => $date, 'requests' => 0,
                'tokens' => 0, 'reserved_tokens' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $usage = DB::table('ai_usage_daily')->where('tenant_id', $tenantId)
                ->where('usage_date', $date)->lockForUpdate()->first();

            if (! $usage) {
                throw new RuntimeException('Daily AI usage counter could not be reserved.');
            }
            if ($requestLimit > 0 && (int) $usage->requests >= $requestLimit) {
                return false;
            }

            $reserved = (int) ($usage->reserved_tokens ?? 0);
            if ($tokenLimit > 0 && ((int) $usage->tokens + $reserved + $estimatedTokens) > $tokenLimit) {
                return false;
            }

            DB::table('ai_usage_daily')->where('id', $usage->id)->update([
                'requests' => (int) $usage->requests + 1,
                'reserved_tokens' => $reserved + $estimatedTokens,
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    /**
     * Release the estimate. When usage metadata is absent after a successful
     * completion, charge the estimate conservatively instead of failing open.
     */
    public function finalizeTokenReservation(int $reservedTokens, int $fallbackCharge = 0): void
    {
        if (! app()->bound(TenantContext::class) || $reservedTokens <= 0) {
            return;
        }

        $tenantId = app(TenantContext::class)->id();
        $date = now()->toDateString();

        DB::transaction(function () use ($tenantId, $date, $reservedTokens, $fallbackCharge): void {
            $usage = DB::table('ai_usage_daily')->where('tenant_id', $tenantId)
                ->where('usage_date', $date)->lockForUpdate()->first();
            if (! $usage) {
                return;
            }

            DB::table('ai_usage_daily')->where('id', $usage->id)->update([
                'reserved_tokens' => max(0, (int) ($usage->reserved_tokens ?? 0) - $reservedTokens),
                'tokens' => (int) $usage->tokens + max(0, $fallbackCharge),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
