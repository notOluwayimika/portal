import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Edit, Plus, Search, Trash2, UserPlus, GraduationCap } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { Student } from '@/types/models';
import { ToastItem, Toast, ToastType } from '@/components/toast-item';
import { Pagination } from '@/components/pagination';
import Modal from '@/components/ui/Modal';
import { StudentForm } from '@/components/students/student-form';

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Students', href: '/students' },
];

export default function StudentList() {
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
    const [isModalOpen, setIsModalOpen] = useState(false);
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
            addToast('Failed to fetch students', 'error');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchStudents();
    }, [search, page, limit]);

    const handleDelete = async (uuid: string) => {
        if (!confirm('Are you sure you want to delete this student?')) return;

        try {
            await axios.delete(`/api/students/${uuid}`);
            addToast('Student deleted successfully');
            fetchStudents();
        } catch (error) {
            addToast('Failed to delete student', 'error');
        }
    };

    const handleAdd = () => {
        setCurrentStudent(null);
        setIsModalOpen(true);
    };

    const handleEdit = (student: Student) => {
        setCurrentStudent(student);
        setIsModalOpen(true);
    };

    const handleFormSuccess = () => {
        setIsModalOpen(false);
        addToast(currentStudent ? 'Student updated successfully' : 'Student created successfully');
        fetchStudents();
    };

    return (
        <>
            <Head title="Students" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Students</h1>
                        <p className="text-muted-foreground text-sm">Manage your school's student records</p>
                    </div>
                    <Button onClick={handleAdd}>
                        <UserPlus className="mr-2 h-4 w-4" />
                        Add Student
                    </Button>
                </div>

                <div className="bg-background rounded-lg border shadow-sm">
                    <div className="border-b p-4">
                        <div className="relative max-w-sm">
                            <Search className="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
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
                                <tr className="bg-muted/50 border-b">
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">Admission #</th>
                                    <th className="px-4 py-3 text-left font-medium">Class</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {loading ? (
                                    <tr>
                                        <td colSpan={5} className="py-12 text-center">
                                            <Spinner className="mx-auto" />
                                        </td>
                                    </tr>
                                ) : (students?.length ?? 0) === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground py-12 text-center">
                                            No students found.
                                        </td>
                                    </tr>
                                ) : (
                                    students?.map((student) => (
                                        <tr key={student.id} className="hover:bg-muted/30 transition-colors">
                                            <td className="px-4 py-3 font-medium">
                                                <div className="flex items-center gap-3">
                                                    <div className="bg-primary/10 text-primary flex h-8 w-8 items-center justify-center rounded-full">
                                                        {student.first_name?.[0]}{student.last_name?.[0]}
                                                    </div>
                                                    {student.full_name}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">{student.admission_number || '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    <GraduationCap className="h-3.5 w-3.5 opacity-60" />
                                                    {student.class_details?.full_class || 'N/A'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ${
                                                    student.status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
                                                    student.status === 'withdrawn' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                                                    'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                }`}>
                                                    {student.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                <Button variant="ghost" size="icon" onClick={() => handleEdit(student)}>
                                                    <Edit className="h-4 w-4" />
                                                </Button>
                                                    <Button 
                                                        variant="ghost" 
                                                        size="icon" 
                                                        className="text-destructive hover:bg-destructive/10"
                                                        onClick={() => handleDelete(student.uuid)}
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
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                title={currentStudent ? 'Edit Student' : 'Add Student'}
                size="lg"
            >
                <StudentForm
                    student={currentStudent}
                    onSuccess={handleFormSuccess}
                    onCancel={() => setIsModalOpen(false)}
                />
            </Modal>

            <div className="fixed bottom-6 right-6 flex flex-col gap-2 z-50">
                {toasts.map((toast) => (
                    <ToastItem
                        key={toast.id}
                        toast={toast}
                        onDismiss={() => setToasts((prev) => prev.filter((t) => t.id !== toast.id))}
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
