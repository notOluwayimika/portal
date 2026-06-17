import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Pagination } from '@/components/pagination';
import type { Curriculum } from '@/types/models';

// ---------------------------------------------------------------------------
// Confirm Migrate Dialog
// ---------------------------------------------------------------------------

interface ConfirmDialogProps {
    curriculum: Curriculum | null;
    onConfirm: () => void;
    onCancel: () => void;
    loading: boolean;
}

function ConfirmDialog({
    curriculum,
    onConfirm,
    onCancel,
    loading,
}: ConfirmDialogProps) {
    if (!curriculum) {
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
                            Migrate to Non-CCM
                        </h3>
                        <p className="text-xs text-gray-500">
                            This queues a background job
                        </p>
                    </div>
                </div>
                <p className="mb-6 text-sm text-gray-600">
                    This will create (or reuse) a non-CCM curriculum for{' '}
                    <span className="font-semibold text-gray-900">
                        {curriculum.full_name}
                    </span>{' '}
                    and migrate its subjects, marking components, teachers and
                    students into it. The original CCM curriculum is left
                    untouched.
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
                        className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
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
                        Migrate
                    </button>
                </div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Component
// ---------------------------------------------------------------------------

export default function CcmCurricula() {
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

    const [confirmCurriculum, setConfirmCurriculum] =
        useState<Curriculum | null>(null);
    const [migrating, setMigrating] = useState(false);
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

        fetchQueuedIds();
    }, []);

    useEffect(() => {
        const fetchCurricula = async () => {
            setLoading(true);

            try {
                const response = await axios.get('/api/curricula', {
                    params: { is_ccm: true, limit, page },
                });
                setCurricula(response.data.curricula ?? []);
                setPaginationMeta(response.data.pagination);
            } catch (error) {
                console.error(error);
                toast.error('Failed to load CCM curricula');
            } finally {
                setLoading(false);
            }
        };

        fetchCurricula();
    }, [limit, page]);

    async function handleMigrate() {
        if (!confirmCurriculum) {
            return;
        }

        setMigrating(true);

        try {
            await axios.post(
                `/api/curricula/${confirmCurriculum.id}/move-from-ccm`,
            );
            setQueuedIds((prev) => new Set(prev).add(confirmCurriculum.id));
            toast.success(
                `Migration for "${confirmCurriculum.full_name}" has been queued`
            );
            setConfirmCurriculum(null);
        } catch (error) {
            console.error(error);
            toast.error('Failed to queue migration');
        } finally {
            setMigrating(false);
        }
    }

    return (
        <div className="p-4">
            <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                {/* Card header */}
                <div className="border-b border-gray-100 px-6 py-4">
                    <h2 className="text-base font-semibold text-gray-900">
                        CCM Curricula
                    </h2>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Curricula running in Continuous Comprehensive Mode.
                        Migrate a curriculum to convert it (and its subjects,
                        students and teachers) to a non-CCM equivalent.
                    </p>
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
                                    Min Subjects
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
                                        colSpan={6}
                                        className="px-6 py-12 text-center text-sm text-gray-400"
                                    >
                                        Loading…
                                    </td>
                                </tr>
                            ) : curricula.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12">
                                        <div className="flex flex-col items-center justify-center text-center">
                                            <span className="mb-3 text-4xl">
                                                🗂️
                                            </span>
                                            <p className="text-sm font-medium text-gray-700">
                                                No CCM curricula found
                                            </p>
                                            <p className="mt-1 text-xs text-gray-400">
                                                All curricula are running in
                                                standard mode
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
                                                <td className="px-6 py-3 text-gray-600">
                                                    {c.min_subjects}
                                                </td>
                                                <td className="px-6 py-3 text-gray-600 capitalize">
                                                    {c.status}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    <button
                                                        onClick={() =>
                                                            setConfirmCurriculum(
                                                                c,
                                                            )
                                                        }
                                                        disabled={queued}
                                                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        {queued
                                                            ? 'Queued'
                                                            : 'Migrate to Non-CCM'}
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
                curriculum={confirmCurriculum}
                onConfirm={handleMigrate}
                onCancel={() => setConfirmCurriculum(null)}
                loading={migrating}
            />

        </div>
    );
}

CcmCurricula.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'School Setup', href: '/setup' },
        { title: 'CCM Curricula', href: '/setup/curricula-ccm' },
    ],
};
