<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherAccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $plainPassword,
        private readonly string $schoolName,
        private readonly string $loginUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Teacher Account Created Successfully')
            ->greeting("Hello {$notifiable->full_name},")
            ->line('Your teacher account has been created successfully.')
            ->line('**Login Details**')
            ->line("**Email:** {$notifiable->email}")
            ->line("**Temporary Password:** `{$this->plainPassword}`")
            ->action('Log In Now', $this->loginUrl)
            ->line('For security reasons, please change your password immediately after your first login.')
            ->salutation("Regards,\n{$this->schoolName}");
    }
}
