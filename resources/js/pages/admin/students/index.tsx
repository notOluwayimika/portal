import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Download, Edit, FileX, GraduationCap, Search, Trash2, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Pagination } from '@/components/pagination';
import { StudentForm } from '@/components/students/student-form';
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
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
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

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Students</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage your school's student records
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={handleExport} disabled={exporting}>
                            <Download className="mr-1 h-4 w-4" />
                            {exporting ? 'Exporting…' : 'Export'}
                        </Button>
                        <Button variant="outline" onClick={handleStudentImport}>
                            <FileX className="mr-1 h-4 w-4" />
                            Import
                        </Button>
                        <Button onClick={handleAdd}>
                            <UserPlus className="mr-1 h-4 w-4" />
                            Add Student
                        </Button>
                    </div>
                </div>

                <div className="rounded-lg border bg-background shadow-sm">
                    <div className="border-b p-4">
                        <div className="relative max-w-sm">
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search students..."
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
                                        Admission #
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Class
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
                                            colSpan={5}
                                            className="py-12 text-center"
                                        >
                                            <Spinner className="mx-auto" />
                                        </td>
                                    </tr>
                                ) : (students?.length ?? 0) === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="py-12 text-center text-muted-foreground"
                                        >
                                            No students found.
                                        </td>
                                    </tr>
                                ) : (
                                    students?.map((student) => (
                                        <tr
                                            key={student.id}
                                            className="transition-colors hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                <div className="flex items-center gap-3">
                                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                                        <AvatarImage
                                                            src={student?.photo}
                                                            alt={
                                                                student?.first_name +
                                                                ' ' +
                                                                student?.last_name
                                                            }
                                                        />
                                                        <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                                            {getInitials(
                                                                student?.first_name +
                                                                    ' ' +
                                                                    student?.last_name,
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    {student.full_name}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {student.admission_number ||
                                                    '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    <GraduationCap className="h-3.5 w-3.5 opacity-60" />
                                                    {student.class_details
                                                        ?.full_class || 'N/A'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Select
                                                    value={student.status}
                                                    onChange={(val) =>
                                                        val &&
                                                        handleStatusChange(
                                                            student,
                                                            String(val),
                                                        )
                                                    }
                                                    options={
                                                        student_statuses?.map(
                                                            (s) => ({
                                                                label: s.name,
                                                                value: s.value,
                                                            }),
                                                        ) || []
                                                    }
                                                    buttonClass={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium capitalize border-none hover:bg-opacity-80 transition-colors ${
                                                        student.status ===
                                                        'active'
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                            : student.status ===
                                                                'withdrawn'
                                                              ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                                              : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                    }`}
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            handleEdit(student)
                                                        }
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-destructive hover:bg-destructive/10"
                                                        onClick={() =>
                                                            handleDelete(student)
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
                isOpen={isStudentModalOpen}
                onClose={() => setIsStudentModalOpen(false)}
                title={currentStudent ? 'Edit Student' : 'Add Student'}
                size="lg"
            >
                <StudentForm
                    student={currentStudent}
                    onSuccess={handleFormSuccess}
                    onCancel={() => setIsStudentModalOpen(false)}
                />
            </Modal>

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
