<?php

namespace App\Notifications\Jobs;

use App\Jobs\Middleware\SchoolAware;
use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Services\ChannelRegistry;
use App\Notifications\Services\NotificationRegistry;
use App\Notifications\Services\PreferenceGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Turn recipients into per-channel delivery rows.
 *
 * ONE JOB FOR THE WHOLE FAN-OUT, not one per recipient: dispatch has already
 * written the recipient rows, and the expensive part — a preference lookup and a
 * channel decision per recipient per channel — is what belongs behind the queue.
 *
 * CHUNKED AT 200. The worker here is a cron-invoked `queue:work`, not a
 * supervised daemon, and shared hosting kills long PHP processes unpredictably.
 * Small chunks mean an interrupted run loses one chunk's progress, and the
 * insertOrIgnore below makes re-running it free.
 *
 * IDs, NOT MODELS, in the constructor. SerializesModels would re-query the
 * notification at unserialize time and, more to the point, would resolve it
 * OUTSIDE the School context that SchoolAware installs — the global SchoolScope
 * would then be applied with whatever team id the previous job left behind.
 */
class FanOutNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $notificationId,
        public readonly int $schoolId,
    ) {
        $this->onConnection(config('notifications.queue.connection'));
        $this->onQueue(config('notifications.queue.fanout'));
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(
        NotificationRegistry $registry,
        ChannelRegistry $channels,
        PreferenceGate $preferences,
    ): void {
        $notification = Notification::query()->find($this->notificationId);

        if ($notification === null) {
            // Deleted between dispatch and fan-out. Nothing to do, and nothing
            // wrong — retrying would not make it reappear.
            return;
        }

        $definition = $registry->get($notification->type);

        NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->chunkById(200, function ($chunk) use ($definition, $channels, $preferences) {
                $rows = [];
                $now = now();

                foreach ($chunk as $recipient) {
                    $asDto = new Recipient(
                        $recipient->notifiable_type,
                        (int) $recipient->notifiable_id,
                        $recipient->reason,
                    );

                    foreach ($channels->all() as $channel) {
                        $skipReason = match (true) {
                            ! $preferences->allows($asDto, $definition, $channel->key(), $this->schoolId) => 'preference_off',
                            ! $channel->supports($asDto) => 'no_address',
                            default => null,
                        };

                        $rows[] = [
                            'uuid' => (string) Str::orderedUuid(),
                            'notification_recipient_id' => $recipient->id,
                            'channel' => $channel->key()->value,
                            // A REFUSAL IS A ROW. "Why did this parent not get
                            // it?" has to be answerable from one record rather
                            // than reconstructed from logs — so a skip is written
                            // with its reason, never dropped.
                            'status' => $skipReason === null
                                ? DeliveryStatus::PENDING->value
                                : DeliveryStatus::SKIPPED->value,
                            'skip_reason' => $skipReason,
                            'queued_at' => $skipReason === null ? $now : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows === []) {
                    return;
                }

                // The UNIQUE (recipient, channel) index plus insertOrIgnore is
                // what makes a re-run of this job free rather than duplicating.
                \App\Notifications\Models\NotificationDelivery::query()->insertOrIgnore($rows);

                $this->deliverInApp($chunk->pluck('id')->all(), $channels);
            });
    }

    /**
     * v1 has exactly one channel and it needs no transmission, so its deliveries
     * are completed inline rather than through a second queue hop that would only
     * ever flip a status column.
     *
     * When email lands in v2 this becomes an enqueue of SendDeliveryJob per
     * pending row; the in-app case stays inline because it genuinely has nothing
     * to send.
     *
     * @param  list<int>  $recipientIds
     */
    private function deliverInApp(array $recipientIds, ChannelRegistry $channels): void
    {
        $inApp = $channels->inApp();

        $pending = \App\Notifications\Models\NotificationDelivery::query()
            ->whereIn('notification_recipient_id', $recipientIds)
            ->where('channel', $inApp->key()->value)
            ->where('status', DeliveryStatus::PENDING->value)
            ->get();

        foreach ($pending as $delivery) {
            $inApp->send($delivery);
        }
    }
}
