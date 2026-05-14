import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Briefcase,
    ChevronDown,
    CreditCard,
    Edit,
    MapPin,
    Phone,
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

interface ShowPageProps {
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
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-sm">{value}</dd>
        </div>
    );
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

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">

                {/* ── Back nav ──────────────────────────────────────────── */}
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Link href="/" className="hover:underline">Home</Link>
                    <span>/</span>
                    <span>Guardians</span>
                    <span>/</span>
                    <span className="text-foreground">{guardian.full_name}</span>
                </div>

                {/* ── Soft-deleted banner ───────────────────────────────── */}
                {isSoftDeleted && (
                    <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive">
                        This guardian was deleted on{' '}
                        {guardian.deleted_at ? new Date(guardian.deleted_at).toLocaleDateString() : '—'}.
                        Edit actions are disabled.
                    </div>
                )}

                {/* ── Header card ───────────────────────────────────────── */}
                <div className="rounded-xl border bg-card p-6 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-start gap-4">
                            <Avatar className="size-16 rounded-full border-2 border-muted">
                                <AvatarImage src={guardian.photo ?? undefined} alt={guardian.full_name} />
                                <AvatarFallback className="rounded-full bg-neutral-200 text-lg font-bold text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(guardian.full_name)}
                                </AvatarFallback>
                            </Avatar>

                            <div className="space-y-2">
                                <h1 className="text-xl font-bold tracking-tight">{guardian.full_name}</h1>
                                <div className="flex flex-wrap items-center gap-2">
                                    {guardian.status && (
                                        <Badge className={`capitalize ${statusColor(guardian.status)}`}>
                                            {guardian.status}
                                        </Badge>
                                    )}
                                    {guardian.has_login && (
                                        <Badge className="bg-blue-100 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/40 dark:text-blue-400">
                                            Has Login Access
                                        </Badge>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                    {guardian.phone && (
                                        <span className="inline-flex items-center gap-1">
                                            <Phone className="h-3 w-3" /> {guardian.phone}
                                        </span>
                                    )}
                                    {guardian.occupation && (
                                        <span className="inline-flex items-center gap-1">
                                            <Briefcase className="h-3 w-3" /> {guardian.occupation}
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Action buttons */}
                        {!isSoftDeleted && (
                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setShowEdit(true)}
                                >
                                    <Edit className="mr-2 h-3.5 w-3.5" />
                                    Edit Guardian
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm">
                                            More
                                            <ChevronDown className="ml-1 h-3.5 w-3.5" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem onClick={() => setShowEdit(true)}>
                                            <Edit className="mr-2 h-4 w-4" />
                                            Edit Details
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        )}
                    </div>
                </div>

                {/* ── Two-column content area ──────────────────────────── */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">

                    {/* Left column (2/3 width on lg) */}
                    <div className="space-y-6 lg:col-span-2">

                        {/* Personal & Contact Details */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <User2 className="h-4 w-4 text-muted-foreground" />
                                    Personal & Contact Details
                                </CardTitle>
                                {!isSoftDeleted && (
                                    <Button size="sm" variant="ghost" onClick={() => setShowEdit(true)}>
                                        <Edit className="h-3.5 w-3.5" />
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
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
                                        <div className="my-4 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                            <MapPin className="h-3.5 w-3.5" />
                                            Address
                                        </div>
                                        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                                            <DetailRow label="City"        value={guardian.city} />
                                            <DetailRow label="State"       value={guardian.state} />
                                            <DetailRow label="Country"     value={guardian.country} />
                                            <DetailRow label="Postal Code" value={guardian.postal_code} />
                                        </dl>
                                    </>
                                )}

                                {(guardian.occupation || guardian.employer_name || guardian.emergency_contact) && (
                                    <>
                                        <div className="my-4 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                            <Briefcase className="h-3.5 w-3.5" />
                                            Employment & Emergency
                                        </div>
                                        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                                            <DetailRow label="Occupation"       value={guardian.occupation} />
                                            <DetailRow label="Employer"         value={guardian.employer_name} />
                                            <DetailRow label="Emergency Contact" value={guardian.emergency_contact} />
                                        </dl>
                                    </>
                                )}

                                {(guardian.id_type || guardian.id_number || guardian.id_expiry_date) && (
                                    <>
                                        <div className="my-4 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                            <CreditCard className="h-3.5 w-3.5" />
                                            Identification
                                        </div>
                                        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                                            <DetailRow label="ID Type"        value={guardian.id_type} />
                                            <DetailRow label="ID Number"      value={guardian.id_number} />
                                            <DetailRow label="ID Expiry Date" value={guardian.id_expiry_date} />
                                        </dl>
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        {/* Linked Students */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Users className="h-4 w-4 text-muted-foreground" />
                                    Children in this School ({linkedStudents.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {linkedStudents.length === 0 ? (
                                    <p className="text-xs text-muted-foreground">
                                        This guardian has no children linked. Link them from a student profile.
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        {linkedStudents.map((s) => (
                                            <StudentCard key={s.id} student={s} />
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column (1/3 width on lg) */}
                    <div className="space-y-6">
                        <LoginAccessCard
                            guardian={guardian}
                            onUpdate={handleLoginUpdate}
                            onError={(msg) => addToast(msg, 'error')}
                        />

                        <ActivityLogCard guardianId={guardian.id} refreshKey={activityRefreshKey} />
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
