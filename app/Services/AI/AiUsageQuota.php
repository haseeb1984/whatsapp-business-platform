<?php

namespace App\Services\AI;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiUsageQuota
{
    /**
     * Atomically reserve one provider request for the active tenant.
     *
     * A zero limit disables enforcement but still records daily request counts.
     */
    public function reserveRequest(): bool
    {
        if (! app()->bound(TenantContext::class)) {
            return true;
        }

        $tenantId = app(TenantContext::class)->id();
        $limit = max(0, (int) config('services.groq.daily_request_limit', 0));
        $date = now()->toDateString();

        return DB::transaction(function () use ($tenantId, $limit, $date): bool {
            DB::table('ai_usage_daily')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'usage_date' => $date,
                'requests' => 0,
                'tokens' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = DB::table('ai_usage_daily')
                ->where('tenant_id', $tenantId)
                ->where('usage_date', $date)
                ->lockForUpdate()
                ->first();

            if (! $usage) {
                throw new RuntimeException('Daily AI usage counter could not be reserved.');
            }

            if ($limit > 0 && (int) $usage->requests >= $limit) {
                return false;
            }

            DB::table('ai_usage_daily')
                ->where('id', $usage->id)
                ->update([
                    'requests' => (int) $usage->requests + 1,
                    'updated_at' => now(),
                ]);

            return true;
        }, 3);
    }
}
