<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuardianAccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $plainPassword,
        private readonly string $schoolName,
        private readonly string $loginUrl,
        private readonly array  $studentNames = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Parent Account Created Successfully')
            ->greeting("Hello {$notifiable->full_name},")
            ->line("A parent/guardian account has been created for you at {$this->schoolName}.");

        if (!empty($this->studentNames)) {
            $mail->line('You are linked to the following student(s): **' . implode(', ', $this->studentNames) . '**.');
        }

        return $mail
            ->line('**Login Details**')
            ->line("**Email:** {$notifiable->email}")
            ->line("**Temporary Password:** `{$this->plainPassword}`")
            ->action('Log In Now', $this->loginUrl)
            ->line('For security reasons, please change your password immediately after your first login.')
            ->salutation("Regards,\n{$this->schoolName}");
    }
}
