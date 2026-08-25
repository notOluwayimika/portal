import type { Money } from '@/types/finance';

/**
 * The SINGLE money renderer (Constitution-named). All money in the UI is displayed
 * through this, from the wire shape {amount_minor, currency} — e.g. formatNaira({
 * amount_minor: -250075, currency: 'NGN' }) → "-₦2,500.75".
 *
 * THE LOAD-BEARING RULE: the frontend performs NO monetary arithmetic. JS numbers are
 * floats; the backend moved to integer minor units precisely to avoid float money bugs.
 * The API returns every total, balance and outstanding already computed — the UI only
 * DISPLAYS. This file is the ONE place allowed to touch Intl.NumberFormat/toLocaleString
 * for money; bin/ci-money-lint.php bans them everywhere else and flags money arithmetic.
 *
 * Kept float-free where it counts: the naira and kobo parts are split with integer ops
 * (trunc + %), so the significant digits are exact; Intl only groups the whole-naira
 * integer with thousands separators.
 */
export function formatNaira(money: Money): string {
    const { amount_minor, currency } = money;
    const negative = amount_minor < 0;
    const abs = Math.abs(amount_minor);
    const naira = Math.trunc(abs / 100); // integer naira part (exact for amounts < 2^53)
    const kobo = abs % 100; // exact
    const symbol = currency === 'NGN' ? '₦' : `${currency} `;
    const grouped = new Intl.NumberFormat('en-NG').format(naira);

    return `${negative ? '-' : ''}${symbol}${grouped}.${String(kobo).padStart(2, '0')}`;
}

/**
 * Parse a naira amount the user typed ("2500.75") into integer minor units (250075) to
 * send to the API — the inverse boundary of formatNaira, and the ONLY sanctioned place a
 * money value is converted. Float-free on purpose: `2500.75 * 100` misfires in floating
 * point, so the whole and fractional parts are parsed as integers and combined exactly
 * (mirrors the backend Money::fromNaira). Returns null on malformed input (for inline
 * validation) — never guesses. This lives beside formatNaira so all money conversion is
 * in one lint-exempt file; callers never do the arithmetic themselves.
 *
 * FLOAT-FREE HAS A CEILING, AND IT IS Number.MAX_SAFE_INTEGER (2^53 − 1 minor units, i.e.
 * ₦90,071,992,547,409.91). Integer arithmetic in JS is exact only while the result fits a
 * double's mantissa; above that, `Number(whole) * 100` rounds and the parse returns a
 * number that is not the amount that was typed. The regex above constrains the SHAPE of
 * the input and says nothing about its magnitude, so it admits as many digits as anyone
 * cares to type. This is the INPUT path — a wrong integer here is POSTed as the operator's
 * intended amount and accepted by a server with no reason to doubt it, with no error
 * anywhere. Silent corruption, which is the one failure mode a validation boundary must
 * not have.
 *
 * IT IS REFUSED THROUGH THE SAME `return null` THE REGEX MISMATCH USES, deliberately. The
 * callers already render an invalid-input state for a malformed amount; an out-of-range one
 * is malformed for the same reason (this function cannot represent it), and inventing a
 * second failure mode would mean every caller learning about a case none of them have a
 * screen for. `Number.isSafeInteger` is asked about the COMPUTED value rather than the
 * digit count because that is the property that matters: any product at or above 2^53 is
 * by definition not a safe integer, and every result below it was computed exactly.
 *
 * The ceiling is far above the domain — the backend's own is intdiv(PHP_INT_MAX, 100),
 * about 9.22e16, an order of magnitude higher, and a school fee is nowhere near either.
 * The point is not that the case arises; it is that a boundary must not be able to alter
 * the figure it converts.
 *
 * NEGATIVE ZERO IS NORMALISED TO ZERO. `'-0'` and `'-0.00'` both parse to a magnitude of
 * 0 with the sign flag set, and `-0` is a distinct IEEE value: `Object.is(-0, 0)` is false,
 * `1 / -0` is -Infinity, and a test written with `toBe` compares with `Object.is`. There is
 * no such quantity as minus zero naira, so the only thing that value can do downstream is
 * surprise a sign check or an equality test that is otherwise correct.
 */
export function nairaToMinor(input: string): number | null {
    const trimmed = input.trim();

    if (!/^-?\d+(\.\d{1,2})?$/.test(trimmed)) {
        return null;
    }

    const negative = trimmed.startsWith('-');
    const [whole, frac = ''] = trimmed.replace('-', '').split('.');
    const minor = Number(whole) * 100 + Number(frac.padEnd(2, '0'));

    // Above 2^53 the line above has already rounded — see the docblock. Same refusal as a
    // malformed string, because it is malformed for the same reason.
    if (!Number.isSafeInteger(minor)) {
        return null;
    }

    return negative && minor !== 0 ? -minor : minor;
}

/**
 * Render a stored amount back into the plain string an amount INPUT holds ("250075" →
 * "2500.75") — the inverse of nairaToMinor, and the shape it accepts back verbatim. This is
 * what prefills an edit form: formatNaira's output ("₦2,500.75") is for reading, and feeding
 * it to an input would make nairaToMinor reject the operator's own unchanged value.
 *
 * Mirrors the backend Money::toNaira() exactly, and is float-free the same way: integer trunc
 * and modulo, no division of the significant digits, no toFixed. It lives here because this is
 * the ONE file where money conversion is allowed to happen at all (bin/ci-money-lint.php exempts
 * it and bans the arithmetic everywhere else) — a caller doing `amount_minor / 100` itself is
 * exactly what that gate exists to refuse.
 */
export function minorToNairaInput(money: Money): string {
    const { amount_minor } = money;
    const abs = Math.abs(amount_minor);
    const naira = Math.trunc(abs / 100); // exact for amounts < 2^53
    const kobo = abs % 100; // exact

    return `${amount_minor < 0 ? '-' : ''}${naira}.${String(kobo).padStart(2, '0')}`;
}

/**
 * Sum integer minor-unit amounts (e.g. a live invoice-line running total). One of the five
 * sanctioned money ops — display→formatNaira, input→nairaToMinor, prefill→minorToNairaInput,
 * total→sumMinor, headroom→differenceMinor — so a form never does ad-hoc `+`/`reduce` on amounts
 * (banned by the money-lint).
 *
 * THE COUNT IN THIS DOCBLOCK HAS NOW BEEN WRONG TWICE, in two different ways, and both are worth
 * keeping. It first said "the third and LAST sanctioned money op", which was a prediction rather
 * than a rule: U10's allocation screen needs a live "still unallocated" figure, which is a
 * subtraction, and the honest choices were a fourth op here or the same subtraction spelled inline
 * in a page — precisely what the lint exists to refuse. It then said FOUR and listed four while the
 * file exported five: minorToNairaInput was added with its own docblock and never added to this
 * list, so the enumeration silently stopped being one. A number in prose beside a list is checked
 * by nobody; if the count and the list disagree with `export`, the list is what a reader trusts.
 *
 * Neither correction moved the boundary, only the count: this file is still the only place money
 * arithmetic happens.
 *
 * Float-SAFE precisely because it is INTEGER addition: minor units are whole numbers and
 * their sum is exact for any realistic total (far below 2^53). The danger the money rule
 * guards against is float — `0.1 + 0.2` — which cannot arise here since nothing has a
 * fractional part. Reductions carry a NEGATIVE amount, so this signed sum equals the
 * server's F6 total (charges − reductions); the preview only mirrors the authoritative total.
 */
export function sumMinor(amounts: number[]): number {
    return amounts.reduce((total, amount) => total + amount, 0);
}

/**
 * a − b over integer minor units, for a live "how much of this is left" figure — U10's allocation
 * screen, where an operator editing a table of amounts has to see the payment's remaining headroom
 * change as they type.
 *
 * FLOAT-SAFE for the same reason sumMinor is: minor units are whole numbers, so their difference is
 * exact for any realistic amount (far below 2^53). The float danger the money rule guards against is
 * `0.1 + 0.2`, which cannot arise where nothing has a fractional part.
 *
 * IT MAY RETURN A NEGATIVE, and callers must not clamp it. Over-allocating is a state the operator
 * needs to SEE while they are in it — the screen shows the overshoot and refuses the submit, which is
 * better than a figure that sits at zero while the numbers above it do not add up. The server refuses
 * it too, and the finance_allocation_not_over_payment_amount trigger is the floor under both.
 */
export function differenceMinor(a: number, b: number): number {
    return a - b;
}
