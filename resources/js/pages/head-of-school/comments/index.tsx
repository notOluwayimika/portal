import { Head } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle, Eye, MessageSquare } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { CurriculumCardFinal } from '@/components/curriculum-card-final';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import EmptyState from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/spinner';
import { TermFilterSelect } from '@/components/term-filter-select';
import { useInitials } from '@/hooks/use-initials';
import type { GradeBoundary, Student, StudentCurriculum } from '@/types/models';

interface CommentRow {
    student_curriculum_id: string;
    student: Student;
    class_name: string | null;
    comment: string | null;
}

interface ModalData {
    studentCurriculum: StudentCurriculum;
    defaultBoundaries: GradeBoundary[];
}

export default function HeadOfSchoolCommentsIndex() {
    const getInitials = useInitials();
    const [rows, setRows] = useState<CommentRow[]>([]);
    const [comments, setComments] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(true);
    const [savingId, setSavingId] = useState<string | null>(null);

    const [selectedRow, setSelectedRow] = useState<CommentRow | null>(null);
    const [modalData, setModalData] = useState<ModalData | null>(null);
    const [modalLoading, setModalLoading] = useState(false);
    const [termId, setTermId] = useState('');
    const [ftComment, setFtComment] = useState('');
    const [bpComment, setBpComment] = useState('');

    useEffect(() => {
        async function fetchData() {
            setLoading(true);

            try {
                const res = await axios.get('/api/head-of-school/students', {
                    params: termId ? { term_id: termId } : {},
                });
                const data: CommentRow[] = res.data.data ?? [];

                setRows(data);
                setComments(
                    Object.fromEntries(
                        data.map((row) => [
                            row.student_curriculum_id,
                            row.comment ?? '',
                        ]),
                    ),
                );
            } catch {
                toast.error('Failed to load students.');
            } finally {
                setLoading(false);
            }
        }

        fetchData();
    }, [savingId, termId]);

    const grouped = useMemo(() => {
        const map = new Map<string, CommentRow[]>();

        for (const row of rows) {
            const key = row.class_name ?? 'Unassigned';

            if (!map.has(key)) {
                map.set(key, []);
            }

            map.get(key)!.push(row);
        }

        return Array.from(map.entries());
    }, [rows]);

    async function openModal(row: CommentRow) {
        setSelectedRow(row);
        setModalData(null);
        setModalLoading(true);

        try {
            const res = await axios.get(
                `/api/head-of-school/students/${row.student_curriculum_id}/result`,
            );
            const studentCurriculum: StudentCurriculum =
                res.data.studentCurriculum;
            setModalData({
                studentCurriculum,
                defaultBoundaries:
                    res.data.defaultBoundaries.data ??
                    res.data.defaultBoundaries,
            });
            setFtComment(studentCurriculum.form_teacher_comment ?? '');
            setBpComment(
                studentCurriculum.behavioral_assessments?.[0]?.comment ?? '',
            );
        } catch {
            toast.error('Failed to load result.');
            setSelectedRow(null);
        } finally {
            setModalLoading(false);
        }
    }

    function closeModal() {
        setSelectedRow(null);
        setModalData(null);
        setFtComment('');
        setBpComment('');
    }

    async function handleSave(row: CommentRow) {
        setSavingId(row.student_curriculum_id);

        // The boarding parent comment lives on the behavioral assessment
        // row, which only exists once the boarding parent has recorded one —
        // omit it from the payload when there is nothing to attach it to.
        const hasAssessment =
            !!modalData?.studentCurriculum.behavioral_assessments?.length;
        const payload: Record<string, string | null> = {
            comment: comments[row.student_curriculum_id] || null,
            form_teacher_comment: ftComment || null,
        };

        if (hasAssessment) {
            payload.boarding_parent_comment = bpComment || null;
        }

        try {
            await axios.patch(
                `/api/head-of-school/students/${row.student_curriculum_id}/comment`,
                payload,
            );
            toast.success(
                `Saved comments for ${row.student.first_name} ${row.student.last_name}.`,
            );
        } catch {
            toast.error('Failed to save comments.');
        } finally {
            setSavingId(null);
            setSelectedRow(null);
        }
    }

    const sc = modalData?.studentCurriculum;
    const defaultBoundaries = modalData?.defaultBoundaries ?? [];
    const boundaries = sc?.curriculum?.exam_type?.grade_boundaries?.length
        ? sc.curriculum.exam_type.grade_boundaries
        : defaultBoundaries;

    return (
        <>
            <Head title="Student Comments" />
            <div className="space-y-6 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900">
                            Student Comments
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Write the head of school comment for students
                            across your supervised classes for the selected
                            term.
                        </p>
                    </div>
                    <TermFilterSelect value={termId} onChange={setTermId} />
                </div>

                {loading ? (
                    <div className="flex items-center justify-center py-24">
                        <Spinner className="size-6 text-gray-400" />
                    </div>
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon={<MessageSquare className="h-8 w-8" />}
                        title="No students found"
                        description="You don't have a head of school assignment for the current term yet, or no students are currently enrolled in your supervised arm(s)."
                    />
                ) : (
                    grouped.map(([className, classRows]) => (
                        <Card key={className}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {className}
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {classRows.length} student
                                    {classRows.length !== 1 ? 's' : ''}
                                </p>
                            </CardHeader>
                            <CardContent className="divide-y divide-gray-100">
                                {classRows.map((row) => (
                                    <div
                                        key={row.student_curriculum_id}
                                        className="flex items-center gap-3 py-4"
                                    >
                                        <Avatar>
                                            <AvatarImage
                                                src={
                                                    row.student.photo ??
                                                    undefined
                                                }
                                            />
                                            <AvatarFallback className="bg-indigo-100 text-sm font-semibold text-indigo-700">
                                                {getInitials(
                                                    `${row.student.first_name} ${row.student.last_name}`,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-gray-900">
                                                {row.student.first_name}{' '}
                                                {row.student.last_name}{' '}
                                                {row.comment && (
                                                    <CheckCircle className='text-green-500 inline-block size-4' />
                                                )}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {row.student.admission_number}
                                            </p>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => openModal(row)}
                                        >
                                            <Eye className="size-4" />
                                            View Result
                                        </Button>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>

            <Dialog
                open={selectedRow !== null}
                onOpenChange={(open) => !open && closeModal()}
            >
                <DialogContent className="max-h-[90vh] max-w-7xl overflow-x-auto overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedRow
                                ? `${selectedRow.student.first_name} ${selectedRow.student.last_name} — Result`
                                : 'Result'}
                        </DialogTitle>
                    </DialogHeader>

                    {modalLoading ? (
                        <div className="flex items-center justify-center py-16">
                            <Spinner className="size-6 text-gray-400" />
                        </div>
                    ) : sc && selectedRow ? (
                        <div className="space-y-4">
                            <CurriculumCardFinal
                                sc={sc}
                                defaultBoundaries={defaultBoundaries}
                                studentId={
                                    sc.student?.id ?? selectedRow.student.id
                                }
                                student={sc.student ?? selectedRow.student}
                                boundaries={boundaries}
                            />

                            <div className="space-y-4 border-t pt-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium text-gray-700">
                                        Form Teacher Comment
                                    </label>
                                    <textarea
                                        value={ftComment}
                                        onChange={(e) =>
                                            setFtComment(e.target.value)
                                        }
                                        rows={3}
                                        placeholder="Write the form teacher's comment for this student…"
                                        className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-medium text-gray-700">
                                        Boarding Parent Comment
                                    </label>
                                    <textarea
                                        value={bpComment}
                                        onChange={(e) =>
                                            setBpComment(e.target.value)
                                        }
                                        rows={3}
                                        disabled={
                                            !sc.behavioral_assessments?.length
                                        }
                                        placeholder={
                                            sc.behavioral_assessments?.length
                                                ? "Write the boarding parent's comment for this student…"
                                                : 'No behavioral assessment recorded yet — the boarding parent must record one first.'
                                        }
                                        className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-medium text-gray-700">
                                        Head of School Comment
                                    </label>
                                    <textarea
                                        value={
                                            comments[
                                                selectedRow
                                                    .student_curriculum_id
                                            ] ?? ''
                                        }
                                        onChange={(e) =>
                                            setComments((prev) => ({
                                                ...prev,
                                                [selectedRow.student_curriculum_id]:
                                                    e.target.value,
                                            }))
                                        }
                                        rows={3}
                                        placeholder="Write a comment for this student…"
                                        className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                                    />
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        onClick={() => handleSave(selectedRow)}
                                        disabled={
                                            savingId ===
                                            selectedRow.student_curriculum_id
                                        }
                                    >
                                        {savingId ===
                                            selectedRow.student_curriculum_id && (
                                            <Spinner className="size-4" />
                                        )}
                                        Save Comments
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}
