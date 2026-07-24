import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import {
    billableEnrollment,
    generateForStudent,
} from '@/actions/App/Finance/Http/Controllers/InvoiceController';
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
import { formatNaira, nairaToMinor, sumMinor } from '@/lib/format';
import type { BillableEnrollmentInfo, DraftLine } from '@/types/finance';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    student: { uuid: string; name: string };
    onCreated: () => void;
};

const EMPTY_LINE: DraftLine = { description: '', amount: '', kind: 'charge' };

/**
 * Create an invoice for the STUDENT. Enrollment resolution is server-side: on open the
 * modal reads the current billable episode (academic context + F7 preview) — the frontend
 * never handles an enrollment id. Line entry is manual (no fee catalog yet); the live total
 * goes through sumMinor (the sanctioned integer sum), reductions carry a negative amount, and
 * the preview MIRRORS the server's F6 total. Submit posts to the student-scoped generate
 * endpoint; no-enrollment / already-invoiced (F7) / negative-total come back as 422 inline.
 */
export function NewInvoiceModal({
    isOpen,
    onClose,
    student,
    onCreated,
}: Props) {
    const [enrollment, setEnrollment] = useState<BillableEnrollmentInfo | null>(
        null,
    );
    const [blocked, setBlocked] = useState<string | null>(null); // no active enrollment
    const [lines, setLines] = useState<DraftLine[]>([{ ...EMPTY_LINE }]);
    const [formError, setFormError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const loadEnrollment = useCallback(async () => {
        setEnrollment(null);
        setBlocked(null);
        setFormError(null);
        setLines([{ ...EMPTY_LINE }]);

        try {
            const { data } = await axios.get<BillableEnrollmentInfo>(
                billableEnrollment.url(student.uuid),
            );
            setEnrollment(data);
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                setBlocked(
                    err.response.data?.message ??
                        'This student cannot be billed.',
                );
            } else {
                setBlocked('Could not resolve the student’s enrollment.');
            }
        }
    }, [student.uuid]);

    useEffect(() => {
        if (isOpen) {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            void loadEnrollment();
        }
    }, [isOpen, loadEnrollment]);

    const setLine = (index: number, patch: Partial<DraftLine>) =>
        setLines((prev) =>
            prev.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );
    const addLine = () => setLines((prev) => [...prev, { ...EMPTY_LINE }]);
    const removeLine = (index: number) =>
        setLines((prev) =>
            prev.length === 1 ? prev : prev.filter((_, i) => i !== index),
        );

    // Signed minor units per line: a charge adds, a waiver/discount subtracts (the wire
    // carries a NEGATIVE amount for reductions, matching the server's per-kind sign rule).
    // null when a line's amount is not yet a valid number.
    const signedMinors = lines.map((l) => {
        const m = nairaToMinor(l.amount);

        if (m === null || m === 0) {
            return null;
        }

        return l.kind === 'charge' ? m : -m;
    });
    const allValid =
        lines.every((l) => l.description.trim() !== '') &&
        signedMinors.every((m) => m !== null);
    const previewTotal = allValid ? sumMinor(signedMinors as number[]) : null;

    const submit = async () => {
        setFormError(null);

        if (!allValid) {
            setFormError(
                'Every line needs a description and a non-zero amount.',
            );

            return;
        }

        if (previewTotal !== null && previewTotal < 0) {
            setFormError(
                'Reductions may not exceed the charges — the total would be negative.',
            );

            return;
        }

        setSubmitting(true);

        try {
            await axios.post(generateForStudent.url(student.uuid), {
                lines: lines.map((l, i) => ({
                    description: l.description.trim(),
                    amount_minor: signedMinors[i],
                    kind: l.kind,
                })),
            });
            toast.success('Invoice created.');
            onCreated();
            onClose();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                setFormError(
                    err.response.data?.message ??
                        'The invoice could not be created.',
                );
            } else {
                setFormError('Something went wrong creating the invoice.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`New invoice — ${student.name}`}
            size="lg"
        >
            <div className="space-y-4">
                {blocked && (
                    <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {blocked}
                    </p>
                )}

                {enrollment && (
                    <>
                        <div className="rounded-md bg-muted p-2 text-sm">
                            <span className="text-muted-foreground">
                                Billing episode:{' '}
                            </span>
                            {enrollment.academic_context}
                        </div>
                        {enrollment.already_invoiced && (
                            <p className="rounded-md bg-amber-100 p-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                This episode already has an active invoice. Void
                                it first — creating another will be rejected.
                            </p>
                        )}

                        {formError && (
                            <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                                {formError}
                            </p>
                        )}

                        <div className="space-y-2">
                            {lines.map((line, index) => (
                                <div
                                    key={index}
                                    className="flex items-end gap-2"
                                >
                                    <div className="flex-1">
                                        {index === 0 && (
                                            <Label>Description</Label>
                                        )}
                                        <Input
                                            placeholder="Tuition"
                                            value={line.description}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    description: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="w-32">
                                        {index === 0 && <Label>Kind</Label>}
                                        <Select
                                            value={line.kind}
                                            onValueChange={(v) =>
                                                setLine(index, {
                                                    kind: v as DraftLine['kind'],
                                                })
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="charge">
                                                    Charge
                                                </SelectItem>
                                                <SelectItem value="waiver">
                                                    Waiver
                                                </SelectItem>
                                                <SelectItem value="discount">
                                                    Discount
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="w-32">
                                        {index === 0 && (
                                            <Label>Amount (₦)</Label>
                                        )}
                                        <Input
                                            inputMode="decimal"
                                            placeholder="0.00"
                                            value={line.amount}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    amount: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removeLine(index)}
                                        disabled={lines.length === 1}
                                        aria-label="Remove line"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addLine}
                            >
                                Add line
                            </Button>
                        </div>

                        <div className="flex items-center justify-between border-t pt-3">
                            <span className="text-sm text-muted-foreground">
                                Total
                            </span>
                            <span className="text-lg font-semibold">
                                {previewTotal === null
                                    ? '—'
                                    : formatNaira({
                                          amount_minor: previewTotal,
                                          currency: 'NGN',
                                      })}
                            </span>
                        </div>
                    </>
                )}

                <div className="flex justify-end gap-2 border-t pt-3">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={submitting || blocked !== null}
                    >
                        {submitting ? 'Creating…' : 'Create invoice'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
