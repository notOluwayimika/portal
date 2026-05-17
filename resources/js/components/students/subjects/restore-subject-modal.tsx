import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import type { StudentSubject } from '@/types/models';
import axios from 'axios';
import { useState } from 'react';

interface RestoreSubjectModalProps {
    isOpen: boolean;
    subject: StudentSubject | null;
    studentName: string;
    enrollmentId: string;
    studentId: string;
    onClose: () => void;
    onRestored: () => void;
}

export function RestoreSubjectModal({
    isOpen,
    subject,
    studentName,
    enrollmentId,
    studentId,
    onClose,
    onRestored,
}: RestoreSubjectModalProps) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const subjectName = subject?.curriculum_subject?.subject?.name ?? 'this subject';
    const isArchived = subject?.curriculum_subject?.active === false;

    async function handleRestore() {
        if (!subject) return;
        setBusy(true);
        setError(null);

        try {
            await axios.patch(
                `/api/students/${studentId}/enrollments/${enrollmentId}/subjects/${subject.id}/restore`
            );
            onRestored();
            onClose();
        } catch (err: any) {
            setError(err?.response?.data?.message ?? 'Failed to restore subject. Please try again.');
        } finally {
            setBusy(false);
        }
    }

    function handleClose() {
        setError(null);
        onClose();
    }

    return (
        <Modal
            isOpen={isOpen}
            onClose={handleClose}
            title="Restore Subject"
            size="sm"
            footer={
                !isArchived ? (
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={handleClose} disabled={busy}>
                            Cancel
                        </Button>
                        <Button onClick={handleRestore} disabled={busy}>
                            {busy ? 'Restoring…' : 'Restore'}
                        </Button>
                    </div>
                ) : (
                    <div className="flex justify-end">
                        <Button variant="outline" onClick={handleClose}>
                            Close
                        </Button>
                    </div>
                )
            }
        >
            {isArchived ? (
                <p className="text-sm text-slate-600 dark:text-slate-300">
                    This subject has been archived in the curriculum and can no longer be added to
                    students. Contact a curriculum administrator if it needs to be reactivated.
                </p>
            ) : (
                <div className="space-y-3">
                    <p className="text-sm text-slate-600 dark:text-slate-300">
                        Restore{' '}
                        <span className="font-semibold">{subjectName}</span> to{' '}
                        <span className="font-semibold">{studentName}</span>'s active subjects?
                    </p>

                    {error && (
                        <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-900/30 dark:text-red-400">
                            {error}
                        </p>
                    )}
                </div>
            )}
        </Modal>
    );
}
