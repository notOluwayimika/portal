import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import type { StudentSubject } from '@/types/models';
import axios from 'axios';
import { useState } from 'react';

interface DropSubjectModalProps {
    isOpen: boolean;
    subject: StudentSubject | null;
    studentName: string;
    enrollmentId: string;
    studentId: string;
    onClose: () => void;
    onDropped: () => void;
}

const MAX_REASON = 500;

export function DropSubjectModal({
    isOpen,
    subject,
    studentName,
    enrollmentId,
    studentId,
    onClose,
    onDropped,
}: DropSubjectModalProps) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const subjectName =
        subject?.curriculum_subject?.subject?.name ?? 'this subject';

    async function handleDrop() {
        if (!subject) return;
        setBusy(true);
        setError(null);

        try {
            const response = await axios.patch(
                `/api/students/${studentId}/enrollments/${enrollmentId}/subjects/${subject.id}/drop`,
                { reason: reason.trim() || undefined },
            );
            console.log(response.data);
            setReason('');
            onDropped();
            onClose();
        } catch (err: any) {
            setError(
                err?.response?.data?.message ??
                    'Failed to drop subject. Please try again.',
            );
        } finally {
            setBusy(false);
        }
    }

    function handleClose() {
        setReason('');
        setError(null);
        onClose();
    }

    return (
        <Modal
            isOpen={isOpen}
            onClose={handleClose}
            title="Drop Subject?"
            size="md"
            footer={
                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        onClick={handleClose}
                        disabled={busy}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleDrop}
                        disabled={busy}
                    >
                        {busy ? 'Dropping…' : 'Drop Subject'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                <p className="text-sm text-slate-600 dark:text-slate-300">
                    Are you sure you want to drop{' '}
                    <span className="font-semibold">{subjectName}</span> from{' '}
                    <span className="font-semibold">{studentName}</span>'s
                    subject list?
                </p>

                <div className="space-y-1">
                    <label className="text-xs font-medium text-slate-500 dark:text-slate-400">
                        Reason (optional)
                    </label>
                    <textarea
                        className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm placeholder-slate-400 focus:border-primary focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        rows={3}
                        maxLength={MAX_REASON}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. Student switched track"
                    />
                    <p className="text-right text-[11px] text-slate-400">
                        {reason.length}/{MAX_REASON}
                    </p>
                </div>

                <p className="text-xs text-slate-400">
                    This action can be reversed later from the dropped subjects
                    section.
                </p>

                {error && (
                    <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-900/30 dark:text-red-400">
                        {error}
                    </p>
                )}
            </div>
        </Modal>
    );
}
