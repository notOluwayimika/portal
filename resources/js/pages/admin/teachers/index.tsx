import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    BookOpen,
    Download,
    Edit,
    GraduationCap,
    Save,
    Search,
    Trash2,
    Upload,
    UserPlus,
    X,
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
    const [teacherFormProcessing, setTeacherFormProcessing] = useState(false);
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
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400';
        }

        if (status === 'resigned') {
            return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
        }

        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    };

    return (
        <>
            <Head title="Teachers" />

            <div className="min-h-screen bg-[#f5f7fb] py-5 px-4 sm:px-6 lg:px-8 pb-24 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">

                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <GraduationCap className="h-6 w-6 text-indigo-600" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Teachers
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Manage your school's teaching staff.
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setIsImportModalOpen(true)}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <Upload className="mr-1.5 h-4 w-4" />
                                    Import
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={handleExport}
                                    disabled={exporting}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    {exporting ? (
                                        <Spinner className="mr-1.5 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Download className="mr-1.5 h-4 w-4" />
                                    )}
                                    {exporting ? 'Exporting…' : 'Export'}
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={handleAdd}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <UserPlus className="mr-1.5 h-4 w-4" />
                                    Add Teacher
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        {/* Search row */}
                        <div className="border-b border-slate-100 dark:border-slate-800">
                            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                                <div className="relative w-full sm:max-w-md sm:flex-1">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        placeholder="Search by name or staff number…"
                                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-indigo-100 dark:border-slate-700 dark:bg-slate-900"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                        Showing <span className="font-bold text-slate-700 dark:text-slate-200">{teachers.length}</span> of{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">{pagination.total}</span>
                                    </span>
                                    {search && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setSearch('')}
                                            className="rounded-lg text-slate-500 hover:bg-slate-50 hover:text-slate-700"
                                        >
                                            <X className="mr-1 h-3.5 w-3.5" />
                                            Clear
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Table */}
                        <div className="overflow-x-auto custom-scrollbar">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Name
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Staff Number
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Qualification
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Hire Date
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Status
                                        </th>
                                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {loading ? (
                                        <tr>
                                            <td colSpan={6} className="py-10 text-center">
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : (teachers?.length ?? 0) === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="py-10 text-center text-xs text-muted-foreground">
                                                No teachers found.
                                            </td>
                                        </tr>
                                    ) : (
                                        teachers?.map((teacher) => (
                                            <tr
                                                key={teacher.id}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    <div className="flex items-center gap-2.5">
                                                        <Avatar className="size-7 shrink-0 overflow-hidden rounded-full">
                                                            <AvatarImage
                                                                src={teacher?.photo ?? undefined}
                                                                alt={teacher.full_name}
                                                            />
                                                            <AvatarFallback className="rounded-full bg-neutral-200 text-[10px] text-black dark:bg-neutral-700 dark:text-white">
                                                                {getInitials(teacher.full_name)}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <Link
                                                            href={`/setup/teacher/${teacher.id}`}
                                                            className="hover:text-primary hover:underline transition-colors"
                                                        >
                                                            {teacher.full_name}
                                                        </Link>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5 text-muted-foreground">
                                                    {teacher.staff_number || '—'}
                                                </td>
                                                <td className="px-3 py-2.5 text-muted-foreground">
                                                    {teacher.qualification || '—'}
                                                </td>
                                                <td className="px-3 py-2.5 text-muted-foreground">
                                                    {formatDate(teacher.hire_date) || '—'}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <Select
                                                        value={teacher.status}
                                                        onChange={(val) =>
                                                            val && handleStatusChange(teacher, String(val))
                                                        }
                                                        options={
                                                            teacher_statuses?.map((s) => ({
                                                                label: s.name,
                                                                value: s.value,
                                                            })) || []
                                                        }
                                                        buttonClass={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize border-none hover:bg-opacity-80 transition-colors ${statusBadgeClass(teacher.status)}`}
                                                    />
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            title="Assigned Subjects"
                                                            onClick={() => handleOpenSubjects(teacher)}
                                                        >
                                                            <BookOpen className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            onClick={() => handleEdit(teacher)}
                                                        >
                                                            <Edit className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 text-destructive hover:bg-destructive/10"
                                                            onClick={() => handleDelete(teacher)}
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
                            <Pagination
                                meta={pagination}
                                setPage={setPage}
                                setLimit={setLimit}
                            />
                        </div>
                    </div>
                </div>
            </div>

            <Modal
                isOpen={isFormModalOpen}
                onClose={() => setIsFormModalOpen(false)}
                title={currentTeacher ? 'Edit Teacher' : 'Add Teacher'}
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => setIsFormModalOpen(false)} disabled={teacherFormProcessing}>
                            Cancel
                        </Button>
                        <Button type="submit" form="teacher-form" disabled={teacherFormProcessing}>
                            {teacherFormProcessing ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            {currentTeacher ? 'Update Teacher' : 'Create Teacher'}
                        </Button>
                    </div>
                }
            >
                <TeacherForm
                    teacher={currentTeacher}
                    onSuccess={handleFormSuccess}
                    onCancel={() => setIsFormModalOpen(false)}
                    onProcessingChange={setTeacherFormProcessing}
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
