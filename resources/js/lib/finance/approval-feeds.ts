import {
    approve as approveCredit,
    pending as pendingCredit,
    reject as rejectCredit,
} from '@/actions/App/Finance/Http/Controllers/CreditNoteController';
import {
    approve as approveDiscountChange,
    pending as pendingDiscountChange,
    reject as rejectDiscountChange,
} from '@/actions/App/Finance/Http/Controllers/DiscountPolicyChangeController';
import {
    approve as approveScheduleChange,
    pending as pendingScheduleChange,
    reject as rejectScheduleChange,
} from '@/actions/App/Finance/Http/Controllers/FeeScheduleChangeController';
import { pending as pendingOpeningBalance } from '@/actions/App/Finance/Http/Controllers/OpeningBalanceBatchController';
import {
    approve as approveVoid,
    pending as pendingVoid,
    reject as rejectVoid,
} from '@/actions/App/Finance/Http/Controllers/VoidRequestController';
import type { PendingApproval } from '@/types/finance';

/**
 * THE APPROVALS QUEUE'S FEEDS, DECLARED (§9 step 5a).
 *
 * WHAT THIS FILE EXISTS TO PREVENT. Before it, admin/finance/approvals.tsx imported two pending
 * feeds by hand while four were live and ability-gated at the API. `fee-schedule-changes/pending`
 * and `discount-policy-changes/pending` had shipped, were reachable, and were rendered NOWHERE —
 * an approver holding finance.fee-schedule.change.approve had no screen at all, and nothing said
 * so, because a page that cannot enumerate its feeds cannot notice one is missing. Two hardcoded
 * imports are not a small version of a list; they are the mechanism that lost two types.
 *
 * So the queue reads THIS, and only this. Adding a sixth type is one entry. Forgetting one is a
 * red test rather than a silence: ApprovalsQueueFeedCoverageTest
 * (tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php) walks the registered routes, takes
 * every `/api/v1/finance/**\/pending` it finds, and asserts this file imports that controller —
 * in BOTH directions, so a feed that exists and is unrendered fails, and so does an entry whose
 * route has gone. The test parses this source as text (the precedent is
 * NotificationDeepLinkRouteTest, which reads use-notifications.ts the same way, for the same
 * reason: the alternative is a dead surface discovered in production). Two consequences worth
 * knowing before editing: the per-controller `from '@/actions/App/Finance/Http/Controllers/X'`
 * import lines are LOAD-BEARING, and this list must live in its own module rather than inside the
 * page, so that what the test reads is a declaration and not a component.
 *
 * DECISION URLS ARE OPTIONAL, and that is the honest shape rather than a convenience. A type whose
 * decisions are taken elsewhere carries `decidedElsewhere` instead of `decide`, and the queue
 * renders that sentence in place of buttons. Opening-balance batches are the live case: §9 step 4c
 * shipped their approval gate as domain only — there is no approve/reject endpoint and no policy
 * until step 5b's operator screen. Rendering them with Approve/Reject would produce a row an
 * approver can see, press and fail on, which is worse than an absent one: absent is honestly
 * broken, present-and-dead is dishonestly broken. Step 5b turns this into a decidable row by
 * giving this one entry a `decide`.
 */
export type ApprovalFeed = {
    /** The `type` discriminator the matching Resource emits. */
    type: PendingApproval['type'];
    /** The type badge's text. */
    label: string;
    /** The type badge's colour classes (light + dark). */
    badgeClass: string;
    /** GET — the pending feed. Every one answers `{"data": [...]}`. */
    pendingUrl: () => string;
    /** POST urls for the decision. Absent ⇒ this queue does not decide this type. */
    decide?: {
        approve: (id: string) => string;
        reject: (id: string) => string;
        /** Past-tense sentence for the approve toast, e.g. "invoice voided". */
        approvedMessage: string;
    };
    /** Where this type IS decided, when `decide` is absent. Shown in place of the buttons. */
    decidedElsewhere?: string;
    /** The row's human handle in the Request column. */
    rowLabel: (row: PendingApproval) => string;
};

export const APPROVAL_FEEDS: ApprovalFeed[] = [
    {
        type: 'credit_note',
        label: 'Credit note',
        badgeClass:
            'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-400',
        pendingUrl: () => pendingCredit.url(),
        decide: {
            approve: (id) => approveCredit.url(id),
            reject: (id) => rejectCredit.url(id),
            approvedMessage: 'credit applied',
        },
        rowLabel: (row) =>
            row.type === 'credit_note' ? row.display_number : '—',
    },
    {
        type: 'void',
        label: 'Void',
        badgeClass:
            'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
        pendingUrl: () => pendingVoid.url(),
        decide: {
            approve: (id) => approveVoid.url(id),
            reject: (id) => rejectVoid.url(id),
            approvedMessage: 'invoice voided',
        },
        rowLabel: (row) =>
            row.type === 'void'
                ? `Void · ${row.invoice_display_number ?? '—'}`
                : '—',
    },
    {
        type: 'fee_schedule_change',
        label: 'Fee schedule',
        badgeClass:
            'bg-sky-50 text-sky-700 dark:bg-sky-900/20 dark:text-sky-400',
        pendingUrl: () => pendingScheduleChange.url(),
        decide: {
            approve: (id) => approveScheduleChange.url(id),
            reject: (id) => rejectScheduleChange.url(id),
            approvedMessage: 'fee schedule change applied',
        },
        rowLabel: (row) =>
            row.type === 'fee_schedule_change'
                ? `Fee schedule · ${row.kind}`
                : '—',
    },
    {
        type: 'discount_policy_change',
        label: 'Discount policy',
        badgeClass:
            'bg-violet-50 text-violet-700 dark:bg-violet-900/20 dark:text-violet-400',
        pendingUrl: () => pendingDiscountChange.url(),
        decide: {
            approve: (id) => approveDiscountChange.url(id),
            reject: (id) => rejectDiscountChange.url(id),
            approvedMessage: 'discount policy change applied',
        },
        rowLabel: (row) =>
            row.type === 'discount_policy_change'
                ? `Discount · ${row.name ?? row.kind}`
                : '—',
    },
    {
        type: 'opening_balance',
        label: 'Opening balance',
        badgeClass:
            'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-400',
        pendingUrl: () => pendingOpeningBalance.url(),
        // No `decide`: see the module docblock — the decision surface is §9 step 5b.
        decidedElsewhere: 'Decided on the opening-balance batch screen',
        rowLabel: (row) =>
            row.type === 'opening_balance'
                ? `Batch · ${row.batch_reference}`
                : '—',
    },
];

/** The feed a row belongs to. Undefined only for a `type` no feed declares. */
export function feedFor(row: PendingApproval): ApprovalFeed | undefined {
    return APPROVAL_FEEDS.find((feed) => feed.type === row.type);
}

/** The row's human handle, via its own feed. */
export function rowLabel(row: PendingApproval): string {
    return feedFor(row)?.rowLabel(row) ?? '—';
}
