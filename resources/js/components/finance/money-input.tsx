import type { ComponentProps } from 'react';
import { NumericFormat } from 'react-number-format';
import { Input } from '@/components/ui/input';
import { minorToNairaInput, nairaToMinor } from '@/lib/format';

/**
 * The naira amount INPUT — a thin adapter over react-number-format that lets an operator
 * see thousands separators and two decimals as they type, while the form above it still
 * holds the amount as integer minor units.
 *
 * IT EXISTS BECAUSE THE PARSER IS STRICT AND MUST STAY STRICT. nairaToMinor
 * (resources/js/lib/format.ts:71) accepts `^-?\d+(\.\d{1,2})?$` and nothing else, so a
 * separator anywhere in the string is a null — i.e. the operator's own displayed figure,
 * fed back, would be refused. The obvious "fix" is to loosen the regex to strip commas;
 * that is the wrong repair, because the regex is the thing that makes a malformed amount
 * REFUSED rather than guessed at, and every character it starts tolerating is a character
 * it stops using to tell an amount from a typo. So the separators are put on and taken off
 * on THIS side of the boundary, and the parser is handed a clean string exactly as before.
 *
 * MASKING AND CARET ARE NOT OURS. react-number-format owns the whole of it — where the
 * caret lands after a separator appears or disappears, what a paste means, what a
 * selection-replace means, what backspacing a separator does. Hand-rolled cursor
 * arithmetic over a live-formatted field is a well-known way to produce an input that is
 * correct on every value and unusable at speed; there is no local version of it here, by
 * choice. What this component contributes is two value mappings and nothing else.
 *
 * THE SIGN IS NOT BLOCKED at the mask. react-number-format's allowNegative default (true)
 * is left alone so a caller's own validation is what rejects a negative — the credit-note
 * modal, the first call site, rejects `<= 0` with a message of its own, and a mask that
 * silently refused the minus key would make that branch unreachable and its message a lie.
 */

/**
 * Minor units → the unformatted string react-number-format holds (250075 → "2500.75");
 * null (an empty field) → "".
 *
 * THE NULL TEST IS `=== null`, NOT FALSINESS. Zero is a real amount an operator can be
 * looking at — a cleared-to-zero line, a prefilled ₦0.00 — and `value ? … : ''` would blank
 * the field for it, which reads to the operator as "nothing entered" and to the form above
 * as a different value than the one it passed down.
 *
 * The currency is nominal: minorToNairaInput reads only amount_minor (it renders the
 * machine decimal, no symbol), and this component is the NAIRA input by name.
 */
export function minorToInputValue(amountMinor: number | null): string {
    if (amountMinor === null) {
        return '';
    }

    return minorToNairaInput({ amount_minor: amountMinor, currency: 'NGN' });
}

/**
 * The unformatted string react-number-format reports → minor units, or null when the field
 * is empty or holds something the parser will not commit to (a lone "-" mid-typing, a
 * value past the safe-integer ceiling).
 *
 * IT DOES NOT STRIP SEPARATORS, deliberately. The string handed here is `values.value` —
 * react-number-format's own unformatted value, with the mask already removed — so a comma
 * arriving in it would mean the mask had failed, and quietly deleting it would hide that.
 * A second, more forgiving parser next to the strict one is how the strict one stops being
 * the boundary.
 */
export function inputValueToMinor(rawValue: string): number | null {
    return nairaToMinor(rawValue);
}

type MoneyInputProps = Omit<
    ComponentProps<typeof Input>,
    'value' | 'defaultValue' | 'onChange' | 'type'
> & {
    /** Current amount in integer minor units, or null when the field is empty. */
    value: number | null;
    /** Fires with integer minor units, or null when empty/unparseable. */
    onChange: (amountMinor: number | null) => void;
};

export function MoneyInput({ value, onChange, ...props }: MoneyInputProps) {
    return (
        <NumericFormat
            {...props}
            customInput={Input}
            // Not type="number": a number input has no thousands separators to give, and
            // its spinner and locale-dependent decimal handling are both wrong for money.
            inputMode="decimal"
            value={minorToInputValue(value)}
            valueIsNumericString
            thousandSeparator=","
            decimalScale={2}
            fixedDecimalScale
            onValueChange={(values) =>
                onChange(inputValueToMinor(values.value))
            }
        />
    );
}
