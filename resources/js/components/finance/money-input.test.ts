import { describe, expect, it } from 'vitest';
import { inputValueToMinor, minorToInputValue } from './money-input';

/**
 * The two value mappings <MoneyInput> is made of, tested without a DOM.
 *
 * WHAT THIS FILE DOES AND DOES NOT PROVE, stated up front because the component's whole
 * job is split across a boundary. <MoneyInput> is react-number-format's mask plus these
 * two functions. The mask — separators appearing as digits are typed, the caret landing
 * where a person expects after one does, paste, selection-replace, backspacing a comma —
 * is the vendor's, is not reimplemented here, and is NOT exercised by these tests. What
 * is exercised is the part this repository wrote: the conversion between integer minor
 * units and the unformatted decimal string the mask sits on top of. That is also the part
 * that can corrupt an amount silently, which is why it is the part with tests.
 *
 * ENVIRONMENT IS NODE, per vitest.config.ts:31, and no DOM environment was added. These
 * functions are pure string/integer mapping; installing jsdom to reach them would add a
 * dependency and seconds per run to test code that never touches a document.
 *
 * THE CASES ARE THE SAME FIVE SHAPES resources/js/lib/format.test.ts uses, for the same
 * reason — each has broken a money field somewhere: ZERO (a real amount, and the one a
 * truthiness check swallows), a SUB-NAIRA amount (whole part 0), a KOBO REMAINDER BELOW
 * TEN (5 kobo is `.05`, not `.5`), a MAGNITUDE needing two thousands separators, and the
 * two non-amounts a live field spends most of its keystrokes in: EMPTY and UNPARSEABLE.
 * Every expectation states the exact string or integer rather than describing it.
 */

describe('minorToInputValue', () => {
    it('renders zero as a full two-decimal figure, not an empty field', () => {
        // The falsiness bug: `value ? … : ''` blanks a legitimate ₦0.00 and tells the
        // operator nothing was entered.
        expect(minorToInputValue(0)).toBe('0.00');
    });

    it('renders a sub-naira amount with a zero whole part', () => {
        expect(minorToInputValue(75)).toBe('0.75');
    });

    it('zero-pads a kobo remainder below ten', () => {
        expect(minorToInputValue(5)).toBe('0.05');
        expect(minorToInputValue(250005)).toBe('2500.05');
    });

    it('emits the plain ungrouped decimal at a magnitude the mask will group twice', () => {
        // ₦1,000,000.00 — two separators once react-number-format has it. What this
        // function hands over carries NONE of them, which is the point: the string is
        // also what nairaToMinor has to accept back.
        expect(minorToInputValue(100000000)).toBe('1000000.00');
    });

    it('renders an empty field for null, and only for null', () => {
        expect(minorToInputValue(null)).toBe('');
    });

    it('keeps the sign on a negative amount', () => {
        expect(minorToInputValue(-250075)).toBe('-2500.75');
    });
});

describe('inputValueToMinor', () => {
    it('parses zero, a sub-naira amount and a kobo remainder below ten', () => {
        expect(inputValueToMinor('0.00')).toBe(0);
        expect(inputValueToMinor('0.75')).toBe(75);
        expect(inputValueToMinor('0.05')).toBe(5);
    });

    it('parses a magnitude the mask would group twice', () => {
        expect(inputValueToMinor('1000000.00')).toBe(100000000);
    });

    it('returns null for an empty field', () => {
        expect(inputValueToMinor('')).toBeNull();
    });

    it('returns null for unparseable input rather than guessing', () => {
        expect(inputValueToMinor('abc')).toBeNull();
        expect(inputValueToMinor('-')).toBeNull(); // a lone minus, mid-typing
        expect(inputValueToMinor('1.234')).toBeNull(); // three decimal places
    });

    it('still refuses a string carrying thousands separators', () => {
        // NOT a gap — the pin. This function is handed react-number-format's already
        // unformatted value, so a separator reaching it would mean the mask had failed.
        // If this ever goes green, the strict parser has been loosened and the component
        // is no longer the thing keeping separators out of it.
        expect(inputValueToMinor('1,000,000.00')).toBeNull();
    });
});

describe('round trip', () => {
    it('returns the original integer through display and back', () => {
        for (const amountMinor of [0, 5, 75, 100, 250075, 100000000, -250075]) {
            expect(inputValueToMinor(minorToInputValue(amountMinor))).toBe(
                amountMinor,
            );
        }
    });
});
