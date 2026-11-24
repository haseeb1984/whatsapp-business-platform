<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ConversationHandoverNotification extends TenantConversationNotification
{
    protected function preferenceKey(): string
    {
        return 'email_handover_requests';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A WhatsApp conversation needs staff attention')
            ->greeting('Staff handover requested')
            ->line('A customer conversation in '.$this->tenantName.' is waiting for a team member. Select this workspace to open the inbox.')
            ->action('Choose workspace', $this->conversationUrl())
            ->line('You can change handover email preferences in your workspace settings.');
    }
}
