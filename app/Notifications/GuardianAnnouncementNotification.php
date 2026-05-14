<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuardianAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $subject,
        private readonly string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting("Hello {$notifiable->full_name},")
            ->line($this->body);
    }
}
