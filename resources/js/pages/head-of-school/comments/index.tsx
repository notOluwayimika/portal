import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Eye, MessageSquare } from 'lucide-react';
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

    useEffect(() => {
        async function fetchData() {
            setLoading(true);

            try {
                const res = await axios.get('/api/head-of-school/students');
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
    }, []);

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
            setModalData({
                studentCurriculum: res.data.studentCurriculum,
                defaultBoundaries:
                    res.data.defaultBoundaries.data ??
                    res.data.defaultBoundaries,
            });
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
    }

    async function handleSave(row: CommentRow) {
        setSavingId(row.student_curriculum_id);

        try {
            await axios.patch(
                `/api/head-of-school/students/${row.student_curriculum_id}/comment`,
                {
                    comment: comments[row.student_curriculum_id] || null,
                },
            );
            toast.success(
                `Saved comment for ${row.student.first_name} ${row.student.last_name}.`,
            );
        } catch {
            toast.error('Failed to save comment.');
        } finally {
            setSavingId(null);
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
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">
                        Student Comments
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Write this term's head of school comment for students
                        across your supervised classes.
                    </p>
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
                                                {row.student.last_name}
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

                            <div className="space-y-2 border-t pt-4">
                                <label className="text-sm font-medium text-gray-700">
                                    Head of School Comment
                                </label>
                                <textarea
                                    value={
                                        comments[
                                            selectedRow.student_curriculum_id
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
                                        Save Comment
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
