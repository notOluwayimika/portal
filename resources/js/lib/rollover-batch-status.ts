/**
 * Which word a rollover/CCM-fold batch gets on the operator's panel.
 *
 * ── WHY THIS IS A FUNCTION AND NOT A TERNARY IN THE PAGE ─────────────────────────────────────────
 * It was a ternary in the page, and it re-derived the answer from the WRONG field. The server sends
 * `settled_state`, computed against Laravel's batch accounting; the panel branched on
 * `failed_jobs > 0`, which is a MONOTONE counter Laravel never decrements — not even when a retried
 * job succeeds. So a batch that had genuinely finished rendered as "Stopped … it will not resume on
 * its own", with no reason beside it, because `failed_job_ids` had been pruned while the counter had
 * not. A finished batch reported dead, in the component whose entire purpose is not doing that.
 *
 * Extracted so the decision can be asserted without a DOM. Same argument as
 * `money-input.test.ts`: test the part this repository wrote that can go silently wrong, not the
 * rendering around it. The page maps the result to colour and copy; it makes no judgement.
 *
 * THE SERVER IS THE ONLY AUTHORITY on whether a batch has settled. Nothing here recomputes it from
 * counts — that is precisely the mistake being removed.
 */
export type BatchStatusInput = {
    is_draining: boolean;
    settled_state: 'finished' | 'stopped' | 'cancelled' | null;
    failed_jobs: number;
    /** Failures still unresolved, counted server-side. NOT the length of `failure_reasons`. */
    outstanding_failures: number;
    failure_reasons: string[];
};

export type BatchStatus =
    | { kind: 'draining' }
    | { kind: 'cancelled' }
    | { kind: 'stopped'; failures: number }
    | { kind: 'finished' };

export function batchStatus(b: BatchStatusInput): BatchStatus {
    if (b.is_draining) {
        return { kind: 'draining' };
    }

    if (b.settled_state === 'cancelled') {
        return { kind: 'cancelled' };
    }

    if (b.settled_state === 'stopped') {
        // COUNT FAILURES, NOT DISTINCT SENTENCES. This read `failure_reasons.length`, and
        // failureReasons() de-duplicates — so N jobs failing with one shared message (a deadlock, a
        // timeout, anything school-wide) collapsed to "Stopped with 1 failure(s)" beside a
        // failed_jobs column reading N. Understating a dead batch, on the surface built not to.
        return { kind: 'stopped', failures: b.outstanding_failures };
    }

    // FAIL SAFE ON AN UNEXPECTED PAIR. This used to `return finished` for anything that fell
    // through, so `is_draining: false` with `settled_state: null` — a combination a torn read could
    // produce — resolved to the single most reassuring word available, for a batch with a job in
    // flight. Only an explicit 'finished' earns the word now; anything else is treated as still
    // draining, because the cost of an over-long warning is an operator who waits, and the cost of
    // a premature "Finished" is one who changes the session underneath a running job.
    return b.settled_state === 'finished'
        ? { kind: 'finished' }
        : { kind: 'draining' };
}
