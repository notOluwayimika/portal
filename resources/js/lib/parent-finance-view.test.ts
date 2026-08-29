import { describe, expect, it } from 'vitest';
import type { FinanceWard, Money } from '@/types/parent-finance';
import {
    hasAvailableCredit,
    isInCredit,
    wardView,
    wardsView,
} from './parent-finance-view';

const naira = (minor: number): Money => ({
    amount_minor: minor,
    currency: 'NGN',
});

/**
 * A ward built from the ENDPOINT's shape, not the design draft's. Defaults are the ordinary case —
 * one outstanding invoice, nothing in credit — so every test below changes exactly the one thing it
 * is about and a failure can only be attributed to that thing.
 */
const ward = (over: Partial<FinanceWard> = {}): FinanceWard => ({
    student: { id: 'ward-uuid-1', name: 'A Ward' },
    invoices: [
        {
            id: 'inv-uuid-1',
            display_number: 'BSS-000042',
            kind: 'scheduled',
            academic_context: 'First Term 2026/2027',
            total: naira(300000),
            outstanding: naira(180000),
        },
    ],
    account: { balance: naira(180000), available_credit: naira(0) },
    ...over,
});

describe('the two states the design draft has no concept of', () => {
    // The draft models a ward as `outstanding_balance: number` — one non-negative figure. Both
    // states below are inexpressible in that shape, which is why they are pinned here rather than
    // left to the rendering.

    it('an empty invoice list is a real ward with nothing owed, NOT an absent ward', () => {
        const view = wardView(ward({ invoices: [] }));

        expect(view.invoices).toEqual({ kind: 'nothing-outstanding' });

        // AND THE WARD SURVIVES. Dropping it is the failure this arm exists to refuse: it would make
        // "paid up" and "not your child" the same screen.
        expect(view.name).toBe('A Ward');
        expect(wardsView([ward({ invoices: [] })])).toEqual({
            kind: 'wards',
            wards: [view],
        });
    });

    it('a NEGATIVE balance means the school owes the parent, and the credit is shown', () => {
        const view = wardView(
            ward({
                invoices: [],
                account: {
                    balance: naira(-50000),
                    available_credit: naira(50000),
                },
            }),
        );

        expect(view.inCredit).toBe(true);
        expect(view.showCredit).toBe(true);

        // The figures come from the SERVER's two fields. Nothing here derives the credit from the
        // balance, or the balance from the invoices — the account position is not recoverable from
        // the invoice list, which is the whole reason the endpoint carries it separately.
    });
});

describe('credit is shown whenever it exists, not only when nothing is owed', () => {
    it('shows credit alongside an outstanding invoice', () => {
        // THE ARM THAT STOPS "credit" BECOMING "the empty-list decoration". A parent can hold
        // unapplied credit and owe on a newer invoice at the same time; if this only fired on an
        // empty list, their money would be invisible on the one screen that reports their position.
        const view = wardView(
            ward({
                account: {
                    balance: naira(120000),
                    available_credit: naira(60000),
                },
            }),
        );

        expect(view.invoices).toEqual({ kind: 'invoices', count: 1 });
        expect(view.showCredit).toBe(true);
        expect(view.inCredit).toBe(false); // still owes overall
    });

    it('does not show a credit line when there is none', () => {
        expect(wardView(ward()).showCredit).toBe(false);
    });
});

describe('the zero boundaries, pinned in both directions', () => {
    // Zero is the value that decides which sentence a parent reads, so each predicate is pinned on
    // both sides of it rather than at one convenient point.
    it('treats a zero balance as neither owing nor in credit', () => {
        expect(isInCredit(naira(0))).toBe(false);
        expect(isInCredit(naira(-1))).toBe(true);
        expect(isInCredit(naira(1))).toBe(false);
    });

    it('treats zero available credit as no credit', () => {
        expect(hasAvailableCredit(naira(0))).toBe(false);
        expect(hasAvailableCredit(naira(1))).toBe(true);
    });
});

describe('no wards is a legitimate state, not a failure', () => {
    it('distinguishes "no children in this school" from a list of wards', () => {
        // The endpoint answers a guardian with no row in the active school with an empty list rather
        // than a 403 — they may hold wards in a school they have not switched to. The page must not
        // render that through the same branch as a request that failed.
        expect(wardsView([])).toEqual({ kind: 'no-wards' });
        expect(wardsView([ward()]).kind).toBe('wards');
    });
});

describe('ward identity', () => {
    it('keys on the student uuid the endpoint sends, never an integer id', () => {
        expect(wardView(ward()).id).toBe('ward-uuid-1');
    });
});
