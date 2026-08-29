import type { FinanceWard, Money } from '@/types/parent-finance';

/**
 * WHAT THE PARENT'S FEES SCREEN MUST SAY ABOUT ONE WARD.
 *
 * ── WHY THIS IS A MODULE AND NOT THREE TERNARIES IN THE PAGE ─────────────────────────────────────
 *
 * Same argument as `rollover-batch-status.ts`: extract the part this repository decides so it can be
 * asserted without a DOM, and leave the page to map the result to colour and copy. But there is a
 * second reason here, specific to this screen.
 *
 * The visual reference for the parent portal (`resources/js/pages/parent/dashboard.tsx`, 59KB) has no
 * concept of either state below. It models a ward as a single `outstanding_balance: number` — one
 * non-negative figure, invented, in minor units, rendered with `.toLocaleString()`. Built from that
 * picture, this screen would get BOTH of the following wrong, and both wrongly in the direction that
 * looks fine:
 *
 *   1. **An empty invoice list is a real ward with nothing owed, not an absent ward.** The endpoint
 *      returns every ward and carries `invoices: []` when there is nothing outstanding, precisely so
 *      that "paid up" and "not your child" are different responses. A screen that renders wards by
 *      iterating invoices drops the paid-up child off the page entirely, and the parent concludes the
 *      school has lost their record.
 *
 *   2. **A negative balance means the SCHOOL OWES THE PARENT**, and `available_credit` is the only
 *      place that money is visible — a parent in credit has no outstanding invoice for it to sit on.
 *      A screen that only knows how to say "outstanding" reports their position as zero while the
 *      school is holding their money. `outstanding_balance: number` cannot express it: the draft's
 *      own shape has no sign and no second figure.
 *
 * Neither is exotic and neither announces itself — which is why they are pinned in a test rather than
 * left to the rendering.
 *
 * NOTHING HERE DOES MONETARY ARITHMETIC. Every figure is one the server derived; this module only
 * compares against zero to decide which sentence to show. `bin/ci-money-lint.php` bans UI-side money
 * arithmetic, and comparing a signed integer to 0 is a predicate, not a computation.
 */

/** Positive: the parent owes the school. Negative: the school owes the parent. */
export function isInCredit(balance: Money): boolean {
    return balance.amount_minor < 0;
}

/**
 * Is there money on the account not yet applied to a bill? Shown WHENEVER there is any, not only
 * when the invoice list is empty — a parent can hold credit and owe on a newer invoice at once, and
 * this is the only surface the credit appears on.
 */
export function hasAvailableCredit(credit: Money): boolean {
    return credit.amount_minor > 0;
}

/** What the ward's invoice section resolves to. */
export type WardInvoiceView =
    /** A real ward with nothing owed. Must still render, saying so. */
    | { kind: 'nothing-outstanding' }
    /** One or more outstanding invoices, in the order the server sent them. */
    | { kind: 'invoices'; count: number };

export interface WardView {
    /** The ward's uuid — the React key, and never the integer id. */
    id: string;
    name: string;
    invoices: WardInvoiceView;
    /** True when the school owes this parent. */
    inCredit: boolean;
    /** True when unapplied credit exists and the credit line must be shown. */
    showCredit: boolean;
}

export function wardView(ward: FinanceWard): WardView {
    return {
        id: ward.student.id,
        name: ward.student.name,
        invoices:
            ward.invoices.length === 0
                ? { kind: 'nothing-outstanding' }
                : { kind: 'invoices', count: ward.invoices.length },
        inCredit: isInCredit(ward.account.balance),
        showCredit: hasAvailableCredit(ward.account.available_credit),
    };
}

/** What the page as a whole resolves to, once the request has settled. */
export type WardsView =
    /**
     * The guardian holds no wards IN THE ACTIVE SCHOOL. A legitimate state — they may hold wards in
     * a school they have not switched to — and explicitly NOT an error, which is why it is a distinct
     * case rather than an empty list rendered by the same branch as a failure.
     */
    { kind: 'no-wards' } | { kind: 'wards'; wards: WardView[] };

export function wardsView(wards: FinanceWard[]): WardsView {
    if (wards.length === 0) {
        return { kind: 'no-wards' };
    }

    return { kind: 'wards', wards: wards.map(wardView) };
}
