<?php

namespace App\Notifications\Services\Channels;

use App\Models\User;
use App\Notifications\Contracts\Channel;
use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Mail\NotificationMail;
use App\Notifications\Models\NotificationDelivery;
use App\Notifications\Services\PayloadHydrator;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Email delivery, over whatever mailer is configured (SES in production).
 *
 * IT READS `contact_points`, NOT `users.email`, through the same
 * `deliverableEmailAddress()` the feed gate and the mail router use. That is what
 * makes this channel independent of the deliverability cutover's DEPLOY: the audit
 * remediation gates the OTHER email readers, not this one, because this one never
 * consults the column.
 *
 * ⚠️ THE SENT TRANSITION AND THE PROVIDER MESSAGE ID ARE ONE WRITE. Split them and a
 * crash between the two leaves a row marked SENT with a null correlation key — which
 * is deliverable forever and UN-BOUNCEABLE, because the SNS handler correlates on
 * exactly that key. It would look green: the mail went, the row says sent, and the
 * bounce for it can never be matched to anything. One write makes
 * "SENT ⟹ correlatable" an invariant rather than a hope.
 *
 * ACCEPTED RESIDUAL, stated rather than hidden: a crash BEFORE that write leaves the
 * row PENDING after the provider already accepted the message, so the retry sends
 * again. That is at-least-once, and it is the right side to err on for email —
 * a duplicate notification is a nuisance, a silently dropped result notification is
 * a parent who never learns. A per-delivery provider idempotency key is the harden
 * pass, not this slice.
 *
 * THE SES CONFIGURATION SET IS APPLIED HERE, and it is load-bearing for Part B: SES
 * emits bounce and complaint events to SNS only for messages sent under a
 * configuration set that has an event destination. A config set that exists but is
 * never NAMED on the send produces no events at all — and sending still works
 * perfectly, which is why it is the silent one of the three prerequisites.
 */
class EmailChannel implements Channel
{
    public function __construct(private readonly PayloadHydrator $hydrator) {}

    public function key(): ChannelKey
    {
        return ChannelKey::EMAIL;
    }

    /**
     * A recipient is supported when they have a deliverable email CONTACT POINT.
     *
     * False produces a SKIPPED row with `no_address` rather than a silent drop —
     * which is the honest outcome for a guardian on record with no email, the
     * population the retired synthetic sentinel used to disguise.
     */
    public function supports(Recipient $recipient): bool
    {
        return $this->addressFor($recipient) !== null;
    }

    public function send(NotificationDelivery $delivery): void
    {
        $recipient = $delivery->recipient;
        $notification = $recipient?->notification;

        if ($recipient === null || $notification === null) {
            throw new RuntimeException("Delivery {$delivery->id} has no recipient or notification to render.");
        }

        $address = $this->addressFor(new Recipient(
            $recipient->notifiable_type,
            (int) $recipient->notifiable_id,
            $recipient->reason,
        ));

        if ($address === null) {
            // Reachable if the contact point was removed between fan-out and send.
            // A row, with a reason — never a silent drop.
            $delivery->forceFill([
                'status' => DeliveryStatus::SKIPPED,
                'skip_reason' => 'no_address',
            ])->save();

            return;
        }

        // The SAME hydration the feed row uses, so the subject line names the child
        // or the request exactly as the in-app row does. Re-deriving it here is how
        // the two drift into saying different things about one event.
        $this->hydrator->hydrate(collect([$recipient]));

        $sent = Mail::to($address)->send(new NotificationMail(
            title: $this->hydrator->title($recipient),
            body: $notification->rendered_fallback,
            deepLink: $this->hydrator->deepLinkFor($recipient),
            configurationSet: config('services.ses.configuration_set'),
        ));

        // ONE WRITE. See the class docblock: splitting these is the un-bounceable
        // SENT row.
        $delivery->forceFill([
            'status' => DeliveryStatus::SENT,
            'sent_at' => now(),
            'provider' => config('mail.default'),
            'provider_message_id' => $this->messageIdOf($sent),
            'attempts' => (int) $delivery->attempts + 1,
        ])->save();
    }

    private function addressFor(Recipient $recipient): ?string
    {
        if ($recipient->notifiableType !== User::class) {
            return null;
        }

        return User::query()
            ->find($recipient->notifiableId)
            ?->deliverableEmailAddress();
    }

    /**
     * The provider's own id for this message — the ONLY thing an inbound bounce can
     * be correlated on, since SES references the message by its MessageId and
     * nothing we control.
     *
     * Trimmed of the angle brackets Symfony wraps around it, because SES reports the
     * bare id in `mail.messageId` and a correlation that never matches is
     * indistinguishable from a bounce that never arrived.
     */
    private function messageIdOf(mixed $sent): ?string
    {
        // NULL UNDER Mail::fake(), which returns nothing from send().
        if (! is_object($sent)) {
            return null;
        }

        // ⚠️ NOT method_exists(). Illuminate\Mail\SentMessage proxies to Symfony's
        // via __call, so method_exists() returns FALSE while the call itself succeeds
        // and returns the id. A structural check standing in for "can I call this" —
        // and they diverge for exactly the object this receives in production. Guarded
        // by an actual attempt, which is the only thing that answers the real question.
        try {
            $id = $sent->getMessageId();
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? trim($id, '<>') : null;
    }
}
