import { describe, expect, it } from 'vitest';
import type { Money } from '@/types/parent-finance';
import {
    countsView,
    queueView,
    REASON_RENDER_LIMIT,
    reasonIsTruncated,
    returnerLabel,
    STALLED_AFTER_DAYS,
    waitedLabel,
} from './returned-bills-queue';
import type {
    ReturnedBill,
    ReturnedCounts,
    ReturnedResponse,
} from './returned-bills-queue';

const naira = (minor: number): Money => ({
    amount_minor: minor,
    currency: 'NGN',
});

const bill = (
    number: number,
    overrides: Partial<ReturnedBill> = {},
): ReturnedBill => ({
    number,
    billed_to: 'Ada Payer',
    kind: 'scheduled',
    total: naira(250000),
    issued_at: '2026-09-01T09:00:00+01:00',
    returned_at: '2026-09-02T09:00:00+01:00',
    returned_by: 'Ada Auditor',
    return_reason: 'The development levy is billed twice on this one.',
    ...overrides,
});

const counts = (overrides: Partial<ReturnedCounts> = {}): ReturnedCounts => ({
    returned_total: 3,
    oldest_waiting_days: 2,
    ...overrides,
});

const response = (
    rows: ReturnedBill[],
    total = rows.length,
    countOverrides: Partial<ReturnedCounts> = {},
): ReturnedResponse => ({
    data: rows,
    pagination: { total, per_page: 25, current_page: 1, last_page: 1 },
    counts: counts({ returned_total: total, ...countOverrides }),
});

describe('queueView', () => {
    it('is loading while the request is in flight', () => {
        expect(
            queueView({ loading: true, failed: false, response: null }),
        ).toEqual({
            kind: 'loading',
        });
    });

    it('is FAILED, never empty, when the request failed', () => {
        // THE AXIS OF THIS WHOLE UNION. An empty list would tell Finance "Internal Audit has sent
        // nothing back" at the moment the truth is "I could not ask".
        expect(
            queueView({ loading: false, failed: true, response: null }),
        ).toEqual({
            kind: 'failed',
        });
    });

    it('is FAILED when there is no response at all, even with failed unset', () => {
        // Failure is tested BEFORE emptiness precisely because a failed request usually carries no
        // response; a check reading `data.length` first would classify this as empty.
        expect(
            queueView({ loading: false, failed: false, response: null }),
        ).toEqual({
            kind: 'failed',
        });
    });

    it('is empty when the request succeeded and nothing has been returned', () => {
        expect(
            queueView({
                loading: false,
                failed: false,
                response: response([], 0),
            }),
        ).toEqual({
            kind: 'empty',
        });
    });

    it('reads the total from pagination, NOT from the rows on this page', () => {
        const view = queueView({
            loading: false,
            failed: false,
            response: response([bill(1001), bill(1002)], 97),
        });

        expect(view).toEqual({
            kind: 'rows',
            rows: [bill(1001), bill(1002)],
            returnedTotal: 97,
        });
    });

    it('is NOT empty on a page past the last one — an empty page with a non-zero total', () => {
        // The reassuring lie reached by paginating rather than by a network fault: `data` is empty
        // while 40 bills wait. Keying emptiness on `data.length` would render "all clear".
        const view = queueView({
            loading: false,
            failed: false,
            response: response([], 40),
        });

        expect(view.kind).toBe('rows');
    });
});

describe('countsView', () => {
    it('renders BOTH numbers as an em dash when the request failed', () => {
        // Zero is a claim; an em dash is the absence of one. A confident 0 under "Returned to
        // Finance" after a failed request says every bill is corrected.
        expect(
            countsView({ loading: false, failed: true, response: null }),
        ).toEqual({
            total: '—',
            oldestWaited: '—',
            stalled: false,
            unknown: true,
        });
    });

    it('renders both as an em dash while loading', () => {
        expect(
            countsView({ loading: true, failed: false, response: null })
                .unknown,
        ).toBe(true);
    });

    it('reports the size and the age together', () => {
        const view = countsView({
            loading: false,
            failed: false,
            response: response([bill(1001)], 4, { oldest_waiting_days: 3 }),
        });

        expect(view).toEqual({
            total: '4',
            oldestWaited: '3 days',
            stalled: false,
            unknown: false,
        });
    });

    it('a SMALL queue whose oldest bill is old is STALLED — the count alone cannot say so', () => {
        // The whole argument for the second number. Four bills that arrived this morning and four
        // that have sat three weeks are the same count and a different situation.
        const fresh = countsView({
            loading: false,
            failed: false,
            response: response([bill(1)], 4, { oldest_waiting_days: 0 }),
        });
        const stale = countsView({
            loading: false,
            failed: false,
            response: response([bill(1)], 4, { oldest_waiting_days: 21 }),
        });

        expect(fresh.total).toBe(stale.total);
        expect(fresh.stalled).toBe(false);
        expect(stale.stalled).toBe(true);
    });

    it('stalls AT the threshold, not one day after it', () => {
        const at = (days: number) =>
            countsView({
                loading: false,
                failed: false,
                response: response([bill(1)], 1, { oldest_waiting_days: days }),
            }).stalled;

        expect(at(STALLED_AFTER_DAYS - 1)).toBe(false);
        expect(at(STALLED_AFTER_DAYS)).toBe(true);
        expect(at(STALLED_AFTER_DAYS + 1)).toBe(true);
    });

    it('an unknown age never reads as stalled', () => {
        expect(
            countsView({
                loading: false,
                failed: false,
                response: response([], 0, { oldest_waiting_days: null }),
            }).stalled,
        ).toBe(false);
    });
});

describe('waitedLabel', () => {
    it('is an em dash when there is no oldest bill, NOT "0 days"', () => {
        // An empty queue has no oldest bill. "0 days" would assert that one arrived today.
        expect(waitedLabel(null)).toBe('—');
    });

    it('is "today" at zero, not "0 days"', () => {
        expect(waitedLabel(0)).toBe('today');
    });

    it('is singular at one', () => {
        expect(waitedLabel(1)).toBe('1 day');
    });

    it('is plural above one', () => {
        expect(waitedLabel(2)).toBe('2 days');
        expect(waitedLabel(30)).toBe('30 days');
    });
});

describe('returnerLabel', () => {
    it('renders the name', () => {
        expect(returnerLabel('Ada Auditor')).toBe('Ada Auditor');
    });

    it('says the person is unresolvable rather than rendering a blank', () => {
        // A blank reads as "nobody returned this bill", which is false and is the reassuring
        // direction. And there is no `user#<id>` fallback: an internal identifier is not an answer
        // to "who do I ask about this".
        expect(returnerLabel(null)).toBe('No longer a user');
        expect(returnerLabel('   ')).toBe('No longer a user');
        expect(returnerLabel(null)).not.toContain('user#');
    });
});

describe('reasonIsTruncated — the tripwire', () => {
    it('is false for every reason the server can send, including one at the cap', () => {
        expect(reasonIsTruncated(null)).toBe(false);
        expect(reasonIsTruncated('')).toBe(false);
        expect(reasonIsTruncated('x'.repeat(REASON_RENDER_LIMIT))).toBe(false);
    });

    it('fires only past the cap, so widening the column without deciding reds here', () => {
        expect(reasonIsTruncated('x'.repeat(REASON_RENDER_LIMIT + 1))).toBe(
            true,
        );
    });

    it('measures CODE POINTS, not UTF-16 units — an emoji must not count twice', () => {
        // `.length` would call this 2 × LIMIT and refuse a reason the PHP cap (mb_strlen) accepts.
        expect(reasonIsTruncated('🙂'.repeat(REASON_RENDER_LIMIT))).toBe(false);
        expect(reasonIsTruncated('🙂'.repeat(REASON_RENDER_LIMIT + 1))).toBe(
            true,
        );
    });
});
