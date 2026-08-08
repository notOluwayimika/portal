<?php

namespace App\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\SuppressionReason;
use App\Notifications\Models\NotificationDelivery;
use App\Notifications\Models\NotificationSuppression;
use Aws\Sns\Message as SnsMessage;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SES bounce loop — an SNS SUBSCRIPTION endpoint, not a signed webhook.
 *
 * SES does not POST you a bounce. It publishes to SNS, and SNS delivers to this URL,
 * which makes three things different from an HMAC webhook and each of them silent if
 * missed:
 *
 *  1. A `SubscriptionConfirmation` ARRIVES FIRST and must be confirmed by fetching
 *     its SubscribeURL. Ignore it — or treat it as a notification — and the
 *     subscription stays pending, no event ever arrives, and the endpoint looks
 *     perfectly healthy because it returns 200 to everything.
 *  2. Verification is CERTIFICATE-BASED, not a shared secret. Hand-rolling it is the
 *     class of thing that ships subtly wrong, so this uses the AWS validator.
 *  3. REPLAYS ARE ROUTINE. SNS redelivers on any non-2xx and sometimes anyway, so
 *     every effect here has to be idempotent.
 *
 * ⚠️ UNAUTHENTICATED BY NECESSITY, which is why the signature check is the whole
 * security boundary. AWS cannot present a session or a token; the certificate
 * signature IS the proof this came from SNS. An unverified message is rejected before
 * anything is read from it.
 *
 * NO `school_id` ANYWHERE. A bounce is a fact about an address, and this endpoint has
 * no tenant context to run in — correlation is by provider message id, and
 * suppression is address-scoped for the same reason the table has no school column.
 */
class SesEventController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $message = SnsMessage::fromJsonString($request->getContent());
            (new MessageValidator)->validate($message);
        } catch (Throwable $e) {
            // Reject rather than absorb: a message we cannot verify is not from SNS,
            // and acting on it would let anyone suppress any address.
            Log::warning('SNS message rejected', ['error' => $e->getMessage()]);

            return response('invalid signature', 403);
        }

        return match ($message['Type'] ?? null) {
            'SubscriptionConfirmation' => $this->confirmSubscription($message),
            'Notification' => $this->handleNotification($message),
            // Acknowledged so SNS stops retrying; nothing to do.
            'UnsubscribeConfirmation' => response('ok', 200),
            default => response('ignored', 200),
        };
    }

    /**
     * Without this, NOTHING else in Part B ever runs. The subscription sits pending
     * and no SES event is delivered — while this endpoint happily returns 200.
     */
    private function confirmSubscription(SnsMessage $message): Response
    {
        $url = $message['SubscribeURL'] ?? null;

        if (! is_string($url) || $url === '') {
            return response('no SubscribeURL', 422);
        }

        Http::timeout(10)->get($url);

        Log::info('SNS subscription confirmed', ['topic' => $message['TopicArn'] ?? null]);

        return response('confirmed', 200);
    }

    private function handleNotification(SnsMessage $message): Response
    {
        $event = json_decode((string) ($message['Message'] ?? ''), true);

        if (! is_array($event)) {
            return response('unparseable', 200);
        }

        // CORRELATION IS THE PROVIDER'S MESSAGE ID, the only handle SES gives us —
        // which is why EmailChannel writes it in the SAME update that sets SENT. A
        // SENT row with a null key is un-bounceable, and this is where that would
        // show up as a bounce matching nothing.
        $messageId = $event['mail']['messageId'] ?? null;
        $delivery = is_string($messageId)
            ? NotificationDelivery::query()->where('provider_message_id', $messageId)->first()
            : null;

        $recipients = $this->recipientsOf($event);

        match ($event['notificationType'] ?? null) {
            'Bounce' => $this->handleBounce($event, $delivery, $recipients),
            'Complaint' => $this->suppressAll($recipients, SuppressionReason::COMPLAINT, $delivery, DeliveryStatus::BOUNCED),
            'Delivery' => $this->markDelivered($delivery),
            default => null,
        };

        return response('ok', 200);
    }

    private function handleBounce(array $event, ?NotificationDelivery $delivery, array $recipients): void
    {
        // Permanent vs Transient is SES's own judgement and the distinction that
        // matters most: a full mailbox is Transient, and a permanent address-scoped
        // suppression for one would mute a live parent forever.
        $permanent = ($event['bounce']['bounceType'] ?? null) === 'Permanent';

        $this->suppressAll(
            $recipients,
            $permanent ? SuppressionReason::HARD_BOUNCE : SuppressionReason::SOFT_BOUNCE,
            $delivery,
            $permanent ? DeliveryStatus::BOUNCED : DeliveryStatus::FAILED,
        );
    }

    /** @param  list<string>  $recipients */
    private function suppressAll(array $recipients, SuppressionReason $reason, ?NotificationDelivery $delivery, DeliveryStatus $status): void
    {
        foreach ($recipients as $address) {
            // Idempotent by the UNIQUE index — a replayed SNS message adds nothing.
            NotificationSuppression::record(
                channel: ChannelKey::EMAIL,
                rawAddress: $address,
                reason: $reason,
                source: 'ses:sns',
                deliveryId: $delivery?->id,
            );
        }

        $delivery?->forceFill([
            'status' => $status,
            'failed_at' => now(),
            'last_error' => $reason->value,
        ])->save();
    }

    private function markDelivered(?NotificationDelivery $delivery): void
    {
        // Only forward from SENT: a bounce can arrive before or after a delivery
        // notification, and a late Delivery must not overwrite a BOUNCED row.
        if ($delivery?->status === DeliveryStatus::SENT) {
            $delivery->forceFill([
                'status' => DeliveryStatus::DELIVERED,
                'delivered_at' => now(),
            ])->save();
        }
    }

    /**
     * The addresses this event is about.
     *
     * SES reports them per-event-type, and a bounce can name SEVERAL — one message to
     * three addresses, two of which bounce. Suppressing only the first would leave the
     * others sending forever.
     *
     * @return list<string>
     */
    private function recipientsOf(array $event): array
    {
        $addresses = match ($event['notificationType'] ?? null) {
            'Bounce' => array_column($event['bounce']['bouncedRecipients'] ?? [], 'emailAddress'),
            'Complaint' => array_column($event['complaint']['complainedRecipients'] ?? [], 'emailAddress'),
            default => [],
        };

        return array_values(array_filter($addresses, 'is_string'));
    }
}
