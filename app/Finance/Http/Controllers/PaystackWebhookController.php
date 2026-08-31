<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Actions\SettleGatewayTransaction;
use App\Finance\Enums\GatewaySettlementOutcome;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\GatewayTransactionEvent;
use App\Finance\Services\GatewayReference;
use App\Finance\Services\PaystackSignature;
use App\Http\Controllers\Controller;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paystack's server-to-server delivery.
 *
 * ── UNAUTHENTICATED BY SESSION, AUTHENTICATED BY HMAC ──
 *
 * Paystack carries no session, no cookie and no Sanctum token, so this route sits outside every
 * auth group. Its ONLY authentication is the `x-paystack-signature` HMAC over the RAW body, and
 * that check is the first thing that happens. Nothing — not a lookup, not a log line naming the
 * reference, and above all not an event row — happens before it passes. An unsigned request is not
 * a delivery, and writing it to an append-only table would let anyone who knows the URL fill that
 * table with rows nobody can delete.
 *
 * RAW BODY, NOT THE PARSED ARRAY. The HMAC is over the exact bytes Paystack signed. Re-encoding the
 * decoded array produces different bytes — key order, unicode escaping, float formatting — and the
 * signature would fail for honest deliveries while a naive fix ("compare loosely") would break the
 * check entirely.
 *
 * ── IT ANSWERS 200 TO ALMOST EVERYTHING ──
 *
 * The status code instructs Paystack about REDELIVERY; it is not a verdict. A duplicate, a
 * transaction we do not recognise, an event we do not act on, a race lost — all are 200, because
 * redelivering them would produce the same result forever. Only a failed signature is refused, and
 * only a genuine server fault is allowed to 500 into Paystack's retry schedule, where the retry is
 * actually wanted.
 *
 * ── NO SCHOOL CONTEXT ARRIVES WITH IT ──
 *
 * There is no authenticated user, so `ActiveSchool` is empty when the request lands. The school is
 * DERIVED FROM THE TRANSACTION the reference names, and everything downstream runs inside
 * `ActiveSchool::runFor()` for that school. The lookup that finds the transaction therefore has to
 * run WITHOUT the global scope — which is why it is explicit and narrow, keyed on
 * `(provider, reference)`, the pair that carries a unique index.
 */
final class PaystackWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaystackSignature $signature,
        SettleGatewayTransaction $settle,
    ): JsonResponse {
        $raw = $request->getContent();

        if (! $signature->verify($raw, $request->header(PaystackSignature::HEADER))) {
            // 401 and nothing else. No detail about what failed: a signature endpoint that explains
            // itself is an oracle for guessing at it.
            return response()->json(['status' => 'unauthorised'], 401);
        }

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            // Signed but unreadable. This is the one case where the delivery is genuinely malformed,
            // and it means our shared secret signed something we cannot parse — worth a 400 so it
            // shows up in Paystack's own delivery log rather than being silently accepted.
            return response()->json(['status' => 'unreadable'], 400);
        }

        $event = is_string($body['event'] ?? null) ? $body['event'] : null;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;

        if ($reference === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'no reference'], 200);
        }

        // THE SCHOOL COMES FROM THE REFERENCE, AND THE SCOPE IS NEVER SWITCHED OFF. See
        // GatewayReference: routing on a segment of the reference lets the lookup run INSIDE the
        // school's context, so a delivery naming one school cannot read another's row even if the
        // reference were forged. Removing the global scope and adopting whatever school the row
        // named would have had the boundary trusting the row it exists to guard.
        $schoolId = GatewayReference::schoolIdFrom($reference);

        if ($schoolId === null) {
            // Not a reference this system minted — a dashboard test delivery, or another
            // integration on the same Paystack account. 200: redelivery will not change it.
            return response()->json(['status' => 'ignored', 'reason' => 'unknown reference'], 200);
        }

        $transaction = ActiveSchool::runFor($schoolId, fn () => GatewayTransaction::query()
            ->where('provider', 'paystack')
            ->where('reference', $reference)
            ->first());

        if ($transaction === null) {
            // A reference this system never issued. 200, because redelivery will not make it exist.
            Log::warning('paystack.webhook.unknown_reference', ['event' => $event]);

            return response()->json(['status' => 'ignored', 'reason' => 'unknown reference'], 200);
        }

        $outcome = ActiveSchool::runFor(
            $schoolId,
            fn () => $settle->handle($transaction, GatewayTransactionEvent::SOURCE_WEBHOOK, $event, $body),
        );

        if ($outcome === GatewaySettlementOutcome::FeeNotReported) {
            // The provider said success without saying what it took, so the net is unknowable and
            // the row stays pending for step 7's discrepancy report. Logged because nothing else
            // will surface it until that report runs.
            Log::warning('paystack.webhook.fee_not_reported', ['transaction' => $transaction->getKey()]);
        }

        return response()->json(['status' => 'recorded', 'outcome' => $outcome->value], 200);
    }
}
