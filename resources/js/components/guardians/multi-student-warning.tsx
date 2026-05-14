import { AlertTriangle } from 'lucide-react';

interface AffectedStudent {
    id: string;
    full_name: string;
}

interface MultiStudentWarningProps {
    students: AffectedStudent[];
}

export function MultiStudentWarning({ students }: MultiStudentWarningProps) {
    if (students.length <= 1) return null;

    const names = students.map((s) => s.full_name).join(', ');

    return (
        <div className="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <p>
                This guardian is linked to <strong>{students.length} students</strong>: {names}.
                Changes to these details will affect all linked students.
            </p>
        </div>
    );
}
