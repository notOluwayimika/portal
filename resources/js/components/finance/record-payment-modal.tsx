import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { store as recordPayment } from '@/actions/App/Finance/Http/Controllers/PaymentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { nairaToMinor } from '@/lib/format';
import type { Invoice } from '@/types/finance';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    invoice: Invoice | null;
    /** Called after a successful record so the statement can refetch. */
    onRecorded: () => void;
};

/**
 * Record a payment against an invoice (the existing /api/v1/finance payment endpoint).
 * Overpayment is ACCEPTED and banked by the backend (W2) — the excess shows up as the
 * account's available credit on the statement after the refetch; this form does nothing
 * special for it. Amount is entered in naira and converted to minor units ONCE, via the
 * money-boundary helper (no arithmetic here).
 *
 * NOTE: the API accepts amount + payer_name only. "method" (defaults to 'manual'
 * server-side) and "reference" (auto-generated) are not request inputs, so the form does
 * not collect them — binding to the contract, not inventing fields the API ignores.
 */
export function RecordPaymentModal({
    isOpen,
    onClose,
    invoice,
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

    if (!invoice) {
        return null;
    }

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
            await axios.post(recordPayment.url(invoice.id), {
                amount_minor: amountMinor,
                payer_name: payerName,
            });
            toast.success(
                `Payment recorded against ${invoice.display_number}.`,
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
            title={`Record payment — ${invoice.display_number}`}
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
                        More than the invoice&apos;s outstanding is accepted —
                        the excess banks as account credit.
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
