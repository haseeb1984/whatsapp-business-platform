<?php

namespace App\\Notifications;

use Illuminate\\Bus\\Queueable;
use Illuminate\\Notifications\\Messages\\MailMessage;
use Illuminate\\Notifications\\Notification;

class TenantStaffInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $inviterName,
        public readonly string $role,
        public readonly string $acceptUrl,
        public readonly string $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitation to join '.$this->tenantName)
            ->greeting('You have been invited to a business workspace')
            ->line($this->inviterName.' invited you to join '.$this->tenantName.' as '.str_replace('_', ' ', $this->role).'.')
            ->line('This invitation expires '.$this->expiresAt.'.')
            ->action('Review invitation', $this->acceptUrl)
            ->line('Sign in with this email address to accept the invitation. If you do not have an account, create one using this same email address first.');
    }
}
