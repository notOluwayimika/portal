import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState, useMemo, useTransition, useEffect } from 'react';
import type { Teacher } from '@/types/models';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Props {
    /** All teachers for this school (backend should eager-load user + roles) */
    teachers: Teacher[];
    /** Inertia route name or URL for assigning the role */
    assignRoute?: string;
    /** Inertia route name or URL for unassigning the role */
    unassignRoute?: string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function Avatar({ teacher }: { teacher: Teacher }) {
    const initials =
        `${teacher.first_name[0]}${teacher.last_name[0]}`.toUpperCase();

    return teacher.photo ? (
        <img
            src={teacher.photo}
            alt={`${teacher.first_name} ${teacher.last_name}`}
            className="h-9 w-9 rounded-full object-cover ring-2 ring-white"
        />
    ) : (
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 ring-2 ring-white select-none">
            {initials}
        </span>
    );
}

function Badge({ status }: { status: Teacher['status'] }) {
    const map: Record<NonNullable<Teacher['status']>, string> = {
        active: 'bg-emerald-100 text-emerald-700',
        inactive: 'bg-gray-100 text-gray-500',
        resigned: 'bg-red-100 text-red-600',
    };

    if (!status) {
        return null;
    }

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${map[status]}`}
        >
            {status}
        </span>
    );
}

// ---------------------------------------------------------------------------
// Confirm Unassign Dialog (inline, lightweight)
// ---------------------------------------------------------------------------

interface ConfirmDialogProps {
    teacher: Teacher | null;
    onConfirm: () => void;
    onCancel: () => void;
    loading: boolean;
}

function ConfirmDialog({
    teacher,
    onConfirm,
    onCancel,
    loading,
}: ConfirmDialogProps) {
    if (!teacher) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
            onClick={onCancel}
        >
            <div
                className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-4 flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100">
                        <svg
                            className="h-5 w-5 text-red-600"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                            />
                        </svg>
                    </span>
                    <div>
                        <h3 className="text-sm font-semibold text-gray-900">
                            Remove Head of School
                        </h3>
                        <p className="text-xs text-gray-500">
                            This action can be reversed later
                        </p>
                    </div>
                </div>
                <p className="mb-6 text-sm text-gray-600">
                    Are you sure you want to remove{' '}
                    <span className="font-semibold text-gray-900">
                        {teacher.first_name} {teacher.last_name}
                    </span>{' '}
                    as Head of School?
                </p>
                <div className="flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        disabled={loading}
                        className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={onConfirm}
                        disabled={loading}
                        className="flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                    >
                        {loading && (
                            <svg
                                className="h-4 w-4 animate-spin"
                                viewBox="0 0 24 24"
                                fill="none"
                            >
                                <circle
                                    className="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    strokeWidth="4"
                                />
                                <path
                                    className="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                />
                            </svg>
                        )}
                        Remove Role
                    </button>
                </div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Assign Modal
// ---------------------------------------------------------------------------

interface AssignModalProps {
    eligible: Teacher[];
    onAssign: (teacher: Teacher) => void;
    onClose: () => void;
    assigningId: string | null;
}

function AssignModal({
    eligible,
    onAssign,
    onClose,
    assigningId,
}: AssignModalProps) {
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const q = query.toLowerCase().trim();

        if (!q) {
            return eligible;
        }

        return eligible.filter(
            (t) =>
                t.first_name.toLowerCase().includes(q) ||
                t.last_name.toLowerCase().includes(q) ||
                (t.staff_number ?? '').toLowerCase().includes(q) ||
                (t.qualification ?? '').toLowerCase().includes(q),
        );
    }, [eligible, query]);

    return (
        <div
            className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 backdrop-blur-sm"
            onClick={onClose}
        >
            <div
                className="flex w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl"
                style={{ maxHeight: '85vh' }}
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">
                            Assign Head of School
                        </h2>
                        <p className="mt-0.5 text-xs text-gray-500">
                            {eligible.length} eligible teacher
                            {eligible.length !== 1 ? 's' : ''}
                        </p>
                    </div>
                    <button
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                    >
                        <svg
                            className="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                {/* Search */}
                <div className="border-b border-gray-100 px-6 py-3">
                    <div className="relative">
                        <svg
                            className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M21 21l-4.35-4.35M17 11A6 6 0 105 11a6 6 0 0012 0z"
                            />
                        </svg>
                        <input
                            type="text"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by name, staff number, qualification…"
                            className="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pr-4 pl-9 text-sm text-gray-900 placeholder-gray-400 outline-none focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                        />
                    </div>
                </div>

                {/* Teacher list */}
                <div className="flex-1 overflow-y-auto px-6 py-2">
                    {eligible.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-12 text-center">
                            <span className="mb-3 text-4xl">🎓</span>
                            <p className="text-sm font-medium text-gray-700">
                                All teachers are already assigned
                            </p>
                            <p className="mt-1 text-xs text-gray-400">
                                No eligible teachers remaining
                            </p>
                        </div>
                    ) : filtered.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-12 text-center">
                            <span className="mb-3 text-4xl">🔍</span>
                            <p className="text-sm font-medium text-gray-700">
                                No results for "{query}"
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {filtered.map((teacher) => {
                                const isAssigning = assigningId === teacher.id;
                                const hasUser = !!teacher?.user?.id;

                                return (
                                    <li
                                        key={teacher.id}
                                        className="flex items-center gap-3 py-3"
                                    >
                                        <Avatar teacher={teacher} />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-gray-900">
                                                {teacher.first_name}{' '}
                                                {teacher.last_name}
                                            </p>
                                            <div className="mt-0.5 flex items-center gap-2">
                                                {teacher.staff_number && (
                                                    <span className="text-xs text-gray-400">
                                                        #{teacher.staff_number}
                                                    </span>
                                                )}
                                                {teacher.qualification && (
                                                    <span className="truncate text-xs text-gray-400">
                                                        {teacher.qualification}
                                                    </span>
                                                )}
                                                {!hasUser && (
                                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                                        No account
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge status={teacher.status} />
                                            <button
                                                onClick={() =>
                                                    onAssign(teacher)
                                                }
                                                disabled={
                                                    isAssigning || !hasUser
                                                }
                                                title={
                                                    !hasUser
                                                        ? 'Teacher has no linked user account'
                                                        : undefined
                                                }
                                                className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {isAssigning ? (
                                                    <>
                                                        <svg
                                                            className="h-3.5 w-3.5 animate-spin"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                        >
                                                            <circle
                                                                className="opacity-25"
                                                                cx="12"
                                                                cy="12"
                                                                r="10"
                                                                stroke="currentColor"
                                                                strokeWidth="4"
                                                            />
                                                            <path
                                                                className="opacity-75"
                                                                fill="currentColor"
                                                                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                                            />
                                                        </svg>
                                                        Assigning…
                                                    </>
                                                ) : (
                                                    'Assign'
                                                )}
                                            </button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {/* Footer */}
                <div className="border-t border-gray-100 px-6 py-3">
                    <button
                        onClick={onClose}
                        className="w-full rounded-lg border border-gray-200 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Component
// ---------------------------------------------------------------------------

export default function HeadOfSchoolManager({
    assignRoute = 'teachers.roles.assign',
    unassignRoute = 'teachers.roles.unassign',
}: Props) {
    const [teachers, setTeachers] = useState<Teacher[]>([]);
    const [isPending, startTransition] = useTransition();
    const [loading, setLoading] = useState(true);
    useEffect(() => {
        const fetchTeachers = async () => {
            const response = await axios.get('/api/heads-of-schools');
            const data = await response.data;
            setTeachers(data);
        };

        fetchTeachers();
    }, [loading]);

    // Split teachers into current heads and eligible candidates
    const heads = useMemo(
        () => teachers.filter((t) => t.user?.roles?.includes('head_of_school')),
        [teachers],
    );
    const eligible = useMemo(
        () =>
            teachers.filter((t) => !t.user?.roles?.includes('head_of_school')),
        [teachers],
    );

    // Modal visibility
    const [showModal, setShowModal] = useState(false);

    // Assign state
    const [assigningId, setAssigningId] = useState<string | null>(null);

    // Unassign confirm state
    const [confirmTeacher, setConfirmTeacher] = useState<Teacher | null>(null);
    const [unassigning, setUnassigning] = useState(false);

    // ── Assign ────────────────────────────────────────────────────────────────
    function handleAssign(teacher: Teacher) {
        setLoading(true);

        try {
            setAssigningId(teacher.id);
            startTransition(() => {
                router.post(
                    `/api/heads-of-schools`,
                    { role: 'head_of_school', teacher: teacher.id },
                    {
                        preserveScroll: true,
                        onFinish: () => setAssigningId(null),
                    },
                );
            });
        } catch (error) {
            console.log(error);
        } finally {
            setTimeout(() => {
                setLoading(false);
            }, 500);
        }
    }

    // ── Unassign ──────────────────────────────────────────────────────────────
    function handleUnassignConfirm() {
        if (!confirmTeacher) {
            return;
        }

        setLoading(true);

        try {
            setUnassigning(true);
            startTransition(() => {
                router.delete(`/api/heads-of-schools/${confirmTeacher.id}`, {
                    data: { role: 'head_of_school' },
                    preserveScroll: true,
                    onFinish: () => {
                        setUnassigning(false);
                        setConfirmTeacher(null);
                    },
                });
            });
        } catch (error) {
            console.log(error);
        } finally {
            setTimeout(() => {
                setLoading(false);
            }, 500);
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────
    return (
        <div className="p-4">
            <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                {/* Card header */}
                <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">
                            Head of School
                        </h2>
                        <p className="mt-0.5 text-xs text-gray-500">
                            {heads.length} currently assigned
                        </p>
                    </div>
                    <button
                        onClick={() => setShowModal(true)}
                        disabled={eligible.length === 0}
                        className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <svg
                            className="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M12 4v16m8-8H4"
                            />
                        </svg>
                        Assign
                    </button>
                </div>

                {/* Current heads list */}
                <div className="divide-y divide-gray-50 px-6">
                    {heads.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-12 text-center">
                            <span className="mb-3 text-5xl">🏫</span>
                            <p className="text-sm font-medium text-gray-700">
                                No Head of School assigned
                            </p>
                            <p className="mt-1 text-xs text-gray-400">
                                Click "Assign" to get started
                            </p>
                        </div>
                    ) : (
                        heads.map((teacher) => (
                            <div
                                key={teacher.id}
                                className="flex items-center gap-4 py-4"
                            >
                                <Avatar teacher={teacher} />

                                {/* Info */}
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <p className="text-sm font-semibold text-gray-900">
                                            {teacher.first_name}{' '}
                                            {teacher.last_name}
                                        </p>
                                        <Badge status={teacher.status} />
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                        {teacher.staff_number && (
                                            <span className="text-xs text-gray-400">
                                                #{teacher.staff_number}
                                            </span>
                                        )}
                                        {teacher.qualification && (
                                            <span className="text-xs text-gray-500">
                                                {teacher.qualification}
                                            </span>
                                        )}
                                        {teacher.phone && (
                                            <span className="text-xs text-gray-400">
                                                {teacher.phone}
                                            </span>
                                        )}
                                        {teacher.hire_date && (
                                            <span className="text-xs text-gray-400">
                                                Hired{' '}
                                                {new Date(
                                                    teacher.hire_date,
                                                ).toLocaleDateString('en-GB', {
                                                    year: 'numeric',
                                                    month: 'short',
                                                })}
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {/* Role badge */}
                                <span className="hidden items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 sm:inline-flex">
                                    <svg
                                        className="h-3.5 w-3.5"
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                    Head of School
                                </span>

                                {/* Unassign */}
                                <button
                                    onClick={() => setConfirmTeacher(teacher)}
                                    className="flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                >
                                    <svg
                                        className="h-3.5 w-3.5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"
                                        />
                                    </svg>
                                    Remove
                                </button>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Assign Modal */}
            {showModal && (
                <AssignModal
                    eligible={eligible}
                    onAssign={(teacher) => {
                        handleAssign(teacher);
                        // Optimistically close modal if only assigning one at a time is desired:
                        // setShowModal(false);
                    }}
                    onClose={() => setShowModal(false)}
                    assigningId={assigningId}
                />
            )}

            {/* Confirm Unassign Dialog */}
            <ConfirmDialog
                teacher={confirmTeacher}
                onConfirm={handleUnassignConfirm}
                onCancel={() => setConfirmTeacher(null)}
                loading={unassigning}
            />
        </div>
    );
}
