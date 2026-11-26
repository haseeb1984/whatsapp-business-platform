<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class PlatformOperationalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.strtoupper($this->severity).'] Platform operational alert: '.$this->title)
            ->greeting('Platform operations alert')
            ->line($this->title)
            ->line($this->detail)
            ->line('Alert code: '.$this->code)
            ->line('This is an automated operational notice. Review the platform dashboard and follow the incident runbook.');
    }
}
