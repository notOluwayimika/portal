import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Download, Edit, FileX, GraduationCap, Save, Search, Trash2, Users, UserPlus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Pagination } from '@/components/pagination';
import { StudentForm } from '@/components/students/student-form';
import { StudentGuardiansPanel } from '@/components/students/student-guardians-panel';
import { ImportStudentForm } from '@/components/students/import-student-form';
import type { Toast, ToastType } from '@/components/toast-item';
import { ToastItem } from '@/components/toast-item';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { useApiSweetAlertConfirmation } from '@/hooks/use-sweetalert-confirmation';
import type { Student } from '@/types/models';

interface StatusOption {
    name: string;
    value: string;
}

interface StudentListProps {
    student_statuses: StatusOption[];
}

export default function StudentList({ student_statuses }: StudentListProps) {
    const getInitials = useInitials();
    const [students, setStudents] = useState<Student[]>([]);
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
    const [isStudentModalOpen, setIsStudentModalOpen] = useState(false);
    const [studentFormProcessing, setStudentFormProcessing] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [guardiansPanelStudent, setGuardiansPanelStudent] = useState<Student | null>(null);
    const [currentStudent, setCurrentStudent] = useState<Student | null>(null);
    let toastCounter = 0;

    const addToast = (message: string, type: ToastType = 'success') => {
        const id = ++toastCounter;
        setToasts((prev) => [...prev, { id, message, type }]);
    };

    const fetchStudents = async () => {
        try {
            setLoading(true);
            const response = await axios.get('/api/students', {
                params: {
                    search,
                    page,
                    per_page: limit,
                },
            });
            setStudents(response.data.data || []);

            if (response.data.pagination) {
                setPagination(response.data.pagination);
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to fetch students', 'error');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchStudents();

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, page, limit]);

    const { confirmAndExecute } = useApiSweetAlertConfirmation();

    const handleDelete = async (student: Student) => {
        const result = await confirmAndExecute({
            method: 'delete',
            url: `/api/students/${student.id}`,
            sweetAlertTitle: 'Delete Student?',
            sweetAlertText: `This will permanently remove ${student.first_name} ${student.last_name}. This action cannot be undone.`,
            sweetAlertIcon: 'warning',
            confirmButtonText: 'Delete',
            successMessage: 'Student deleted successfully.',
            showSuccessAlert: false,
        });
        if (result !== false) {
            addToast('Student deleted successfully');
            fetchStudents();
        }
    };

    const handleAdd = () => {
        setCurrentStudent(null);
        setIsStudentModalOpen(true);
    };

    const handleStudentImport = () => {
        setIsImportModalOpen(true);
    };

    const [exporting, setExporting] = useState(false);

    const handleExport = async () => {
        try {
            setExporting(true);
            const response = await axios.get('/api/students/export', {
                responseType: 'blob',
                params: { search },
            });
            const url = URL.createObjectURL(new Blob([response.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `students-${new Date().toISOString().slice(0, 10)}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch {
            addToast('Failed to export students', 'error');
        } finally {
            setExporting(false);
        }
    };

    const handleStatusChange = async (student: Student, newStatus: string) => {
        try {
            await axios.patch(`/api/students/${student.id}/status`, {
                status: newStatus,
            });
            addToast(`Student status updated to ${newStatus}`);
            fetchStudents();
        } catch (error) {
            console.log(error);
            addToast('Failed to update student status', 'error');
        }
    };

    const handleEdit = (student: Student) => {
        setCurrentStudent(student);
        setIsStudentModalOpen(true);
    };

    const handleFormSuccess = () => {
        setIsStudentModalOpen(false);
        setIsImportModalOpen(false);
        addToast(
            currentStudent
                ? 'Student updated successfully'
                : 'Student created successfully',
        );
        fetchStudents();
    };

    return (
        <>
            <Head title="Students" />

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
                                        Students
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Manage your school's student records.
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={handleStudentImport}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50"
                                >
                                    <FileX className="mr-1.5 h-4 w-4" />
                                    Import
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={handleExport}
                                    disabled={exporting}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50"
                                >
                                    <Download className="mr-1.5 h-4 w-4" />
                                    {exporting ? 'Exporting…' : 'Export'}
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={handleAdd}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <UserPlus className="mr-1.5 h-4 w-4" />
                                    Add Student
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
                                        placeholder="Search by name or admission number…"
                                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-indigo-100 dark:border-slate-700 dark:bg-slate-900"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                        Showing <span className="font-bold text-slate-700 dark:text-slate-200">{students.length}</span> of{' '}
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
                                            Admission #
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Class
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
                                            <td colSpan={5} className="py-10 text-center">
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : (students?.length ?? 0) === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="py-10 text-center text-xs text-muted-foreground">
                                                No students found.
                                            </td>
                                        </tr>
                                    ) : (
                                        students?.map((student) => (
                                            <tr
                                                key={student.id}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    <div className="flex items-center gap-2.5">
                                                        <Avatar className="size-7 shrink-0 overflow-hidden rounded-full">
                                                            <AvatarImage
                                                                src={student?.photo}
                                                                alt={student?.first_name + ' ' + student?.last_name}
                                                            />
                                                            <AvatarFallback className="rounded-full bg-neutral-200 text-[10px] text-black dark:bg-neutral-700 dark:text-white">
                                                                {getInitials(student?.first_name + ' ' + student?.last_name)}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <Link
                                                            href={`/students/${student.id}`}
                                                            className="hover:text-primary hover:underline transition-colors"
                                                        >
                                                            {student.full_name}
                                                        </Link>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5 text-muted-foreground">
                                                    {student.admission_number || '—'}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <div className="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                                                        <GraduationCap className="h-3.5 w-3.5 opacity-60" />
                                                        {student.class_details?.full_class || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <Select
                                                        value={student.status}
                                                        onChange={(val) =>
                                                            val && handleStatusChange(student, String(val))
                                                        }
                                                        options={
                                                            student_statuses?.map((s) => ({
                                                                label: s.name,
                                                                value: s.value,
                                                            })) || []
                                                        }
                                                        buttonClass={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize border-none hover:bg-opacity-80 transition-colors ${
                                                            student.status === 'active'
                                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                                                : student.status === 'withdrawn'
                                                                  ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                                                  : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                        }`}
                                                    />
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            title="Manage guardians"
                                                            onClick={() => setGuardiansPanelStudent(student)}
                                                        >
                                                            <Users className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            onClick={() => handleEdit(student)}
                                                        >
                                                            <Edit className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 text-destructive hover:bg-destructive/10"
                                                            onClick={() => handleDelete(student)}
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
                isOpen={isStudentModalOpen}
                onClose={() => setIsStudentModalOpen(false)}
                title={currentStudent ? 'Edit Student' : 'Add Student'}
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => setIsStudentModalOpen(false)} disabled={studentFormProcessing}>
                            Cancel
                        </Button>
                        <Button type="submit" form="student-form" disabled={studentFormProcessing}>
                            {studentFormProcessing ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            {currentStudent ? 'Update Student' : 'Create Student'}
                        </Button>
                    </div>
                }
            >
                <StudentForm
                    student={currentStudent}
                    onSuccess={handleFormSuccess}
                    onCancel={() => setIsStudentModalOpen(false)}
                    onProcessingChange={setStudentFormProcessing}
                />
            </Modal>

            <StudentGuardiansPanel
                isOpen={!!guardiansPanelStudent}
                onClose={() => setGuardiansPanelStudent(null)}
                studentUuid={guardiansPanelStudent?.id ?? ''}
                studentName={guardiansPanelStudent?.full_name ?? ''}
                onChanged={fetchStudents}
            />

            <Modal
                isOpen={isImportModalOpen}
                onClose={() => setIsImportModalOpen(false)}
                title="Import Students"
                size="4xl"
            >
                <ImportStudentForm
                    student={currentStudent}
                    onSuccess={handleFormSuccess}
                    onCancel={() => setIsImportModalOpen(false)}
                />
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

StudentList.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Students', href: '/students' },
    ],
};
