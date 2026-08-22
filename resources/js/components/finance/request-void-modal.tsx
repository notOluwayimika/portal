import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { submit as submitVoidRequest } from '@/actions/App/Finance/Http/Controllers/VoidRequestController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { invoiceLabel } from '@/lib/finance/invoice-kind';
import type { Invoice } from '@/types/finance';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    invoice: Invoice | null;
    onRequested: () => void;
};

/**
 * Ph3b MAKER side — SUBMIT a request to VOID an invoice for approval. This creates a PENDING
 * request; the invoice is NOT touched (still issued, in the balance) and NO money moves until a
 * checker (≠ the maker) approves it in the pending queue — approval is what voids it and posts
 * the reversal. The OPEN control is gated by <Can permission="finance.invoice.void-request.submit">
 * on the statement — convenience, not security: the real guard is the backend 403.
 *
 * A settled/credited invoice comes back as a 422 { message } (VoidEligibility), rendered inline —
 * void reverses the WHOLE charge, so it is only offered when nothing has settled against it.
 */
export function RequestVoidModal({
    isOpen,
    onClose,
    invoice,
    onRequested,
}: Props) {
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const reset = () => {
        setReason('');
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

        if (reason.trim() === '') {
            setErrors({ reason: 'A reason is required to request a void.' });

            return;
        }

        setSubmitting(true);

        try {
            await axios.post(submitVoidRequest.url(invoice.id), {
                reason: reason.trim(),
            });
            toast.success(
                `Void requested for approval against ${invoice.display_number}.`,
            );
            onRequested();
            onClose();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                setErrors(err.response.data?.errors ?? {});
                setFormError(err.response.data?.message ?? null);
            } else if (
                axios.isAxiosError(err) &&
                err.response?.status === 403
            ) {
                setFormError('You do not have permission to request a void.');
            } else {
                setFormError('Something went wrong requesting the void.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    // THE TITLE NAMES KIND AND NUMBER (U7), and the ticket calls this the most expensive of the
    // three: voiding an invoice discards its payment allocations, and the confirmation a maker
    // reads before proposing it named a number that stopped implying which document it was the
    // moment an episode could carry a term bill and live supplementary charges together.
    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`Request void for approval — ${invoiceLabel(invoice)}`}
            size="md"
        >
            <div className="space-y-4">
                {formError && (
                    <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {formError}
                    </p>
                )}

                <div>
                    <Label htmlFor="void-reason">Reason (required)</Label>
                    <Input
                        id="void-reason"
                        placeholder="Why should this invoice be voided?"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    {errors.reason && (
                        <p className="mt-0.5 text-xs text-destructive">
                            {errors.reason}
                        </p>
                    )}
                    <p className="mt-1 text-xs text-muted-foreground">
                        This is a proposal — a second person must approve it
                        before the invoice is voided. Voiding reverses the whole
                        charge, so it is only allowed when no payment or
                        approved credit note has settled against the invoice.
                    </p>
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
                        {submitting ? 'Submitting…' : 'Submit for approval'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
