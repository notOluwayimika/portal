import type { StudentSubject } from '@/types/models';
import { SubjectRow } from './subject-row';

interface SubjectListGroupProps {
    title: string;
    subjects: StudentSubject[];
    isEnrollmentEnded: boolean;
    onDrop?: (subject: StudentSubject) => void;
    onRestore?: (subject: StudentSubject) => void;
    collapsible?: boolean;
    defaultCollapsed?: boolean;
}

export function SubjectListGroup({
    title,
    subjects,
    isEnrollmentEnded,
    onDrop,
    onRestore,
    defaultCollapsed = false,
}: SubjectListGroupProps) {
    if (subjects.length === 0) return null;

    return (
        <div className="space-y-0.5">
            <p className="px-3 pb-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">
                {title}
            </p>
            {subjects.map((subject) => (
                <SubjectRow
                    key={subject.id}
                    subject={subject}
                    isEnrollmentEnded={isEnrollmentEnded}
                    onDrop={onDrop}
                    onRestore={onRestore}
                />
            ))}
        </div>
    );
}
