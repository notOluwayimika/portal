// The parent portal's finance read contract — GET /api/parent/finance/wards.
//
// THESE TYPES ARE TRANSCRIBED FROM THE ENDPOINT, NOT FROM A DESIGN. The shape below is pinned as an
// EXACT key set by tests/Feature/Finance/ParentPortalFinanceReadTest.php:334,344-345 — a field added
// server-side fails there before it can reach this file. So this is a mirror of a frozen contract
// rather than a guess at one, and it is the only description of the wire shape the portal is allowed
// to build against.
//
// IN PARTICULAR IT IS NOT `parent/dashboard.tsx`. That file is a 59KB visual draft carrying
// `outstanding_balance: 185000` as a bare literal, a "Fee Balance" card and a "Clear Balance" button.
// Its props are a picture of a screen, not an API: the number is invented, the key does not exist on
// this contract, and money there is rendered with `.toLocaleString()`, which
// `bin/ci-money-lint.php` bans outside `resources/js/lib/format.ts`. Use it for layout inspiration
// and take no data shape and no figure from it. (It is also not dead code — `parent/wards.tsx`
// imports `NoticesCard` and `QuickContactCard` from it — so it stays exactly as it is.)

import type { Money } from './finance';

export type { Money };

/**
 * Who the ward is, and nothing more. The endpoint deliberately carries no date of birth, no class
 * and no admission number: a payment surface needs to tell one child from another, which two fields
 * do. Richer ward data belongs to `/api/parent/wards`, behind its own decision.
 */
export interface FinanceWardStudent {
    /** The student's uuid — never the integer id. */
    id: string;
    name: string;
}

/**
 * One line of a bill as a PAYER sees it — the three fields `GuardianInvoiceLineResource` admits,
 * and no more. `id`, `note`, `fee_item_id`, `bank_account_id` and the rest are refused server-side;
 * this type is the wire shape rather than a subset chosen here.
 */
export interface FinanceWardInvoiceLine {
    /** WHAT the charge is — "Tuition", "Development levy". Not re-worded for parents. */
    description: string;
    /** `charge`, `waiver` or `discount` — what the line MEANS. The SIGN below is what it DOES. */
    kind: string;
    /** SIGNED. Negative on a reduction, and the lines sum to `total` on the invoice. */
    amount: Money;
}

/**
 * One invoice as a PAYER sees it. Deliberately not the staff invoice shape: no `status`, no
 * `settlement_state`, and none of the bursar's eligibility flags.
 *
 * `lines` WAS in that refused list, on the reasoning that a payer needs only what lets them decide
 * and pay. Segun overruled it on 5 September 2026: a parent asked for a term's fees may see what
 * they comprise, discounts included. The old reasoning is recorded rather than deleted in
 * `GuardianInvoiceResource`'s docblock, which is where it was argued.
 */
export interface FinanceWardInvoice {
    /** The invoice uuid — the identifier a payment would be initiated against. */
    id: string;
    /** What the parent sees on the document, e.g. "BSS-000042". Already prefixed server-side. */
    display_number: string;
    /** `scheduled` (the term bill) or `supplementary` (a one-off). An episode may carry both. */
    kind: string;
    /** Which term/class this belongs to. */
    academic_context: string;
    /** What was billed. */
    total: Money;
    /** What is still owed — the figure the parent is being asked for. */
    outstanding: Money;
    /**
     * What the bill is MADE OF — charges and any reductions, signed, summing to `total`.
     *
     * Optional because `whenLoaded` omits the key entirely when the relation was not eager-loaded,
     * and that is a real state rather than a theoretical one: it is exactly the defect this feature
     * hit in its first hour, where a missing `->with('lines')` would have rendered a breakdown of
     * zero rows on a bill with three. Typed as absent-or-present so a consumer has to decide.
     */
    lines?: FinanceWardInvoiceLine[];
}

/**
 * The ACCOUNT-level position, which is not derivable from the invoice list and is the whole reason
 * this key exists.
 *
 * `balance` is signed and the sign is the meaning: positive is owed TO the school, and **negative
 * means the school owes the parent**. `available_credit` is where money in hand shows up — a parent
 * in credit has no outstanding invoice to display it on, so a screen that rendered invoices alone
 * would report their position as zero while the school in fact holds their money.
 */
export interface FinanceWardAccount {
    balance: Money;
    available_credit: Money;
}

/** One ward's complete finance position. */
export interface FinanceWard {
    student: FinanceWardStudent;
    /**
     * Outstanding invoices only, and **an empty array is a real state, not an absence**: it means a
     * ward with nothing owed. The ward still appears, because dropping it would make "paid up" and
     * "not your child" the same response. Render it as "nothing outstanding".
     */
    invoices: FinanceWardInvoice[];
    account: FinanceWardAccount;
}

/** The endpoint's envelope. An empty `data` means this guardian holds no wards in the active school. */
export interface FinanceWardsResponse {
    data: FinanceWard[];
}
