import axios from 'axios';
import { BookOpen, ChevronDown, Clock, History, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import type {
    Student,
    StudentCurriculum,
    StudentSubject,
    StudentSubjectsGrouped,
} from '@/types/models';
import { AddSubjectsModal } from './add-subjects-modal';
import { DropSubjectModal } from './drop-subject-modal';
import { RestoreSubjectModal } from './restore-subject-modal';
import { SubjectHistoryDrawer } from './subject-history-drawer';
import { SubjectListGroup } from './subject-list-group';

interface StudentSubjectsSectionProps {
    student: Student;
    studentCurriculum?: StudentCurriculum | null;
    // When false, subjects are read-only (no add/drop/restore). Defaults to true.
    canManage?: boolean;
}

export function StudentSubjectsSection({
    student,
    studentCurriculum = null,
    canManage = true,
}: StudentSubjectsSectionProps) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [grouped, setGrouped] = useState<StudentSubjectsGrouped | null>(null);
    const [showDropped, setShowDropped] = useState(false);

    const [addOpen, setAddOpen] = useState(false);
    const [dropSubject, setDropSubject] = useState<StudentSubject | null>(null);
    const [restoreSubject, setRestoreSubject] = useState<StudentSubject | null>(
        null,
    );
    const [historyOpen, setHistoryOpen] = useState(false);

    // Use the enrollment with status "active" (mirrors Student::currentCurriculum()).
    const enrollments = student.student_curricula ?? [];
    const activeEnrollment =
        enrollments.find((e) => e.status === 'active') ?? enrollments[0];
    const enrollmentId = studentCurriculum
        ? studentCurriculum.id
        : activeEnrollment?.id;

    async function fetchSubjects() {
        if (!enrollmentId) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const res = await axios.get(
                `/api/students/${student.id}/enrollments/${studentCurriculum ? studentCurriculum.id : enrollmentId}/subjects`,
            );
            setGrouped(res.data?.data ?? null);
        } catch {
            setError('Failed to load subjects. Please refresh.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        fetchSubjects();
    }, [enrollmentId]);

    if (!enrollmentId) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <BookOpen className="h-4 w-4" />
                        Subjects
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-slate-400">
                        No curriculum enrollment found.
                    </p>
                </CardContent>
            </Card>
        );
    }

    const isEnded = grouped?.enrollment?.is_ended ?? false;
    const activeCount =
        (grouped?.compulsory_active.length ?? 0) +
        (grouped?.optional_active.length ?? 0);
    const droppedCount = grouped?.optional_dropped.length ?? 0;

    const autoHideDropped = droppedCount > 3;

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <BookOpen className="h-4 w-4" />
                        Subjects
                        {activeCount > 0 && (
                            <Badge variant="secondary" className="text-xs">
                                {activeCount} active
                            </Badge>
                        )}
                    </CardTitle>
                    <div className="flex items-center gap-2">
                        {canManage && !isEnded && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1 text-xs"
                                onClick={() => setAddOpen(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add Subject
                            </Button>
                        )}
                    </div>
                </CardHeader>

                <CardContent className="space-y-4 pt-0">
                    {isEnded && grouped?.enrollment?.ended_at && (
                        <div className="flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                            <Clock className="h-3.5 w-3.5 shrink-0" />
                            This enrollment ended on{' '}
                            {new Date(
                                grouped.enrollment.ended_at,
                            ).toLocaleDateString(undefined, {
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric',
                            })}
                            . Subjects are read-only.
                        </div>
                    )}

                    {loading && (
                        <div className="flex justify-center py-6">
                            <Spinner className="h-5 w-5 text-slate-400" />
                        </div>
                    )}

                    {error && (
                        <p className="py-4 text-center text-sm text-red-500">
                            {error}
                        </p>
                    )}

                    {!loading && !error && grouped && (
                        <>
                            {grouped.compulsory_active.length === 0 && (
                                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
                                    This curriculum has no compulsory subjects.
                                    Verify the curriculum setup.
                                </div>
                            )}

                            <div className="space-y-4">
                                <SubjectListGroup
                                    title="Compulsory"
                                    subjects={grouped.compulsory_active}
                                    isEnrollmentEnded={isEnded}
                                />

                                <SubjectListGroup
                                    title="Optional — Active"
                                    subjects={grouped.optional_active}
                                    isEnrollmentEnded={isEnded}
                                    onDrop={canManage ? (s) => setDropSubject(s) : undefined}
                                />

                                {droppedCount > 0 && (
                                    <div>
                                        <div className="flex items-center justify-between px-3 pb-1">
                                            <p className="text-[10px] font-bold tracking-widest text-slate-400 uppercase">
                                                Optional — Dropped
                                            </p>
                                            {autoHideDropped && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 gap-1 text-[10px] text-slate-400"
                                                    onClick={() =>
                                                        setShowDropped(
                                                            (v) => !v,
                                                        )
                                                    }
                                                >
                                                    {showDropped
                                                        ? 'Hide'
                                                        : `Show (${droppedCount})`}
                                                    <ChevronDown
                                                        className={`h-3 w-3 transition-transform ${showDropped ? 'rotate-180' : ''}`}
                                                    />
                                                </Button>
                                            )}
                                        </div>

                                        {(!autoHideDropped || showDropped) && (
                                            <div className="space-y-0.5">
                                                {grouped.optional_dropped.map(
                                                    (subject) => (
                                                        <SubjectListGroup
                                                            key={subject.id}
                                                            title=""
                                                            subjects={[subject]}
                                                            isEnrollmentEnded={
                                                                isEnded
                                                            }
                                                            onRestore={
                                                                canManage
                                                                    ? (s) =>
                                                                          setRestoreSubject(
                                                                              s,
                                                                          )
                                                                    : undefined
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {activeCount === 0 && droppedCount === 0 && (
                                    <p className="py-4 text-center text-sm text-slate-400">
                                        No subjects attached yet.
                                    </p>
                                )}
                            </div>

                            <div className="border-t pt-3">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="gap-1.5 text-xs text-slate-500 hover:text-slate-700"
                                    onClick={() => setHistoryOpen(true)}
                                >
                                    <History className="h-3.5 w-3.5" />
                                    View Full Subject History
                                </Button>
                            </div>
                        </>
                    )}
                </CardContent>
            </Card>

            <AddSubjectsModal
                isOpen={addOpen}
                grouped={grouped}
                enrollmentId={enrollmentId}
                studentId={student.id}
                onClose={() => setAddOpen(false)}
                onAdded={fetchSubjects}
            />

            <DropSubjectModal
                isOpen={!!dropSubject}
                subject={dropSubject}
                studentName={student.full_name}
                enrollmentId={enrollmentId}
                studentId={student.id}
                onClose={() => setDropSubject(null)}
                onDropped={fetchSubjects}
            />

            <RestoreSubjectModal
                isOpen={!!restoreSubject}
                subject={restoreSubject}
                studentName={student.full_name}
                enrollmentId={enrollmentId}
                studentId={student.id}
                onClose={() => setRestoreSubject(null)}
                onRestored={fetchSubjects}
            />

            <SubjectHistoryDrawer
                isOpen={historyOpen}
                enrollmentId={enrollmentId}
                studentId={student.id}
                onClose={() => setHistoryOpen(false)}
            />
        </>
    );
}
