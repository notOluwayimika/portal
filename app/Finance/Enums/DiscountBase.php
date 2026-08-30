<?php

namespace App\Finance\Enums;

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;

/**
 * WHAT a percentage discount policy takes its percentage OF. Axis C, and the only axis on
 * `finance_discount_policies` that changes an ARITHMETIC result rather than who may apply a policy.
 *
 * It is meaningful ONLY on a `percent`-basis policy. An `amount`-basis policy carries a naira figure
 * and has nothing to take a percentage of, so its `base` is inert — see the migration for why the
 * column is still NOT NULL there rather than nullable.
 *
 *   Discountable — the percentage applies to the charge lines whose fee item is `is_discountable`.
 *                  This is what EVERY policy did before this enum existed, which is why it is the
 *                  backfill value and the column default: the migration is stating a fact about the
 *                  existing rows, not guessing one.
 *
 *   Total        — the percentage applies to ALL charge lines, discountable or not. Brookstone's
 *                  BSS scheme needs both: "50% off tuition only" and "50% off the whole bill" are
 *                  different offers, and before this axis existed only the first was expressible.
 *
 * THE DISTINCTION IS OBSERVABLE ONLY WHEN THE TWO BASES DISAGREE — a schedule whose every mandatory
 * item is discountable gives the same answer either way. That is a fixture warning as much as a
 * doc note: a test built on such a schedule cannot detect a resolver that ignores this enum.
 *
 * IT IS NOT A PROPERTY OF A LINE. {@see InvoiceLineSpec} carries it beside `percent` and refuses to
 * carry it without one, and {@see GenerateInvoice::resolvePercentages()} reads it PER SPEC — two
 * percentage reductions on one invoice may legitimately sit on different bases.
 */
enum DiscountBase: string
{
    /** Charge lines whose fee item is discountable. Today's behaviour, and the default. */
    case Discountable = 'discountable';

    /** Every charge line on the invoice. */
    case Total = 'total';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
