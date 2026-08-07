<?php

namespace App\Notifications\Enums;

/**
 * The notification vocabulary. PUBLIC — other modules name these in contracts.
 *
 * A case here is vocabulary, NOT a promise that the type is implemented. The
 * NotificationRegistry holds the definitions, and dispatching a type with no
 * definition throws rather than silently sending nothing. So a case may be
 * declared a phase before its resolver and templates exist.
 *
 * APPROVALS ARE TWO TYPES, NOT ONE PER FAMILY. `ApprovalAbility` already derives
 * the whole maker–checker vocabulary by convention (terminal `approve`/`reject`,
 * matching maker `submit`), so credit notes, invoice voids, discount-policy
 * changes, fee-schedule changes and result approvals all emit APPROVAL_REQUESTED
 * parameterised by the checker ability. A new approval family the day it is added
 * needs no new notification type, exactly as it needs no new bypass-exclusion
 * entry.
 */
enum NotificationType: string
{
    // ── Approvals (v1) ──────────────────────────────────────────────────────
    case APPROVAL_REQUESTED = 'approval.requested';
    case APPROVAL_DECIDED = 'approval.decided';

    // ── Academics (v1) ──────────────────────────────────────────────────────
    case RESULT_READY = 'result.ready';
    case RESULT_COMMENTS_SUBMITTED = 'result.comments.submitted';

    // ── Finance (v2) ────────────────────────────────────────────────────────
    // State transitions that change what the payer owes or must do. Deliberately
    // NOT "any invoice update": a draft line-item edit notifies nobody, because
    // the payer has never seen the draft, and on SMS every edit costs money.
    case INVOICE_ISSUED = 'finance.invoice.issued';
    case INVOICE_SETTLED = 'finance.invoice.settled';
    case INVOICE_VOIDED = 'finance.invoice.voided';
    case INVOICE_AMENDED = 'finance.invoice.amended';
    // NO DISPATCHER TODAY — this case is declared and nothing sends it. WHOEVER WIRES IT owes the
    // migrated-payment refusal: a payment with origin = 'migrated' was collected by WCBS before the
    // cutover and nobody at Brookstone handed that parent this system's receipt, so a confirmation
    // must be REFUSED for it with a stated reason, never silently skipped. Same obligation on any
    // receipt PDF, printable page or export over finance_payments — see
    // docs/handoff/opening-balance-import-spec.md §4, "THE RECEIPT REFUSAL IS OWED, NOT BUILT".
    case PAYMENT_RECEIVED = 'finance.payment.received';

    // ── Account (v2) ────────────────────────────────────────────────────────
    case PROFILE_DETAILS_UPDATED = 'profile.details.updated';
    case ACCOUNT_CREATED = 'account.created';

    // ── Operations (v2) ─────────────────────────────────────────────────────
    case IMPORT_COMPLETED = 'import.completed';
    case EXPORT_READY = 'export.ready';
}
