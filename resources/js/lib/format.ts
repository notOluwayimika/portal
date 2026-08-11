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
 */
export function nairaToMinor(input: string): number | null {
    const trimmed = input.trim();

    if (!/^-?\d+(\.\d{1,2})?$/.test(trimmed)) {
        return null;
    }

    const negative = trimmed.startsWith('-');
    const [whole, frac = ''] = trimmed.replace('-', '').split('.');
    const minor = Number(whole) * 100 + Number(frac.padEnd(2, '0'));

    return negative ? -minor : minor;
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
 * Sum integer minor-unit amounts (e.g. a live invoice-line running total). The third and
 * last sanctioned money op — display→formatNaira, input→nairaToMinor, total→sumMinor — so
 * the create form never does ad-hoc `+`/`reduce` on amounts (banned by the money-lint).
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
