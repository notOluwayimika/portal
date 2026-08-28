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
 * ── WHY FOUR AND NOT THE FIVE THE BOUNDARY NAMES ────────────────────────────────────────────────
 *
 * Boundary §5 names five states; `initialised` is the one absent here, and its absence is a decision
 * rather than an omission. A row is INSERTED at initiation — that write IS the initialise — so a
 * distinct `initialised` case would be a state no transaction ever occupies for the duration of a
 * single statement, and `pending` would mean "initialised and still waiting", which is the same
 * thing said twice. The same reasoning kept `approved` out of {@see OpeningBalanceBatchStatus} where
 * approval posts in the same transaction.
 *
 * WHAT WOULD CHANGE THE DECISION, so it is checkable rather than merely asserted: if initiation ever
 * becomes two steps — a row written BEFORE the provider has accepted the checkout, and the
 * provider's acceptance arriving separately — then `initialised` and `pending` describe genuinely
 * different states and the fifth case is owed. That is a migration plus a trigger change, and the
 * database is what refuses it until then.
 *
 * ── NON-TERMINAL IS NOT THE SAME AS UNRESOLVED, and the discrepancy report depends on it ────────
 *
 * Three of these four are non-terminal in the DATABASE sense: the update guard will still let their
 * row change. Only `Pending` is unresolved in the BUSINESS sense — the one state where this system
 * is still waiting to be told something.
 *
 * The distinction is written down here because §6 step 7's report asks for transactions "stuck in a
 * non-terminal state beyond a stated age", and read as the database sense that query returns every
 * abandoned checkout that ever happened, for ever. A report nobody can read is a report nobody
 * reads. **The stuck query is over `Pending` only.** `Failed` and `Abandoned` are ANSWERED — the
 * provider told us the outcome — and they stay writable solely because that answer can be
 * superseded, not because anyone is waiting on them.
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
     * The provider says the money is collected. TERMINAL AT THE DATABASE FOR STATUS, not by
     * convention: the update guard refuses to let a row in this state become anything else.
     *
     * It is terminal because reaching it is what writes the `finance_payments` row, and that row can
     * never be unwritten. A second webhook delivery for the same reference — which providers make no
     * promise against — must therefore find a status it cannot move, rather than one it can flip
     * back to `Pending` and settle a second time.
     *
     * TERMINAL FOR STATUS, NOT FOR THE ROW, and the difference is load-bearing: SETTLEMENT HAPPENS
     * AFTER SUCCESS. The fee, the settlement reference and the settlement date are all reported days
     * later, into the same row, and a guard that froze it here would make boundary §5's required
     * columns unwritable. What protects the row instead is that every provider-reported fact is
     * write-once — NULL to a value, never a value to another value.
     */
    case Success = 'success';

    /**
     * The provider says the attempt failed — a declined card, an insufficient balance, a timeout it
     * has given up on.
     *
     * NOT TERMINAL, and the reason is how these providers are UNDERSTOOD to behave — not something
     * measured here, and the word "measured" is deliberately not used: no provider contract was read
     * and no gateway was observed doing it. The reasoning is that a payer whose card declines may
     * complete the SAME reference by transfer minutes later, and the provider then reports success on
     * a reference it previously reported failed. If Paystack's contract says otherwise, tightening
     * this is a one-line trigger change in a new migration. Freezing this state would leave that money invisible to the portal and visible only on
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
