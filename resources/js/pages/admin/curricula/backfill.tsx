import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Pagination } from '@/components/pagination';
import type { Curriculum, Term } from '@/types/models';

// ---------------------------------------------------------------------------
// Confirm Backfill Dialog — single curriculum or all listed
// ---------------------------------------------------------------------------

interface ConfirmDialogProps {
    open: boolean;
    curriculum: Curriculum | null; // null while open => bulk (all listed)
    bulkCount: number;
    terms: Term[];
    onConfirm: (termId: string) => void;
    onCancel: () => void;
    loading: boolean;
}

function ConfirmDialog({
    open,
    curriculum,
    bulkCount,
    terms,
    onConfirm,
    onCancel,
    loading,
}: ConfirmDialogProps) {
    const [termId, setTermId] = useState('');

    useEffect(() => {
        if (open) {
            setTermId('');
        }
    }, [open]);

    if (!open) {
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
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100">
                        <svg
                            className="h-5 w-5 text-amber-600"
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
                            Backfill Past Term
                        </h3>
                        <p className="text-xs text-gray-500">
                            This queues a background job
                        </p>
                    </div>
                </div>
                <p className="mb-4 text-sm text-gray-600">
                    {curriculum ? (
                        <>
                            This will mirror{' '}
                            <span className="font-semibold text-gray-900">
                                {curriculum.full_name}
                            </span>{' '}
                        </>
                    ) : (
                        <>
                            This will mirror{' '}
                            <span className="font-semibold text-gray-900">
                                all {bulkCount} listed curricula
                            </span>{' '}
                        </>
                    )}
                    (subjects, marking components, teachers and students) into
                    the selected past term so scores and comments can be
                    entered retroactively. No scores are copied and the current
                    term is not affected.
                </p>
                <label className="mb-1 block text-xs font-medium text-gray-700">
                    Target term
                </label>
                <select
                    value={termId}
                    onChange={(e) => setTermId(e.target.value)}
                    className="mb-6 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:border-indigo-500 focus:outline-none"
                >
                    <option value="">Select a completed term…</option>
                    {terms.map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.full_name}
                        </option>
                    ))}
                </select>
                <div className="flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        disabled={loading}
                        className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={() => termId && onConfirm(termId)}
                        disabled={loading || !termId}
                        className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
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
                        Backfill
                    </button>
                </div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Component
// ---------------------------------------------------------------------------

export default function BackfillCurricula() {
    const [curricula, setCurricula] = useState<Curriculum[]>([]);
    const [loading, setLoading] = useState(true);
    const [limit, setLimit] = useState(10);
    const [page, setPage] = useState(1);
    const [paginationMeta, setPaginationMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    const [completedTerms, setCompletedTerms] = useState<Term[]>([]);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [confirmCurriculum, setConfirmCurriculum] =
        useState<Curriculum | null>(null);
    const [backfilling, setBackfilling] = useState(false);
    const [queuedIds, setQueuedIds] = useState<Set<string>>(new Set());

    useEffect(() => {
        const fetchQueuedIds = async () => {
            try {
                const response = await axios.get('/api/curricula/queued');
                setQueuedIds(new Set(response.data.curriculum_uuids));
            } catch (error) {
                console.error(error);
                toast.error('Failed to load queued curriculum IDs');
            }
        };

        const fetchTerms = async () => {
            try {
                const response = await axios.get('/api/class-structure');
                const terms: Term[] = response.data.terms ?? [];
                setCompletedTerms(
                    terms.filter((t) => t.status === 'completed'),
                );
            } catch (error) {
                console.error(error);
                toast.error('Failed to load terms');
            }
        };

        fetchQueuedIds();
        fetchTerms();
    }, []);

    useEffect(() => {
        const fetchCurricula = async () => {
            setLoading(true);

            try {
                const response = await axios.get('/api/curricula', {
                    params: { is_ccm: false, limit, page },
                });
                setCurricula(response.data.curricula ?? []);
                setPaginationMeta(response.data.pagination);
            } catch (error) {
                console.error(error);
                toast.error('Failed to load curricula');
            } finally {
                setLoading(false);
            }
        };

        fetchCurricula();
    }, [limit, page]);

    async function backfillOne(curriculum: Curriculum, termId: string) {
        await axios.post(`/api/curricula/${curriculum.id}/backfill-term`, {
            term_id: termId,
        });
        setQueuedIds((prev) => new Set(prev).add(curriculum.id));
    }

    async function handleBackfill(termId: string) {
        setBackfilling(true);

        const term = completedTerms.find((t) => t.id === termId);
        const termName = term?.full_name ?? 'the selected term';

        try {
            if (confirmCurriculum) {
                await backfillOne(confirmCurriculum, termId);
                toast.success(
                    `Backfill of "${confirmCurriculum.full_name}" into ${termName} has been queued`,
                );
            } else {
                const targets = curricula.filter(
                    (c) => c.status === 'active' && !queuedIds.has(c.id),
                );
                let queued = 0;
                for (const c of targets) {
                    try {
                        await backfillOne(c, termId);
                        queued++;
                    } catch (error) {
                        console.error(error);
                        toast.error(`Failed to queue "${c.full_name}"`);
                    }
                }
                toast.success(
                    `${queued} backfill job(s) into ${termName} queued`,
                );
            }
            setDialogOpen(false);
            setConfirmCurriculum(null);
        } catch (error) {
            console.error(error);
            toast.error('Failed to queue backfill');
        } finally {
            setBackfilling(false);
        }
    }

    const eligible = curricula.filter(
        (c) => c.status === 'active' && !queuedIds.has(c.id),
    );

    return (
        <div className="p-4">
            <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                {/* Card header */}
                <div className="flex items-start justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">
                            Backfill Past Terms
                        </h2>
                        <p className="mt-0.5 text-xs text-gray-500">
                            Mirror an active curriculum into a completed term
                            so teachers can enter scores and comments for past
                            terms. Created curricula are closed and never
                            affect the current term.
                        </p>
                    </div>
                    <button
                        onClick={() => {
                            setConfirmCurriculum(null);
                            setDialogOpen(true);
                        }}
                        disabled={
                            loading ||
                            eligible.length === 0 ||
                            completedTerms.length === 0
                        }
                        className="ml-4 shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Backfill all listed…
                    </button>
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-gray-100 text-xs text-gray-500">
                                <th className="px-6 py-3 font-medium">
                                    Curriculum
                                </th>
                                <th className="px-6 py-3 font-medium">Term</th>
                                <th className="px-6 py-3 font-medium">
                                    Exam Type
                                </th>
                                <th className="px-6 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-6 py-3 font-medium" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {loading ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-6 py-12 text-center text-sm text-gray-400"
                                    >
                                        Loading…
                                    </td>
                                </tr>
                            ) : curricula.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12">
                                        <div className="flex flex-col items-center justify-center text-center">
                                            <span className="mb-3 text-4xl">
                                                🗂️
                                            </span>
                                            <p className="text-sm font-medium text-gray-700">
                                                No active curricula found
                                            </p>
                                            <p className="mt-1 text-xs text-gray-400">
                                                Create curricula for the
                                                current term first
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                curricula.map((c) => {
                                    const queued = queuedIds.has(c.id);

                                    return (
                                        c.status === 'active' && (
                                            <tr key={c.id}>
                                                <td className="px-6 py-3 font-medium text-gray-900">
                                                    {c.full_name}
                                                </td>
                                                <td className="px-6 py-3 text-gray-600">
                                                    {c.term?.full_name ?? '—'}
                                                </td>
                                                <td className="px-6 py-3 text-gray-600">
                                                    {c.exam_type?.name ?? '—'}
                                                </td>
                                                <td className="px-6 py-3 text-gray-600 capitalize">
                                                    {c.status}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    <button
                                                        onClick={() => {
                                                            setConfirmCurriculum(
                                                                c,
                                                            );
                                                            setDialogOpen(
                                                                true,
                                                            );
                                                        }}
                                                        disabled={
                                                            queued ||
                                                            completedTerms.length ===
                                                                0
                                                        }
                                                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        {queued
                                                            ? 'Queued'
                                                            : 'Backfill past term…'}
                                                    </button>
                                                </td>
                                            </tr>
                                        )
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {!loading && curricula.length > 0 && (
                    <Pagination
                        meta={paginationMeta}
                        setPage={setPage}
                        setLimit={setLimit}
                    />
                )}
            </div>

            <ConfirmDialog
                open={dialogOpen}
                curriculum={confirmCurriculum}
                bulkCount={eligible.length}
                terms={completedTerms}
                onConfirm={handleBackfill}
                onCancel={() => {
                    setDialogOpen(false);
                    setConfirmCurriculum(null);
                }}
                loading={backfilling}
            />
        </div>
    );
}

BackfillCurricula.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'School Setup', href: '/setup' },
        { title: 'Backfill Past Terms', href: '/setup/curricula-backfill' },
    ],
};
