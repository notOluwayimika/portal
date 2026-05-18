import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';

interface AffectedStudent {
    id: string;
    full_name: string;
    class_details?: { full_class?: string };
}

interface MultiStudentConfirmModalProps {
    isOpen: boolean;
    students: AffectedStudent[];
    onConfirm: () => void;
    onCancel: () => void;
    submitting?: boolean;
}

export function MultiStudentConfirmModal({
    isOpen,
    students,
    onConfirm,
    onCancel,
    submitting = false,
}: MultiStudentConfirmModalProps) {
    return (
        <Modal isOpen={isOpen} onClose={onCancel} title="Confirm Guardian Update" size="md">
            <div className="space-y-4">
                <div className="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <p>
                        Saving these changes will update the guardian's details for all linked students.
                    </p>
                </div>

                <ul className="space-y-1 text-sm">
                    {students.map((s) => (
                        <li key={s.id} className="flex items-center gap-2">
                            <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground" />
                            <span>{s.full_name}</span>
                            {s.class_details?.full_class && (
                                <span className="text-xs text-muted-foreground">({s.class_details.full_class})</span>
                            )}
                        </li>
                    ))}
                </ul>

                <div className="flex justify-end gap-2 border-t pt-3">
                    <Button variant="outline" onClick={onCancel} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button onClick={onConfirm} disabled={submitting}>
                        Yes, Update All
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
