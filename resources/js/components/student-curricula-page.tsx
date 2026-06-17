import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import type { SetStateAction } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { handleBack } from '@/helpers';
import type { Curriculum, Student, StudentCurriculum } from '@/types/models';
import { toast } from 'react-toastify';
import { Button } from './ui/button';
import { FileText } from 'lucide-react';

// ---------- Types ----------

type StudentCurriculumStatus = 'active' | 'promoted' | 'repeated' | 'withdrawn';

type FilterValue = 'all' | StudentCurriculumStatus;

const STATUS_OPTIONS: { value: StudentCurriculumStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'promoted', label: 'Promoted' },
    { value: 'repeated', label: 'Repeated' },
    { value: 'withdrawn', label: 'Withdrawn' },
];

const STATUS_BADGE: Record<StudentCurriculumStatus, string> = {
    active: 'bg-green-50 text-green-800 ring-green-200',
    promoted: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    repeated: 'bg-amber-50 text-amber-800 ring-amber-200',
    withdrawn: 'bg-gray-100 text-gray-700 ring-gray-200',
};

// ---------- Helpers ----------

const formatCurriculum = (c: Curriculum | null) => {
    if (!c) {
        return '—';
    }

    return [
        c.academic_session?.name,
        c.class_level_arm?.name,
        `Term ${c.term?.name}`,
        c.is_ccm ? 'CCM' : 'End Of Term',
    ]
        .filter(Boolean)
        .join(' · ');
};

const fullName = (s: Student) =>
    [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');

function CurriculumRow({
    sc,
    handleStatusChange,
    busy,
    student,
    roles,
    setPromoteTarget,
    eligible,
    err,
}: {
    sc: StudentCurriculum;
    handleStatusChange: (
        sc: StudentCurriculum,
        status: StudentCurriculumStatus,
    ) => Promise<void>;
    busy: boolean;
    student: Student;
    roles: string[];
    setPromoteTarget: (value: SetStateAction<StudentCurriculum | null>) => void;
    eligible: Curriculum[];
    err: string | null;
}) {
    const [activeResultAvailable, setActiveResultAvailable] = useState(true);
    useEffect(() => {
        const checkResultReadiness = async () => {
            const response = await axios.get(
                `/api/students/${student.id}/curriculum/${sc.curriculum.id}/result-status`,
            );
            setActiveResultAvailable(response.data.available);
        };

        if (student.id) {
            checkResultReadiness();
        }
    }, [student, sc]);

    return (
        <tr key={sc.id} className="hover:bg-gray-50">
            <Td>
                <div className="font-medium text-gray-900">
                    {formatCurriculum(sc.curriculum)}
                </div>
            </Td>
            <Td>
                <div className="flex flex-col gap-1.5">
                    <span
                        className={`inline-flex w-fit items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[sc.status as StudentCurriculumStatus]}`}
                    >
                        {sc.status}
                    </span>
                    {!roles.includes('guardian') && (
                        <select
                            value={sc.status}
                            onChange={(e) =>
                                handleStatusChange(
                                    sc,
                                    e.target.value as StudentCurriculumStatus,
                                )
                            }
                            disabled={busy}
                            className="block w-40 rounded-md border border-gray-300 px-2 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none disabled:opacity-50"
                        >
                            {STATUS_OPTIONS.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
            </Td>
            {/* <Td>
                                            {sc.promoted_to ? (
                                                <span className="text-gray-700">
                                                    {formatCurriculum(
                                                        sc.promoted_to,
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="text-gray-400">
                                                    —
                                                </span>
                                            )}
                                        </Td> */}
            <Td className="text-right">
                <div className="flex gap-4">
                    {roles.includes('guardian') && !activeResultAvailable ? (
                        <button
                            onClick={() =>
                                toast.info(
                                    'No active results available or result incomplete',
                                )
                            }
                            className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-center text-xs font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                        >
                            View Result
                        </button>
                    ) : (
                        <Link
                            href={`/students/${student.id}/results/${sc.id}`}
                            className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-center text-xs font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                        >
                            View Result
                        </Link>
                    )}
                    {(roles.includes('admin') ||
                        roles.includes('head_of_school')) && (
                        <button
                            type="button"
                            onClick={() => setPromoteTarget(sc)}
                            disabled={
                                busy ||
                                sc.status !== 'active' ||
                                eligible.length === 0
                            }
                            title={
                                sc.status !== 'active'
                                    ? 'Only active enrollments can be promoted'
                                    : eligible.length === 0
                                      ? 'No eligible curricula available'
                                      : undefined
                            }
                            className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                        >
                            Promote
                        </button>
                    )}
                    {sc.status === 'active' &&
                        (roles.includes('admin') ||
                            roles.includes('head_of_school')) && (
                            <Link
                                disabled={
                                    busy ||
                                    sc.status !== 'active' ||
                                    eligible.length === 0
                                }
                                href={`/setup/student-curricula/${sc.id}/subjects`}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-center text-xs font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                            >
                                Manage Subjects
                            </Link>
                        )}
                </div>

                {err && <p className="mt-1 text-xs text-red-600">{err}</p>}
            </Td>
        </tr>
    );
}
// ---------- Page ----------

export default function StudentCurriculaPage({
    student,
}: {
    student: Student;
}) {
    const [items, setItems] = useState<StudentCurriculum[]>(
        student.student_curricula,
    );
    const { auth } = usePage().props;
    const roles = auth.roles;
    const [eligible, setEligible] = useState<Curriculum[]>([]);
    const [filter, setFilter] = useState<FilterValue>('all');
    const [rowBusy, setRowBusy] = useState<Record<string, boolean>>({});
    const [rowError, setRowError] = useState<Record<string, string | null>>({});
    const [promoteTarget, setPromoteTarget] =
        useState<StudentCurriculum | null>(null);
    const [registerOpen, setRegisterOpen] = useState(false);
    const [registerBusy, setRegisterBusy] = useState(false);
    const [registerError, setRegisterError] = useState<string | null>(null);

    const filtered = useMemo(() => {
        if (filter === 'all') {
            return items;
        }

        return items.filter((i) => i.status === filter);
    }, [items, filter]);

    useEffect(() => {
        async function getCurricula() {
            const response = await axios.get('/api/curricula/active');
            setEligible(response.data.data);
        }
        getCurricula();
    }, []);

    const counts = useMemo(() => {
        const base: Record<FilterValue, number> = {
            all: items.length,
            active: 0,
            promoted: 0,
            repeated: 0,
            withdrawn: 0,
        };

        for (const i of items) {
            base[i.status as StudentCurriculumStatus]++;
        }

        return base;
    }, [items]);

    const upsertItem = (next: StudentCurriculum, remove: boolean = false) =>
        setItems((prev) => {
            if (remove) {
                return prev.filter((i) => i.id !== next.id);
            }

            const idx = prev.findIndex((i) => i.id === next.id);

            if (idx === -1) {
                return [next, ...prev];
            }

            const copy = [...prev];
            copy[idx] = next;

            return copy;
        });

    const handleStatusChange = async (
        sc: StudentCurriculum,
        status: StudentCurriculumStatus,
    ) => {
        if (status === sc.status) {
            return;
        }

        setRowBusy((prev) => ({ ...prev, [sc.id]: true }));
        setRowError((prev) => ({ ...prev, [sc.id]: null }));

        try {
            const { data } = await axios.patch(
                `/api/student-curricula/${sc.id}`,
                { status },
            );
            upsertItem(data as StudentCurriculum, data.status === 'withdrawn');
        } catch (e: unknown) {
            const err = e as { response?: { data?: { message?: string } } };
            setRowError((prev) => ({
                ...prev,
                [sc.id]: err?.response?.data?.message ?? 'Update failed.',
            }));
        } finally {
            setRowBusy((prev) => ({ ...prev, [sc.id]: false }));
        }
    };

    const handleRegisterConfirm = async (curriculumId: string) => {
        setRegisterBusy(true);
        setRegisterError(null);

        try {
            const { data } = await axios.post(
                `/api/students/${student.id}/curricula`,
                { curriculum_id: curriculumId },
            );
            const next = data.student_curriculum as StudentCurriculum;
            setItems((prev) => [next, ...prev.filter((i) => i.id !== next.id)]);
            setEligible((prev) => prev.filter((c) => c.id !== curriculumId));
            setRegisterOpen(false);
        } catch (e: unknown) {
            const err = e as { response?: { data?: { message?: string } } };
            setRegisterError(
                err?.response?.data?.message ?? 'Registration failed.',
            );
        } finally {
            setRegisterBusy(false);
        }
    };

    const handlePromoteConfirm = async (toCurriculumId: string) => {
        if (!promoteTarget) {
            return;
        }

        setRowBusy((prev) => ({ ...prev, [promoteTarget.id]: true }));
        setRowError((prev) => ({ ...prev, [promoteTarget.id]: null }));

        try {
            const { data } = await axios.post(
                `/api/students/${student.id}/curricula/promote`,
                {
                    from_student_curriculum_id: promoteTarget.id,
                    to_curriculum_id: toCurriculumId,
                },
            );
            const from = data.from as StudentCurriculum;
            const next = data.new as StudentCurriculum;
            upsertItem(from);
            setItems((prev) => [next, ...prev.filter((i) => i.id !== next.id)]);
            // Remove the target curriculum from eligible list.
            setEligible((prev) => prev.filter((c) => c.id !== toCurriculumId));
            setPromoteTarget(null);
        } catch (e: unknown) {
            const err = e as { response?: { data?: { message?: string } } };
            setRowError((prev) => ({
                ...prev,
                [promoteTarget.id]:
                    err?.response?.data?.message ?? 'Promote failed.',
            }));
        } finally {
            setRowBusy((prev) => ({ ...prev, [promoteTarget.id]: false }));
        }
    };

    return (
        <>
            <Head title={`Curricula – ${fullName(student)}`} />
            <div className="flex">
                <button
                    className="btn btn-ghost btn-sm btn-icon cursor-pointer p-4"
                    onClick={handleBack}
                    title="Back to curricula"
                    style={{ fontSize: 14 }}
                >
                    ← Go back
                </button>
            </div>

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                {/* Header */}
                <div className="rounded-lg border bg-white p-5 shadow-sm">
                    <div className="flex items-center justify-between">
                        <div>
                            {' '}
                            <h1 className="text-xl font-semibold text-gray-900">
                                {fullName(student)}
                            </h1>
                            {student.admission_number && (
                                <p className="mt-1 text-sm text-gray-500">
                                    {student.admission_number}
                                </p>
                            )}
                        </div>
                        <div>
                            <Link
                                href={`/students/${student.id}/results/active`}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-center text-xs font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                            >
                                View active result
                            </Link>
                        </div>
                    </div>
                </div>

                {/* Filter pills + register */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        <FilterPill
                            label={`All (${counts.all})`}
                            active={filter === 'all'}
                            onClick={() => setFilter('all')}
                        />
                        {STATUS_OPTIONS.map((o) => (
                            <FilterPill
                                key={o.value}
                                label={`${o.label} (${counts[o.value]})`}
                                active={filter === o.value}
                                onClick={() => setFilter(o.value)}
                            />
                        ))}
                    </div>

                    {(roles.includes('admin') ||
                        roles.includes('head_of_school')) && (
                        <button
                            type="button"
                            onClick={() => {
                                setRegisterError(null);
                                setRegisterOpen(true);
                            }}
                            disabled={eligible.length === 0}
                            title={
                                eligible.length === 0
                                    ? 'No eligible curricula available'
                                    : undefined
                            }
                            className="inline-flex items-center rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-500 disabled:cursor-not-allowed disabled:bg-emerald-300"
                        >
                            Register in a curriculum
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-lg border bg-white shadow-sm">
                    <table className="min-w-full border-collapse text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <Th>Curriculum</Th>
                                <Th>Status</Th>
                                {/* <Th>Promoted to</Th> */}
                                <Th className="text-right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filtered.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-10 text-center text-gray-500"
                                    >
                                        No enrollments match this filter.
                                    </td>
                                </tr>
                            )}

                            {filtered.map((sc) => {
                                const busy = !!rowBusy[sc.id];
                                const err = rowError[sc.id];

                                return (
                                    <CurriculumRow
                                        busy={busy}
                                        err={err}
                                        eligible={eligible}
                                        handleStatusChange={handleStatusChange}
                                        roles={roles}
                                        sc={sc}
                                        setPromoteTarget={setPromoteTarget}
                                        student={student}
                                        key={sc.id}
                                    />
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            <PromoteModal
                source={promoteTarget}
                eligible={eligible}
                busy={promoteTarget ? !!rowBusy[promoteTarget.id] : false}
                onClose={() => setPromoteTarget(null)}
                onConfirm={handlePromoteConfirm}
            />

            <RegisterModal
                open={registerOpen}
                eligible={eligible}
                busy={registerBusy}
                error={registerError}
                onClose={() => setRegisterOpen(false)}
                onConfirm={handleRegisterConfirm}
            />
        </>
    );
}

// ---------- Sub-components ----------

function FilterPill({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3 py-1.5 text-sm font-medium transition ${
                active
                    ? 'border-indigo-600 bg-indigo-600 text-white'
                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
            }`}
        >
            {label}
        </button>
    );
}

function Th({
    children,
    className = '',
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <th
            className={`px-4 py-3 text-left font-medium text-gray-700 ${className}`}
        >
            {children}
        </th>
    );
}

function Td({
    children,
    className = '',
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <td className={`px-4 py-3 align-top text-gray-700 ${className}`}>
            {children}
        </td>
    );
}

function PromoteModal({
    source,
    eligible,
    busy,
    onClose,
    onConfirm,
}: {
    source: StudentCurriculum | null;
    eligible: Curriculum[];
    busy: boolean;
    onClose: () => void;
    onConfirm: (toCurriculumId: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<string | null>(null);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return eligible;
        }

        return eligible.filter((c) =>
            [
                c.academic_session?.name,
                c.class_level_arm?.name,
                c.exam_type?.name,
                `term ${c.term?.name}`,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(q),
        );
    }, [eligible, query]);

    if (!source) {
        return null;
    }

    const handleClose = () => {
        if (busy) {
            return;
        }

        setQuery('');
        setSelected(null);
        onClose();
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="promote-modal-title"
        >
            <div className="flex w-full max-w-lg flex-col rounded-lg bg-white shadow-xl">
                <div className="border-b p-5">
                    <h3
                        id="promote-modal-title"
                        className="text-base font-semibold text-gray-900"
                    >
                        Promote student
                    </h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Promoting from{' '}
                        <span className="font-medium text-gray-900">
                            {formatCurriculum(source.curriculum)}
                        </span>
                        .
                    </p>
                </div>

                <div className="p-5">
                    <label
                        htmlFor="promote-search"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Choose target curriculum
                    </label>
                    <input
                        id="promote-search"
                        type="search"
                        placeholder="Search session, class, term, exam type…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        autoFocus
                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />

                    <div className="mt-3 max-h-64 overflow-y-auto rounded-md border">
                        {filtered.length === 0 ? (
                            <div className="px-3 py-8 text-center text-sm text-gray-500">
                                {eligible.length === 0
                                    ? 'No eligible curricula available.'
                                    : 'No matches for that search.'}
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {filtered.map((c) => {
                                    const isSelected = selected === c.id;

                                    return (
                                        <li key={c.id}>
                                            <label
                                                className={`flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50 ${
                                                    isSelected
                                                        ? 'bg-indigo-50'
                                                        : ''
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="target_curriculum"
                                                    checked={isSelected}
                                                    onChange={() =>
                                                        setSelected(c.id)
                                                    }
                                                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                                />
                                                <span className="flex-1 text-gray-800">
                                                    {formatCurriculum(c)}
                                                </span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>

                <div className="flex justify-end gap-2 border-t bg-gray-50 px-5 py-3">
                    <button
                        type="button"
                        onClick={handleClose}
                        disabled={busy}
                        className="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={() => selected && onConfirm(selected)}
                        disabled={busy || !selected}
                        className="rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                    >
                        {busy ? 'Promoting…' : 'Confirm promotion'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function RegisterModal({
    open,
    eligible,
    busy,
    error,
    onClose,
    onConfirm,
}: {
    open: boolean;
    eligible: Curriculum[];
    busy: boolean;
    error: string | null;
    onClose: () => void;
    onConfirm: (curriculumId: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<string | null>(null);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return eligible;
        }

        return eligible.filter((c) =>
            [
                c.academic_session?.name,
                c.class_level_arm?.class_level?.name,
                c.exam_type?.name,
                `term ${c.term?.name}`,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(q),
        );
    }, [eligible, query]);

    if (!open) {
        return null;
    }

    const handleClose = () => {
        if (busy) {
            return;
        }

        setQuery('');
        setSelected(null);
        onClose();
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="register-modal-title"
        >
            <div className="flex w-full max-w-lg flex-col rounded-lg bg-white shadow-xl">
                <div className="border-b p-5">
                    <h3
                        id="register-modal-title"
                        className="text-base font-semibold text-gray-900"
                    >
                        Register in a curriculum
                    </h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Pick a curriculum to enroll this student in. A new
                        active enrollment will be created.
                    </p>
                </div>

                <div className="p-5">
                    <label
                        htmlFor="register-search"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Choose curriculum
                    </label>
                    <input
                        id="register-search"
                        type="search"
                        placeholder="Search session, class, term, exam type…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        autoFocus
                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none"
                    />

                    <div className="mt-3 max-h-64 overflow-y-auto rounded-md border">
                        {filtered.length === 0 ? (
                            <div className="px-3 py-8 text-center text-sm text-gray-500">
                                {eligible.length === 0
                                    ? 'No eligible curricula available.'
                                    : 'No matches for that search.'}
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {filtered.map((c) => {
                                    const isSelected = selected === c.id;

                                    return (
                                        <li key={c.id}>
                                            <label
                                                className={`flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50 ${
                                                    isSelected
                                                        ? 'bg-emerald-50'
                                                        : ''
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="register_curriculum"
                                                    checked={isSelected}
                                                    onChange={() =>
                                                        setSelected(c.id)
                                                    }
                                                    className="h-4 w-4 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                <span className="flex-1 text-gray-800">
                                                    {formatCurriculum(c)}
                                                </span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>

                    {error && (
                        <p className="mt-2 text-sm text-red-600" role="alert">
                            {error}
                        </p>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t bg-gray-50 px-5 py-3">
                    <button
                        type="button"
                        onClick={handleClose}
                        disabled={busy}
                        className="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={() => selected && onConfirm(selected)}
                        disabled={busy || !selected}
                        className="rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-500 disabled:cursor-not-allowed disabled:bg-emerald-300"
                    >
                        {busy ? 'Registering…' : 'Register'}
                    </button>
                </div>
            </div>
        </div>
    );
}
