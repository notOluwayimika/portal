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
        // The count comes from the LIVE reason list where there is one, never from the monotone
        // counter. `failed_jobs` is the fallback only for a stopped batch whose reasons could not be
        // resolved, where showing the historical count beats showing nothing.
        return {
            kind: 'stopped',
            failures: b.failure_reasons.length || b.failed_jobs,
        };
    }

    return { kind: 'finished' };
}
