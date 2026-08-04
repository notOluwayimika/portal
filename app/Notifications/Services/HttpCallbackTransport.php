<?php

namespace App\Notifications\Services;

use App\Notifications\Contracts\CallbackTransport;
use App\Notifications\DTOs\CallbackResult;
use App\Notifications\Exceptions\CallbackUnconfirmed;
use App\Notifications\Models\NotificationAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Signed HTTP relay to the service that owns the decision.
 *
 * ⚠️ IT DOES NOT RETRY, AND THAT IS DELIBERATE. Every failure mode here is
 * indistinguishable from "the request landed and the answer was lost", so a retry is
 * a second revocation attempt against a service that may have honoured the first. The
 * timeout is the budget; past it the honest answer is UNCONFIRMED and the
 * reconciliation is somebody's explicit decision later, not this method's guess.
 *
 * THE TIMEOUT IS THE UX. The tap blocks on this call — that is the whole
 * tap-and-see-the-outcome design — so the ceiling is a product constraint, not a
 * network default: past ~10s the person has stopped believing the button worked.
 *
 * AN UNRECOGNISED BODY IS UNCONFIRMED, NOT A FAILURE. A 200 whose payload we cannot
 * parse means the service probably acted and we cannot say how. Guessing "rejected"
 * would tell a parent their revoke did not happen when it may have — the one error
 * direction that matters here.
 */
final class HttpCallbackTransport implements CallbackTransport
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly ServiceCallbackSigner $signer) {}

    public function send(NotificationAction $action): CallbackResult
    {
        $body = (string) json_encode([
            'action_uuid' => $action->uuid,
            'label' => $action->label,
            'payload' => $action->callback_payload,
        ]);

        $signed = $this->signer->sign($body);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Callback-Signature' => $signed['signature'],
                    'X-Callback-Timestamp' => (string) $signed['timestamp'],
                ])
                ->withBody($body, 'application/json')
                ->post($action->callback_url);
        } catch (ConnectionException $e) {
            // Timed out or never connected. We do not know whether it landed.
            throw new CallbackUnconfirmed($e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new CallbackUnconfirmed($e->getMessage(), previous: $e);
        }

        if ($response->serverError()) {
            // A 5xx may follow a side effect that already committed on their side.
            throw new CallbackUnconfirmed('callback returned '.$response->status());
        }

        // A 4xx is a DECISION we can read: the service understood and declined.
        // Only 410 Gone carries the "their window closed" meaning; any other refusal
        // is not something we can characterise, so it stays unknown rather than being
        // flattened into TOO_LATE.
        if ($response->status() === 410) {
            return CallbackResult::tooLate();
        }

        if ($response->clientError()) {
            throw new CallbackUnconfirmed('callback returned '.$response->status());
        }

        return match ($response->json('outcome')) {
            'revoked' => CallbackResult::revoked(),
            'too_late' => CallbackResult::tooLate(),
            default => throw new CallbackUnconfirmed('unrecognised callback outcome'),
        };
    }
}
