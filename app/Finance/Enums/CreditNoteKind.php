<?php

namespace App\Finance\Enums;

/**
 * The kind of post-issuance credit against an invoice (§10 C1). Both post the SAME
 * compensating ledger credit through SubledgerPoster — the distinction is intent and
 * reporting, not mechanism:
 *
 *   CreditNote  a deliberate reduction of what the student owes (goodwill, correction,
 *               an over-charge acknowledged) — the money is forgiven, not collected.
 *   WriteOff    the receivable is judged uncollectable and removed. Same ledger effect;
 *               a distinct label so a write-off is reportable apart from a credit note.
 *
 * DELIBERATELY TWO. Percentage credits are C2 (they need Money::allocate); a void of a
 * credit note is a later opposing entry, not a state here.
 */
enum CreditNoteKind: string
{
    case CreditNote = 'credit_note';
    case WriteOff = 'write_off';
}
