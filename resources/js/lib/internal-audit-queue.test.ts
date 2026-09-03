import { describe, expect, it } from 'vitest';
import type { Money } from '@/types/parent-finance';
import { batchView, queueView, selectAllOnPage } from './internal-audit-queue';
import type {
    BatchResponse,
    PendingInvoice,
    PendingResponse,
} from './internal-audit-queue';

const naira = (minor: number): Money => ({
    amount_minor: minor,
    currency: 'NGN',
});

const invoice = (uuid: string): PendingInvoice => ({
    uuid,
    number: `INV-${uuid}`,
    student_id: 1,
    kind: 'scheduled',
    total: naira(150000),
    issued_at: '2026-09-03T00:00:00+00:00',
});

const response = (rows: PendingInvoice[], total: number): PendingResponse => ({
    data: rows,
    pagination: { total, per_page: 25, current_page: 1, last_page: 1 },
});

const settled = (r: PendingResponse | null, failed = false) => ({
    loading: false,
    failed,
    response: r,
});

describe('the pending count', () => {
    // (a) THE COUNT IS THE ENDPOINT'S TOTAL, NOT THE ROW COUNT. The fixture makes them DIFFER —
    // two rows, nine hundred pending — because a fixture where they are equal cannot tell the two
    // implementations apart, and equal is what a page of test data naturally is.
    it('reports the endpoint total, not the number of rows on the page', () => {
        const view = queueView(
            settled(response([invoice('a'), invoice('b')], 900)),
        );

        expect(view.kind).toBe('rows');

        if (view.kind !== 'rows') {
            return;
        }

        expect(view.pendingTotal).toBe(900);
        expect(view.rows).toHaveLength(2);
        expect(view.pendingTotal).not.toBe(view.rows.length);
    });
});

describe('an empty queue and a failed feed are different', () => {
    // (b) THE ARM THIS SCREEN EXISTS FOR. Both are "no rows to show"; only one is reassuring.
    it('renders nothing-pending as empty', () => {
        expect(queueView(settled(response([], 0))).kind).toBe('empty');
    });

    it('renders a failed request as failed, NOT as empty', () => {
        expect(queueView(settled(null, true)).kind).toBe('failed');
        // A failure that carried a body must still be `failed` — the flag decides, not the payload.
        expect(queueView(settled(response([], 0), true)).kind).toBe('failed');
    });

    it('treats a missing response as failed rather than empty', () => {
        // The common shape of a network fault: no body at all. Classified on the response being
        // absent, so it cannot fall through to the zero-total branch.
        expect(queueView(settled(null)).kind).toBe('failed');
    });

    it('does not call a later page empty when the queue is not', () => {
        // data: [] with a non-zero total is a page past the last one. Keying `empty` on
        // data.length would say "nothing awaiting review" while 900 wait.
        expect(queueView(settled(response([], 900))).kind).toBe('rows');
    });

    it('is loading before it is anything else', () => {
        expect(
            queueView({ loading: true, failed: false, response: null }).kind,
        ).toBe('loading');
    });
});

describe('the batch result', () => {
    const partial: BatchResponse = {
        approved: 2,
        refused: 1,
        results: [
            { uuid: 'a', outcome: 'approved' },
            {
                uuid: 'b',
                outcome: 'refused',
                message:
                    'Invoice b is void and cannot be released to its payer.',
            },
            { uuid: 'c', outcome: 'approved' },
        ],
    };

    // (c) A PARTIAL BATCH IS NOT A SUCCESS, and both halves are asserted: the refusal is shown AND
    // the successes are shown. A screen that reported only the failure would be as wrong as one
    // that reported only success — the auditor must know which bills are now released.
    it('reports the refusal AND the successes, and is not unqualified success', () => {
        const view = batchView(partial);

        expect(view.allApproved).toBe(false);
        expect(view.refusedCount).toBe(1);
        expect(view.approvedCount).toBe(2);
        expect(view.approved.map((r) => r.uuid)).toEqual(['a', 'c']);
        expect(view.refused[0].uuid).toBe('b');
    });

    it("passes the server's own sentence through untouched", () => {
        // ApproveInvoice already names the reviewer holding an existing attestation, or why the
        // bill cannot be released. A second spelling here would be poorer and could drift.
        expect(batchView(partial).refused[0].message).toBe(
            'Invoice b is void and cannot be released to its payer.',
        );
    });

    it('is allApproved only when every one was released', () => {
        expect(
            batchView({
                approved: 2,
                refused: 0,
                results: [
                    { uuid: 'a', outcome: 'approved' },
                    { uuid: 'c', outcome: 'approved' },
                ],
            }).allApproved,
        ).toBe(true);
    });
});

describe('select-all', () => {
    // (d) SELECTS THE PAGE, NOT THE QUEUE. The fixture again makes page and total differ, so a
    // select-all that reached for the total would be visible.
    it('selects only the invoices on the current page', () => {
        const view = queueView(
            settled(response([invoice('a'), invoice('b')], 900)),
        );

        expect(selectAllOnPage(view)).toEqual(['a', 'b']);
        expect(selectAllOnPage(view)).toHaveLength(2);
    });

    it('selects nothing when there is nothing to select', () => {
        expect(selectAllOnPage(queueView(settled(response([], 0))))).toEqual(
            [],
        );
        expect(selectAllOnPage(queueView(settled(null, true)))).toEqual([]);
    });
});
