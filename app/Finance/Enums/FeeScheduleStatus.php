<?php

namespace App\Finance\Enums;

use App\Finance\Services\FeeScheduleLineMapper;
use App\Finance\Services\FeeScheduleLookup;

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

    /**
     * THE BILLABLE SET — which lifecycle states a bill may be raised from, in ONE place that every
     * deciding site reads. There are two such sites today: {@see FeeScheduleLookup::activeFor()}
     * (the bursar's prefill read) and {@see FeeScheduleLineMapper::linesFor()}
     * (the bulk-run mapper).
     *
     * IT IS A SHARED SYMBOL RATHER THAN A SHARED COMMENT, and that distinction is the whole of this
     * method's justification. U6 commit 2 shipped the mapper testing `!== self::Active` as PHP enum
     * identity while the lookup tested `where('status', self::Active->value)` as SQL, with a docblock
     * on each asserting they were "one rule, not two". They were two rules that happened to agree:
     * same set, different layer, no shared symbol, nothing that could turn both red at once. Cold
     * review called it, on the same ruling #258's F2 landed on. Widening or narrowing this array now
     * moves BOTH sites, and a test asserting its contents is the thing that notices.
     *
     * WHY ONLY `active`. `draft` and `pending_approval` were never approved by the ED — a draft is a
     * proposal, not a price, and a rejected publish returns a pending schedule to `draft`, so pending
     * is undecided rather than nearly-active. `superseded` and `retired` WERE approved once and have
     * since been replaced or withdrawn; billing a cohort from one prices a whole year group off a list
     * the school has retired, silently and N invoices wide.
     *
     * @return list<self>
     */
    public static function billable(): array
    {
        return [self::Active];
    }

    /**
     * The billable set as column values, for a SQL predicate. `whereIn` over this rather than a
     * hand-written `where('status', 'active')`: identical while the set has one member, and it MOVES
     * when the set does, which a literal cannot.
     *
     * @return list<string>
     */
    public static function billableValues(): array
    {
        return array_map(fn (self $case) => $case->value, self::billable());
    }

    /** Whether a schedule in this state may be billed from — the in-PHP form of the same set. */
    public function isBillable(): bool
    {
        return in_array($this, self::billable(), true);
    }
}
