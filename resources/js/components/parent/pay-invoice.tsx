import axios from 'axios';
import { useState } from 'react';
import { formatNaira } from '@/lib/format';
import { paymentReturnUrl } from '@/lib/payment-return-url';
import type { FinanceWardInvoice, Money } from '@/types/parent-finance';

/**
 * PAY ONE BILL — the amount field, the confirmation, and the hand-off to the provider.
 *
 * ── EVERY FIGURE ON THE CONFIRMATION COMES FROM THE SERVER ──
 *
 * The gross a parent is charged is a fee computation, and money is never computed in the browser
 * (`bin/ci-money-lint.php`). So the flow is: the parent types an amount, the server PREVIEWS it, and
 * the confirmation renders the server's three figures. Nothing here adds, subtracts or compares
 * naira.
 *
 * A FEE PREVIEW, NOT A QUOTE. "Quote" is the step-5 spec's word for the parked design that asks
 * Paystack first; this is local arithmetic and no provider is called until the parent confirms.
 *
 * ── THE PREVIEW'S NUMBERS ARE DISPLAY-ONLY AND NEVER GO BACK ──
 *
 * Confirm posts `amount_minor` — the amount the parent typed — and the server recomputes the gross
 * from it. The preview's gross is never returned, so a tampered figure cannot become a charge. If
 * this component ever posts a figure it received, that property is gone.
 *
 * ── THERE IS NO PAYABILITY CHECK HERE, DELIBERATELY ──
 *
 * `GuardianFinanceController::wards` withholds unreleased bills on both keys, so every invoice that
 * reaches this component is already released. A client-side release check would be a second spelling
 * of a server-side control, and the copy could drift into offering a button the server refuses. The
 * button's condition is that the bill is in the list.
 */

type Preview = { gross: Money; applied: Money; excess: Money };

type Stage =
    | { at: 'idle' }
    | { at: 'previewing' }
    | { at: 'confirming'; amountMinor: number; preview: Preview }
    | { at: 'starting' }
    | { at: 'refused'; message: string };

/** NGN 1,000. A CONVENIENCE, NOT THE CONTROL — the server refuses independently. */
const MINIMUM_MINOR = 100_000;

export function PayInvoice({
    invoice,
    studentName,
    schoolName,
}: {
    invoice: FinanceWardInvoice;
    studentName: string;
    schoolName: string;
}) {
    const [naira, setNaira] = useState('');
    const [stage, setStage] = useState<Stage>({ at: 'idle' });

    // Naira string to minor units. The ONLY arithmetic in this file, and it is a unit conversion of
    // the parent's own typed input rather than a computation over money the server sent.
    const amountMinor = Math.round(Number(naira.replace(/,/g, '')) * 100);
    const usable = Number.isFinite(amountMinor) && amountMinor > 0;
    const belowMinimum = usable && amountMinor < MINIMUM_MINOR;

    async function preview() {
        setStage({ at: 'previewing' });

        try {
            const { data } = await axios.post<Preview>(
                `/api/parent/invoices/${invoice.id}/payment/preview`,
                { amount_minor: amountMinor },
            );

            setStage({ at: 'confirming', amountMinor, preview: data });
        } catch (error) {
            setStage({ at: 'refused', message: refusalMessage(error) });
        }
    }

    async function confirm(amount: number) {
        setStage({ at: 'starting' });

        try {
            // AMOUNT ONLY. The gross is not sent back — see the note on this component.
            const { data } = await axios.post<{ authorization_url: string }>(
                `/api/parent/invoices/${invoice.id}/payment`,
                {
                    amount_minor: amount,
                    // WHERE PAYSTACK RETURNS THE PAYER (§6 step 6). Absolute, built from this
                    // origin, and pinned to the registered route by `GatewayReturnRouteTest` —
                    // which reads `PAYMENT_RETURN_PATH` out of the module rather than restating it.
                    //
                    // WITHOUT THIS LINE Paystack falls back to the dashboard's default return URL
                    // and the payer never reaches the page built to tell them what happened.
                    callback_url: paymentReturnUrl(window.location.origin),
                },
            );

            window.location.href = data.authorization_url;
        } catch (error) {
            setStage({ at: 'refused', message: refusalMessage(error) });
        }
    }

    if (stage.at === 'confirming') {
        const preview = stage.preview;
        const overpaying = preview.excess.amount_minor > 0;

        return (
            <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                {/*
                    THE SCHOOL IS NAMED, NOT ONLY THE CHILD, and the school is the discriminator. A
                    parent with children at two campuses who is served the wrong school's data
                    recognises both names; only the school tells them they are in the wrong place.
                */}
                <p className="mb-2 text-sm font-medium text-gray-900">
                    Paying for {studentName} — {schoolName}
                </p>

                {overpaying ? (
                    /*
                        ONE QUANTITY PER LINE, AND THE LARGE CREDIT ABOVE THE FEE SENTENCE. The
                        approved wording's "the remainder is credited to your account" exists to
                        explain a fee estimate of a few hundred naira. At an overpayment it is
                        technically true and completely misleading, so the excess is stated on its
                        own line, in its own words, before the fee is mentioned at all.
                    */
                    <div className="space-y-1 text-sm text-gray-800">
                        <p>
                            You'll be charged{' '}
                            <strong>{formatNaira(preview.gross)}</strong>.
                        </p>
                        <p>
                            <strong>{formatNaira(preview.applied)}</strong>{' '}
                            settles invoice {invoice.display_number} for{' '}
                            {studentName}.
                        </p>
                        <p>
                            <strong>{formatNaira(preview.excess)}</strong> will
                            be held as credit on their account at {schoolName}.
                        </p>
                        <p className="text-gray-600">
                            The rest is the payment processing charge. If it
                            comes to less than we've estimated, that difference
                            is credited too.
                        </p>
                    </div>
                ) : (
                    /* DEV 1'S APPROVED WORDING, VERBATIM, for the ordinary case. */
                    <p className="text-sm text-gray-800">
                        You'll be charged{' '}
                        <strong>{formatNaira(preview.gross)}</strong>. This
                        settles <strong>{formatNaira(preview.applied)}</strong>{' '}
                        on invoice {invoice.display_number}. The difference is
                        the payment processing charge — if it comes to less than
                        we've estimated, the remainder is credited to your
                        account.
                    </p>
                )}

                <div className="mt-4 flex gap-2">
                    <button
                        type="button"
                        onClick={() => confirm(stage.amountMinor)}
                        className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                    >
                        Continue to payment
                    </button>
                    <button
                        type="button"
                        onClick={() => setStage({ at: 'idle' })}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700"
                    >
                        Back
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="mt-3">
            <div className="flex flex-wrap items-center gap-2">
                <label
                    className="text-sm text-gray-600"
                    htmlFor={`amount-${invoice.id}`}
                >
                    Amount
                </label>
                <input
                    id={`amount-${invoice.id}`}
                    inputMode="decimal"
                    value={naira}
                    onChange={(event) => setNaira(event.target.value)}
                    placeholder={formatNaira(invoice.outstanding)}
                    className="w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm"
                />
                <button
                    type="button"
                    disabled={stage.at === 'previewing' || !usable}
                    onClick={preview}
                    className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                >
                    {stage.at === 'previewing' ? 'Checking…' : 'Pay this bill'}
                </button>
            </div>

            {belowMinimum && (
                /*
                    A CONVENIENCE SO A PARENT IS NOT SENT TO PAYSTACK TO BE REFUSED. The server
                    enforces the same minimum independently; this is not the control, and its test
                    says so.
                */
                <p className="mt-2 text-sm text-amber-700">
                    The smallest payment we can take online is ₦1,000. For a
                    smaller amount, please pay at the school office.
                </p>
            )}

            {stage.at === 'refused' && (
                <p role="alert" className="mt-2 text-sm text-red-800">
                    {stage.message}
                </p>
            )}
        </div>
    );
}

/**
 * The server's own refusal where there is one, and a generic sentence where there is not.
 *
 * THE STALE-PREVIEW CASE HAS ITS OWN COPY, AND IT IS DELIBERATELY NEUTRAL. Another guardian can
 * settle the bill while this parent reads the confirmation, and initiate then correctly refuses what
 * preview allowed — but 403/404 is ALSO what a voided invoice and an unresolvable uuid return, since
 * the request refuses rather than 404s so as not to leak whether a uuid exists. Saying "settled"
 * would be a specific claim the status code does not support, and false for the voided case: the
 * same shape as a correct refusal carrying a wrong sentence. So the wording is true across all of
 * them, and distinguishing settled from voided would need a signal the server does not currently
 * send.
 *
 * THE PENDING CASE IS NOT HERE because this component never sees it: a parent who reaches Paystack
 * leaves this screen. It belongs to the return path (step 6).
 */
function refusalMessage(error: unknown): string {
    const status = axios.isAxiosError(error)
        ? error.response?.status
        : undefined;
    const message = axios.isAxiosError(error)
        ? (error.response?.data as { message?: string } | undefined)?.message
        : undefined;

    if (status === 503) {
        return 'The payment provider is not responding. Nothing has been charged — please try again shortly.';
    }

    if (status === 422 && message) {
        return message;
    }

    if (status === 403 || status === 404) {
        return 'This bill is no longer payable — refresh to see its current state. Nothing has been charged.';
    }

    return 'We could not start this payment. Nothing has been charged.';
}
