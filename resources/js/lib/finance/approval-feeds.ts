import {
    approve as approveCredit,
    decided as decidedCredit,
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
import {
    approve as approveOpeningBalance,
    pending as pendingOpeningBalance,
    reject as rejectOpeningBalance,
} from '@/actions/App/Finance/Http/Controllers/OpeningBalanceBatchController';
import {
    approve as approveVoid,
    decided as decidedVoid,
    pending as pendingVoid,
    reject as rejectVoid,
} from '@/actions/App/Finance/Http/Controllers/VoidRequestController';
import { formatNaira } from '@/lib/format';
import type {
    DiscountPolicyChangeApproval,
    PendingApproval,
} from '@/types/finance';

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
 * route has gone. It also pins the per-entry `pendingUrl` ALIASES against the import aliases 1:1,
 * because import coverage alone stays green if one entry is pointed at another feed's url: every
 * import present, five entries, and one type silently fetched twice while another is fetched
 * never. The test parses this source as text (the precedent is NotificationDeepLinkRouteTest,
 * which reads use-notifications.ts the same way, for the same reason: the alternative is a dead
 * surface discovered in production). Two consequences worth knowing before editing: the
 * per-controller `from '@/actions/App/Finance/Http/Controllers/X'` import lines are LOAD-BEARING,
 * and this list must live in its own module rather than inside the page, so that what the test
 * reads is a declaration and not a component.
 *
 * `subject` IS REQUIRED, AND THAT IS THE RULE THIS LIST CARRIES. An approval row that cannot say
 * WHICH thing it is about voids the control it implements: maker-checker's premise is that a
 * second person looks at the thing, and a screen showing two rows that both read
 * "Fee schedule · publish" makes the second signature ceremonial. That state was live and
 * reachable — `open_key` forbids two open requests against the SAME schedule and permits any
 * number against different ones, so a checker really can face several at once. Making `subject` a
 * required member of ApprovalFeed means the sixth type cannot be added without answering the
 * question, and ApprovalsQueueFeedCoverageTest fails an entry that declares none — the rule is
 * enforced rather than remembered. Compose it from fields the Resource EMITS; if the parts are not
 * on the wire, put them there (both change resources gained `target_*` fields for exactly this).
 *
 * DECISION URLS ARE OPTIONAL, and that is the honest shape rather than a convenience. A type whose
 * decisions are taken elsewhere carries `decidedElsewhere` instead of `decide`, and the queue
 * renders that sentence in place of buttons — because a row an approver can see, press and fail on
 * is worse than an absent one: absent is honestly broken, present-and-dead is dishonestly broken.
 *
 * NO TYPE WITHHOLDS `decide` TODAY. Opening-balance batches were the live case and stopped being one
 * in §9 step 5b-ii, which gave them a Policy and an approve/reject endpoint; the option stays because
 * the RULE it carries outlives the example. That rule: `decide` may be withheld ONLY because the
 * endpoint does not exist. It is never the answer to a row that is hard to label — withholding it
 * there reproduces this branch's own defect, a visible row nobody can act on, and the fix for a hard
 * label is to put the subject on the wire.
 *
 * THE DECIDED FEED IS DECLARED HERE TOO (U13/U14), AND IT IS GATED MORE BROADLY THAN `pendingUrl`.
 * A `/pending` route carries its type's approve ability because it precedes an act; a `/decided`
 * route carries only the API group's `finance.access`, because reading what already happened is a
 * different capability from being trusted to decide it. Both live on the SAME entry rather than in a
 * second list, so a type cannot gain one and lose the other silently — and the decisions surface
 * (pages/admin/finance/decisions.tsx) consumes this list exactly as the approvals queue consumes
 * `pendingUrl`, with no per-type knowledge of its own.
 *
 * TWO OF THE FIVE CARRY ONE TODAY, AND THE OTHER THREE SAY SO IN WORDS. `decidedUrl` is optional and
 * `decidedNotImplemented` is required whenever it is absent, because a type nobody has built a
 * decided feed for and a type whose decided feed somebody FORGOT are indistinguishable in a list of
 * optional members — and one is a decision while the other is a defect. The coverage test asserts
 * exactly one of the two on every entry, so the distinction is enforced rather than described.
 *
 * `confirm` IS THE OTHER OPTIONAL MEMBER, AND IT IS NOT A UI PREFERENCE. It exists for approvals that
 * cannot be undone, and it is declared PER TYPE for the reason a blanket confirmation would defeat:
 * a dialog on every approval is a dialog nobody reads, which is how the one that matters gets clicked
 * through. Exactly one type carries it — opening-balance, whose approval posts the cutover under
 * triggers that deny every exit from `posted`. Reject carries none anywhere: nothing it does is
 * irreversible. And the dialog is a courtesy, never a control: the ability on the route, the Policy,
 * the Action's locked re-read and the database's CHECK all hold against a client that never renders it.
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
    /**
     * GET — the DECIDED feed: the same documents AFTER a checker approved or rejected them.
     * Every one answers `{"data": [...]}`, exactly as `pendingUrl` does.
     *
     * Absent ⇒ `decidedNotImplemented` MUST say why, and ApprovalsQueueFeedCoverageTest refuses an
     * entry carrying neither or both. That pairing is the whole design of this field: a type with no
     * decided feed and a type whose decided feed somebody forgot look identical in a list of
     * optional members, and the second one is a defect while the first is a decision.
     */
    decidedUrl?: () => string;
    /**
     * WHY this type has no decided feed yet — required exactly when `decidedUrl` is absent.
     *
     * It is a sentence and not a boolean because the reason is the thing worth reading: whoever adds
     * the missing feed needs to know what was deferred and what it costs, and a `false` tells them
     * nothing. Nothing consumes it at runtime; it exists to be read here and asserted by the test.
     */
    decidedNotImplemented?: string;
    /** POST urls for the decision. Absent ⇒ this queue does not decide this type. */
    decide?: {
        approve: (id: string) => string;
        reject: (id: string) => string;
        /** Past-tense sentence for the approve toast, e.g. "invoice voided". */
        approvedMessage: string;
    };
    /** Where this type IS decided, when `decide` is absent. Shown in place of the buttons. */
    decidedElsewhere?: string;
    /**
     * WHICH thing this row is about, in human terms. REQUIRED — see the module docblock. It must
     * distinguish two pending rows of the same type from each other; a bare type name does not.
     */
    subject: (row: PendingApproval) => string;
    /**
     * An APPROVAL that cannot be undone stops here first. Absent ⇒ Approve fires on the click, which
     * is the right default for the four types whose approval is correctable afterwards.
     *
     * Only ever consulted on APPROVE. A reject moves no money on any type.
     */
    confirm?: (row: PendingApproval) => ApprovalConfirmation;
};

/** What the queue puts in front of an irreversible approval. */
export type ApprovalConfirmation = {
    title: string;
    /** One paragraph per entry. The numbers a checker ties back go here, not in the title. */
    body: string[];
    /** The affirmative button's text. Name the ACT, never "Confirm". */
    confirmLabel: string;
};

/**
 * WHAT the percentage applies to, in the checker's words. Read from `effective_base` and NEVER from
 * `base`: the raw proposed term is null on the ordinary amend — a rate raised, the base unmentioned
 * — which is precisely the case where the checker cannot otherwise tell the two apart. The server
 * resolves it through the same method that stamps the catalog, so this string names the term that
 * will actually be written.
 *
 * THE ONE COPY OF THE TWO PHRASES, which is why it is exported. The maker states a base on the
 * catalog screen, the ED approves it here, and it is read back on the policy list — all three
 * through this function (discount-policies.tsx imports it for both its control and valueLabel), so
 * the phrase cannot be re-worded in one place and quietly differ in the others. It was two copies
 * held in agreement by a comment until the maker's own control needed a third; two was defensible
 * and three was not.
 *
 * TWO SIGNATURES, AND THE NARROW ONE IS NOT DECORATION. The catalog's `base` is NOT NULL on every
 * policy row, so its callers get a `string` and need no dead branch for a null that cannot arrive;
 * the approvals side passes `effective_base`, which IS null on a retire — an act that approves no
 * policy and so states no base — and gets the nullable return that fact deserves.
 */
export function baseLabel(base: 'discountable' | 'total'): string;
export function baseLabel(
    base: DiscountPolicyChangeApproval['effective_base'],
): string | null;
export function baseLabel(
    base: DiscountPolicyChangeApproval['effective_base'],
): string | null {
    if (base === 'total') {
        return 'of the whole bill';
    }

    if (base === 'discountable') {
        return 'of discountable charges';
    }

    return null;
}

/**
 * `10% of the whole bill`, or a fixed amount through the single money renderer. Null when the change
 * states neither.
 *
 * THE BASE IS PART OF THE RATE, not a detail beside it. "50%" is not a term a checker can approve:
 * half the tuition and half the whole bill are different amounts of money, and this queue's Subject
 * column is the only place the discount type says what it does. An unlabelled percentage here is the
 * checker-visibility hole, and it stayed open while the Resource emitted a key nothing rendered.
 *
 * Only on the percent side: an `amount` policy has no percentage to take of anything, so its base is
 * inert and printing it would invent a distinction the money does not have.
 */
function discountValue(row: DiscountPolicyChangeApproval): string | null {
    if (
        row.basis === 'percent' &&
        row.percent !== null &&
        row.percent !== undefined
    ) {
        const of = baseLabel(row.effective_base);

        return of === null ? `${row.percent}%` : `${row.percent}% ${of}`;
    }

    if (
        row.basis === 'amount' &&
        row.value_minor !== null &&
        row.value_minor !== undefined &&
        row.value_currency !== null &&
        row.value_currency !== undefined
    ) {
        return formatNaira({
            amount_minor: row.value_minor,
            currency: row.value_currency,
        });
    }

    return null;
}

export const APPROVAL_FEEDS: ApprovalFeed[] = [
    {
        type: 'credit_note',
        label: 'Credit note',
        badgeClass:
            'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-400',
        pendingUrl: () => pendingCredit.url(),
        decidedUrl: () => decidedCredit.url(),
        decide: {
            approve: (id) => approveCredit.url(id),
            reject: (id) => rejectCredit.url(id),
            approvedMessage: 'credit applied',
        },
        // The note's own number already names one document.
        subject: (row) =>
            row.type === 'credit_note' ? row.display_number : '—',
    },
    {
        type: 'void',
        label: 'Void',
        badgeClass:
            'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
        pendingUrl: () => pendingVoid.url(),
        decidedUrl: () => decidedVoid.url(),
        decide: {
            approve: (id) => approveVoid.url(id),
            reject: (id) => rejectVoid.url(id),
            approvedMessage: 'invoice voided',
        },
        // The invoice being reversed is the subject.
        subject: (row) =>
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
        // No `…/decided` route exists. The read side is one controller method and one route — the
        // same template U13 and U14 followed — but the GATE is not a template: a decided feed takes
        // the group's `finance.access`, and the same rows are ALREADY readable under it for credit
        // notes and voids (the statement's feed returns them in every status). A fee-schedule change
        // is readable today under `finance.fee-schedule.change.approve` and nowhere else, so putting
        // one on `finance.access` is a fresh exposure decision rather than a precedent applied.
        // Two model edits precede it too: FeeScheduleChange declares no `decided_at` and no
        // `decidedBy()` relation, though the column exists (2026_07_28_120000:50).
        decidedNotImplemented:
            'Fee-schedule changes have no decided feed yet — the route, the `decidedBy()` relation and the `decided_at` cast are unbuilt, and the exposure decision (a decided feed sits on finance.access) has not been taken for this type.',
        decide: {
            approve: (id) => approveScheduleChange.url(id),
            reject: (id) => rejectScheduleChange.url(id),
            approvedMessage: 'fee schedule change applied',
        },
        // The ACT plus the schedule it acts on. A schedule IS its (class level × term) pair, so
        // those identify it even when two carry the same author-written label. The uuid tail is
        // the last resort — ugly, and still better than two rows a checker cannot tell apart.
        subject: (row) => {
            if (row.type !== 'fee_schedule_change') {
                return '—';
            }

            const pair = [row.target_class_level, row.target_term]
                .filter(Boolean)
                .join(' · ');
            const named = [pair, row.target_label].filter(Boolean).join(' — ');
            const fallback = `#${row.target_schedule_id?.slice(0, 8) ?? 'unknown'}`;

            return `${row.kind} · ${named === '' ? fallback : named}`;
        },
    },
    {
        type: 'discount_policy_change',
        label: 'Discount policy',
        badgeClass:
            'bg-violet-50 text-violet-700 dark:bg-violet-900/20 dark:text-violet-400',
        pendingUrl: () => pendingDiscountChange.url(),
        // Same shape and same two reasons as the fee-schedule entry above: no route, no
        // `decidedBy()` relation, no `decided_at` cast (the column exists —
        // 2026_07_26_140001:49), and the exposure decision untaken.
        decidedNotImplemented:
            'Discount-policy changes have no decided feed yet — same position as fee-schedule changes: route, relation and cast unbuilt, and the finance.access exposure decision untaken for this type.',
        decide: {
            approve: (id) => approveDiscountChange.url(id),
            reject: (id) => rejectDiscountChange.url(id),
            approvedMessage: 'discount policy change applied',
        },
        // The ACT, the policy, and the rate or amount at stake. A create and an amend name
        // themselves; a RETIRE carries no name, no basis and no value at all (the `…_terms_shape`
        // CHECK forbids them), so its subject is the target policy's name and nothing else.
        subject: (row) => {
            if (row.type !== 'discount_policy_change') {
                return '—';
            }

            const value = discountValue(row);
            const policy =
                row.name ?? row.target_policy_name ?? 'unnamed policy';

            return `${row.kind} · ${policy}${value === null ? '' : ` · ${value}`}`;
        },
    },
    {
        type: 'opening_balance',
        label: 'Opening balance',
        badgeClass:
            'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-400',
        pendingUrl: () => pendingOpeningBalance.url(),
        // NOT THE SAME TEMPLATE, and that is why this one is listed separately rather than lumped in
        // with the two above. An opening-balance batch has no `approved` status at all — approval
        // goes straight to `posted` (OpeningBalanceBatchStatus) — so "decided" here is
        // {posted, rejected}, a different predicate from the other four's {approved, rejected}. And
        // its checker column is `decided_by_user_id`, a LOOKUP rather than an FK
        // (OpeningBalanceBatch.php:72), so it carries no `decidedBy()` relation to eager-load. A
        // decided feed for this type is a design question, not a copy of U13's method.
        decidedNotImplemented:
            'Opening-balance batches have no decided feed yet, and it is not the same build: there is no `approved` status (approval goes straight to `posted`), so the decided predicate differs, and the checker is a lookup id rather than an FK relation.',
        decide: {
            approve: (id) => approveOpeningBalance.url(id),
            reject: (id) => rejectOpeningBalance.url(id),
            // NOT "batch approved". Approving this type is not a state change with a posting to
            // follow — the post happens inside the same transaction, so the past tense has to name
            // what happened to the money or the toast understates the act it is reporting.
            approvedMessage: 'opening balances posted to the subledger',
        },
        // The batch reference is the operator's own handle for the file they uploaded.
        subject: (row) =>
            row.type === 'opening_balance'
                ? `Batch · ${row.batch_reference}`
                : '—',
        // THE ONE IRREVERSIBLE APPROVAL ON THIS QUEUE. It writes a ledger charge per positive
        // fee-type line and a netted migrated payment per student in credit, and two database
        // triggers then deny every exit from `posted` — UPDATE and DELETE alike. There is no
        // un-post, no delete and no second attempt; a wrong cutover is corrected by restoring the
        // database, which is a decision taken by people, outside this application.
        //
        // The figures are the ones the wire carries: the batch reference, and the control total the
        // operator read off WCBS's own report and typed at upload (§1's L2 witness). The row COUNT
        // would be the third number a checker wants and OpeningBalanceBatchResource does not emit
        // it; that is recorded in the implementation report rather than papered over here.
        confirm: (row) => ({
            title: 'Post this cutover? It cannot be undone.',
            body: [
                row.type === 'opening_balance'
                    ? `Approving batch ${row.batch_reference} posts its opening balances into the subledger immediately — a charge for every fee type a student owes, and a credit for every student in credit.`
                    : '',
                row.type === 'opening_balance' && row.amount
                    ? `The batch states a control total of ${formatNaira(row.amount)} — the figure the uploader read off WCBS and attested to. Check it against WCBS before you approve.`
                    : 'This batch carries no control total, so there is no figure to check it against.',
                'This is irreversible. Posted balances cannot be un-posted, deleted or moved to another school, and this school may never post a second batch.',
            ].filter((paragraph) => paragraph !== ''),
            confirmLabel: 'Post opening balances',
        }),
    },
];

/**
 * The feeds that HAVE a decided read — what the decisions surface fetches, the way the approvals
 * queue fetches every entry's `pendingUrl`.
 *
 * Derived rather than listed, for the reason APPROVAL_FEEDS itself exists: a second hardcoded list
 * is precisely the mechanism that lost two feeds off the approvals page.
 */
export type DecidedFeed = ApprovalFeed & { decidedUrl: () => string };

export const DECIDED_FEEDS: DecidedFeed[] = APPROVAL_FEEDS.filter(
    (feed): feed is DecidedFeed => feed.decidedUrl !== undefined,
);

/** The feed a row belongs to. Undefined only for a `type` no feed declares. */
export function feedFor(row: PendingApproval): ApprovalFeed | undefined {
    return APPROVAL_FEEDS.find((feed) => feed.type === row.type);
}

/** WHICH thing this row is about, via its own feed. */
export function rowSubject(row: PendingApproval): string {
    return feedFor(row)?.subject(row) ?? '—';
}
