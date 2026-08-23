import { describe, expect, it } from 'vitest';
import type { Money } from '@/types/finance';
import {
    differenceMinor,
    formatNaira,
    minorToNairaInput,
    nairaToMinor,
    sumMinor,
} from './format';

/**
 * The first tests in this repository's frontend, and they are on the money boundary
 * deliberately: resources/js/lib/format.ts is the ONE file bin/ci-money-lint.php exempts
 * from the ban on Intl formatting and on money arithmetic, so every naira figure the UI
 * shows and every amount an operator types passes through these five functions. They were
 * exempted from the gate on the strength of review alone — nothing executed them.
 *
 * WHAT EACH CASE IS FOR, since "a few numbers" is not a specification. Five shapes are
 * covered for every function, because each one has broken a money renderer somewhere:
 * ZERO (the empty-state figure, and the one a truthiness check silently swallows), a
 * NEGATIVE (reductions and credit notes are negative amounts, and the sign has to survive
 * the split into naira and kobo), a SUB-NAIRA amount (whole part 0 — the case where an
 * integer-division renderer prints nothing at all), a NON-ZERO KOBO REMAINDER (the
 * zero-padding: 5 kobo is `.05`, not `.5`), and a value large enough to need TWO thousands
 * separators (grouping is not exercised at all below ₦1,000).
 *
 * These are behaviour tests, not shape tests. Every expectation states the exact string or
 * integer rather than describing it — an oracle that says `toMatch(/₦/)` cannot tell a
 * correct renderer from one that has lost the kobo.
 *
 * NOTHING IN A describe BLOCK CALLS ANOTHER FUNCTION'S RENDERER, with one deliberate exception.
 * An earlier draft asserted formatNaira() over the result inside the sumMinor and differenceMinor
 * blocks; bite-proving showed what that costs — breaking formatNaira's kobo padding reddened four
 * tests across three blocks, two of which name a function that was not touched. A test that reds
 * for a defect in something it does not name sends the next reader to the wrong file. The
 * exception is inside minorToNairaInput, where the pair of assertions IS the claim: the same
 * amount grouped by formatNaira and ungrouped here, which is the distinction that docblock is
 * about and cannot be stated with one of them.
 */

const ngn = (amount_minor: number): Money => ({
    amount_minor,
    currency: 'NGN',
});

describe('formatNaira', () => {
    it('renders zero as a full two-decimal figure, not an empty or bare one', () => {
        expect(formatNaira(ngn(0))).toBe('₦0.00');
    });

    it('puts the sign before the symbol on a negative amount', () => {
        expect(formatNaira(ngn(-250075))).toBe('-₦2,500.75');
        expect(formatNaira(ngn(-50))).toBe('-₦0.50');
    });

    it('renders a sub-naira amount with a zero whole part', () => {
        expect(formatNaira(ngn(75))).toBe('₦0.75');
    });

    it('zero-pads a kobo remainder below ten', () => {
        expect(formatNaira(ngn(250005))).toBe('₦2,500.05');
        expect(formatNaira(ngn(250075))).toBe('₦2,500.75');
    });

    it('groups the whole-naira part with thousands separators', () => {
        expect(formatNaira(ngn(1234567890))).toBe('₦12,345,678.90');
    });

    it('prefixes a non-NGN currency with its ISO code instead of the naira mark', () => {
        expect(formatNaira({ amount_minor: 123456, currency: 'USD' })).toBe(
            'USD 1,234.56',
        );
    });
});

describe('nairaToMinor', () => {
    it('parses zero', () => {
        expect(nairaToMinor('0')).toBe(0);
        expect(nairaToMinor('0.00')).toBe(0);
    });

    it('carries the sign of a negative amount into the minor units', () => {
        expect(nairaToMinor('-2500.75')).toBe(-250075);
    });

    it('parses a sub-naira amount', () => {
        expect(nairaToMinor('0.75')).toBe(75);
    });

    it('parses a non-zero kobo remainder, and pads a single kobo digit', () => {
        expect(nairaToMinor('2500.75')).toBe(250075);
        expect(nairaToMinor('2500.5')).toBe(250050);
        expect(nairaToMinor('2500.05')).toBe(250005);
    });

    it('parses a value large enough to need two thousands separators', () => {
        expect(nairaToMinor('12345678.90')).toBe(1234567890);
    });

    it('refuses a string that carries thousands separators', () => {
        expect(nairaToMinor('2,500.75')).toBeNull();
    });

    it('refuses a trailing decimal point', () => {
        expect(nairaToMinor('2500.')).toBeNull();
    });

    it('refuses more than two decimal places rather than truncating', () => {
        expect(nairaToMinor('2500.755')).toBeNull();
    });

    it('accepts leading zeros', () => {
        expect(nairaToMinor('0025.50')).toBe(2550);
    });

    it('refuses an empty or blank string', () => {
        expect(nairaToMinor('')).toBeNull();
        expect(nairaToMinor('   ')).toBeNull();
    });

    it('trims surrounding whitespace before parsing', () => {
        expect(nairaToMinor(' 2500.75 ')).toBe(250075);
    });
});

describe('minorToNairaInput', () => {
    it('renders zero as the two-decimal shape an amount input holds', () => {
        expect(minorToNairaInput(ngn(0))).toBe('0.00');
    });

    it('keeps the sign of a negative amount', () => {
        expect(minorToNairaInput(ngn(-250075))).toBe('-2500.75');
        expect(minorToNairaInput(ngn(-50))).toBe('-0.50');
    });

    it('renders a sub-naira amount with a zero whole part', () => {
        expect(minorToNairaInput(ngn(75))).toBe('0.75');
    });

    it('zero-pads a kobo remainder below ten', () => {
        expect(minorToNairaInput(ngn(250005))).toBe('2500.05');
    });

    it('emits NO thousands separators, at a magnitude formatNaira would give two', () => {
        expect(minorToNairaInput(ngn(1234567890))).toBe('12345678.90');
        expect(formatNaira(ngn(1234567890))).toBe('₦12,345,678.90');
    });

    it('produces a string nairaToMinor accepts back unchanged', () => {
        const amounts = [0, 75, 250005, -250075, 1234567890];

        expect(
            amounts.map((a) => nairaToMinor(minorToNairaInput(ngn(a)))),
        ).toEqual(amounts);
    });
});

describe('sumMinor', () => {
    it('sums an empty list, and a list of zeros, to zero', () => {
        expect(sumMinor([])).toBe(0);
        expect(sumMinor([0, 0, 0])).toBe(0);
    });

    it('subtracts a negative amount, so a reduction reduces the total', () => {
        expect(sumMinor([250075, -50000])).toBe(200075);
        expect(sumMinor([-100, -50])).toBe(-150);
    });

    it('sums sub-naira amounts exactly', () => {
        expect(sumMinor([25, 50])).toBe(75);
    });

    it('carries a non-zero kobo remainder', () => {
        expect(sumMinor([250075, 1])).toBe(250076);
    });

    it('sums to a value large enough to need two thousands separators', () => {
        expect(sumMinor([1234567890, 10])).toBe(1234567900);
    });
});

describe('differenceMinor', () => {
    it('returns zero when the two amounts are equal', () => {
        expect(differenceMinor(0, 0)).toBe(0);
        expect(differenceMinor(250075, 250075)).toBe(0);
    });

    it('returns a NEGATIVE on an overshoot rather than clamping at zero', () => {
        expect(differenceMinor(100, 250)).toBe(-150);
    });

    it('subtracts sub-naira amounts exactly', () => {
        expect(differenceMinor(75, 25)).toBe(50);
    });

    it('carries a non-zero kobo remainder', () => {
        expect(differenceMinor(250075, 50)).toBe(250025);
    });

    it('subtracts at a magnitude needing two thousands separators', () => {
        expect(differenceMinor(1234567890, 890)).toBe(1234567000);
    });
});
