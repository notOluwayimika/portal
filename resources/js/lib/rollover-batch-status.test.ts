import { describe, expect, it } from 'vitest';
import { batchStatus } from './rollover-batch-status';

/**
 * The one decision the batch panel makes, asserted without a DOM.
 *
 * The defect this file exists to prevent shipped once: the page branched on `failed_jobs > 0`
 * instead of the server's `settled_state`, and `failed_jobs` is a counter Laravel NEVER decrements —
 * so a batch whose failure had been retried successfully still rendered "Stopped … will not resume
 * on its own". Every arm below crosses that exact axis: a non-zero `failed_jobs` on a batch that is
 * NOT stopped.
 */
const base = {
    is_draining: false,
    settled_state: null,
    failed_jobs: 0,
    outstanding_failures: 0,
    failure_reasons: [],
} satisfies Parameters<typeof batchStatus>[0];

describe('batchStatus', () => {
    it('is finished when the server says finished — even with a non-zero failed_jobs counter', () => {
        // THE REGRESSION ARM. This is the post-retry-success row Laravel actually writes:
        // failed_job_ids pruned, finished_at stamped, failed_jobs left at 1 forever. Keying on the
        // counter renders "Stopped"; keying on settled_state renders the truth.
        expect(
            batchStatus({ ...base, settled_state: 'finished', failed_jobs: 1 }),
        ).toEqual({ kind: 'finished' });
    });

    it('is draining while the server says draining, whatever the counter says', () => {
        // A retry in flight: the server reports draining so the session warning stays up. A counter
        // reading would call this stopped and withdraw the warning mid-flight.
        expect(
            batchStatus({ ...base, is_draining: true, failed_jobs: 1 }),
        ).toEqual({ kind: 'draining' });
    });

    it('counts FAILURES, not distinct reason sentences', () => {
        // THE ARM THAT USED TO PIN THE BUG. It asserted failures: 2 for three failures sharing two
        // messages — encoding the undercount as correct, so no mutation could ever red it. Reasons
        // are de-duplicated server-side; a school-wide fault fails N jobs with ONE message.
        expect(
            batchStatus({
                ...base,
                settled_state: 'stopped',
                failed_jobs: 12,
                outstanding_failures: 12,
                failure_reasons: ['the same sentence'],
            }),
        ).toEqual({ kind: 'stopped', failures: 12 });
    });

    it('counts failures below the historical counter too, so it tracks OUTSTANDING not EVER-FAILED', () => {
        // The other direction, so "just use failed_jobs" cannot pass the arm above: two of three
        // recorded failures have since been retried away.
        expect(
            batchStatus({
                ...base,
                settled_state: 'stopped',
                failed_jobs: 3,
                outstanding_failures: 1,
                failure_reasons: ['a'],
            }),
        ).toEqual({ kind: 'stopped', failures: 1 });
    });

    it('treats an inconsistent pair as draining rather than finished', () => {
        // A torn read across two queries could return is_draining false with settled_state null.
        // The old fall-through resolved that to "Finished" — the most reassuring word available for
        // a batch that may have a job in flight. It fails safe now.
        expect(
            batchStatus({ ...base, is_draining: false, settled_state: null }),
        ).toEqual({
            kind: 'draining',
        });
    });

    it('is cancelled when the server says cancelled', () => {
        // Reachable only via settled_state: cancel() stamps finished_at too, so the server's
        // ordering is what distinguishes them and the panel must not re-derive it.
        expect(batchStatus({ ...base, settled_state: 'cancelled' })).toEqual({
            kind: 'cancelled',
        });
    });

    it('is finished for a clean batch', () => {
        expect(batchStatus({ ...base, settled_state: 'finished' })).toEqual({
            kind: 'finished',
        });
    });
});
