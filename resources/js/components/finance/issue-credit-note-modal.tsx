import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { store as issueCreditNote } from '@/actions/App/Finance/Http/Controllers/CreditNoteController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { nairaToMinor } from '@/lib/format';
import type { CreditNoteKind, Invoice } from '@/types/finance';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    invoice: Invoice | null;
    onIssued: () => void;
};

/**
 * Issue a credit note / write-off against an invoice. The OPEN control is gated by
 * <Can permission="finance.credit-note.issue"> on the statement — but that is convenience,
 * not security: the real guard is the backend 403 (proven in the acceptance harness). The
 * over-credit ceiling and crediting a void invoice come back as a 422 { message }, rendered
 * inline here rather than thrown.
 */
export function IssueCreditNoteModal({
    isOpen,
    onClose,
    invoice,
    onIssued,
}: Props) {
    const [amount, setAmount] = useState('');
    const [kind, setKind] = useState<CreditNoteKind>('credit_note');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const reset = () => {
        setAmount('');
        setKind('credit_note');
        setNote('');
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
            setErrors({ amount: 'Enter a valid amount (e.g. 3000.00).' });

            return;
        }

        setSubmitting(true);

        try {
            await axios.post(issueCreditNote.url(invoice.id), {
                amount_minor: amountMinor,
                kind,
                note: note.trim() === '' ? null : note.trim(),
            });
            toast.success(
                `Credit note issued against ${invoice.display_number}.`,
            );
            onIssued();
            onClose();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                // Over-credit / void invoice → { message }; validation → { errors }.
                setErrors(err.response.data?.errors ?? {});
                setFormError(err.response.data?.message ?? null);
            } else if (
                axios.isAxiosError(err) &&
                err.response?.status === 403
            ) {
                setFormError(
                    'You do not have permission to issue a credit note.',
                );
            } else {
                setFormError('Something went wrong issuing the credit note.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`Issue credit note — ${invoice.display_number}`}
            size="md"
        >
            <div className="space-y-4">
                {formError && (
                    <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {formError}
                    </p>
                )}

                <div>
                    <Label htmlFor="credit-amount">Amount (₦)</Label>
                    <Input
                        id="credit-amount"
                        inputMode="decimal"
                        placeholder="3000.00"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                    />
                    {errors.amount && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.amount}
                        </p>
                    )}
                    <p className="mt-1 text-xs text-muted-foreground">
                        Total credit notes on an invoice cannot exceed its full
                        amount.
                    </p>
                </div>

                <div>
                    <Label htmlFor="credit-kind">Kind</Label>
                    <Select
                        value={kind}
                        onValueChange={(v) => setKind(v as CreditNoteKind)}
                    >
                        <SelectTrigger id="credit-kind">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="credit_note">
                                Credit note
                            </SelectItem>
                            <SelectItem value="write_off">Write-off</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div>
                    <Label htmlFor="credit-note-text">Note (optional)</Label>
                    <Input
                        id="credit-note-text"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    {errors.note && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.note}
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
                        {submitting ? 'Issuing…' : 'Issue credit note'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
