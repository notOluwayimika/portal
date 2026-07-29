<?php

namespace App\Finance\Enums;

/**
 * The lifecycle of a fee schedule (S1). A schedule is authored as a `draft` (items freely editable), moves
 * to `pending_approval` the moment a Head submits it for publication (S1 4a — items FROZEN by the three
 * finance_fee_items_parent_state_guard triggers, so the ED approves exactly what was shown), then `active`
 * once approved (still frozen; at most one active per school/term/class level), and finally `superseded`
 * when re-priced or `retired` when withdrawn. Money is never mutated — only `status` moves.
 *
 * `pending_approval` is NAMED distinctly from FeeScheduleChangeStatus::Submitted deliberately: the schedule
 * awaiting approval and the change request proposing it are different rows, and the two must never have to
 * be disambiguated in prose or an error message. A rejected publish returns the schedule to `draft`.
 */
enum FeeScheduleStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Superseded = 'superseded';
    case Retired = 'retired';
}
