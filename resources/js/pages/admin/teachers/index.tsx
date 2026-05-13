import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    BookOpen,
    Download,
    Edit,
    Search,
    Trash2,
    Upload,
    UserPlus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Pagination } from '@/components/pagination';
import { ImportTeacherForm } from '@/components/teachers/import-teacher-form';
import { TeacherForm } from '@/components/teachers/teacher-form';
import { TeacherSubjectsModal } from '@/components/teachers/teacher-subjects-modal';
import type { Toast, ToastType } from '@/components/toast-item';
import { ToastItem } from '@/components/toast-item';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/hooks/use-helper';
import { useInitials } from '@/hooks/use-initials';
import { useApiSweetAlertConfirmation } from '@/hooks/use-sweetalert-confirmation';
import type { Teacher } from '@/types/models';

interface StatusOption {
    name: string;
    value: string;
}

interface CurriculumOption {
    id: number;
    uuid: string;
    class_level: string;
    arm: string;
    stream?: string;
}

interface TeacherListProps {
    teacher_statuses: StatusOption[];
}

export default function TeacherList({ teacher_statuses }: TeacherListProps) {
    const getInitials = useInitials();
    const [teachers, setTeachers] = useState<Teacher[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(25);
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        prev_page_url: null,
        next_page_url: null,
    });
    const [isFormModalOpen, setIsFormModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [isSubjectsModalOpen, setIsSubjectsModalOpen] = useState(false);
    const [currentTeacher, setCurrentTeacher] = useState<Teacher | null>(null);
    const [curricula, setCurricula] = useState<CurriculumOption[]>([]);
    const [exporting, setExporting] = useState(false);
    let toastCounter = 0;

    const addToast = (message: string, type: ToastType = 'success') => {
        const id = ++toastCounter;
        setToasts((prev) => [...prev, { id, message, type }]);
    };

    const fetchTeachers = async () => {
        try {
            setLoading(true);
            const res = await axios.get('/api/teachers', {
                params: { search, page, per_page: limit },
            });
            setTeachers(res.data.data || []);

            if (res.data.pagination) {
                setPagination(res.data.pagination);
            }
        } catch {
            addToast('Failed to fetch teachers', 'error');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        axios
            .get('/api/teachers/resources')
            .then((res) => {
                setCurricula(res.data.data?.curricula || []);
            })
            .catch(() => {});
    }, []);

    useEffect(() => {
        fetchTeachers();
    }, [search, page, limit]);

    const { confirmAndExecute } = useApiSweetAlertConfirmation();

    const handleDelete = async (teacher: Teacher) => {
        const result = await confirmAndExecute({
            method: 'delete',
            url: `/api/teachers/${teacher.id}`,
            sweetAlertTitle: 'Delete Teacher?',
            sweetAlertText: `This will permanently remove ${teacher.full_name}. This action cannot be undone.`,
            sweetAlertIcon: 'warning',
            confirmButtonText: 'Delete',
            successMessage: 'Teacher deleted successfully.',
            showSuccessAlert: false,
        });

        if (result !== false) {
            addToast('Teacher deleted successfully');
            fetchTeachers();
        }
    };

    const handleAdd = () => {
        setCurrentTeacher(null);
        setIsFormModalOpen(true);
    };

    const handleEdit = (teacher: Teacher) => {
        setCurrentTeacher(teacher);
        setIsFormModalOpen(true);
    };

    const handleOpenSubjects = (teacher: Teacher) => {
        setCurrentTeacher(teacher);
        setIsSubjectsModalOpen(true);
    };

    const handleStatusChange = async (teacher: Teacher, newStatus: string) => {
        try {
            await axios.patch(`/api/teachers/${teacher.id}/status`, {
                status: newStatus,
            });
            addToast(`Teacher status updated to ${newStatus}`);
            fetchTeachers();
        } catch {
            addToast('Failed to update teacher status', 'error');
        }
    };

    const handleFormSuccess = () => {
        setIsFormModalOpen(false);
        addToast(
            currentTeacher
                ? 'Teacher updated successfully'
                : 'Teacher created successfully',
        );
        fetchTeachers();
    };

    const handleExport = async () => {
        try {
            setExporting(true);
            const response = await axios.get('/api/teachers/export', {
                responseType: 'blob',
                params: { search },
            });
            const url = URL.createObjectURL(
                new Blob([response.data], {
                    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                }),
            );
            const link = document.createElement('a');
            link.href = url;
            link.download = `teachers-${new Date().toISOString().slice(0, 10)}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch {
            addToast('Failed to export teachers', 'error');
        } finally {
            setExporting(false);
        }
    };

    const statusBadgeClass = (status: string) => {
        if (status === 'active') {
            return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400';
        }

        if (status === 'resigned') {
            return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
        }

        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    };

    return (
        <>
            <Head title="Teachers" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Teachers</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage your school's teaching staff
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            onClick={handleExport}
                            disabled={exporting}
                        >
                            {exporting ? (
                                <Spinner className="mr-1 h-4 w-4 animate-spin" />
                            ) : (
                                <Download className="mr-1 h-4 w-4" />
                            )}
                            {exporting ? 'Exporting…' : 'Export'}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => setIsImportModalOpen(true)}
                        >
                            <Upload className="mr-1 h-4 w-4" />
                            Import
                        </Button>
                        <Button onClick={handleAdd}>
                            <UserPlus className="mr-1 h-4 w-4" />
                            Add Teacher
                        </Button>
                    </div>
                </div>

                <div className="rounded-lg border bg-background shadow-sm">
                    <div className="border-b p-4">
                        <div className="relative max-w-sm">
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search teachers..."
                                className="pl-10"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-4 py-3 text-left font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Staff Number
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Qualification
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Hire Date
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {loading ? (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="py-12 text-center"
                                        >
                                            <Spinner className="mx-auto" />
                                        </td>
                                    </tr>
                                ) : (teachers?.length ?? 0) === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="py-12 text-center text-muted-foreground"
                                        >
                                            No teachers found.
                                        </td>
                                    </tr>
                                ) : (
                                    teachers?.map((teacher) => (
                                        <tr
                                            key={teacher.id}
                                            className="transition-colors hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                <div className="flex items-center gap-3">
                                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                                        <AvatarImage
                                                            src={
                                                                teacher?.photo ??
                                                                undefined
                                                            }
                                                            alt={
                                                                teacher.full_name
                                                            }
                                                        />
                                                        <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                                            {getInitials(
                                                                teacher.full_name,
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <Link
                                                        href={`/setup/teacher/${teacher.id}`}
                                                    >
                                                        {teacher.full_name}
                                                    </Link>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {teacher.staff_number || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {teacher.qualification || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatDate(
                                                    teacher.hire_date,
                                                ) || '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Select
                                                    value={teacher.status}
                                                    onChange={(val) =>
                                                        val &&
                                                        handleStatusChange(
                                                            teacher,
                                                            String(val),
                                                        )
                                                    }
                                                    options={
                                                        teacher_statuses?.map(
                                                            (s) => ({
                                                                label: s.name,
                                                                value: s.value,
                                                            }),
                                                        ) || []
                                                    }
                                                    buttonClass={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium capitalize border-none hover:bg-opacity-80 transition-colors ${statusBadgeClass(teacher.status)}`}
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        title="Assigned Subjects"
                                                        onClick={() =>
                                                            handleOpenSubjects(
                                                                teacher,
                                                            )
                                                        }
                                                    >
                                                        <BookOpen className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            handleEdit(teacher)
                                                        }
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-destructive hover:bg-destructive/10"
                                                        onClick={() =>
                                                            handleDelete(
                                                                teacher,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="mt-auto border-t bg-background/50 p-4">
                    <Pagination
                        meta={pagination}
                        setPage={setPage}
                        setLimit={setLimit}
                    />
                </div>
            </div>

            <Modal
                isOpen={isFormModalOpen}
                onClose={() => setIsFormModalOpen(false)}
                title={currentTeacher ? 'Edit Teacher' : 'Add Teacher'}
                size="lg"
            >
                <TeacherForm
                    teacher={currentTeacher}
                    onSuccess={handleFormSuccess}
                    onCancel={() => setIsFormModalOpen(false)}
                />
            </Modal>

            <Modal
                isOpen={isImportModalOpen}
                onClose={() => setIsImportModalOpen(false)}
                title="Import Teachers"
                size="lg"
            >
                <ImportTeacherForm
                    onSuccess={() => {
                        setIsImportModalOpen(false);
                        fetchTeachers();
                    }}
                    onCancel={() => setIsImportModalOpen(false)}
                />
            </Modal>

            <Modal
                isOpen={isSubjectsModalOpen}
                onClose={() => setIsSubjectsModalOpen(false)}
                title={
                    currentTeacher
                        ? `Assigned Subjects — ${currentTeacher.full_name}`
                        : 'Assigned Subjects'
                }
                size="3xl"
            >
                {currentTeacher && (
                    <TeacherSubjectsModal
                        teacher={currentTeacher}
                        curricula={curricula}
                        addToast={addToast}
                    />
                )}
            </Modal>

            <div className="fixed right-6 bottom-6 z-50 flex flex-col gap-2">
                {toasts.map((toast) => (
                    <ToastItem
                        key={toast.id}
                        toast={toast}
                        onDismiss={() =>
                            setToasts((prev) =>
                                prev.filter((t) => t.id !== toast.id),
                            )
                        }
                    />
                ))}
            </div>
        </>
    );
}

TeacherList.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Teachers', href: '/teachers' },
    ],
};
