<?php

namespace App\Notifications;

use App\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuardianImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Import $import) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reportUrl    = url("/api/guardians/import/{$this->import->uuid}/report");
        $guardiansUrl = url('/admin/guardians');

        return (new MailMessage)
            ->subject('Guardian Import Completed')
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Your guardian import \"{$this->import->file_name}\" has finished processing.")
            ->line("**Summary**")
            ->line("Succeeded: {$this->import->succeeded}")
            ->line("Failed: {$this->import->failed}")
            ->line("Skipped: {$this->import->skipped}")
            ->action('Download Report', $reportUrl)
            ->line("You can view newly created guardians here: {$guardiansUrl}")
            ->line('Reminder: photos are not included in bulk import. Add guardian photos individually via the guardian profile page.');
    }
}
