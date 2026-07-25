<?php

namespace App\Finance\Enums;

/**
 * The maker-checker lifecycle of an invoice void request (Ph3b). Created directly in
 * Submitted (a complete proposal with a reason — like a credit note, there is no draft to
 * accumulate), then a checker ≠ maker moves it to a TERMINAL Approved or Rejected. The
 * invoice is voided and its reversal posted ONLY on Approved; Submitted and Rejected never
 * touch the invoice or the ledger.
 *
 * Deliberately mirrors CreditNoteStatus (both decision states terminal, no `draft`): a void
 * request is a one-action proposal, and rework is a fresh submit — the rejected request is
 * retained for audit. Legal transitions live on VoidRequest::TRANSITIONS.
 */
enum VoidRequestStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
