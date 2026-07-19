<?php

namespace App\Finance\Enums;

/**
 * Invoice lifecycle. Deliberately two states: an invoice is ISSUED on creation
 * (no draft/approval — maker-checker is Ph3) and VOID once reversed.
 *
 * VOID is the signed accounting policy's word (docs/finance/accounting-policy.md):
 * you do not erase a mistake, you post a correction. Voiding is a status change
 * plus a reversing ledger entry — never a row deletion (the DELETE-deny trigger
 * enforces that at the DB).
 *
 * ISSUED is the ACTIVE state, and the `active_enrollment_key` generated column
 * keys on it — so any future non-issued state (DRAFT, REJECTED, Ph3) automatically
 * frees the enrollment's active slot without touching that invariant.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Void = 'void';
}
