import type { Money } from '@/types/parent-finance';

/**
 * WHAT INTERNAL AUDIT'S REVIEW QUEUE MUST SAY.
 *
 * Same argument as `parent-finance-view.ts` and `rollover-batch-status.ts`: the decisions this
 * repository makes live here, so they can be asserted without a DOM, and the page maps the result
 * to colour and copy. Vitest runs in `node` (vitest.config.ts) precisely so that the parts worth
 * pinning are the parts that are pure.
 *
 * Three decisions are pinned here, and each of them is a way this screen could lie.
 */

/** One row of GET /api/internal-audit/invoices/pending. */
export interface PendingInvoice {
    uuid: string;
    number: string;
    student_id: number;
    kind: string;
    total: Money;
    issued_at: string;
}

/**
 * The three school-scoped counts the endpoint reports beside the page.
 *
 * `unreleased_total === awaiting_review + returned_to_finance` is asserted SERVER-SIDE
 * (InvoiceReviewEndpointsTest). It is not re-derived here: a client that recomputed the sum would
 * be a second source of truth for the one number this screen exists to be trusted on.
 */
export interface PendingCounts {
    /** Unreleased AND not out with Finance — the rows this page renders. */
    awaiting_review: number;
    /**
     * Unreleased AND returned to Finance. Until Phase B builds Finance's own queue this is the
     * ONLY place in the system a returned bill is visible at all.
     */
    returned_to_finance: number;
    /** Every unreleased bill, on either side of the return axis. The omission detector. */
    unreleased_total: number;
}

export interface PendingResponse {
    data: PendingInvoice[];
    pagination: {
        /**
         * Everything awaiting review in this school AND NOT OUT WITH FINANCE — not the length of
         * `data`.
         *
         * THIS COMMENT USED TO SAY "EVERYTHING AWAITING REVIEW", AND THAT STOPPED BEING TRUE the
         * day `pending()` gained `whereNull('returned_at')`. It is corrected rather than deleted
         * because a doc comment describing the payload as it WAS is worse than none: the next
         * reader trusts it instead of the endpoint. The unfiltered number is
         * `counts.unreleased_total`.
         */
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
    counts: PendingCounts;
}

/**
 * What the queue resolves to once the request has settled.
 *
 * `empty` AND `failed` ARE DIFFERENT CASES, DELIBERATELY, AND THIS IS THE WHOLE REASON THE TYPE IS
 * A UNION RATHER THAN `{ rows: PendingInvoice[] }`.
 *
 * Nothing pending is a legitimate and reassuring state: every bill has been reviewed. A failed
 * request is an alarm: the queue is unknown. Rendered by the same branch — an empty list — the
 * screen tells an auditor "all clear" at the exact moment the truth is "I could not ask", and it
 * says it most convincingly when the network is worst. The whole control's value is that somebody
 * looks; a screen that reports a comfortable falsehood removes the reason to.
 *
 * Same shape and same argument as `wardsView`'s `no-wards`, which is a distinct case for the same
 * reason.
 */
export type QueueView =
    | { kind: 'loading' }
    /** The request failed. NOT an empty queue — the count is unknown, not zero. */
    | { kind: 'failed' }
    /** The request succeeded and there is nothing awaiting review. */
    | { kind: 'empty' }
    | { kind: 'rows'; rows: PendingInvoice[]; pendingTotal: number };

export function queueView(state: {
    loading: boolean;
    failed: boolean;
    response: PendingResponse | null;
}): QueueView {
    if (state.loading) {
        return { kind: 'loading' };
    }

    // FAILURE IS TESTED BEFORE EMPTINESS. A failed request usually carries no response at all, so a
    // check that looked at `data.length` first would classify it as `empty` and never reach here.
    if (state.failed || state.response === null) {
        return { kind: 'failed' };
    }

    if (state.response.pagination.total === 0) {
        return { kind: 'empty' };
    }

    return {
        kind: 'rows',
        rows: state.response.data,
        // FROM pagination.total, NEVER rows.length. The count is the instrument this control's only
        // silent failure — a bill nobody reviews — will ever show up in, and a page of 25 that
        // reports 25 while 900 wait hides exactly that. See the endpoint's docblock.
        pendingTotal: state.response.pagination.total,
    };
}

/**
 * EMPTINESS IS DECIDED ON THE TOTAL, NOT ON THE PAGE.
 *
 * A page beyond the last one returns `data: []` with a non-zero `total`. Keying `empty` on
 * `data.length` would render "nothing awaiting review" while the queue is full — the same
 * reassuring lie as the failure case, reached by paginating rather than by a network fault.
 */

/** One invoice's outcome from POST /api/internal-audit/invoices/approve. */
export interface BatchResultRow {
    uuid: string;
    outcome: 'approved' | 'refused';
    message?: string;
}

export interface BatchResponse {
    approved: number;
    refused: number;
    results: BatchResultRow[];
}

export type BatchView = {
    /** True ONLY when every invoice in the batch was released. */
    allApproved: boolean;
    approved: BatchResultRow[];
    /** Each carries the server's own sentence — why this one was not released. */
    refused: BatchResultRow[];
    approvedCount: number;
    refusedCount: number;
};

/**
 * THE BATCH RESULT IS PER INVOICE, AND `allApproved` IS FALSE THE MOMENT ONE IS NOT.
 *
 * The endpoint answers 207 with an outcome each precisely so a partial batch cannot be read as
 * done. A screen that collapses that to "done" recreates the failure the endpoint was built to
 * refuse — an unreviewed bill that looks reviewed — and it does so on the operator's side, where
 * there is no audit row to contradict it.
 *
 * The refusal sentences are the SERVER'S, passed through untouched. `ApproveInvoice` already names
 * the reviewer who holds an existing attestation and why a bill cannot be released; rewriting them
 * here would produce a second, poorer spelling of the same refusal.
 */
export function batchView(response: BatchResponse): BatchView {
    const approved = response.results.filter((r) => r.outcome === 'approved');
    const refused = response.results.filter((r) => r.outcome !== 'approved');

    return {
        // Derived from the RESULTS, not from `response.approved === response.results.length`: the
        // counts and the rows are two spellings of the same fact and the rows are the detailed one.
        allApproved: refused.length === 0,
        approved,
        refused,
        approvedCount: approved.length,
        refusedCount: refused.length,
    };
}

/**
 * SELECT-ALL SELECTS THE PAGE, NEVER THE QUEUE.
 *
 * The batch cap is 100 and a page is at most 100, so a select-all meaning "all 900 pending" builds
 * a request the server refuses with a 422 the auditor cannot act on — and worse, it reads as
 * "release everything" while doing nothing at all. The page is also the only set the auditor has
 * actually looked at, which is the substantive reason rather than the mechanical one.
 *
 * Returns the uuids on the CURRENT page. The caller labels the control so the two are not confused.
 */
export function selectAllOnPage(view: QueueView): string[] {
    return view.kind === 'rows' ? view.rows.map((r) => r.uuid) : [];
}

/**
 * THE WIDTH OF `finance_invoices.return_reason`, IN CHARACTERS — mirroring
 * `App\Finance\Actions\ReturnInvoice::REASON_MAX`.
 *
 * IT IS A SECOND COPY, AND THAT IS STATED RATHER THAN HIDDEN. There is no PHP→TypeScript constant
 * bridge in this repository: `wayfinder` generates route helpers and nothing generates values, so
 * the number cannot be imported. `ReturnInvoiceRequest` cites the PHP constant precisely so the
 * request and the action cannot diverge; this file cannot reach it.
 *
 * WHAT KEEPS THE TWO IN STEP IS A GATE, NOT A COMMENT.
 * `tests/Arch/ReturnReasonMaxHasOneValueTest.php` reads THIS LINE out of THIS FILE and asserts it
 * equals `ReturnInvoice::REASON_MAX`. Widening the column without widening the form reds there
 * rather than in production, where the symptom would be a `maxLength` silently truncating what an
 * auditor typed — the one field whose whole job is to say what to fix.
 */
export const RETURN_REASON_MAX = 255;

/** Why a reason is not acceptable, or that it is. */
export type ReasonState = 'empty' | 'too-long' | 'ok';

/**
 * Judge a raw reason exactly as `ReturnInvoice` does: TRIM FIRST, then measure.
 *
 * TRIMMING IS THE POINT, NOT TIDINESS. A reason of three spaces is refused by the server — the
 * request's `required` sees `null`, because `TrimStrings` and `ConvertEmptyStringsToNull` run
 * ahead of validation — and `ReturnInvoice` trims and refuses independently for its off-request
 * callers. A dialog that enabled submit on whitespace would send a request guaranteed to 422, and
 * the operator would learn that only after a round trip.
 *
 * LENGTH IS MEASURED ON THE TRIMMED STRING, for the same reason the action measures it there: what
 * is stored is the trimmed value, so what is judged must be too. Measuring the raw string would
 * refuse a legal reason padded with spaces.
 *
 * `[...trimmed].length` AND NOT `trimmed.length`. JavaScript's `.length` counts UTF-16 code units,
 * so an emoji or any astral character counts twice — while PHP's `mb_strlen` counts CHARACTERS.
 * Using `.length` would refuse client-side a reason the server accepts, which is the direction
 * nobody notices: the operator is stopped by a limit the system does not actually have.
 */
export function reasonState(raw: string): ReasonState {
    const trimmed = raw.trim();

    if (trimmed === '') {
        return 'empty';
    }

    return [...trimmed].length > RETURN_REASON_MAX ? 'too-long' : 'ok';
}

export interface ReturnDialogView {
    reason: ReasonState;
    /** True only when the reason is acceptable AND no request is in flight. */
    canSubmit: boolean;
    /**
     * Characters left, on the TRIMMED reason. Negative once over the cap, so the page can render
     * the overage rather than a floor of zero that hides how far over the auditor is.
     */
    remaining: number;
}

/**
 * WHAT THE RETURN DIALOG DECIDES, so the component only paints it.
 *
 * `busy` GATES SUBMIT SEPARATELY FROM THE REASON, and the two are not collapsed into one boolean:
 * a double-submit is refused by the server as "already returned to Finance on <date> by <name>",
 * which is a true sentence describing the operator's own click and reads as somebody else's
 * return.
 */
export function returnDialogView(state: {
    raw: string;
    busy: boolean;
}): ReturnDialogView {
    const reason = reasonState(state.raw);

    return {
        reason,
        canSubmit: reason === 'ok' && !state.busy,
        remaining: RETURN_REASON_MAX - [...state.raw.trim()].length,
    };
}

/** The shape an axios failure carries, reduced to what this decision reads. */
export interface ReturnFailure {
    status?: number;
    data?: {
        message?: string;
        errors?: Record<string, string[]>;
    };
}

/**
 * MAP A FAILED RETURN ONTO ONE SENTENCE FOR THE FORM'S ERROR SLOT.
 *
 * THE ORDER IS FIELD ERRORS, THEN THE SERVER'S SENTENCE, THEN A FALLBACK — and it is an order
 * rather than a preference. A 422 from `ReturnInvoiceRequest` carries `errors.reason`, which names
 * the field; a 422 from `ReturnInvoice` carries only `message`, and that message is already the
 * best sentence anyone has — it names the first returner, or the void-and-credit-note remedy, or
 * the measured length. Reading `message` first would replace a field-specific error with the
 * generic "The given data was invalid." that Laravel puts alongside validation errors.
 *
 * THE SERVER'S SENTENCE IS PASSED THROUGH UNTOUCHED, the same rule `batchView` states for the
 * batch refusals: rewriting it here produces a second, poorer spelling of the same refusal.
 *
 * THE FALLBACK DOES NOT CLAIM NOTHING CHANGED. A network fault after the request left the browser
 * is indistinguishable from one before it, so "nothing was changed" would be a guess presented as a
 * fact about the ledger. It tells the operator to look instead — which is the only honest
 * instruction when the outcome is genuinely unknown.
 */
export function returnErrorMessage(failure: ReturnFailure | null): string {
    const fieldError = failure?.data?.errors?.reason?.[0];

    if (typeof fieldError === 'string' && fieldError !== '') {
        return fieldError;
    }

    const message = failure?.data?.message;

    if (typeof message === 'string' && message !== '') {
        return message;
    }

    return 'The return could not be completed. Refresh the queue to see the current state of this bill.';
}

/** What the two stat cards read. */
export interface CountsView {
    awaiting: string;
    returned: string;
    /** True when there is at least one bill out with Finance. */
    hasReturned: boolean;
    /** True while the counts are unknown — loading, or the request failed. */
    unknown: boolean;
}

/**
 * THE TWO CARDS, AND AN UNKNOWN COUNT RENDERS AS `—` RATHER THAN `0`.
 *
 * THIS CORRECTS A LIE THE PAGE ALREADY TOLD. The awaiting card read
 * `view.kind === 'rows' ? total : '0'`, so a FAILED request rendered a confident **0** under the
 * label "Awaiting sign-off" — "every bill is reviewed" at the exact moment the truth is "I could
 * not ask". The table below it has always distinguished those two states, and its docblock argues
 * at length that they must never render alike; the card was the one place that did not, and it is
 * the place an auditor glances at.
 *
 * `—` IS NOT A NUMBER AND CANNOT BE MISREAD AS ONE. Zero is a claim; an em dash is the absence of
 * one.
 *
 * NO THIRD CARD FOR `unreleased_total`. See the page docblock: it is the SUM of these two, and a
 * number the reader must reconcile against two others is the "two bare numbers on one screen is a
 * bug report" rule with a third number added to it.
 */
export function countsView(view: {
    loading: boolean;
    failed: boolean;
    response: PendingResponse | null;
}): CountsView {
    if (view.loading || view.failed || view.response === null) {
        return {
            awaiting: '—',
            returned: '—',
            hasReturned: false,
            unknown: true,
        };
    }

    const counts = view.response.counts;

    return {
        awaiting: String(counts.awaiting_review),
        returned: String(counts.returned_to_finance),
        hasReturned: counts.returned_to_finance > 0,
        unknown: false,
    };
}
