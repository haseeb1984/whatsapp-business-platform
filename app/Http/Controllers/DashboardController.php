<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\GoogleCalendarConnection;
use App\Models\Service;
use App\Models\Staff;
use App\Models\WhatsAppConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $timezone = $tenant->timezone ?: 'UTC';

        $upcomingAppointments = Appointment::query()
            ->with(['customer', 'staff'])
            ->where('status', 'confirmed')
            ->where('calendar_sync_status', 'synced')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(6)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'customer' => $appointment->customer?->display_name ?: $appointment->customer?->whatsapp_wa_id,
                'service' => $appointment->service_name,
                'staff' => $appointment->staff?->name,
                'starts_at' => $appointment->starts_at?->setTimezone($timezone)->toIso8601String(),
                'starts_at_label' => $appointment->starts_at?->setTimezone($timezone)->format('D, d M · H:i'),
            ])->all();

        $recentConversations = Conversation::query()
            ->with(['customer', 'latestMessage'])
            ->where('status', 'open')
            ->orderByDesc('last_message_at')
            ->limit(6)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'customer' => $conversation->customer?->display_name ?: $conversation->customer?->whatsapp_wa_id,
                'automation_state' => $conversation->automation_state,
                'last_message' => $conversation->latestMessage?->body
                    ? Str::limit($conversation->latestMessage->body, 100)
                    : 'No message content',
                'last_message_at' => $conversation->last_message_at?->setTimezone($timezone)->format('D, H:i'),
            ])->all();

        return Inertia::render('Dashboard', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'timezone' => $timezone],
            'current_user_role' => $context->role(),
            'metrics' => [
                'customers' => Customer::query()->count(),
                'open_conversations' => Conversation::query()->where('status', 'open')->count(),
                'waiting_for_staff' => Conversation::query()->where('status', 'open')->where('automation_state', 'WAITING_FOR_STAFF')->count(),
                'upcoming_appointments' => Appointment::query()->where('status', 'confirmed')->where('calendar_sync_status', 'synced')->where('starts_at', '>=', now())->count(),
                'calendar_attention' => Appointment::query()->whereIn('status', ['calendar_pending', 'confirmed'])->whereIn('calendar_sync_status', ['pending', 'unknown', 'failed'])->count(),
                'active_services' => Service::query()->where('active', true)->count(),
                'active_staff' => Staff::query()->where('active', true)->count(),
                'pending_reminders' => AppointmentReminder::query()->whereIn('status', ['pending', 'queued', 'processing', 'blocked_template_required'])->where('scheduled_at', '>=', now())->count(),
            ],
            'integrations' => [
                'whatsapp' => WhatsAppConnection::query()->where('status', 'active')->exists(),
                'google_calendar' => GoogleCalendarConnection::query()->where('status', 'connected')->exists(),
            ],
            'recent_conversations' => $recentConversations,
            'upcoming_appointments' => $upcomingAppointments,
        ]);
    }
}
