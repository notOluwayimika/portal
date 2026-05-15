import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeft,
    BookOpen,
    Calendar,
    ChevronDown,
    ClipboardList,
    CreditCard,
    Edit,
    FileText,
    GraduationCap,
    Info,
    Mail,
    MapPin,
    Phone,
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

function statusColor(status: string | undefined) {
    switch (status) {
        case 'active':    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400';
        case 'graduated': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400';
        case 'withdrawn':
        case 'expelled':  return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        default:          return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function DetailRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) return null;
    return (
        <div className="space-y-1">
            <dt className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">{label}</dt>
            <dd className="text-sm font-semibold text-slate-700">{value}</dd>
        </div>
    );
}

function isSyntheticEmail(email?: string | null): boolean {
    return !!email && email.endsWith('@no-email.local');
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

            <div className="min-h-screen bg-[#f5f7fb] py-5 px-4 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">

                    {/* ── Breadcrumbs ──────────────────────────────────────────── */}
                    <nav className="flex items-center gap-2 text-sm font-medium text-muted-foreground/60">
                        <Link href="/" className="transition-colors hover:text-primary">Home</Link>
                        <span className="select-none text-muted-foreground/30">/</span>
                        <Link href="/students" className="transition-colors hover:text-primary">Students</Link>
                        <span className="select-none text-muted-foreground/30">/</span>
                        <span className="text-foreground/80">{student.full_name}</span>
                    </nav>

                    {/* ── Premium Hero Card ───────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="relative shrink-0">
                                    <div className="absolute -inset-0.5 rounded-full bg-gradient-to-tr from-indigo-500 to-violet-500 opacity-10 blur" />
                                    <Avatar className="relative size-14 rounded-full border-2 border-white shadow-sm ring-1 ring-black/5">
                                        <AvatarImage src={student.photo ?? undefined} alt={student.full_name} className="object-cover" />
                                        <AvatarFallback className="rounded-full bg-gradient-to-br from-indigo-50 to-violet-50 text-base font-bold text-indigo-600 dark:from-indigo-950/50 dark:to-violet-950/50">
                                            {getInitials(student.full_name)}
                                        </AvatarFallback>
                                    </Avatar>
                                </div>

                                <div className="space-y-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            {student.full_name}
                                        </h1>
                                        {student.status && (
                                            <Badge className={`rounded-full px-2.5 py-0.5 text-[10px] font-semibold capitalize shadow-sm ${statusColor(student.status)}`}>
                                                {student.status}
                                            </Badge>
                                        )}
                                        {student.admission_number && (
                                            <Badge className="rounded-full bg-slate-50 px-2.5 py-0.5 text-[10px] font-semibold text-slate-500 shadow-sm dark:bg-slate-800 dark:text-slate-400">
                                                #{student.admission_number}
                                            </Badge>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                        <span className="inline-flex items-center gap-1.5">
                                            <GraduationCap className="h-3 w-3" />
                                            {student.class_details?.full_class ?? 'N/A'}
                                        </span>
                                        {student.gender && (
                                            <span className="inline-flex items-center gap-1.5">
                                                <User2 className="h-3 w-3" />
                                                <span className="capitalize">{student.gender}</span>
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Action buttons */}
                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    size="sm"
                                    onClick={() => setShowEditModal(true)}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <Edit className="mr-1.5 h-4 w-4" />
                                    Edit Student
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button size="sm" variant="outline" className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50">
                                            More
                                            <ChevronDown className="ml-1.5 h-4 w-4 opacity-50" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-48 rounded-xl p-1 shadow-xl">
                                        <DropdownMenuItem onClick={() => setShowEditModal(true)} className="rounded-lg py-2 cursor-pointer">
                                            <Edit className="mr-2 h-4 w-4 text-slate-500" />
                                            Edit Details
                                        </DropdownMenuItem>
                                        <DropdownMenuItem disabled className="rounded-lg py-2 cursor-not-allowed opacity-50">
                                            <Printer className="mr-2 h-4 w-4 text-slate-500" />
                                            Print Profile
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>

                    {/* ── Two-column content area ──────────────────────────── */}
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">

                        {/* Left column (2/3 width on lg) */}
                        <div className="space-y-5 lg:col-span-2">

                            {/* Personal Details */}
                            <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                                <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                                    <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                        <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                            <User2 className="h-4 w-4 text-indigo-600" />
                                        </div>
                                        Personal Details
                                    </CardTitle>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setShowEditModal(true)}
                                        className="rounded-lg text-slate-500 hover:bg-white hover:text-indigo-600 hover:shadow-sm"
                                    >
                                        <Edit className="h-4 w-4" />
                                    </Button>
                                </CardHeader>
                                <CardContent className="p-5">
                                    <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                                        <DetailRow label="First Name"  value={student.first_name} />
                                        <DetailRow label="Middle Name" value={student.middle_name} />
                                        <DetailRow label="Last Name"   value={student.last_name} />
                                        <DetailRow label="Gender"      value={student.gender} />
                                        <DetailRow label="Date of Birth" value={student.date_of_birth} />
                                        <DetailRow label="Admission Number" value={student.admission_number} />
                                        <DetailRow label="Current Class" value={student.class_details?.full_class} />
                                        <DetailRow label="Status" value={student.status} />
                                    </dl>
                                </CardContent>
                            </Card>

                            {/* Guardians Section */}
                            <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                                <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                                    <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                        <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                            <Users className="h-4 w-4 text-indigo-600" />
                                        </div>
                                        Guardians ({guardians.length})
                                    </CardTitle>
                                    <Button
                                        size="sm"
                                        onClick={() => setShowAddGuardian(true)}
                                        className="rounded-lg bg-indigo-600 px-3 font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 active:scale-95"
                                    >
                                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                                        Add Guardian
                                    </Button>
                                </CardHeader>
                                <CardContent className="p-4">
                                    {hasNoGuardians ? (
                                        <div className="flex flex-col items-center justify-center py-8 text-center">
                                            <div className="mb-3 flex size-14 items-center justify-center rounded-full bg-amber-50 text-amber-500">
                                                <Info className="h-7 w-7" />
                                            </div>
                                            <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-100">No guardians linked</h3>
                                            <p className="mt-1 max-w-[280px] text-xs text-slate-500">
                                                Every student should have at least one guardian for communication and emergency.
                                            </p>
                                            <Button
                                                size="sm"
                                                onClick={() => setShowAddGuardian(true)}
                                                variant="link"
                                                className="mt-2 font-semibold text-indigo-600"
                                            >
                                                Add the first guardian
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="grid grid-cols-1 gap-3">
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
                        </div>

                        {/* Right column (1/3 width on lg) */}
                        <div className="space-y-5">
                            <PlaceholderCard
                                icon={<BookOpen className="h-4 w-4 text-indigo-600" />}
                                title="Academic Records"
                            />
                            <PlaceholderCard
                                icon={<Calendar className="h-4 w-4 text-indigo-600" />}
                                title="Attendance"
                            />
                            <PlaceholderCard
                                icon={<CreditCard className="h-4 w-4 text-indigo-600" />}
                                title="Fees & Payments"
                            />
                        </div>
                    </div>
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

function PlaceholderCard({
    icon,
    title,
}: {
    icon: React.ReactNode;
    title: string;
}) {
    return (
        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
            <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                    <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                        {icon}
                    </div>
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4">
                <div className="flex flex-col items-center gap-2 py-4 text-center">
                    <ClipboardList className="h-7 w-7 text-slate-200" />
                    <p className="text-[10px] font-bold tracking-tight text-slate-400 uppercase">
                        Coming soon
                    </p>
                    <p className="max-w-[200px] text-[11px] text-slate-400">
                        Detailed {title.toLowerCase()} tracking will be available in the next update.
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
