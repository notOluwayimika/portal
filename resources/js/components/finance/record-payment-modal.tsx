import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import {
    store as recordPayment,
    storeForStudent as recordAccountPayment,
} from '@/actions/App/Finance/Http/Controllers/PaymentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { invoiceLabel } from '@/lib/finance/invoice-kind';
import { nairaToMinor } from '@/lib/format';
import type { Invoice } from '@/types/finance';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    /** Called after a successful record so the statement can refetch. */
    onRecorded: () => void;
    /** INVOICE MODE — pay against a NAMED invoice (the per-row action). */
    invoice: Invoice | null;
    /**
     * ACCOUNT MODE — pay ON THE ACCOUNT, no invoice (the header action). Set when `invoice`
     * is null; the payment banks as credit and settles oldest-first at the next billing.
     */
    student?: { uuid: string; name: string } | null;
};

/**
 * Record a payment — in one of two modes, picked by which context is passed:
 *   • INVOICE MODE (`invoice` set): pay against a named invoice (POST …/invoices/{invoice}/payments).
 *     Overpayment is accepted and the excess banks as account credit (W2).
 *   • ACCOUNT MODE (`student` set, `invoice` null): pay on the account with no invoice
 *     (POST …/students/{student}/payments, ADR 0048). The whole amount banks as credit and
 *     applyCreditForward settles it oldest-first at the next generation — there is no outstanding
 *     to exceed, so the banking note is the PRIMARY explanation, not a footnote.
 *
 * Amount is entered in naira and converted to minor units ONCE via the money-boundary helper (no
 * arithmetic here). "method" (defaults to 'manual' server-side) and "reference" (auto-generated)
 * are not request inputs, so the form does not collect them — binding to the contract, not
 * inventing fields the API ignores.
 *
 * THE RECEIVED DATE IS PRE-FILLED, NOT DEFAULTED, and the distinction is the whole reason
 * finance_payments.received_at is NOT NULL with no database default.
 *
 * A server-side default is invisible: the operator submits, a date they never saw is written, and
 * the row asserts a business fact nobody observed — on an append-only table where it can never be
 * corrected. A pre-filled input is the opposite. The date is on screen, in the operator's hands,
 * and submitting it is an act of CONFIRMATION rather than of omission. Today is right the large
 * majority of the time, so pre-filling costs nothing; being SEEN is what makes it honest.
 *
 * So: do not "simplify" this by defaulting received_at server-side when the field is absent. That
 * would restore exactly the silent gap the column's shape was chosen to close.
 */
/**
 * Today as the API's `Y-m-d`, in the browser's local timezone.
 *
 * NOT `toISOString().slice(0, 10)`, which converts to UTC first and therefore reports YESTERDAY for
 * anyone west of Greenwich in the evening — a payment silently back-dated by a timezone, which the
 * server would then demand a reason for. Local parts, assembled directly.
 */
function todayIso(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

type BankAccountOption = {
    id: string;
    label: string;
    bank_name: string;
    account_number: string;
};

export function RecordPaymentModal({
    isOpen,
    onClose,
    invoice,
    student,
    onRecorded,
}: Props) {
    // Today in the BROWSER's timezone, formatted as the API's Y-m-d. Computed once per render of
    // the module rather than per keystroke; the modal resets it on every open.
    const today = todayIso();

    const [amount, setAmount] = useState('');
    const [payerName, setPayerName] = useState('');
    const [receivedAt, setReceivedAt] = useState(today);
    const [accounts, setAccounts] = useState<BankAccountOption[]>([]);
    const [bankAccountId, setBankAccountId] = useState('');
    const [receivedAtReason, setReceivedAtReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const reset = () => {
        setAmount('');
        setPayerName('');
        setReceivedAt(todayIso());
        setBankAccountId('');
        setReceivedAtReason('');
        setErrors({});
        setFormError(null);
        setSubmitting(false);
    };

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        // ACTIVE ACCOUNTS ONLY — the API returns deactivated ones too, because a historical payment
        // must still render the name of an account that has since been retired. New money may not
        // go to one, which is the whole reason commit 1 chose deactivation over deletion.
        void (async () => {
            try {
                const { data } = await axios.get(
                    '/api/v1/finance/bank-accounts',
                );
                const active = (data.bank_accounts ?? []).filter(
                    (a: BankAccountOption & { is_active: boolean }) =>
                        a.is_active,
                );
                setAccounts(active);

                // PRE-SELECT ONLY WHEN THERE IS EXACTLY ONE, and it is a pre-fill rather than a
                // default for the same reason the received date is: the operator SEES it and can
                // change it. With two or more accounts there is a real choice and guessing would
                // assert a destination nobody picked — on a row that is append-only.
                setBankAccountId(active.length === 1 ? active[0].id : '');
            } catch {
                setAccounts([]);
            }
        })();
    }, [isOpen]);

    useEffect(() => {
        if (isOpen) {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            reset();
        }
    }, [isOpen]);

    // Account mode when there is no invoice but a student context. Neither → nothing to render.
    const accountMode = !invoice && !!student;

    if (!invoice && !student) {
        return null;
    }

    // MIRRORS THE SERVER RULE, not a restatement of it. RecordPaymentRequest (and its account-mode
    // twin) carry `required_unless:received_at,<today>`, so the reason is required exactly when the
    // chosen date is not today — never for a same-day payment. This predicate is that condition and
    // nothing more; if the server's rule changes, this is the line that has to change with it.
    const backDated = receivedAt !== today;

    // THE INVOICE IS NAMED BY KIND AND NUMBER, never by number alone (U7 / the supplementary-
    // invoice ticket §5). An episode can carry an active term bill and any number of live
    // supplementary charges at once, so a number on its own no longer says which document the
    // money is about to be applied to. Account mode names the STUDENT because there is no invoice
    // to name — the money banks to the account and settles oldest-first at the next billing.
    const heading = accountMode
        ? student!.name
        : invoiceLabel({
              kind: invoice!.kind,
              display_number: invoice!.display_number,
          });

    const submit = async () => {
        setErrors({});
        setFormError(null);

        const amountMinor = nairaToMinor(amount);

        if (amountMinor === null || amountMinor <= 0) {
            setErrors({ amount: 'Enter a valid amount (e.g. 2500.00).' });

            return;
        }

        setSubmitting(true);

        try {
            await axios.post(
                accountMode
                    ? recordAccountPayment.url(student!.uuid)
                    : recordPayment.url(invoice!.id),
                {
                    amount_minor: amountMinor,
                    payer_name: payerName,
                    received_at: receivedAt,
                    bank_account_id: bankAccountId,
                    // Sent only when it exists. The server requires it exactly when the date is not
                    // today; sending an empty string on a same-day payment would fail `nullable`
                    // less obviously than omitting it.
                    ...(backDated
                        ? { received_at_reason: receivedAtReason }
                        : {}),
                },
            );
            toast.success(
                accountMode
                    ? `Payment recorded for ${student!.name}.`
                    : `Payment recorded against ${invoice!.display_number}.`,
            );
            onRecorded();
            onClose();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                // Laravel validation → { errors }; a domain rule → { message }.
                setErrors(err.response.data?.errors ?? {});
                setFormError(err.response.data?.message ?? null);
            } else {
                setFormError('Something went wrong recording the payment.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`Record payment — ${heading}`}
            size="md"
        >
            <div className="space-y-4">
                {formError && (
                    <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {formError}
                    </p>
                )}

                <div>
                    <Label htmlFor="payment-amount">Amount (₦)</Label>
                    <Input
                        id="payment-amount"
                        inputMode="decimal"
                        placeholder="2500.00"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                    />
                    {errors.amount && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.amount}
                        </p>
                    )}
                    <p className="mt-1 text-xs text-muted-foreground">
                        {accountMode
                            ? 'Banks to the account as credit and settles the oldest unpaid invoice(s) automatically at the next billing.'
                            : "More than the invoice's outstanding is accepted — the excess banks as account credit."}
                    </p>
                </div>

                <div>
                    <Label htmlFor="payer-name">Payer name</Label>
                    <Input
                        id="payer-name"
                        value={payerName}
                        onChange={(e) => setPayerName(e.target.value)}
                    />
                    {errors.payer_name && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.payer_name}
                        </p>
                    )}
                </div>

                <div>
                    <Label htmlFor="payment-bank-account">Paid into</Label>
                    <select
                        id="payment-bank-account"
                        className="mt-1 w-full rounded-md border bg-background p-2 text-sm"
                        value={bankAccountId}
                        onChange={(e) => setBankAccountId(e.target.value)}
                    >
                        <option value="">Select an account…</option>
                        {accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.label} — {a.bank_name} {a.account_number}
                            </option>
                        ))}
                    </select>
                    {errors.bank_account_id && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.bank_account_id}
                        </p>
                    )}
                    {accounts.length === 0 && (
                        <p className="mt-1 text-xs text-destructive">
                            This school has no active bank account, so a payment
                            cannot be recorded. Add one under Finance → Bank
                            accounts first.
                        </p>
                    )}
                </div>

                <div>
                    <Label htmlFor="payment-received-at">Date received</Label>
                    <Input
                        id="payment-received-at"
                        type="date"
                        max={today}
                        value={receivedAt}
                        onChange={(e) => setReceivedAt(e.target.value)}
                    />
                    {errors.received_at && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.received_at}
                        </p>
                    )}
                    <p className="mt-1 text-xs text-muted-foreground">
                        Pre-filled with today. Change it if the money was
                        received on an earlier day — this is the date the
                        payment counts from, and it cannot be edited afterwards.
                    </p>
                </div>

                {/*
                 * Shown when the CLIENT thinks the date is back-dated, or when the SERVER says so
                 * and the client disagreed. That second condition is not defensive padding: the
                 * app timezone is UTC (config/app.php) while a Lagos browser is UTC+1, so between
                 * 00:00 and 01:00 WAT the two disagree about what "today" is. Without the error
                 * clause the operator would receive a validation error for a field that was never
                 * rendered — a dead end for one hour a day. With it, the field appears carrying the
                 * server's own message and the payment can be completed.
                 */}
                {(backDated || errors.received_at_reason) && (
                    <div>
                        <Label htmlFor="received-at-reason">
                            Why is this back-dated?
                        </Label>
                        <Input
                            id="received-at-reason"
                            value={receivedAtReason}
                            onChange={(e) =>
                                setReceivedAtReason(e.target.value)
                            }
                            placeholder="Handed over at the desk on the 4th"
                        />
                        {errors.received_at_reason && (
                            <p className="mt-0.5 text-xs text-destructive">
                                {errors.received_at_reason}
                            </p>
                        )}
                    </div>
                )}

                <div className="flex justify-end gap-2 border-t pt-3">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? 'Recording…' : 'Record payment'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
