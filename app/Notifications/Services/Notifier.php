<?php

namespace App\Notifications\Services;

use App\Models\User;
use App\Notifications\Contracts\Notification as NotificationContract;
use App\Notifications\Contracts\Notifier as NotifierContract;
use App\Notifications\Jobs\FanOutNotificationJob;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationRecipient;
use App\Support\ActiveSchool;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The developer-facing front door:
 *
 *     Notifier::send(new ResultReady($studentCurriculum));
 *
 * A call site names a TYPE. It never names a channel, a provider or a recipient
 * — which is the whole reason adding email in v2 touches no business code.
 *
 * DISPATCH IS SYNCHRONOUS AND CHEAP; DELIVERY IS NOT. What happens in the request
 * is: resolve, two inserts, enqueue one job. Preference gating, suppression and
 * per-channel fan-out all happen behind the queue, because doing them inline for
 * a 400-parent year group is how a result-approval click becomes a timeout.
 *
 * THE RECORD IS WRITTEN BEFORE THE QUEUE IS TOUCHED. If the worker is dead, the
 * notification still exists and the in-app feed still shows it. Delivery
 * degrades; the record does not — which matters especially here, where the
 * worker is a cron-invoked `queue:work` rather than a supervised daemon.
 */
class Notifier implements NotifierContract
{
    public function __construct(private readonly NotificationRegistry $registry) {}

    /**
     * @return Notification the persisted event row (existing one, if deduped)
     */
    public function send(NotificationContract $notification): ?Notification
    {
        // Dark by default. Every call site added in this release is inert until
        // the flag is on, and turning it back off needs no redeploy.
        if (! config('notifications.enabled')) {
            return null;
        }

        $definition = $this->registry->get($notification->type());
        $schoolId = $notification->schoolId();

        // The School context is explicit and set here, not inherited. Dispatch can
        // happen inside a job or an artisan command where there is no request
        // context at all, and the resolvers below rely on the global SchoolScope.
        return ActiveSchool::runFor($schoolId, function () use ($notification, $definition, $schoolId) {
            $record = $this->persist($notification, $schoolId);

            $recipients = collect($this->registry->resolverFor($notification->type())->resolve($notification))
                // De-duplicate in memory: a user holding a checker grant through
                // two roles resolves twice, and the UNIQUE index would reject the
                // second insert of a batch rather than skip it.
                ->keyBy(fn ($recipient) => $recipient->key());

            if ($definition->excludeActor && $notification->actorId() !== null) {
                $recipients = $recipients->reject(
                    fn ($recipient) => $recipient->notifiableType === User::class
                        && $recipient->notifiableId === $notification->actorId()
                );
            }

            if ($recipients->isEmpty()) {
                // Not an error, and not silent either — a notification with no
                // recipients is a real outcome (nobody holds the checker grant
                // yet), and the event row stays as the evidence that it happened.
                return $record;
            }

            $now = now();

            // insertOrIgnore + the UNIQUE index makes re-dispatch idempotent: the
            // same event raised twice adds no duplicate feed rows.
            NotificationRecipient::query()->insertOrIgnore(
                $recipients->map(fn ($recipient) => [
                    'uuid' => (string) Str::orderedUuid(),
                    'notification_id' => $record->id,
                    'school_id' => $schoolId,
                    'notifiable_type' => $recipient->notifiableType,
                    'notifiable_id' => $recipient->notifiableId,
                    'reason' => $recipient->reason->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all()
            );

            FanOutNotificationJob::dispatch($record->id, $schoolId);

            return $record;
        });
    }

    /**
     * Insert the event row, collapsing a repeat of the SAME EVENT.
     *
     * The dedup key is event identity — never recipient identity (see the
     * Notification contract). Two concurrent dispatches race past firstOrCreate's
     * SELECT, so the UNIQUE index is the real guard and the violation is caught
     * rather than prevented.
     */
    private function persist(NotificationContract $notification, int $schoolId): Notification
    {
        $subject = $notification->subject();

        $attributes = [
            'school_id' => $schoolId,
            'type' => $notification->type()->value,
            'actor_id' => $notification->actorId(),
            'subject_type' => $subject === null ? null : $subject->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $notification->payload(),
            'rendered_fallback' => $notification->renderedFallback(),
            'dedup_key' => $notification->dedupKey(),
        ];

        if ($notification->dedupKey() === null) {
            return Notification::query()->create($attributes);
        }

        $existing = fn () => Notification::query()
            ->where('school_id', $schoolId)
            ->where('type', $notification->type()->value)
            ->where('dedup_key', $notification->dedupKey())
            ->first();

        if ($found = $existing()) {
            return $found;
        }

        try {
            return DB::transaction(fn () => Notification::query()->create($attributes));
        } catch (UniqueConstraintViolationException) {
            // Lost the race. The other dispatch wrote an identical row, which is
            // precisely what the dedup key asked for.
            return $existing() ?? throw new UniqueConstraintViolationException(
                'notifications', '', [], new \RuntimeException('dedup row vanished')
            );
        }
    }
}
