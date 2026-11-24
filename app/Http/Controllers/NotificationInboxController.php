<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NotificationInboxController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $this->forCurrentTenant($request)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($notification): array => [
                'id' => (string) $notification->id,
                'title' => (string) ($notification->data['title'] ?? 'Workspace update'),
                'message' => (string) ($notification->data['message'] ?? ''),
                'conversation_id' => isset($notification->data['conversation_id'])
                    ? (string) $notification->data['conversation_id']
                    : null,
                'created_at' => $notification->created_at?->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Business/Notifications/Inbox', [
            'notifications' => $notifications,
            'unread_count' => $this->forCurrentTenant($request)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, string $notificationId): RedirectResponse
    {
        $notification = $this->forCurrentTenant($request)->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->forCurrentTenant($request)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', 'Notifications marked as read.');
    }

    private function forCurrentTenant(Request $request)
    {
        $tenantId = app(TenantContext::class)->id();
        $driver = DB::connection()->getDriverName();
        $predicate = match ($driver) {
            'pgsql' => "(data::jsonb ->> 'tenant_id') = ?",
            'mysql' => "JSON_UNQUOTE(JSON_EXTRACT(data, '$.tenant_id')) = ?",
            default => "json_extract(data, '$.tenant_id') = ?",
        };

        return $request->user()->notifications()->whereRaw($predicate, [$tenantId]);
    }
}
