<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended'],
        ]);
        $whatsappCounts = DB::table('whatsapp_connections')
            ->select('tenant_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'active')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $calendarCounts = DB::table('google_calendar_connections')
            ->select('tenant_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'connected')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');
        $whatsappStatusCounts = DB::table('whatsapp_connections')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $calendarStatusCounts = DB::table('google_calendar_connections')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $calendarExpiredTokens = DB::table('google_calendar_connections')
            ->where('status', 'connected')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now())
            ->count();
        $calendarMissingRefreshTokens = DB::table('google_calendar_connections')
            ->where('status', 'connected')
            ->where(function ($query): void {
                $query->whereNull('refresh_token')->orWhere('refresh_token', '');
            })
            ->count();

        $messageCounts = DB::table('messages')->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');
        $outboundDeliveryCounts = DB::table('messages')
            ->select('delivery_status', DB::raw('COUNT(*) as total'))
            ->where('direction', 'outbound')
            ->groupBy('delivery_status')
            ->pluck('total', 'delivery_status');
        $conversationCounts = DB::table('conversations')->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');
        $appointmentCounts = DB::table('appointments')->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');

        $aiUsageAvailable = Schema::hasTable('ai_usage_events');
        $aiUsageStart = now()->subDays(30);
        $aiUsageByTenant = $aiUsageAvailable
            ? DB::table('ai_usage_events')->select('tenant_id', DB::raw('COUNT(*) as total'))
                ->where('created_at', '>=', $aiUsageStart)->groupBy('tenant_id')->pluck('total', 'tenant_id')
            : collect();
        $aiTokensByTenant = $aiUsageAvailable
            ? DB::table('ai_usage_events')->select('tenant_id', DB::raw('SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) as total'))
                ->where('created_at', '>=', $aiUsageStart)->groupBy('tenant_id')->pluck('total', 'tenant_id')
            : collect();
        $aiAttemptsLast30Days = $aiUsageAvailable
            ? DB::table('ai_usage_events')->where('created_at', '>=', $aiUsageStart)->count()
            : null;
        $aiTokensLast30Days = $aiUsageAvailable
            ? (int) DB::table('ai_usage_events')->where('created_at', '>=', $aiUsageStart)
                ->selectRaw('COALESCE(SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)), 0) as total')->value('total')
            : null;
        $aiFailuresLast24Hours = $aiUsageAvailable
            ? DB::table('ai_usage_events')->where('created_at', '>=', now()->subDay())
                ->whereNotIn('outcome', ['succeeded'])->count()
            : null;
        $aiRateLimitsLast24Hours = $aiUsageAvailable
            ? DB::table('ai_usage_events')->where('created_at', '>=', now()->subDay())
                ->whereIn('outcome', ['rate_limited', 'rate_limited_cooldown'])->count()
            : null;
        $aiQuotaBlocksLast24Hours = $aiUsageAvailable
            ? DB::table('ai_usage_events')->where('created_at', '>=', now()->subDay())
                ->where('outcome', 'quota_exceeded')->count()
            : null;
        $aiDailyAvailable = Schema::hasTable('ai_usage_daily');
        $aiRequestsToday = $aiDailyAvailable
            ? (int) DB::table('ai_usage_daily')->where('usage_date', now()->toDateString())->sum('requests')
            : null;
        $aiTokensToday = $aiDailyAvailable
            ? (int) DB::table('ai_usage_daily')->where('usage_date', now()->toDateString())->sum('tokens')
            : null;
        $aiProviderHealth = $aiUsageAvailable
            ? DB::table('ai_usage_events')
                ->where('created_at', '>=', now()->subDays(7))
                ->whereIn('outcome', ['succeeded', 'rate_limited', 'provider_error', 'timeout', 'malformed_response'])
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as successes', ['succeeded'])
                ->selectRaw('AVG(duration_ms) as avg_latency_ms')
                ->first()
            : null;
        $aiProviderAttempts7d = $aiProviderHealth ? (int) $aiProviderHealth->attempts : null;
        $aiProviderSuccessRate7d = $aiProviderAttempts7d > 0
            ? round(((int) $aiProviderHealth->successes / $aiProviderAttempts7d) * 100, 1)
            : null;
        $aiProviderAvgLatency7d = $aiProviderHealth?->avg_latency_ms === null
            ? null
            : round((float) $aiProviderHealth->avg_latency_ms, 1);

        $tenantQuery = Tenant::query()
            ->withCount('users')
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(function ($search) use ($term): void {
                $search->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.mb_strtolower($term).'%']);
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at');

        $tenants = $tenantQuery->paginate(20)->withQueryString();
        $tenants->through(fn (Tenant $tenant): array => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'timezone' => $tenant->timezone,
            'users' => $tenant->users_count,
            'whatsapp_connections' => (int) ($whatsappCounts[$tenant->id] ?? 0),
            'calendar_connections' => (int) ($calendarCounts[$tenant->id] ?? 0),
            'messages' => (int) ($messageCounts[$tenant->id] ?? 0),
            'conversations' => (int) ($conversationCounts[$tenant->id] ?? 0),
            'appointments' => (int) ($appointmentCounts[$tenant->id] ?? 0),
            'ai_attempts_30d' => (int) ($aiUsageByTenant[$tenant->id] ?? 0),
            'ai_tokens_30d' => (int) ($aiTokensByTenant[$tenant->id] ?? 0),
            'created_at' => $tenant->created_at?->toDateTimeString(),
        ]);

        $queuePending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $queueOldestCreatedAt = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
        $queueOldestAgeMinutes = is_numeric($queueOldestCreatedAt)
            ? max(0, (int) floor((now()->timestamp - (int) $queueOldestCreatedAt) / 60))
            : null;
        $queueFailed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $queueFailedLast24Hours = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
            : null;
        $recentFailures = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(8)->get(['id', 'queue', 'failed_at'])->map(fn ($job): array => [
                'id' => (string) $job->id,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
            ])->all()
            : [];
        $recentAuditEvents = Schema::hasTable('audit_events')
            ? DB::table('audit_events')
                ->leftJoin('tenants', 'tenants.id', '=', 'audit_events.tenant_id')
                ->leftJoin('users', 'users.id', '=', 'audit_events.actor_user_id')
                ->orderByDesc('audit_events.created_at')
                ->limit(10)
                ->get(['audit_events.id', 'audit_events.event', 'audit_events.subject_type', 'audit_events.subject_id', 'audit_events.ip_address', 'audit_events.created_at', 'tenants.name as tenant_name', 'users.email as actor_email'])
                ->map(fn ($event): array => [
                    'id' => (string) $event->id,
                    'event' => $event->event,
                    'subject' => $event->subject_type && $event->subject_id ? $event->subject_type.' #'.$event->subject_id : null,
                    'tenant' => $event->tenant_name ?: 'Platform-level',
                    'actor' => $event->actor_email ?: 'System',
                    'ip_address' => $event->ip_address,
                    'created_at' => $event->created_at,
                ])->all()
            : [];

        $alertConfig = config('services.groq.alerts', []);
        $minimumProviderAttempts = max(1, (int) ($alertConfig['provider_min_attempts'] ?? 10));
        $successRateThreshold = max(0, min(100, (float) ($alertConfig['provider_success_rate_percent'] ?? 90)));
        $latencyThreshold = max(1, (int) ($alertConfig['provider_avg_latency_ms'] ?? 3000));
        $rateLimitThreshold = max(1, (int) ($alertConfig['rate_limit_count'] ?? 5));
        $queueAgeThreshold = max(1, (int) ($alertConfig['queue_oldest_age_minutes'] ?? 10));
        $failedJobsThreshold = max(1, (int) ($alertConfig['failed_jobs_24h'] ?? 5));
        $operationalAlerts = [];

        if ($aiProviderAttempts7d !== null && $aiProviderAttempts7d >= $minimumProviderAttempts) {
            if ($aiProviderSuccessRate7d !== null && $aiProviderSuccessRate7d < $successRateThreshold) {
                $operationalAlerts[] = [
                    'code' => 'ai_provider_success_rate_low',
                    'severity' => 'warning',
                    'title' => 'AI provider success rate is below threshold',
                    'detail' => $aiProviderSuccessRate7d.'% over '.$aiProviderAttempts7d.' attempts; threshold '.$successRateThreshold.'%.',
                ];
            }

            if ($aiProviderAvgLatency7d !== null && $aiProviderAvgLatency7d >= $latencyThreshold) {
                $operationalAlerts[] = [
                    'code' => 'ai_provider_latency_high',
                    'severity' => 'warning',
                    'title' => 'AI provider latency is elevated',
                    'detail' => $aiProviderAvgLatency7d.' ms average over seven days; threshold '.$latencyThreshold.' ms.',
                ];
            }
        }

        if ($aiRateLimitsLast24Hours !== null && $aiRateLimitsLast24Hours >= $rateLimitThreshold) {
            $operationalAlerts[] = [
                'code' => 'ai_rate_limits_high',
                'severity' => 'warning',
                'title' => 'AI provider rate limits are elevated',
                'detail' => $aiRateLimitsLast24Hours.' rate-limited requests in the last 24 hours; threshold '.$rateLimitThreshold.'.',
            ];
        }

        if ($queueOldestAgeMinutes !== null && $queueOldestAgeMinutes >= $queueAgeThreshold) {
            $operationalAlerts[] = [
                'code' => 'queue_backlog_old',
                'severity' => 'critical',
                'title' => 'Old queued job backlog detected',
                'detail' => 'Oldest queued job is '.$queueOldestAgeMinutes.' minutes old; threshold '.$queueAgeThreshold.' minutes.',
            ];
        }

        if ($queueFailedLast24Hours !== null && $queueFailedLast24Hours >= $failedJobsThreshold) {
            $operationalAlerts[] = [
                'code' => 'failed_jobs_high',
                'severity' => 'warning',
                'title' => 'Recent failed jobs exceed threshold',
                'detail' => $queueFailedLast24Hours.' failed jobs in the last 24 hours; threshold '.$failedJobsThreshold.'.',
            ];
        }

        return Inertia::render('Platform/Dashboard', [
            'metrics' => [
                'tenants_total' => Tenant::query()->count(),
                'tenants_active' => Tenant::query()->where('status', 'active')->count(),
                'tenants_suspended' => Tenant::query()->where('status', 'suspended')->count(),
                'users_total' => User::query()->count(),
                'whatsapp_connections' => (int) $whatsappCounts->sum(),
                'whatsapp_pending' => (int) ($whatsappStatusCounts['pending'] ?? 0),
                'whatsapp_inactive' => (int) collect($whatsappStatusCounts)->except('active')->sum(),
                'calendar_connections' => (int) $calendarCounts->sum(),
                'calendar_reauthorization_required' => (int) ($calendarStatusCounts['reauthorization_required'] ?? 0),
                'calendar_expired_tokens' => (int) $calendarExpiredTokens,
                'calendar_missing_refresh_tokens' => (int) $calendarMissingRefreshTokens,
                'messages_total' => DB::table('messages')->count(),
                'outbound_pending' => (int) ($outboundDeliveryCounts['pending'] ?? 0),
                'outbound_sending' => (int) ($outboundDeliveryCounts['sending'] ?? 0),
                'outbound_unknown' => (int) ($outboundDeliveryCounts['unknown'] ?? 0),
                'outbound_failed' => (int) ($outboundDeliveryCounts['failed'] ?? 0),
                'conversations_total' => DB::table('conversations')->count(),
                'appointments_total' => DB::table('appointments')->count(),
                'ai_attempts_last_30_days' => $aiAttemptsLast30Days,
                'ai_tokens_last_30_days' => $aiTokensLast30Days,
                'ai_failures_last_24_hours' => $aiFailuresLast24Hours,
                'ai_rate_limits_last_24_hours' => $aiRateLimitsLast24Hours,
                'ai_quota_blocks_last_24_hours' => $aiQuotaBlocksLast24Hours,
                'ai_requests_today' => $aiRequestsToday,
                'ai_tokens_today' => $aiTokensToday,
                'ai_provider_attempts_7d' => $aiProviderAttempts7d,
                'ai_provider_success_rate_7d' => $aiProviderSuccessRate7d,
                'ai_provider_avg_latency_ms_7d' => $aiProviderAvgLatency7d,
                'audit_events_total' => Schema::hasTable('audit_events') ? DB::table('audit_events')->count() : null,
                'queue_pending' => $queuePending,
                'queue_oldest_age_minutes' => $queueOldestAgeMinutes,
                'queue_failed' => $queueFailed,
                'queue_failed_last_24_hours' => $queueFailedLast24Hours,
            ],
            'tenants' => $tenants,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'alerts' => $operationalAlerts,
            'recent_failures' => $recentFailures,
            'recent_audit_events' => $recentAuditEvents,
        ]);
    }

    public function exportTenants(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended'],
        ]);

        $query = Tenant::query()
            ->withCount('users')
            ->when($filters['q'] ?? null, fn ($builder, $term) => $builder->where(function ($search) use ($term): void {
                $search->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.mb_strtolower($term).'%']);
            }))
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->orderBy('name');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Tenant ID', 'Name', 'Slug', 'Status', 'Timezone', 'Users', 'Created At']);
            $query->chunk(200, function ($tenants) use ($output): void {
                foreach ($tenants as $tenant) {
                    fputcsv($output, [
                        $tenant->id,
                        $this->safeCsvCell($tenant->name),
                        $this->safeCsvCell($tenant->slug),
                        $this->safeCsvCell($tenant->status),
                        $this->safeCsvCell($tenant->timezone),
                        $tenant->users_count,
                        $tenant->created_at?->toDateTimeString(),
                    ]);
                }
            });
            fclose($output);
        }, 'platform-tenants-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }


    private function safeCsvCell(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\t\r ]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

}
