<?php

namespace App\Notifications\Jobs;

use App\Jobs\Middleware\SchoolAware;
use App\Models\User;
use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationDelivery;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Models\NotificationSuppression;
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
                        // ORDER IS THE SEMANTICS. Suppression is checked LAST but is
                        // the only TERMINAL one: preference and address are about
                        // whether we would send, suppression is about whether we may.
                        // A transactional type cannot be preference-suppressed — a
                        // checker may not opt out of being asked — but it CAN be
                        // bounce-suppressed, because a dead mailbox does not care
                        // what the type is.
                        $skipReason = match (true) {
                            ! $preferences->allows($asDto, $definition, $channel->key(), $this->schoolId) => 'preference_off',
                            ! $channel->supports($asDto) => 'no_address',
                            $this->isSuppressed($asDto, $channel->key()) => 'suppressed',
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
                NotificationDelivery::query()->insertOrIgnore($rows);

                $this->deliverInApp($chunk->pluck('id')->all(), $channels);
                $this->dispatchExternalSends($chunk->pluck('id')->all());
            });
    }

    /**
     * Is this recipient's address for this channel suppressed?
     *
     * ⚠️ IT RESOLVES THE ADDRESS THE SAME WAY THE CHANNEL DOES, and then hands it to
     * the same normalizer the suppression WRITE used. If the check normalized
     * differently from the write, a suppressed address would pass — mail to someone
     * who asked us to stop, with nothing thrown, nothing logged, and every test
     * green. That disagreement is the failure this whole arm exists to prevent, so
     * both sides route through NotificationSuppression, never through their own copy.
     *
     * In-app has no address and cannot be suppressed: the feed IS the record.
     */
    private function isSuppressed(Recipient $recipient, ChannelKey $channel): bool
    {
        if ($channel === ChannelKey::IN_APP || $recipient->notifiableType !== User::class) {
            return false;
        }

        $address = $channel === ChannelKey::EMAIL
            ? User::query()->find($recipient->notifiableId)?->deliverableEmailAddress()
            : null;

        return $address !== null && NotificationSuppression::suppresses($channel, $address);
    }

    /**
     * Enqueue one send job per PENDING external delivery.
     *
     * AFTER the insertOrIgnore, deliberately: dispatching from the in-memory rows
     * would enqueue sends for rows the UNIQUE index rejected as duplicates, and each
     * would then find a delivery already terminal. Reading back what was actually
     * inserted keeps "a job exists" and "a row exists" the same statement.
     *
     * EXTERNAL ONLY. In-app is completed inline above because it has nothing to
     * transmit; routing it through the queue would make the feed depend on a worker
     * it does not need.
     *
     * @param  list<int>  $recipientIds
     */
    private function dispatchExternalSends(array $recipientIds): void
    {
        NotificationDelivery::query()
            ->whereIn('notification_recipient_id', $recipientIds)
            ->where('status', DeliveryStatus::PENDING)
            ->where('channel', '!=', ChannelKey::IN_APP->value)
            ->pluck('id')
            ->each(fn (int $id) => SendDeliveryJob::dispatch($id, $this->schoolId));
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

        $pending = NotificationDelivery::query()
            ->whereIn('notification_recipient_id', $recipientIds)
            ->where('channel', $inApp->key()->value)
            ->where('status', DeliveryStatus::PENDING->value)
            ->get();

        foreach ($pending as $delivery) {
            $inApp->send($delivery);
        }
    }
}
