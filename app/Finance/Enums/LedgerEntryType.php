<?php

namespace App\Finance\Enums;

/**
 * The kinds of movement in the per-student subledger (receivables). The skeleton
 * posts three; the sign convention is a signed Money amount on the row:
 *
 *   Charge    (+) an invoice increases what the student owes  — source: invoice
 *   Payment   (−) a payment allocation reduces it             — source: allocation
 *   Reversal  (−) cancelling an invoice reverses its charge   — source: invoice
 *
 * A student's outstanding balance is SUM(amount_minor) over their rows. There is no
 * GL/journal here — that is §13, a later phase (subledger only, day-one rule 5).
 */
enum LedgerEntryType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Reversal = 'reversal';
}
