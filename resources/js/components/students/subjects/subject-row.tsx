import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { StudentSubject } from '@/types/models';
import { format } from 'date-fns';
import { Archive, Lock, RotateCcw, X } from 'lucide-react';

interface SubjectRowProps {
    subject: StudentSubject;
    isEnrollmentEnded: boolean;
    onDrop?: (subject: StudentSubject) => void;
    onRestore?: (subject: StudentSubject) => void;
}

export function SubjectRow({ subject, isEnrollmentEnded, onDrop, onRestore }: SubjectRowProps) {
    const cs = subject.curriculum_subject;
    const subjectName = cs.subject?.name ?? 'Unknown subject';
    const subjectCode = cs.subject?.code;
    const isArchived = cs.active === false;
    const isDropped = subject.status === 'dropped';

    return (
        <div className={`flex items-start justify-between gap-3 rounded-md px-3 py-2.5 transition-colors ${
            isDropped ? 'opacity-60' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50'
        }`}>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className={`text-sm font-medium ${isDropped ? 'text-slate-400 line-through' : 'text-slate-800 dark:text-slate-100'}`}>
                        {subjectName}
                    </span>
                    {subjectCode && (
                        <span className="text-xs text-slate-400">{subjectCode}</span>
                    )}
                    {cs.is_compulsory && (
                        <Badge variant="secondary" className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide">
                            <Lock className="h-2.5 w-2.5" />
                            Locked
                        </Badge>
                    )}
                    {isArchived && (
                        <Badge variant="outline" className="flex items-center gap-1 border-amber-300 text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:border-amber-700 dark:text-amber-400">
                            <Archive className="h-2.5 w-2.5" />
                            Archived in curriculum
                        </Badge>
                    )}
                </div>

                {isDropped && subject.dropped_at && (
                    <p className="mt-0.5 text-[11px] text-slate-400">
                        Dropped {format(new Date(subject.dropped_at), 'd MMM yyyy')}
                        {subject.dropped_by && ` · by ${subject.dropped_by.full_name}`}
                        {subject.drop_reason && ` · "${subject.drop_reason}"`}
                    </p>
                )}
            </div>

            {!isEnrollmentEnded && (
                <div className="shrink-0">
                    {!cs.is_compulsory && !isDropped && onDrop && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 text-xs text-slate-500 hover:text-red-600 dark:hover:text-red-400"
                            onClick={() => onDrop(subject)}
                        >
                            <X className="h-3.5 w-3.5" />
                            Drop
                        </Button>
                    )}
                    {isDropped && onRestore && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 text-xs text-slate-500 hover:text-primary dark:hover:text-primary"
                            onClick={() => onRestore(subject)}
                            disabled={isArchived}
                            title={isArchived ? 'Cannot restore: subject archived in curriculum' : undefined}
                        >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Restore
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
