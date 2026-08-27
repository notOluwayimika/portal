<?php

namespace App\Finance\Enums;

use App\Finance\Models\Payment;

/**
 * The lifecycle of ONE checkout attempt at an online payment provider.
 *
 * IT IS NOT THE LIFECYCLE OF A PAYMENT, and the distinction is the reason this table exists at all.
 * `finance_payments` is append-only and records money this school HAS. A checkout attempt is a
 * conversation with a third party that may never produce money, may produce it hours later, or may
 * produce it twice over two webhook deliveries. Those states cannot live on an append-only row, so
 * they live here and the payment is written once, at the single moment the attempt becomes
 * `Success`.
 *
 * FOUR VALUES, AND THE SET IS THE DATABASE'S, NOT THIS FILE'S. The authority is the trigger pair
 * `finance_gateway_transactions_insert_guard` / `_update_guard`
 * (2026_08_27_100000_create_finance_gateway_transactions.php), which admits exactly these four
 * spellings case-sensitively under `COLLATE utf8mb4_bin`. This enum is a second READER of that rule,
 * never a second copy of it — the same relationship {@see Payment}'s `ORIGIN_*`
 * constants have with the origin pairing trigger. A fifth value needs a migration, not an edit here.
 *
 * WHY A `string` BACKING AND NOT THE PROVIDER'S OWN VOCABULARY. Paystack calls a settled transaction
 * `success` and this enum agrees with it, deliberately: a reconciliation reads a provider dashboard
 * in one window and this system in the other, and a translation layer between the two words is a
 * place for them to disagree. Where the provider has a state this system does not model
 * (`reversed`, `ongoing`, `queued`), the mapping is made explicitly at the edge that reads the
 * webhook — it is not smuggled in by adding a case here.
 */
enum GatewayTransactionStatus: string
{
    /**
     * Initiated with the provider; no terminal answer has been received. The state every row is
     * born in and the ONLY state from which money may still be recorded.
     */
    case Pending = 'pending';

    /**
     * The provider says the money is collected. TERMINAL AT THE DATABASE, not by convention: the
     * update guard denies every further UPDATE of a row in this state.
     *
     * It is terminal because reaching it is what writes the `finance_payments` row, and that row can
     * never be unwritten. A second webhook delivery for the same reference — which providers make no
     * promise against — must therefore find a row it cannot move, rather than a row it can flip back
     * to `Pending` and settle a second time.
     */
    case Success = 'success';

    /**
     * The provider says the attempt failed — a declined card, an insufficient balance, a timeout it
     * has given up on.
     *
     * NOT TERMINAL, and that is measured against how these providers actually behave rather than how
     * the word reads: a payer whose card declines may complete the SAME reference by transfer
     * minutes later, and the provider then reports success on a reference it previously reported
     * failed. Freezing this state would leave that money invisible to the portal and visible only on
     * a bank statement, which is the discrepancy the reconciliation report exists to hunt — so the
     * guard permits `failed` → `success` and denies only the return to `Pending`.
     */
    case Failed = 'failed';

    /**
     * The payer opened the checkout and never completed it. Distinct from `Failed` because nothing
     * was attempted, so nothing was declined — and a report that cannot tell "the card was refused"
     * from "the parent closed the tab" cannot tell a bursar which of the two to follow up.
     *
     * Not terminal, for the same reason `Failed` is not: an abandoned reference is exactly the one a
     * payer is most likely to return to.
     */
    case Abandoned = 'abandoned';
}
