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

        $messageCounts = DB::table('messages')->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');
        $conversationCounts = DB::table('conversations')->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');
        $appointmentCounts = DB::table('appointments')->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');

        $tenantQuery = Tenant::query()
            ->withCount('users')
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(function ($search) use ($term): void {
                $search->where('name', 'like', '%'.$term.'%')
                    ->orWhere('slug', 'like', '%'.$term.'%');
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
            'created_at' => $tenant->created_at?->toDateTimeString(),
        ]);

        $queuePending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $queueFailed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $recentFailures = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(8)->get(['id', 'queue', 'failed_at'])->map(fn ($job): array => [
                'id' => (string) $job->id,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
            ])->all()
            : [];

        return Inertia::render('Platform/Dashboard', [
            'metrics' => [
                'tenants_total' => Tenant::query()->count(),
                'tenants_active' => Tenant::query()->where('status', 'active')->count(),
                'tenants_suspended' => Tenant::query()->where('status', 'suspended')->count(),
                'users_total' => User::query()->count(),
                'whatsapp_connections' => (int) $whatsappCounts->sum(),
                'calendar_connections' => (int) $calendarCounts->sum(),
                'messages_total' => DB::table('messages')->count(),
                'conversations_total' => DB::table('conversations')->count(),
                'appointments_total' => DB::table('appointments')->count(),
                'queue_pending' => $queuePending,
                'queue_failed' => $queueFailed,
            ],
            'tenants' => $tenants,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'recent_failures' => $recentFailures,
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
                $search->where('name', 'like', '%'.$term.'%')
                    ->orWhere('slug', 'like', '%'.$term.'%');
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
                        $tenant->name,
                        $tenant->slug,
                        $tenant->status,
                        $tenant->timezone,
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

}
