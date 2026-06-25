import { Head } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle, MessageSquare } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import EmptyState from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import type { Student } from '@/types/models';

interface CommentRow {
    student_curriculum_id: string;
    student: Student;
    class_name: string | null;
    comment: string | null;
}

export default function FormTeacherCommentsIndex() {
    const getInitials = useInitials();
    const [rows, setRows] = useState<CommentRow[]>([]);
    const [comments, setComments] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(true);
    const [savingId, setSavingId] = useState<string | null>(null);

    useEffect(() => {
        async function fetchData() {
            setLoading(true);

            try {
                const res = await axios.get('/api/form-teacher/students');
                const data: CommentRow[] = res.data.data ?? [];

                setRows(data);
                setComments(Object.fromEntries(data.map((row) => [row.student_curriculum_id, row.comment ?? ''])));
            } catch {
                toast.error('Failed to load students.');
            } finally {
                setLoading(false);
            }
        }

        fetchData();
    }, []);

    async function handleSave(row: CommentRow) {
        setSavingId(row.student_curriculum_id);

        try {
            await axios.patch(`/api/form-teacher/students/${row.student_curriculum_id}/comment`, {
                comment: comments[row.student_curriculum_id] || null,
            });
            toast.success(`Saved comment for ${row.student.first_name} ${row.student.last_name}.`);
        } catch {
            toast.error('Failed to save comment.');
        } finally {
            setSavingId(null);
        }
    }

    return (
        <>
            <Head title="Student Comments" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Student Comments</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Write this term's form teacher comment for each student in your class.
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
                        description="You don't have a form teacher assignment for the current term yet, or no students are currently enrolled in your assigned arm."
                    />
                ) : (
                    <Card>
                        <CardContent className="divide-y divide-gray-100">
                            {rows.map((row) => (
                                <div key={row.student_curriculum_id} className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start">
                                    <div className="flex items-center gap-3 sm:w-64 sm:shrink-0">
                                        <Avatar>
                                            <AvatarImage src={row.student.photo ?? undefined} />
                                            <AvatarFallback className="bg-indigo-100 text-sm font-semibold text-indigo-700">
                                                {getInitials(`${row.student.first_name} ${row.student.last_name}`)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-gray-900">
                                                {row.student.first_name} {row.student.last_name} {comments[row.student_curriculum_id] && <CheckCircle className='text-green-500 inline-block size-4' />}
                                            </p>
                                            <p className="text-xs text-gray-400">{row.student.admission_number}</p>
                                            {row.class_name && (
                                                <Badge variant="outline" className="mt-1">
                                                    {row.class_name}
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex-1 space-y-2">
                                        <textarea
                                            value={comments[row.student_curriculum_id] ?? ''}
                                            onChange={(e) =>
                                                setComments((prev) => ({ ...prev, [row.student_curriculum_id]: e.target.value }))
                                            }
                                            rows={2}
                                            placeholder="Write a comment for this student…"
                                            className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                                        />
                                        <div className="flex justify-end">
                                            <Button
                                                size="sm"
                                                onClick={() => handleSave(row)}
                                                disabled={savingId === row.student_curriculum_id}
                                            >
                                                {savingId === row.student_curriculum_id && <Spinner className="size-4" />}
                                                Save
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
