import { describe, expect, it } from 'vitest';

import { splitInvoiceLines } from '@/lib/invoice-breakdown';
import type { FinanceWardInvoiceLine } from '@/types/parent-finance';

const line = (
    description: string,
    amount_minor: number,
    kind = 'charge',
): FinanceWardInvoiceLine => ({
    description,
    kind,
    amount: { amount_minor, currency: 'NGN' },
});

/**
 * The fixture is Ada's drive bill, and it is deliberately NOT degenerate: two charges of different
 * amounts and a reduction, so "reductions beneath charges" cannot pass by coincidence. A single
 * charge and a single reduction would be satisfied by any split that puts one on each side.
 */
const adaBill = [
    line('Tuition', 25_000_000),
    line('Development levy', 2_500_000),
    line('Sibling discount', -2_750_000, 'discount'),
];

describe('splitInvoiceLines', () => {
    it('puts reductions beneath charges', () => {
        const { charges, reductions } = splitInvoiceLines(adaBill);

        expect(charges.map((l) => l.description)).toEqual([
            'Tuition',
            'Development levy',
        ]);
        expect(reductions.map((l) => l.description)).toEqual([
            'Sibling discount',
        ]);
    });

    it('splits on the SIGN, not on kind — a mislabelled row still lands correctly', () => {
        // A reduction carrying `kind: 'charge'`. Splitting on kind would list it among the charges
        // and the breakdown would visibly fail to add up; splitting on the sign cannot.
        const { charges, reductions } = splitInvoiceLines([
            line('Tuition', 25_000_000),
            line('Bursary', -5_000_000, 'charge'),
        ]);

        expect(charges.map((l) => l.description)).toEqual(['Tuition']);
        expect(reductions.map((l) => l.description)).toEqual(['Bursary']);
    });

    it('counts a zero line as a charge, never as a reduction', () => {
        const { charges, reductions } = splitInvoiceLines([line('Books', 0)]);

        expect(charges).toHaveLength(1);
        expect(reductions).toHaveLength(0);
    });

    it('loses no line and duplicates none', () => {
        const { charges, reductions } = splitInvoiceLines(adaBill);

        expect(charges.length + reductions.length).toBe(adaBill.length);
        expect(
            [...charges, ...reductions].map((l) => l.description).sort(),
        ).toEqual(adaBill.map((l) => l.description).sort());
    });
});
