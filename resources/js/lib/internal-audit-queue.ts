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

export interface PendingResponse {
    data: PendingInvoice[];
    pagination: {
        /** EVERYTHING awaiting review in this school — not the length of `data`. */
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
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
