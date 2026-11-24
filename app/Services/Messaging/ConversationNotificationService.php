<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Notifications\ConversationAssignedNotification;
use App\Notifications\ConversationHandoverNotification;
use App\Support\Tenancy\TenantContext;

class ConversationNotificationService
{
    public function assigned(string $conversationId, int $userId): void
    {
        $tenant = app(TenantContext::class)->tenant();
        $conversation = Conversation::query()->findOrFail($conversationId);
        $recipient = $tenant->users()
            ->where('users.id', $userId)
            ->wherePivot('status', 'active')
            ->first();

        if ($recipient) {
            $recipient->notify(new ConversationAssignedNotification(
                $tenant->id,
                $tenant->name,
                $conversation->id,
            ));
        }
    }

    public function handover(string $conversationId): void
    {
        $tenant = app(TenantContext::class)->tenant();
        $conversation = Conversation::query()->findOrFail($conversationId);
        $query = $tenant->users()->wherePivot('status', 'active');

        if ($conversation->assigned_user_id !== null) {
            $query->where('users.id', $conversation->assigned_user_id);
        } else {
            $query->wherePivotIn('role', ['business_admin', 'manager']);
        }

        $recipients = $query->get(['users.id', 'users.name', 'users.email'])->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new ConversationHandoverNotification(
                $tenant->id,
                $tenant->name,
                $conversation->id,
            ));
        }
    }
}
