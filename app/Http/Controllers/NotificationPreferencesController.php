<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    private const DEFAULTS = [
        'email_conversation_assignments' => true,
        'email_handover_requests' => true,
    ];

    public function edit(Request $request): Response
    {
        return Inertia::render('Business/Notifications/Index', [
            'preferences' => $this->preferences((int) $request->user()->id),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email_conversation_assignments' => ['required', 'boolean'],
            'email_handover_requests' => ['required', 'boolean'],
        ]);

        $preferences = [
            'email_conversation_assignments' => filter_var($validated['email_conversation_assignments'], FILTER_VALIDATE_BOOLEAN),
            'email_handover_requests' => filter_var($validated['email_handover_requests'], FILTER_VALIDATE_BOOLEAN),
        ];

        DB::table('tenant_user')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->update([
                'notification_preferences' => json_encode($preferences, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        return back()->with('success', 'Notification preferences updated.');
    }

    /**
     * @return array<string, bool>
     */
    private function preferences(int $userId): array
    {
        $stored = DB::table('tenant_user')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('notification_preferences');

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (! is_array($stored)) {
            $stored = [];
        }

        return [
            'email_conversation_assignments' => (bool) ($stored['email_conversation_assignments'] ?? self::DEFAULTS['email_conversation_assignments']),
            'email_handover_requests' => (bool) ($stored['email_handover_requests'] ?? self::DEFAULTS['email_handover_requests']),
        ];
    }
}
