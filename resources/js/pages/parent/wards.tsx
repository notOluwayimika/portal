import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    User,
    Calendar,
    Hash,
    GraduationCap,
    FileText,
    ChevronRight,
    Star,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'react-toastify';
import { Button } from '@/components/ui/button';
import type { StudentCurriculum } from '@/types/models';
import { NoticesCard, QuickContactCard } from './dashboard';

// ---------- Types ----------
// Mirrors the columns on the `students` table + the pivot fields
// from `guardian_student` that are useful in the guardian's view.
export type Gender = 'male' | 'female' | 'other';

export interface Ward {
    id: string; // students.id (uuid)
    admission_number: string | null;
    first_name: string;
    last_name: string;
    middle_name?: string | null;
    gender?: Gender | null;
    date_of_birth?: string | null; // ISO date
    photo?: string | null;

    // Pivot data (from guardian_student)
    relationship: string;
    is_primary: boolean;

    // Optional convenience fields you may eagerly load on the backend
    current_class?: StudentCurriculum | null;
    school?: { id: number | string; name: string } | null;
}

// ---------- Helpers ----------
const fullName = (w: Ward) =>
    [w.first_name, w.middle_name, w.last_name].filter(Boolean).join(' ');

const initials = (w: Ward) =>
    `${w.first_name?.[0] ?? ''}${w.last_name?.[0] ?? ''}`.toUpperCase();

const ageFromDob = (dob?: string | null) => {
    if (!dob) {
        return null;
    }

    const d = new Date(dob);

    if (Number.isNaN(d.getTime())) {
        return null;
    }

    const diff = Date.now() - d.getTime();

    return Math.floor(diff / (1000 * 60 * 60 * 24 * 365.25));
};

const formatDob = (dob?: string | null) => {
    if (!dob) {
        return '—';
    }

    const d = new Date(dob);

    if (Number.isNaN(d.getTime())) {
        return '—';
    }

    return d.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

// ---------- Component ----------
export default function Wards() {
    const [wards, setWards] = useState<Ward[]>([]);
    const { auth } = usePage().props;
    const [activeResultAvailable, setActiveResultAvailable] = useState(true);
    const [latestAvailableResult, setLatestAvailableResult] =
        useState<StudentCurriculum | null>(null);
    const CONTACTS = [
        {
            office: 'Admin',
            phone: '08078855452',
            email: 'admin@brookstoneng.org',
        },
        {
            office: 'Accounts',
            phone: '08078856210',
            email: 'accounts@brookstoneng.org',
        },
        {
            office: 'Principal',
            phone: '08102791331',
            email: 'principal@brookstoneng.org',
        },
        {
            office: 'IFY PHC',
            phone: '07057555058',
            email: 'headfoundation@brookstoneng.org',
        },
        {
            office: 'IFY Abuja',
            phone: '08070653533',
            email: 'centremanagerabuja@brookstoneify.org',
        },
    ];

    const guardianId = auth.user?.guardian?.uuid;
    useEffect(() => {
        // Simulate an API call to fetch wards
        const fetchWards = async () => {
            // Replace this with your actual API call
            const response = await axios.get(
                `/api/guardians/${guardianId}/students`,
            );
            const data = await response.data;
            setWards(data.data);
        };

        fetchWards();
    }, []);

    // Pick the primary ward first, otherwise the first in the list.
    const initialId = useMemo(() => {
        if (!wards.length) {
            return null;
        }

        const primary = wards.find((w) => w.is_primary);

        return (primary ?? wards[0]).id;
    }, [wards]);

    const [activeId, setActiveId] = useState<string | null>(initialId);
    useEffect(() => {
        const checkResultReadiness = async () => {
            const response = await axios.get(
                `/api/students/${activeId}/result-status`,
            );
            setActiveResultAvailable(response.data.available);
            setLatestAvailableResult(response.data.latest_available_result);
        };

        if (activeId) {
            checkResultReadiness();
        }
    }, [activeId]);
    const active = wards.find((w) => w.id === activeId) ?? null;
    const notices = [
        {
            type: 'general',
            title: 'Prize Giving Day/Graduation Ceremony',
            description: `We wish to inform parents that our Prize Giving/Graduation ceremonies are as follows:
- Year 7, 8, & 9 (Prize Giving)  -  Thursday, June 25, 2026 (11:00a.m.)
- Year 10, 11, 12 and IFY (Prize Giving/Graduation)  -  Saturday, June 27, 2026 (11:00a.m.)
Parents are invited to these events and are expected to pick-up their child at the end of the events respectively. `,
            time: 'Today',
            sender: 'Admin',
            badge_colour: 'gray',
        },
        {
            type: 'general',
            title: 'Graduation Check-In',
            description: `Students in Year 11, 12 and IFY are expected to check in on Friday, June 26, 2026, (1:00pm to 3:30pm) for Dinner/Prom and Graduation Ceremony. We kindly appeal to parents/guardians to ensure their child complies with the school rules and regulations regarding the graduation and thoroughly check their luggage to ensure they are not with unauthorized items.`,
            time: 'Today',
            sender: 'Admin',
            badge_colour: 'gray',
        },
        {
            type: 'general',
            title: 'Express Check-In Reminder',
            description:
                'For express check-in, parents are advised to pay all outstanding fees and send the evidence of payment to accounts@brookstoneng.org. ',
            time: '1 week ago',
            sender: 'Admin',
            badge_colour: 'gray',
        },
    ];

    if (!wards.length) {
        return (
            <div className="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
                <GraduationCap className="mx-auto h-10 w-10 text-gray-400" />
                <h3 className="mt-3 text-lg font-semibold text-gray-900">
                    No wards linked yet
                </h3>
                <p className="mt-1 text-sm text-gray-500">
                    Once the school links a student to your account, they will
                    appear here.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-6 p-10">
            {/* Ward switcher */}
            <section>
                <div className="mb-3 flex items-end justify-between">
                    <div>
                        <h2 className="text-xl font-semibold text-gray-900">
                            My Wards
                        </h2>
                        <p className="text-sm text-gray-500">
                            Switch between your wards to see their results.
                        </p>
                    </div>
                    <span className="text-sm text-gray-500">
                        {wards.length} {wards.length === 1 ? 'ward' : 'wards'}
                    </span>
                </div>

                <div className="flex gap-3 overflow-x-auto pb-2">
                    {wards.map((w) => {
                        const isActive = w.id === activeId;

                        return (
                            <button
                                key={w.id}
                                type="button"
                                onClick={() => setActiveId(w.id)}
                                className={[
                                    'group flex min-w-[220px] items-center gap-3 rounded-xl border px-4 py-3 text-left transition',
                                    isActive
                                        ? 'border-indigo-600 bg-indigo-50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50',
                                ].join(' ')}
                            >
                                <Avatar ward={w} size={40} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1">
                                        <p className="truncate text-sm font-semibold text-gray-900">
                                            {w.first_name} {w.last_name}
                                        </p>
                                        {w.is_primary && (
                                            <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />
                                        )}
                                    </div>
                                    <p className="truncate text-xs text-gray-500 capitalize">
                                        {w.relationship}
                                        {w.admission_number
                                            ? ` · ${w.admission_number}`
                                            : ''}
                                    </p>
                                </div>
                                <ChevronRight
                                    className={[
                                        'h-4 w-4 transition',
                                        isActive
                                            ? 'text-indigo-600'
                                            : 'text-gray-400',
                                    ].join(' ')}
                                />
                            </button>
                        );
                    })}
                </div>
            </section>

            {/* Active ward details */}
            {active && (
                <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    {/* Header */}
                    <div className="flex flex-col gap-4 border-b border-gray-100 bg-gradient-to-r from-indigo-50 to-white p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-4">
                            <Avatar ward={active} size={64} />
                            <div>
                                <div className="flex items-center gap-2">
                                    <h3 className="text-lg font-semibold text-gray-900">
                                        {fullName(active)}
                                    </h3>
                                    {active.is_primary && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                            <Star className="h-3 w-3 fill-amber-500 text-amber-500" />
                                            Primary
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-gray-500 capitalize">
                                    {active.relationship}
                                    {active.current_class
                                        ? ` · ${active.current_class.curriculum.class_level_arm?.name}`
                                        : ''}
                                </p>
                            </div>
                        </div>

                        {/* CTAs */}
                        <div className="flex flex-col gap-2 sm:flex-row">
                            {activeResultAvailable ? (
                                <Link
                                    href={`/students/${active.id}/results/active`}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none"
                                >
                                    <FileText className="h-4 w-4" />
                                    View Current Result
                                </Link>
                            ) : latestAvailableResult ? (
                                <Link
                                    href={`/students/${active.id}/results/${latestAvailableResult.id}`}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none"
                                >
                                    <FileText className="h-4 w-4" />
                                    View Latest Available Result
                                </Link>
                            ) : (
                                <Button
                                    onClick={() =>
                                        toast.info(
                                            'No active results available or result incomplete',
                                        )
                                    }
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none"
                                >
                                    <FileText className="h-4 w-4" />
                                    View Current Result
                                </Button>
                            )}

                            <Link
                                href={`/setup/student-curricula/${active.id}`}
                                className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none"
                            >
                                <GraduationCap className="h-4 w-4" />
                                View Academic Records
                            </Link>
                        </div>
                    </div>

                    {/* Info grid */}
                    <div className="grid grid-cols-1 gap-px bg-gray-100 sm:grid-cols-2 lg:grid-cols-3">
                        <InfoCell
                            icon={<Hash className="h-4 w-4" />}
                            label="Admission Number"
                            value={active.admission_number ?? '—'}
                        />
                        <InfoCell
                            icon={<User className="h-4 w-4" />}
                            label="Gender"
                            value={
                                active.gender
                                    ? active.gender.charAt(0).toUpperCase() +
                                      active.gender.slice(1)
                                    : '—'
                            }
                        />
                        <InfoCell
                            icon={<Calendar className="h-4 w-4" />}
                            label="Date of Birth"
                            value={
                                ageFromDob(active.date_of_birth)
                                    ? `${formatDob(active.date_of_birth)} (${ageFromDob(active.date_of_birth)} yrs)`
                                    : formatDob(active.date_of_birth)
                            }
                        />
                        <InfoCell
                            icon={<GraduationCap className="h-4 w-4" />}
                            label="Class"
                            value={
                                active.current_class?.curriculum.class_level_arm
                                    ?.name || '—'
                            }
                        />
                        <InfoCell
                            icon={<User className="h-4 w-4" />}
                            label="Relationship"
                            value={
                                active.relationship.charAt(0).toUpperCase() +
                                active.relationship.slice(1)
                            }
                        />
                        <InfoCell
                            icon={<GraduationCap className="h-4 w-4" />}
                            label="School"
                            value={active.school?.name ?? '—'}
                        />
                    </div>
                </section>
            )}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <NoticesCard
                    notices={notices}
                    onAction={() => toast.info('Feature coming soon!')}
                />
                <QuickContactCard
                    contacts={CONTACTS}
                    onAction={() => toast.info('Feature coming soon!')}
                />
            </div>
        </div>
    );
}

// ---------- Subcomponents ----------
function Avatar({ ward, size = 40 }: { ward: Ward; size?: number }) {
    const style = { width: size, height: size };

    if (ward.photo) {
        return (
            <img
                src={ward.photo}
                alt={fullName(ward)}
                style={style}
                className="rounded-full object-cover ring-2 ring-white"
            />
        );
    }

    return (
        <div
            style={style}
            className="flex shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white ring-2 ring-white"
        >
            {initials(ward)}
        </div>
    );
}

function InfoCell({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="bg-white p-5">
            <div className="flex items-center gap-2 text-xs font-medium tracking-wide text-gray-500 uppercase">
                {icon}
                {label}
            </div>
            <p className="mt-1 text-sm font-medium text-gray-900">{value}</p>
        </div>
    );
}
