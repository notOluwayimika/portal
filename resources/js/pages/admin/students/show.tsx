import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpen,
    Calendar,
    ChevronDown,
    ClipboardList,
    CreditCard,
    Edit,
    FileText,
    GraduationCap,
    Plus,
    Printer,
    User2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { AddGuardianModal } from '@/components/students/add-guardian-modal';
import { GuardianCard } from '@/components/students/guardian-card';
import { EditPivotModal } from '@/components/students/edit-pivot-modal';
import { StudentForm } from '@/components/students/student-form';
import {
    DetachGuardianModal,
    type StudentGuardian,
} from '@/components/students/student-guardians-panel';
import type { Toast, ToastType } from '@/components/toast-item';
import { ToastItem } from '@/components/toast-item';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import Modal from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/skeleton';
import { useInitials } from '@/hooks/use-initials';
import type { Guardian, Student } from '@/types/models';

/* ─── Types ────────────────────────────────────────────────────────────────── */

interface StatusOption {
    name: string;
    value: string;
}

interface ShowPageProps {
    student: { data: Student };
    student_statuses: StatusOption[];
}

/* ─── Helpers ──────────────────────────────────────────────────────────────── */

function statusColor(status: string | undefined) {
    switch (status) {
        case 'active':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400';
        case 'graduated':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400';
        case 'withdrawn':
        case 'expelled':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

/* ─── Page ─────────────────────────────────────────────────────────────────── */

export default function StudentProfile() {
    const { student: studentWrapper } = usePage<ShowPageProps>().props as unknown as ShowPageProps;
    const student = studentWrapper.data;
    const getInitials = useInitials();

    // Modals
    const [showEditModal, setShowEditModal] = useState(false);
    const [showAddGuardian, setShowAddGuardian] = useState(false);
    const [pivotGuardian, setPivotGuardian] = useState<Guardian | null>(null);
    const [detachTarget, setDetachTarget] = useState<Guardian | null>(null);

    // Toasts
    const [toasts, setToasts] = useState<Toast[]>([]);
    let toastCounter = 0;

    const addToast = (message: string, type: ToastType = 'success') => {
        const id = ++toastCounter;

        setToasts((prev) => [...prev, { id, message, type }]);
    };

    // Refresh student data from the server (Inertia partial reload)
    const refreshStudent = () => {
        router.reload({ only: ['student'] });
    };

    // Enable login handler
    const handleEnableLogin = async (guardian: Guardian) => {
        try {
            await axios.post(`/api/guardians/${guardian.id}/enable-login`);
            addToast(`Login enabled for ${guardian.full_name}`);
            refreshStudent();
        } catch {
            addToast('Failed to enable login', 'error');
        }
    };

    // Map Guardian to DetachGuardianModal's expected shape
    const mapToStudentGuardian = (g: Guardian): StudentGuardian => ({
        id: g.id,
        full_name: g.full_name,
        first_name: g.first_name,
        last_name: g.last_name,
        phone: g.phone,
        email: g.email,
        occupation: g.occupation,
        relationship: g.relationship,
        is_primary: g.is_primary,
        can_login: g.can_login,
    });

    const guardians = student.guardians ?? [];
    const hasNoGuardians = guardians.length === 0;

    return (
        <>
            <Head title={`${student.full_name} — Student Profile`} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                {/* ── Breadcrumb / Back ────────────────────────────────── */}
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        asChild
                        className="gap-1.5 text-muted-foreground"
                    >
                        <Link href="/students">
                            <ArrowLeft className="h-4 w-4" />
                            Back to Students
                        </Link>
                    </Button>
                </div>

                {/* ── Student Header ──────────────────────────────────── */}
                <div className="rounded-xl border bg-card p-6 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-start gap-4">
                            <Avatar className="size-16 rounded-full border-2 border-muted">
                                <AvatarImage
                                    src={student.photo}
                                    alt={student.full_name}
                                />
                                <AvatarFallback className="rounded-full bg-neutral-200 text-lg font-bold text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(student.full_name)}
                                </AvatarFallback>
                            </Avatar>

                            <div className="space-y-1.5">
                                <h1 className="text-xl font-bold tracking-tight">
                                    {student.full_name}
                                </h1>
                                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                    {student.admission_number && (
                                        <span className="font-mono text-xs">
                                            #{student.admission_number}
                                        </span>
                                    )}
                                    <span className="flex items-center gap-1">
                                        <GraduationCap className="h-3.5 w-3.5" />
                                        {student.class_details?.full_class ?? 'N/A'}
                                    </span>
                                    <Badge
                                        className={`${statusColor(student.status)} border-none text-xs capitalize`}
                                    >
                                        {student.status ?? 'unknown'}
                                    </Badge>
                                </div>
                            </div>
                        </div>

                        {/* Action buttons */}
                        <div className="flex shrink-0 gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setShowEditModal(true)}
                            >
                                <Edit className="mr-1 h-4 w-4" />
                                Edit Student
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled
                                title="Coming soon"
                            >
                                <Printer className="mr-1 h-4 w-4" />
                                Print
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        More
                                        <ChevronDown className="ml-1 h-3 w-3" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem disabled>
                                        Deactivate
                                    </DropdownMenuItem>
                                    <DropdownMenuItem disabled>
                                        Transfer Class
                                    </DropdownMenuItem>
                                    <DropdownMenuItem disabled>
                                        Archive
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </div>

                {/* ── Personal Details Card ───────────────────────────── */}
                <Card>
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <User2 className="h-4 w-4" />
                            Personal Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-x-8 gap-y-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                            <DetailItem
                                label="First Name"
                                value={student.first_name}
                            />
                            <DetailItem
                                label="Last Name"
                                value={student.last_name}
                            />
                            <DetailItem
                                label="Middle Name"
                                value={student.middle_name || '—'}
                            />
                            <DetailItem
                                label="Gender"
                                value={student.gender}
                                capitalize
                            />
                            <DetailItem
                                label="Date of Birth"
                                value={student.date_of_birth || '—'}
                            />
                            <DetailItem
                                label="Admission #"
                                value={student.admission_number || '—'}
                                mono
                            />
                            <DetailItem
                                label="Class"
                                value={
                                    student.class_details?.full_class ?? 'N/A'
                                }
                            />
                            <DetailItem
                                label="Status"
                                value={student.status ?? 'unknown'}
                                capitalize
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* ── Guardians Section ────────────────────────────────── */}
                <Card>
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Users className="h-4 w-4" />
                            Guardians ({guardians.length})
                        </CardTitle>
                        <Button
                            size="sm"
                            onClick={() => setShowAddGuardian(true)}
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add Guardian
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {/* Warning: no guardians */}
                        {hasNoGuardians && (
                            <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-amber-300 bg-amber-50 p-8 text-center dark:border-amber-700 dark:bg-amber-950/30">
                                <AlertTriangle className="h-8 w-8 text-amber-500" />
                                <div>
                                    <p className="font-semibold text-amber-800 dark:text-amber-300">
                                        No guardians attached
                                    </p>
                                    <p className="mt-1 text-sm text-amber-600 dark:text-amber-400">
                                        Every student should have at least one
                                        guardian. Click the button above to add
                                        one.
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Guardian cards */}
                        {!hasNoGuardians && (
                            <div className="grid gap-4 md:grid-cols-2">
                                {guardians.map((g) => (
                                    <GuardianCard
                                        key={g.id}
                                        guardian={g}
                                        studentUuid={student.id}
                                        isOnlyGuardian={guardians.length === 1}
                                        onEditPivot={setPivotGuardian}
                                        onDetach={setDetachTarget}
                                        onEnableLogin={handleEnableLogin}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Placeholder Sections ────────────────────────────── */}
                <div className="grid gap-4 md:grid-cols-2">
                    <PlaceholderCard
                        icon={<BookOpen className="h-5 w-5" />}
                        title="Academic Records"
                    />
                    <PlaceholderCard
                        icon={<Calendar className="h-5 w-5" />}
                        title="Attendance"
                    />
                    <PlaceholderCard
                        icon={<CreditCard className="h-5 w-5" />}
                        title="Fees & Payments"
                    />
                    <PlaceholderCard
                        icon={<FileText className="h-5 w-5" />}
                        title="Notes & Documents"
                    />
                </div>
            </div>

            {/* ── Modals ─────────────────────────────────────────────── */}

            {/* Edit Student */}
            <Modal
                isOpen={showEditModal}
                onClose={() => setShowEditModal(false)}
                title="Edit Student"
                size="lg"
            >
                <StudentForm
                    student={student}
                    onSuccess={() => {
                        setShowEditModal(false);
                        addToast('Student updated successfully');
                        refreshStudent();
                    }}
                    onCancel={() => setShowEditModal(false)}
                />
            </Modal>

            {/* Add Guardian */}
            <AddGuardianModal
                isOpen={showAddGuardian}
                onClose={() => setShowAddGuardian(false)}
                studentUuid={student.id}
                studentName={student.full_name}
                forcePrimary={hasNoGuardians}
                onAdded={() => {
                    setShowAddGuardian(false);
                    addToast('Guardian added successfully');
                    refreshStudent();
                }}
            />

            {/* Edit Pivot */}
            <EditPivotModal
                isOpen={!!pivotGuardian}
                onClose={() => setPivotGuardian(null)}
                studentUuid={student.id}
                guardian={pivotGuardian}
                onSaved={() => {
                    setPivotGuardian(null);
                    addToast('Guardian relationship updated');
                    refreshStudent();
                }}
            />

            {/* Detach Guardian */}
            {detachTarget && (
                <DetachGuardianModal
                    isOpen={!!detachTarget}
                    onClose={() => setDetachTarget(null)}
                    studentUuid={student.id}
                    target={mapToStudentGuardian(detachTarget)}
                    candidates={guardians
                        .filter((g) => g.id !== detachTarget.id)
                        .map(mapToStudentGuardian)}
                    onDetached={() => {
                        setDetachTarget(null);
                        addToast('Guardian removed from student');
                        refreshStudent();
                    }}
                />
            )}

            {/* ── Toasts ─────────────────────────────────────────────── */}
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

/* ─── Sub-components ───────────────────────────────────────────────────────── */

function DetailItem({
    label,
    value,
    capitalize,
    mono,
}: {
    label: string;
    value: string;
    capitalize?: boolean;
    mono?: boolean;
}) {
    return (
        <div>
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p
                className={`mt-0.5 font-medium ${capitalize ? 'capitalize' : ''} ${mono ? 'font-mono text-xs' : ''}`}
            >
                {value}
            </p>
        </div>
    );
}

function PlaceholderCard({
    icon,
    title,
}: {
    icon: React.ReactNode;
    title: string;
}) {
    return (
        <Card className="opacity-60">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    {icon}
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex flex-col items-center gap-2 py-6 text-center">
                    <ClipboardList className="h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">
                        Coming soon
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

/* ─── Layout metadata ──────────────────────────────────────────────────────── */

StudentProfile.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Students', href: '/students' },
        { title: 'Profile' },
    ],
};
