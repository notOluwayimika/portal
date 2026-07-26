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
 * arithmetic here). The API accepts amount + payer_name only; "method" (defaults to 'manual'
 * server-side) and "reference" (auto-generated) are not request inputs, so the form does not
 * collect them — binding to the contract, not inventing fields the API ignores.
 */
export function RecordPaymentModal({
    isOpen,
    onClose,
    invoice,
    student,
    onRecorded,
}: Props) {
    const [amount, setAmount] = useState('');
    const [payerName, setPayerName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const reset = () => {
        setAmount('');
        setPayerName('');
        setErrors({});
        setFormError(null);
        setSubmitting(false);
    };

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

    const heading = accountMode ? student!.name : invoice!.display_number;

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
