import type { Money } from '@/types/parent-finance';

/**
 * WHAT FINANCE'S RETURNED-BILLS QUEUE MUST SAY.
 *
 * Same argument as `internal-audit-queue.ts`, `parent-finance-view.ts` and
 * `rollover-batch-status.ts`: the decisions this repository makes live here, so they can be
 * asserted without a DOM, and the page maps the result to colour and copy. Vitest runs in `node`
 * (vitest.config.ts) precisely so that the parts worth pinning are the parts that are pure.
 *
 * THIS IS THE MIRROR OF `internal-audit-queue.ts` AND IT SHOULD READ AS ONE. The auditor returns a
 * bill; this is the only place Finance can see that they did.
 */

/** One row of GET /api/v1/finance/invoices/returned. */
export interface ReturnedBill {
    /**
     * THE ROW IDENTITY, and there is deliberately no uuid to fall back on.
     *
     * The invoice number is what the bill is called on paper and everywhere else in the system, it
     * is unique within a school, and it is the only handle an operator can act on. The endpoint
     * sends no uuid at all, so this file could not leak one even by accident.
     */
    number: number;
    /**
     * The payer, by name — `billed_to_name`, the snapshot taken at billing that every document for
     * this bill already renders. NOT a `student_id`, which is as unactionable as the uuid this
     * payload deliberately omits.
     */
    billed_to: string;
    kind: string;
    total: Money;
    issued_at: string;
    returned_at: string | null;
    /** The returner's NAME. NULL means the id resolved to no user — NOT that nobody returned it. */
    returned_by: string | null;
    /** What Finance is being asked to correct. Rendered IN FULL — see `reasonIsTruncated`. */
    return_reason: string | null;
}

/**
 * The two numbers, and the second one is the instrument.
 *
 * `oldest_waiting_days` IS SERVER-COMPUTED AND IS NOT RE-DERIVED HERE. A client-side age is
 * measured against whatever the operator's machine believes the time is, so a skewed laptop would
 * render a reassuring "1 day" over a bill that has waited a month — and the one field on this
 * screen whose whole job is to be alarming would be the one a wrong clock can silence. The same
 * rule `internal-audit-queue.ts` states for the counts invariant: a client that recomputed it would
 * be a second source of truth for the number this screen exists to be trusted on.
 */
export interface ReturnedCounts {
    /** How many bills are waiting. The SIZE. */
    returned_total: number;
    /**
     * How long the OLDEST has waited, in whole days, on the server's clock. NULL when the queue is
     * empty — there is no oldest, and 0 would claim there is one that arrived today.
     */
    oldest_waiting_days: number | null;
}

export interface ReturnedResponse {
    data: ReturnedBill[];
    pagination: {
        /** Everything returned and still unreleased in this school — not the length of `data`. */
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
    counts: ReturnedCounts;
}

/**
 * What the queue resolves to once the request has settled.
 *
 * `empty` AND `failed` ARE DIFFERENT CASES, DELIBERATELY, AND THIS IS THE WHOLE REASON THE TYPE IS
 * A UNION RATHER THAN `{ rows: ReturnedBill[] }`. The argument is `internal-audit-queue.ts`'s,
 * reused rather than restated in weaker words, and it inverts cleanly onto this side of the act:
 *
 * Nothing returned is a legitimate and reassuring state — Internal Audit has sent nothing back. A
 * failed request is an alarm: the queue is unknown. Rendered by the same branch — an empty list —
 * the screen tells Finance "all clear" at the exact moment the truth is "I could not ask", and it
 * says it most convincingly when the network is worst. The whole control's value is that somebody
 * looks; a screen that reports a comfortable falsehood removes the reason to.
 */
export type QueueView =
    | { kind: 'loading' }
    /** The request failed. NOT an empty queue — the count is unknown, not zero. */
    | { kind: 'failed' }
    /** The request succeeded and Internal Audit has returned nothing. */
    | { kind: 'empty' }
    | { kind: 'rows'; rows: ReturnedBill[]; returnedTotal: number };

export function queueView(state: {
    loading: boolean;
    failed: boolean;
    response: ReturnedResponse | null;
}): QueueView {
    if (state.loading) {
        return { kind: 'loading' };
    }

    // FAILURE IS TESTED BEFORE EMPTINESS. A failed request usually carries no response at all, so a
    // check that looked at `data.length` first would classify it as `empty` and never reach here.
    if (state.failed || state.response === null) {
        return { kind: 'failed' };
    }

    // EMPTINESS IS DECIDED ON THE TOTAL, NOT ON THE PAGE. A page beyond the last one returns
    // `data: []` with a non-zero `total`; keying `empty` on `data.length` would render "nothing has
    // been returned" while the queue is full — the same reassuring lie as the failure case, reached
    // by paginating rather than by a network fault.
    if (state.response.pagination.total === 0) {
        return { kind: 'empty' };
    }

    return {
        kind: 'rows',
        rows: state.response.data,
        returnedTotal: state.response.pagination.total,
    };
}

/** What the two stat cards read. */
export interface CountsView {
    /** How many bills are waiting, or `—` when the count is unknown. */
    total: string;
    /** How long the oldest has waited, as a sentence, or `—` when unknown. */
    oldestWaited: string;
    /**
     * True when the oldest bill has waited long enough that the queue is not being worked.
     * Drives the card's TONE, never its value.
     */
    stalled: boolean;
    /** True while the numbers are unknown — loading, or the request failed. */
    unknown: boolean;
}

/**
 * A QUEUE IS STALLED AT SEVEN DAYS, and the number is here rather than in the component so it can
 * be asserted.
 *
 * A WEEK BECAUSE THE WORK IS WEEKLY, not because seven is round. Billing runs, reviews and
 * corrections all move on a school week, so a bill still uncorrected after a full one has been
 * seen and skipped rather than not yet reached. It is a PRESENTATION threshold and nothing else:
 * no request, no filter and no count depends on it, so getting it wrong makes a card the wrong
 * colour and cannot make a number wrong.
 */
export const STALLED_AFTER_DAYS = 7;

/**
 * AN UNKNOWN NUMBER RENDERS AS `—` RATHER THAN `0`.
 *
 * `internal-audit-queue.ts`'s `countsView` records this as a lie its own page had already told: a
 * FAILED request rendered a confident **0** under a label, which is "all clear" at the exact moment
 * the truth is "I could not ask". This file starts from that correction rather than repeating it.
 *
 * `—` IS NOT A NUMBER AND CANNOT BE MISREAD AS ONE. Zero is a claim; an em dash is the absence of
 * one.
 */
export function countsView(view: {
    loading: boolean;
    failed: boolean;
    response: ReturnedResponse | null;
}): CountsView {
    if (view.loading || view.failed || view.response === null) {
        return { total: '—', oldestWaited: '—', stalled: false, unknown: true };
    }

    const counts = view.response.counts;

    return {
        total: String(counts.returned_total),
        oldestWaited: waitedLabel(counts.oldest_waiting_days),
        stalled:
            counts.oldest_waiting_days !== null &&
            counts.oldest_waiting_days >= STALLED_AFTER_DAYS,
        unknown: false,
    };
}

/**
 * HOW LONG THE OLDEST HAS WAITED, AS A SENTENCE.
 *
 * NULL IS `—`, NOT `0 days`. An empty queue has no oldest bill, and "0 days" would assert that one
 * arrived today. This is the same distinction the `empty`/`failed` union makes, one field down:
 * the absence of a value and a value of zero are different facts.
 *
 * ZERO IS `today`, NOT `0 days`. A bill returned this morning has waited a real amount of time that
 * is less than a day, and "0 days" reads as a rounding artifact rather than as information.
 */
export function waitedLabel(days: number | null): string {
    if (days === null) {
        return '—';
    }

    if (days <= 0) {
        return 'today';
    }

    return days === 1 ? '1 day' : `${days} days`;
}

/**
 * THE RETURNER, BY NAME.
 *
 * A NULL NAME IS NOT AN EMPTY CELL. The endpoint sends NULL when `returned_by_user_id` resolves to
 * no user row — it is a LOOKUP and not an FK, so nothing stops the row being removed underneath a
 * return. Rendering that as a blank says "nobody returned this bill", which is false and is the
 * reassuring direction; it says instead that the person is not resolvable, which is true.
 *
 * NO `user#<id>` FALLBACK. An internal identifier is not an answer to "who do I ask about this" —
 * a bursar cannot look up `user#7` — so offering one would substitute the appearance of an answer
 * for the admission that there is none.
 */
export function returnerLabel(name: string | null): string {
    return name === null || name.trim() === '' ? 'No longer a user' : name;
}

/**
 * THE REASON IS RENDERED IN FULL, AND THIS FUNCTION EXISTS TO SAY SO IN A WAY A TEST CAN READ.
 *
 * It is the ENTIRE payload of the act — the auditor typed it precisely to say what Finance must
 * correct — and a reason you have to click to read is a reason Finance will not read. So the page
 * has no clamp, no ellipsis and no "show more", and this predicate is ALWAYS false for anything the
 * server can send.
 *
 * IT IS NOT DEAD CODE, IT IS A TRIPWIRE. `RETURN_REASON_MAX` is the cap the write side enforces
 * (`internal-audit-queue.ts` mirrors `ReturnInvoice::REASON_MAX`, and an arch test pins the two
 * together). If that cap is ever widened past what a table cell can carry, the vitest arm on this
 * function reds and somebody decides deliberately — rather than the screen quietly growing a
 * truncation that hides the field it exists to show.
 *
 * MEASURED IN CODE POINTS, `[...s].length`, NOT `.length`. JavaScript's `.length` counts UTF-16
 * code units, so an emoji counts twice, while the PHP cap is measured in CHARACTERS by `mb_strlen`.
 * Using `.length` would call a legal reason over-long — the same trap `reasonState` documents on
 * the writing side.
 */
export const REASON_RENDER_LIMIT = 255;

export function reasonIsTruncated(reason: string | null): boolean {
    return reason !== null && [...reason].length > REASON_RENDER_LIMIT;
}
