import { describe, expect, it } from 'vitest';
import type { Money } from '@/types/parent-finance';
import {
    batchView,
    countsView,
    queueView,
    reasonState,
    RETURN_REASON_MAX,
    returnDialogView,
    returnErrorMessage,
    selectAllOnPage,
} from './internal-audit-queue';
import type {
    BatchResponse,
    PendingCounts,
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

const response = (
    rows: PendingInvoice[],
    total: number,
    // THE COUNTS DEFAULT TO A SET WHERE ALL THREE DIFFER. A fixture where awaiting === total ===
    // returned cannot tell three implementations apart, and equal is what a careless fixture
    // naturally is. Callers that care override them.
    counts: PendingCounts = {
        awaiting_review: total,
        returned_to_finance: 0,
        unreleased_total: total,
    },
): PendingResponse => ({
    data: rows,
    pagination: { total, per_page: 25, current_page: 1, last_page: 1 },
    counts,
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

describe('the reason', () => {
    // (e) TRIMMED BEFORE IT IS JUDGED, because the server judges the trimmed value. A dialog that
    // enabled submit on whitespace would send a request guaranteed to 422 — the operator learns
    // only after a round trip, and the message they get is a field error about a box they filled.
    it('treats a whitespace-only reason as empty', () => {
        expect(reasonState('   ')).toBe('empty');
        expect(reasonState('\t\n ')).toBe('empty');
        expect(reasonState('')).toBe('empty');
    });

    it('accepts a reason that is only non-empty after trimming', () => {
        // The positive half. Without it, `reasonState` could return 'empty' unconditionally and the
        // arm above would still pass.
        expect(reasonState('  Tuition rate is stale  ')).toBe('ok');
    });

    // (f) THE CAP, BOTH DIRECTIONS, WITH LITERAL PAYLOADS. A length built as `RETURN_REASON_MAX + 1`
    // submits "cap + 1" whatever the cap is and is structurally incapable of noticing it loosening.
    it('refuses 256 characters and accepts 255', () => {
        expect(RETURN_REASON_MAX).toBe(255);
        expect(reasonState('x'.repeat(256))).toBe('too-long');
        expect(reasonState('x'.repeat(255))).toBe('ok');
    });

    // (g) LENGTH IS MEASURED IN CHARACTERS, NOT UTF-16 CODE UNITS. `.length` counts an emoji as
    // two, so a 200-emoji reason would be refused client-side as 400 while the server's `mb_strlen`
    // counts 200 and accepts it — the operator stopped by a limit the system does not have.
    it('counts astral characters once, as mb_strlen does', () => {
        expect(reasonState('🙂'.repeat(255))).toBe('ok');
        expect(reasonState('🙂'.repeat(256))).toBe('too-long');
    });

    // (h) THE PADDED CASE. Length is measured on the TRIMMED string, because the trimmed string is
    // what is stored — measuring the raw one would refuse a legal reason padded with spaces.
    it('does not count surrounding whitespace toward the cap', () => {
        expect(reasonState('  ' + 'x'.repeat(255) + '  ')).toBe('ok');
    });
});

describe('the return dialog', () => {
    // (i) SUBMIT IS GATED ON THE REASON AND ON `busy` SEPARATELY. A double-submit is refused by the
    // server as "already returned to Finance on <date> by <name>" — a true sentence describing the
    // operator's own first click, which reads as somebody else's return.
    it('enables submit only for an acceptable reason with no request in flight', () => {
        expect(
            returnDialogView({ raw: 'wrong fee line', busy: false }).canSubmit,
        ).toBe(true);
        expect(
            returnDialogView({ raw: 'wrong fee line', busy: true }).canSubmit,
        ).toBe(false);
        expect(returnDialogView({ raw: '   ', busy: false }).canSubmit).toBe(
            false,
        );
        expect(
            returnDialogView({ raw: 'x'.repeat(256), busy: false }).canSubmit,
        ).toBe(false);
    });

    // (j) `remaining` GOES NEGATIVE. Flooring it at zero would tell an auditor who pasted 400
    // characters that they have none left, which is true and useless; the overage is what they need
    // in order to cut the right amount.
    it('reports the overage rather than flooring at zero', () => {
        expect(
            returnDialogView({ raw: 'x'.repeat(300), busy: false }).remaining,
        ).toBe(-45);
        expect(returnDialogView({ raw: '', busy: false }).remaining).toBe(255);
    });
});

describe('a failed return', () => {
    // (k) FIELD ERRORS BEAT THE MESSAGE, AND THE ORDER IS THE POINT. Laravel puts the generic
    // "The given data was invalid." in `message` ALONGSIDE `errors`, so reading `message` first
    // would replace a field-specific error with a sentence that names nothing.
    it('prefers the field error over the envelope message', () => {
        expect(
            returnErrorMessage({
                status: 422,
                data: {
                    message: 'The given data was invalid.',
                    errors: { reason: ['The reason field is required.'] },
                },
            }),
        ).toBe('The reason field is required.');
    });

    // (l) THE ACTION'S SENTENCE PASSES THROUGH UNTOUCHED. It already names the first returner, the
    // remedy, or the measured length; a second spelling here would be poorer and could drift.
    it('surfaces the action sentence verbatim when there is no field error', () => {
        // THE SERVER'S CURRENT SHAPE, kept in step with it deliberately. It used to read
        // "Invoice <uuid> … by user#7"; both identifiers were replaced with the operator's own
        // vocabulary in fix/refusals-name-the-bill-and-the-person. This arm does not ASSERT the
        // server's wording — it asserts pass-through — but a fixture that no longer resembles
        // anything the server sends is a worked example that teaches the wrong thing.
        //
        // THE NAME IS INITIALS, NOT A PERSON-SHAPED ONE. It reads identically for the purpose of
        // this arm, and it cannot be mistaken for copied data by a reader who has not been told
        // where it came from — which is the point of the ids-not-names rule.
        const sentence =
            'Invoice BSS-000042 was already returned to Finance on 2026-09-04 by A. B.. It is awaiting correction.';

        expect(
            returnErrorMessage({ status: 422, data: { message: sentence } }),
        ).toBe(sentence);
    });

    // (m) THE FALLBACK DOES NOT CLAIM NOTHING CHANGED. A network fault after the request left the
    // browser is indistinguishable from one before it, so "nothing was changed" is a guess
    // presented as a fact about the ledger.
    it('falls back to an instruction to look, not to a claim about the ledger', () => {
        const fallback = returnErrorMessage(null);

        expect(fallback).toContain('Refresh the queue');
        expect(fallback).not.toContain('Nothing was changed');
        expect(returnErrorMessage({ status: 500, data: {} })).toBe(fallback);
        // An empty-string message must not be surfaced as the error: it would render an empty
        // banner, which is the "sentence with no content" §26 forbids.
        expect(returnErrorMessage({ status: 422, data: { message: '' } })).toBe(
            fallback,
        );
    });
});

describe('the two stat cards', () => {
    // (n) AN UNKNOWN COUNT IS AN EM DASH, NOT A ZERO. This is §26's own rule and this page's own
    // docblock argument: a confident `0` under "Awaiting sign-off" says "every bill is reviewed" at
    // the moment the truth is "I could not ask".
    it('dashes both numbers while loading and after a failure', () => {
        const loading = countsView({
            loading: true,
            failed: false,
            response: null,
        });
        const failed = countsView({
            loading: false,
            failed: true,
            response: null,
        });

        expect(loading).toEqual({
            awaiting: '—',
            returned: '—',
            hasReturned: false,
            unknown: true,
        });
        expect(failed).toEqual({
            awaiting: '—',
            returned: '—',
            hasReturned: false,
            unknown: true,
        });
    });

    // (o) AND THE DASH IS CONDITIONAL — §26 says so in as many words: "a card that dashes
    // unconditionally passes every test you would write for the failure case, so show a genuine
    // zero still rendering 0 in the same run".
    it('renders a genuine zero as 0', () => {
        const view = countsView(
            settled(
                response([], 0, {
                    awaiting_review: 0,
                    returned_to_finance: 0,
                    unreleased_total: 0,
                }),
            ),
        );

        expect(view).toEqual({
            awaiting: '0',
            returned: '0',
            hasReturned: false,
            unknown: false,
        });
    });

    // (p) THE CARDS READ `counts`, NOT `pagination.total`. The fixture makes them DIFFER, because a
    // fixture where awaiting_review === pagination.total cannot tell the two apart — and they are
    // equal in the ordinary case, which is exactly what makes the confusion survivable.
    it('reads the counts object and never pagination.total', () => {
        const view = countsView(
            settled(
                response([invoice('a')], 900, {
                    awaiting_review: 7,
                    returned_to_finance: 3,
                    unreleased_total: 10,
                }),
            ),
        );

        expect(view.awaiting).toBe('7');
        expect(view.returned).toBe('3');
        expect(view.hasReturned).toBe(true);
    });
});
