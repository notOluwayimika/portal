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
    case PAYMENT_RECEIVED = 'finance.payment.received';

    // ── Account (v2) ────────────────────────────────────────────────────────
    case PROFILE_DETAILS_UPDATED = 'profile.details.updated';
    case ACCOUNT_CREATED = 'account.created';

    // ── Operations (v2) ─────────────────────────────────────────────────────
    case IMPORT_COMPLETED = 'import.completed';
    case EXPORT_READY = 'export.ready';
}
