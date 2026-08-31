<?php

namespace App\Finance\Exceptions;

use App\Finance\Services\PaystackClient;
use RuntimeException;

/**
 * WE ASKED PAYSTACK AND WE DO NOT KNOW THE ANSWER — a timeout, a refused connection, a 5xx, or a
 * body we could not read.
 *
 * ── THIS IS A THIRD OUTCOME, AND COLLAPSING IT INTO "FAILED" IS THE EXPENSIVE BUG ────────────────
 *
 * A verify call has three results, not two: the transaction succeeded, the transaction did not
 * succeed, and **we could not find out**. The third is not a variety of the second. If the caller
 * treats "we could not reach Paystack" as "the payment failed", then a parent who has genuinely paid
 * gets an attempt marked `failed`, no `finance_payments` row is written, and the money exists only on
 * a bank statement and in Paystack's dashboard — the exact discrepancy §6 step 7's report is built to
 * hunt, manufactured by the code meant to prevent it.
 *
 * So this is a THROW rather than a status. A status can be ignored by a caller that only branches on
 * success; an exception has to be handled, and the handling is: leave the attempt where it is and
 * ask again later. `finance_gateway_transactions` is designed for exactly this — `pending` is not
 * terminal, and a later verify or webhook may still move it.
 *
 * IT IS DELIBERATELY NOT A BusinessRuleException. Nothing about it is a business rule and there is
 * no field to name; it is an infrastructure fact about a third party, and rendering it to a payer as
 * a validation error would tell them they did something wrong.
 *
 * @see PaystackClient::verify()
 * The notifications module's `CallbackUnconfirmed` is the same shape, learned first on the outbound
 * callback path and for the same reason: an ambiguous outcome reported as a definite one is worse
 * than an error. (Named in prose rather than `@see`, so Pint does not turn it into an import that
 * breaks the notifications privacy arch rule.)
 */
final class PaystackUnavailable extends RuntimeException {}
