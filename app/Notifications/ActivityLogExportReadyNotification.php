<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivityLogExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $downloadUrl,
        private readonly int $rowCount,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activity Log Export Ready')
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Your activity log export ({$this->rowCount} rows) is ready.")
            ->line('This download link is valid for 7 days.')
            ->action('Download Export', $this->downloadUrl);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'activity_log_export',
            'download_url' => $this->downloadUrl,
            'row_count'    => $this->rowCount,
        ];
    }
}
