import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Briefcase,
    ChevronDown,
    CreditCard,
    Edit,
    MapPin,
    Phone,
    RotateCcw,
    User2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { ActivityLogCard } from '@/components/guardians/activity-log-card';
import { EditGuardianModal } from '@/components/guardians/edit-guardian-modal';
import { LoginAccessCard } from '@/components/guardians/login-access-card';
import { StudentCard } from '@/components/guardians/student-card';
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
import { useInitials } from '@/hooks/use-initials';
import type { Guardian, GuardianPivot, Student } from '@/types/models';

/* ─── Types ─────────────────────────────────────────────────────────────────── */

interface ShowPageProps extends Record<string, unknown> {
    guardian: { data: Guardian & { students?: (Student & { pivot: GuardianPivot })[] } };
}

/* ─── Helpers ───────────────────────────────────────────────────────────────── */

function statusColor(status: string | undefined) {
    switch (status) {
        case 'active':   return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400';
        case 'inactive': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400';
        case 'blocked':  return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        default:         return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function DetailRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) return null;
    return (
        <div className="space-y-1.5">
            <dt className="text-xs font-bold tracking-wide text-slate-400 uppercase">{label}</dt>
            <dd className="text-[15px] font-semibold text-slate-700">{value}</dd>
        </div>
    );
}

function isSyntheticEmail(email?: string | null): boolean {
    return !!email && email.endsWith('@no-email.local');
}

/* ─── Page ──────────────────────────────────────────────────────────────────── */

export default function GuardianProfile() {
    const { guardian: guardianWrapper } = usePage<ShowPageProps>().props as unknown as ShowPageProps;
    const [guardian, setGuardian] = useState<Guardian>(guardianWrapper.data);
    const linkedStudents: (Student & { pivot: GuardianPivot })[] =
        (guardianWrapper.data.students as (Student & { pivot: GuardianPivot })[]) ?? [];

    const getInitials = useInitials();
    const isSoftDeleted = !!guardian.deleted_at;

    // Modals
    const [showEdit, setShowEdit] = useState(false);

    // Toasts
    const [toasts, setToasts] = useState<Toast[]>([]);
    let toastCounter = 0;

    const [activityRefreshKey, setActivityRefreshKey] = useState(0);

    const addToast = (message: string, type: ToastType = 'success') => {
        const id = ++toastCounter;
        setToasts((prev) => [...prev, { id, message, type }]);
    };

    const refreshGuardian = () => router.reload({ only: ['guardian'] });

    const handleSaved = () => {
        setShowEdit(false);
        addToast('Guardian updated successfully.');
        refreshGuardian();
    };

    const handleLoginUpdate = (updated: Guardian) => {
        setGuardian((prev) => ({ ...prev, ...updated }));
        setActivityRefreshKey((k) => k + 1);
        addToast('Login access updated.');
    };

    return (
        <>
            <Head title={`${guardian.full_name} — Guardian Profile`} />

            {/* Toast stack */}
            <div className="fixed bottom-4 right-4 z-50 flex flex-col gap-2">
                {toasts.map((t) => (
                    <ToastItem
                        key={t.id}
                        toast={t}
                        onDismiss={() => setToasts((prev) => prev.filter((x) => x.id !== t.id))}
                    />
                ))}
            </div>

            <div className="min-h-screen bg-[#f5f7fb] py-8 px-4 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-7xl space-y-8">

                    {/* ── Breadcrumbs ──────────────────────────────────────────── */}
                    <nav className="flex items-center gap-2 text-sm font-medium text-muted-foreground/60">
                        <Link href="/" className="transition-colors hover:text-primary">Home</Link>
                        <span className="select-none text-muted-foreground/30">/</span>
                        <Link href="/guardians" className="transition-colors hover:text-primary">Guardians</Link>
                        <span className="select-none text-muted-foreground/30">/</span>
                        <span className="text-foreground/80">{guardian.full_name}</span>
                    </nav>

                {/* ── Soft-deleted banner ───────────────────────────────── */}
                {isSoftDeleted && (
                    <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive">
                        This guardian was deleted on{' '}
                        {guardian.deleted_at ? new Date(guardian.deleted_at).toLocaleDateString() : '—'}.
                        Edit actions are disabled.
                    </div>
                )}

                    {/* ── Premium Hero Card ───────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-[2rem] border border-white bg-white p-8 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex flex-col items-center gap-6 text-center sm:flex-row sm:text-left">
                                <div className="relative">
                                    <div className="absolute -inset-1 rounded-full bg-gradient-to-tr from-indigo-500 to-violet-500 opacity-10 blur" />
                                    <Avatar className="relative size-24 rounded-full border-4 border-white shadow-sm ring-1 ring-black/5">
                                        <AvatarImage src={guardian.photo ?? undefined} alt={guardian.full_name} className="object-cover" />
                                        <AvatarFallback className="rounded-full bg-gradient-to-br from-indigo-50 to-violet-50 text-2xl font-bold text-indigo-600 dark:from-indigo-950/50 dark:to-violet-950/50">
                                            {getInitials(guardian.full_name)}
                                        </AvatarFallback>
                                    </Avatar>
                                </div>

                                <div className="space-y-3">
                                    <div className="flex flex-wrap items-center justify-center gap-3 sm:justify-start">
                                        <h1 className="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            {guardian.full_name}
                                        </h1>
                                        <div className="flex gap-2">
                                            {guardian.status && (
                                                <Badge className={`rounded-full px-3 py-0.5 text-[11px] font-semibold capitalize shadow-sm ${statusColor(guardian.status)}`}>
                                                    {guardian.status}
                                                </Badge>
                                            )}
                                            {guardian.has_login && (
                                                <Badge className="rounded-full bg-indigo-50 px-3 py-0.5 text-[11px] font-semibold text-indigo-600 shadow-sm hover:bg-indigo-50 dark:bg-indigo-500/10 dark:text-indigo-400">
                                                    Has Login Access
                                                </Badge>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm font-medium text-slate-500 sm:justify-start">
                                        {guardian.phone && (
                                            <span className="inline-flex items-center gap-2">
                                                <div className="flex size-6 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                                    <Phone className="h-3 w-3" />
                                                </div>
                                                {guardian.phone}
                                            </span>
                                        )}
                                        {guardian.email && (
                                            <span className="inline-flex items-center gap-2">
                                                <div className="flex size-6 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                                    <User2 className="h-3 w-3" />
                                                </div>
                                                {guardian.email}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Action buttons */}
                            {!isSoftDeleted && (
                                <div className="flex shrink-0 items-center justify-center gap-3">
                                    <Button
                                        onClick={() => setShowEdit(true)}
                                        className="rounded-xl bg-indigo-600 px-6 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                    >
                                        <Edit className="mr-2 h-4 w-4" />
                                        Edit Guardian
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="outline" className="rounded-xl border-slate-200 px-4 font-semibold text-slate-700 transition-all hover:bg-slate-50">
                                                More
                                                <ChevronDown className="ml-2 h-4 w-4 opacity-50" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="w-56 rounded-xl p-1 shadow-xl">
                                            <DropdownMenuItem onClick={() => setShowEdit(true)} className="rounded-lg py-2 cursor-pointer">
                                                <Edit className="mr-2 h-4 w-4 text-slate-500" />
                                                Edit Details
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                disabled={!guardian.email || isSyntheticEmail(guardian.email)}
                                                onClick={() => {
                                                    if (window.confirm(`Send a password reset link to ${guardian.email}?`)) {
                                                        axios.post(`/api/guardians/${guardian.id}/reset-password`)
                                                            .then(() => addToast('Password reset link sent.'))
                                                            .catch((err) => addToast(err.response?.data?.message ?? 'Failed to send reset link.', 'error'));
                                                    }
                                                }}
                                                className="rounded-lg py-2 cursor-pointer"
                                            >
                                                <RotateCcw className="mr-2 h-4 w-4 text-slate-500" />
                                                Reset Password
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* ── Two-column content area ──────────────────────────── */}
                    <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">

                        {/* Left column (2/3 width on lg) */}
                        <div className="space-y-8 lg:col-span-2">

                            {/* Personal & Contact Details */}
                            <Card className="overflow-hidden rounded-[1.5rem] border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                                <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-8 py-6">
                                    <CardTitle className="flex items-center gap-3 text-lg font-bold text-slate-800">
                                        <div className="flex size-9 items-center justify-center rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                                            <User2 className="h-5 w-5 text-indigo-600" />
                                        </div>
                                        Personal & Contact Details
                                    </CardTitle>
                                    {!isSoftDeleted && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setShowEdit(true)}
                                            className="rounded-lg text-slate-500 hover:bg-white hover:text-indigo-600 hover:shadow-sm"
                                        >
                                            <Edit className="h-4 w-4" />
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent className="p-8">
                                    <dl className="grid grid-cols-1 gap-x-12 gap-y-8 sm:grid-cols-2">
                                        <DetailRow label="First Name"  value={guardian.first_name} />
                                        <DetailRow label="Middle Name" value={guardian.middle_name} />
                                        <DetailRow label="Last Name"   value={guardian.last_name} />
                                        <DetailRow label="Gender"      value={guardian.gender} />
                                        <DetailRow label="Marital Status" value={guardian.marital_status} />
                                        <DetailRow label="Phone"       value={guardian.phone} />
                                        <DetailRow label="WhatsApp"    value={guardian.whatsapp_number} />
                                        <DetailRow label="Email"       value={guardian.email} />
                                    </dl>

                                    {(guardian.city || guardian.state || guardian.country || guardian.postal_code) && (
                                        <>
                                            <div className="mt-12 mb-6 flex items-center gap-3 text-sm font-bold tracking-wide text-slate-400 uppercase">
                                                <MapPin className="h-4 w-4" />
                                                Address Information
                                            </div>
                                            <dl className="grid grid-cols-1 gap-x-12 gap-y-8 sm:grid-cols-2">
                                                <DetailRow label="City"        value={guardian.city} />
                                                <DetailRow label="State"       value={guardian.state} />
                                                <DetailRow label="Country"     value={guardian.country} />
                                                <DetailRow label="Postal Code" value={guardian.postal_code} />
                                            </dl>
                                        </>
                                    )}

                                    {(guardian.occupation || guardian.employer_name || guardian.emergency_contact) && (
                                        <>
                                            <div className="mt-12 mb-6 flex items-center gap-3 text-sm font-bold tracking-wide text-slate-400 uppercase">
                                                <Briefcase className="h-4 w-4" />
                                                Work & Emergency
                                            </div>
                                            <dl className="grid grid-cols-1 gap-x-12 gap-y-8 sm:grid-cols-2">
                                                <DetailRow label="Occupation"       value={guardian.occupation} />
                                                <DetailRow label="Employer"         value={guardian.employer_name} />
                                                <DetailRow label="Emergency Contact" value={guardian.emergency_contact} />
                                            </dl>
                                        </>
                                    )}

                                    {(guardian.id_type || guardian.id_number || guardian.id_expiry_date) && (
                                        <>
                                            <div className="mt-12 mb-6 flex items-center gap-3 text-sm font-bold tracking-wide text-slate-400 uppercase">
                                                <CreditCard className="h-4 w-4" />
                                                Identity Documents
                                            </div>
                                            <dl className="grid grid-cols-1 gap-x-12 gap-y-8 sm:grid-cols-2">
                                                <DetailRow label="ID Type"        value={guardian.id_type} />
                                                <DetailRow label="ID Number"      value={guardian.id_number} />
                                                <DetailRow label="ID Expiry Date" value={guardian.id_expiry_date} />
                                            </dl>
                                        </>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Linked Students */}
                            <Card className="overflow-hidden rounded-[1.5rem] border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                                <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-8 py-6">
                                    <CardTitle className="flex items-center gap-3 text-lg font-bold text-slate-800">
                                        <div className="flex size-9 items-center justify-center rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                                            <Users className="h-5 w-5 text-indigo-600" />
                                        </div>
                                        Linked Children ({linkedStudents.length})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-5">
                                    {linkedStudents.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center py-12 text-center">
                                            <div className="mb-4 flex size-20 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                                                <Users className="h-10 w-10" />
                                            </div>
                                            <h3 className="text-lg font-semibold text-slate-900">No children linked yet</h3>
                                            <p className="mt-2 max-w-[280px] text-sm text-slate-500">
                                                You can link this guardian to a student from the student's profile page.
                                            </p>
                                            <Button variant="link" className="mt-4 font-semibold text-indigo-600">
                                                Link from a student profile
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="flex flex-col gap-4">
                                            {linkedStudents.map((s) => (
                                                <StudentCard key={s.id} student={s} />
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Right column (1/3 width on lg) */}
                        <div className="space-y-8">
                            <LoginAccessCard
                                guardian={guardian}
                                onUpdate={handleLoginUpdate}
                                onError={(msg) => addToast(msg, 'error')}
                            />

                            <ActivityLogCard guardianId={guardian.id} refreshKey={activityRefreshKey} />
                        </div>
                    </div>
                </div>
            </div>

            {/* Edit modal */}
            {showEdit && (
                <EditGuardianModal
                    isOpen={showEdit}
                    onClose={() => setShowEdit(false)}
                    guardian={guardian}
                    linkedStudents={linkedStudents}
                    onSaved={handleSaved}
                />
            )}
        </>
    );
}
