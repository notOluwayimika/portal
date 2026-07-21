<?php

namespace App\Finance\Enums;

/**
 * What an invoice line MEANS. The sign of its amount carries the arithmetic.
 *
 * `charge` is the pre-existing default — every line written before this enum existed is
 * one, which is what makes the `kind` column additive with no backfill.
 *
 * `waiver` and `discount` are the same MECHANISM differing only in reason (§6: "whether
 * it is waived, discounted, or charged in full is a manual per-case adjustment"). They
 * are modelled as one shape with two labels rather than two shapes, and the optional
 * `note` carries the human "why".
 *
 * DELIBERATELY ABSENT: credit note / write-off. Those are POST-ISSUANCE adjustments
 * (§10, Ph2+), and the difference is structural, not cosmetic — a post-issuance line
 * would have to be inserted into an invoice whose total is already frozen, which is the
 * one thing this design must not make reachable. See InvoiceLineSpec for why.
 */
enum InvoiceLineKind: string
{
    case Charge = 'charge';
    case Waiver = 'waiver';
    case Discount = 'discount';

    /**
     * Reductions carry a negative amount; charges carry a positive one.
     *
     * This is the ONLY place `kind` is consulted for arithmetic intent, and even here it
     * is used to VALIDATE the sign, never to compute with it — the total fold is a
     * literal signed SUM that does not branch on kind. Sign carries the arithmetic,
     * kind carries the meaning.
     */
    public function isReduction(): bool
    {
        return $this !== self::Charge;
    }
}
