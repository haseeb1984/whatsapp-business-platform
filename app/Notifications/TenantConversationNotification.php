<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

abstract class TenantConversationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public string $tenantId,
        public string $tenantName,
        public string $conversationId,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function via(object $notifiable): array
    {
        if (! isset($notifiable->id)) {
            return [];
        }

        $membership = DB::table('tenant_user')
            ->join('tenants', 'tenants.id', '=', 'tenant_user.tenant_id')
            ->where('tenant_user.tenant_id', $this->tenantId)
            ->where('tenant_user.user_id', $notifiable->id)
            ->where('tenant_user.status', 'active')
            ->where('tenants.status', 'active')
            ->first(['tenant_user.notification_preferences']);

        if (! $membership) {
            return [];
        }

        $preferences = $membership->notification_preferences;
        if (is_string($preferences)) {
            $preferences = json_decode($preferences, true);
        }
        if (! is_array($preferences)) {
            $preferences = [];
        }

        $channels = ['database'];
        if (filter_var($preferences[$this->preferenceKey()] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    abstract protected function preferenceKey(): string;

    abstract protected function databaseTitle(): string;

    abstract protected function databaseMessage(): string;

    public function toDatabase(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'tenant_name' => $this->tenantName,
            'conversation_id' => $this->conversationId,
            'title' => $this->databaseTitle(),
            'message' => $this->databaseMessage(),
            'url' => route('conversations.show', $this->conversationId),
        ];
    }

    protected function conversationUrl(): string
    {
        return route('workspaces.index');
    }

    abstract public function toMail(object $notifiable): MailMessage;
}
