<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ConversationAssignedNotification extends TenantConversationNotification
{
    protected function preferenceKey(): string
    {
        return 'email_conversation_assignments';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A WhatsApp conversation was assigned to you')
            ->greeting('New conversation assignment')
            ->line('A customer conversation in '.$this->tenantName.' needs your attention. Select this workspace to open the inbox.')
            ->action('Choose workspace', $this->conversationUrl())
            ->line('You can change assignment email preferences in your workspace settings.');
    }
}
