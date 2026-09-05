import type { FinanceWardInvoiceLine } from '@/types/parent-finance';

/**
 * SPLIT A BILL'S LINES THE WAY A PAYER READS THEM — charges, then what came off.
 *
 * Extracted from the component so the rule is testable: this repository's vitest suite tests
 * LOGIC rather than rendering (there is no testing-library here), and "reductions appear beneath
 * charges" is a claim worth an assertion rather than a docblock.
 *
 * THE SPLIT IS ON THE SIGN, NOT ON `kind`. The sign of the amount is what carries the arithmetic —
 * `InvoiceLine::kind` says what a line MEANS (charge / waiver / discount) and the signed SUM never
 * branches on it. Splitting on `kind` would put a mislabelled row on the wrong side of the bill and
 * produce a breakdown that does not visibly add up; splitting on the sign cannot.
 *
 * A ZERO line is a charge. It is not a reduction — it takes nothing off — and a fully-waived item
 * that nets to zero belongs where the thing being charged for is listed.
 *
 * ORDER IS PRESERVED within each group, so the bill reads in the order the bursar composed it.
 */
export function splitInvoiceLines(lines: FinanceWardInvoiceLine[]): {
    charges: FinanceWardInvoiceLine[];
    reductions: FinanceWardInvoiceLine[];
} {
    return {
        charges: lines.filter((line) => line.amount.amount_minor >= 0),
        reductions: lines.filter((line) => line.amount.amount_minor < 0),
    };
}
