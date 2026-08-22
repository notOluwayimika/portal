<?php

namespace App\Finance\Exceptions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\AllocatePayment;

/**
 * A refusal from {@see AllocatePayment} that knows WHICH FIELD it is about.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT A PLAIN BusinessRuleException. Every refusal this Action makes
 * depends on state it can only read AFTER taking the student-account row lock — the payment's
 * remaining headroom, each invoice's outstanding, whether the position moved since the screen was
 * rendered. None of it can be checked in a FormRequest, because a FormRequest runs before the
 * transaction and would be answering from a snapshot the write does not use. But the operator is
 * editing a table of amounts, and "422: something is wrong" against a table of eight rows is not a
 * usable refusal — the brief's words are that a 1644 surfacing as a 500 is not one either, and a
 * message with no field is only marginally better.
 *
 * So the Action names the field, and the controller renders `errors` from it. The alternative
 * considered and rejected was throwing Laravel's ValidationException from the Action: no Action in
 * app/Finance does that today, and it would put HTTP-shaped validation inside a domain object that
 * queues and console commands also call.
 *
 * THIS IS NOT THE AUTHORITY ON ANY OF THESE RULES. The two allocation triggers
 * (`finance_allocation_not_over_payment_amount`, `finance_allocation_not_over_invoice_total`) and
 * `finance_allocation_provenance_pairing_bi` are, and they stay reachable: a writer that never enters
 * this Action still meets them, and gets 1644 rather than a sentence. This class is what turns the
 * ones an OPERATOR can trip into something they can act on.
 */
final class AllocationRefused extends BusinessRuleException
{
    /**
     * @param  string  $field  The request key this refusal is about, in the shape the client posted
     *                         it — `allocations`, `allocations.<invoice uuid>`, `override_reason` or
     *                         `fingerprint`. Rendered as the `errors` key, so a table row can show
     *                         its own message rather than a page-level banner.
     */
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
