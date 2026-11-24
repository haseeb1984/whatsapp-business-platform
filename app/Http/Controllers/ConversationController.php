<?php

namespace App\Http\Controllers;

use App\Enums\MessageActor;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['all', 'AUTO_ACTIVE', 'WAITING_FOR_STAFF', 'HUMAN_ACTIVE'])],
            'status' => ['nullable', Rule::in(['open', 'closed', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $conversations = Conversation::query()
            ->with(['customer', 'assignedUser', 'latestMessage'])
            ->when(($filters['state'] ?? 'all') !== 'all', fn ($query) => $query->where('automation_state', $filters['state']))
            ->when(($filters['status'] ?? 'open') !== 'all', fn ($query) => $query->where('status', $filters['status'] ?? 'open'))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->whereHas('customer', function ($customer) use ($search): void {
                    $customer->where('display_name', 'like', '%'.$search.'%')
                        ->orWhere('whatsapp_wa_id', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return Inertia::render('Business/Conversations/Index', [
            'conversations' => $conversations->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'customer_name' => $conversation->customer?->display_name,
                'whatsapp_wa_id' => $conversation->customer?->whatsapp_wa_id,
                'status' => $conversation->status,
                'automation_state' => $conversation->automation_state,
                'assigned_user' => $conversation->assignedUser?->name,
                'last_message' => $conversation->latestMessage?->body
                    ? mb_substr($conversation->latestMessage->body, 0, 140)
                    : '['.($conversation->latestMessage?->message_type ?? 'No messages').']',
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ])->all(),
            'filters' => [
                'state' => $filters['state'] ?? 'all',
                'status' => $filters['status'] ?? 'open',
                'search' => $filters['search'] ?? '',
            ],
            'current_user_id' => (int) $request->user()->id,
        ]);
    }

    public function show(string $conversationId): Response
    {
        $conversation = Conversation::query()
            ->with([
                'customer',
                'assignedUser',
                'messages' => fn ($query) => $query->orderBy('created_at')->limit(250),
                'notes.author',
            ])
            ->findOrFail($conversationId);

        $teamMembers = app(TenantContext::class)->tenant()->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['business_admin', 'manager', 'staff'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();

        return Inertia::render('Business/Conversations/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'customer_name' => $conversation->customer?->display_name,
                'whatsapp_wa_id' => $conversation->customer?->whatsapp_wa_id,
                'status' => $conversation->status,
                'automation_state' => $conversation->automation_state,
                'assigned_user_id' => $conversation->assigned_user_id,
                'assigned_user_name' => $conversation->assignedUser?->name,
                'handover_requested_at' => $conversation->handover_requested_at?->toIso8601String(),
                'handover_reason' => $conversation->handover_reason,
            ],
            'messages' => $conversation->messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'message_type' => $message->message_type,
                'body' => $message->body,
                'delivery_status' => $message->delivery_status,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ])->all(),
            'notes' => $conversation->notes->map(fn (ConversationNote $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author?->name ?? 'Former user',
                'created_at' => $note->created_at?->toIso8601String(),
            ])->all(),
            'team_members' => $teamMembers,
            'current_user_id' => (int) request()->user()->id,
            'current_user_role' => app(TenantContext::class)->role(),
        ]);
    }

    public function take(Request $request, string $conversationId): RedirectResponse
    {
        DB::transaction(function () use ($request, $conversationId): void {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            abort_unless($conversation->status === 'open', 409, 'Closed conversations cannot be taken.');

            if ($conversation->assigned_user_id !== null
                && $conversation->assigned_user_id !== $request->user()->id
                && app(TenantContext::class)->role() !== 'business_admin') {
                abort(409, 'Another team member currently owns this conversation.');
            }

            $conversation->forceFill([
                'automation_state' => 'HUMAN_ACTIVE',
                'assigned_user_id' => $request->user()->id,
                'handover_requested_at' => $conversation->handover_requested_at ?? now(),
            ])->save();
        });

        return back()->with('success', 'You have taken ownership of this conversation. Automation is paused.');
    }

    public function release(Request $request, string $conversationId): RedirectResponse
    {
        DB::transaction(function () use ($request, $conversationId): void {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            abort_unless($conversation->status === 'open' && $conversation->automation_state === 'HUMAN_ACTIVE', 409);
            abort_unless(
                $conversation->assigned_user_id === $request->user()->id || app(TenantContext::class)->role() === 'business_admin',
                403,
            );

            $conversation->forceFill([
                'automation_state' => 'AUTO_ACTIVE',
                'assigned_user_id' => null,
                'handover_requested_at' => null,
                'handover_reason' => null,
            ])->save();
        });

        return back()->with('success', 'Conversation released. Automation may respond to future inbound messages.');
    }

    public function assign(Request $request, string $conversationId): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'assigned_user_id' => [
                'required',
                'integer',
                Rule::exists('tenant_user', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->whereIn('role', ['business_admin', 'manager', 'staff'])),
            ],
        ]);

        DB::transaction(function () use ($conversationId, $validated): void {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            abort_unless($conversation->status === 'open', 409);

            $conversation->forceFill([
                'assigned_user_id' => $validated['assigned_user_id'],
                'automation_state' => $conversation->automation_state === 'AUTO_ACTIVE' ? 'WAITING_FOR_STAFF' : $conversation->automation_state,
                'handover_requested_at' => $conversation->handover_requested_at ?? now(),
            ])->save();
        });

        return back()->with('success', 'Conversation assigned to a workspace team member.');
    }

    public function requestHandover(Request $request, string $conversationId): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:160']]);
        DB::transaction(function () use ($conversationId, $validated): void {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            abort_unless($conversation->status === 'open' && $conversation->automation_state === 'AUTO_ACTIVE', 409);

            $conversation->forceFill([
                'automation_state' => 'WAITING_FOR_STAFF',
                'handover_requested_at' => now(),
                'handover_reason' => $validated['reason'] ?? 'Requested by team member',
            ])->save();
        });

        return back()->with('success', 'Automation paused and staff handover requested.');
    }

    public function reply(Request $request, string $conversationId, WhatsAppMessageService $messenger): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:4096']]);
        $conversation = Conversation::query()->findOrFail($conversationId);
        abort_unless($conversation->automation_state === 'HUMAN_ACTIVE', 409);
        abort_unless($conversation->assigned_user_id === $request->user()->id, 403);

        $messenger->sendText($conversation, $validated['body'], MessageActor::Staff, null, (int) $request->user()->id);

        return back()->with('success', 'Staff reply queued for WhatsApp delivery.');
    }

    public function addNote(Request $request, string $conversationId): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $conversation = Conversation::query()->findOrFail($conversationId);
        abort_unless($conversation->automation_state === 'HUMAN_ACTIVE', 409);
        abort_unless($conversation->assigned_user_id === $request->user()->id, 403);

        DB::transaction(function () use ($conversation, $request, $validated): void {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            abort_unless($locked->automation_state === 'HUMAN_ACTIVE', 409);
            abort_unless($locked->assigned_user_id === $request->user()->id, 403);

            ConversationNote::query()->create([
                'conversation_id' => $locked->id,
                'created_by_user_id' => $request->user()->id,
                'body' => $validated['body'],
            ]);
        });

        return back()->with('success', 'Internal note saved.');
    }

    public function close(string $conversationId): RedirectResponse
    {
        DB::transaction(function () use ($conversationId): void {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            abort_unless($conversation->status === 'open', 409);
            $conversation->forceFill(['status' => 'closed', 'automation_state' => 'HUMAN_ACTIVE'])->save();
        });

        return back()->with('success', 'Conversation closed.');
    }

    public function reopen(string $conversationId): RedirectResponse
    {
        DB::transaction(function () use ($conversationId): void {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversationId);
            abort_unless($conversation->status === 'closed', 409);

            $conversation->forceFill([
            'status' => 'open',
            'automation_state' => 'WAITING_FOR_STAFF',
            'assigned_user_id' => null,
            'handover_requested_at' => now(),
            'handover_reason' => 'Conversation reopened by a team administrator',
            ])->save();
        });

        return back()->with('success', 'Conversation reopened and queued for staff.');
    }
}
