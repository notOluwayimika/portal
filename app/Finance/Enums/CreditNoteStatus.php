<?php

namespace App\Finance\Enums;

/**
 * The maker-checker lifecycle of a credit note (Ph3). A credit note is created directly
 * in Submitted (a complete proposal — unlike a result there is no draft accumulation),
 * then a checker ≠ maker moves it to a TERMINAL Approved or Rejected. Money moves ONLY
 * on Approved; Rejected and Submitted never touch the ledger.
 *
 * Legal transitions live on the model (CreditNote::TRANSITIONS + canTransitionTo),
 * mirroring the result workflow's state machine.
 */
enum CreditNoteStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
