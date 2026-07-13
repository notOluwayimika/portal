import axios from 'axios';
import { AlertTriangle, ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type MissingSubject = {
    uuid: string | null;
    name: string | null;
};

type IncompleteRow = {
    student_curriculum_uuid: string;
    status: string;
    student: {
        uuid: string | null;
        name: string | null;
        admission_number: string | null;
    };
    curriculum: {
        uuid: string | null;
        name: string | null;
        term: string | null;
    };
    subjects_offered: number;
    missing_results: number;
    missing_subjects: MissingSubject[];
    reason: 'missing_results' | 'no_active_subjects';
};

type Pagination = {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
};

type ReasonFilter = 'all' | 'missing_results' | 'no_active_subjects';

const reasonFilters: { value: ReasonFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'missing_results', label: 'Missing results' },
    { value: 'no_active_subjects', label: 'No active subjects' },
];

function ReasonBadge({ reason }: { reason: IncompleteRow['reason'] }) {
    if (reason === 'no_active_subjects') {
        return (
            <span className="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">
                No active subjects
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
            Missing results
        </span>
    );
}

export default function IncompleteResults() {
    const [rows, setRows] = useState<IncompleteRow[]>([]);
    const [pagination, setPagination] = useState<Pagination | null>(null);
    const [page, setPage] = useState(1);
    const [reason, setReason] = useState<ReasonFilter>('all');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const fetchRows = useCallback(
        async (targetPage: number, targetReason: ReasonFilter) => {
            setLoading(true);
            setError(null);
            try {
                const response = await axios.get('/api/results/incomplete', {
                    params: {
                        page: targetPage,
                        per_page: 25,
                        ...(targetReason !== 'all' ? { reason: targetReason } : {}),
                    },
                });
                setRows(response.data.data);
                setPagination(response.data.pagination);
            } catch {
                setError('Failed to load incomplete results.');
            } finally {
                setLoading(false);
            }
        },
        [],
    );

    useEffect(() => {
        fetchRows(page, reason);
    }, [page, reason, fetchRows]);

    const changeReason = (value: ReasonFilter) => {
        setReason(value);
        setPage(1);
    };

    return (
        <div className="mx-auto max-w-7xl space-y-6 overflow-x-auto p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">
                        Incomplete Results
                    </h1>
                    <p className="mt-1 text-sm text-gray-600">
                        Enrollments in active curricula whose results are not
                        ready — one or more subjects still have no computed
                        result.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => fetchRows(page, reason)}
                    className="inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                >
                    <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                    Refresh
                </button>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5" role="tablist" aria-label="Filter by reason">
                    {reasonFilters.map((filter) => (
                        <button
                            key={filter.value}
                            type="button"
                            role="tab"
                            aria-selected={reason === filter.value}
                            onClick={() => changeReason(filter.value)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                reason === filter.value
                                    ? 'bg-white text-gray-900 shadow-sm'
                                    : 'text-gray-500 hover:text-gray-800'
                            }`}
                        >
                            {filter.label}
                        </button>
                    ))}
                </div>

                {pagination && (
                    <p className="text-sm text-gray-500">
                        <span className="font-semibold text-gray-900">
                            {pagination.total}
                        </span>{' '}
                        enrollment{pagination.total === 1 ? '' : 's'} with
                        incomplete results
                    </p>
                )}
            </div>

            {error && (
                <div className="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    {error}
                </div>
            )}

            <div className="overflow-x-auto rounded-lg border border-gray-200">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                        <tr>
                            <th className="px-4 py-3">Student</th>
                            <th className="px-4 py-3">Class</th>
                            <th className="px-4 py-3">Term</th>
                            <th className="px-4 py-3">Progress</th>
                            <th className="px-4 py-3">Missing Subjects</th>
                            <th className="px-4 py-3">Reason</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {loading && rows.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-gray-400">
                                    Loading…
                                </td>
                            </tr>
                        )}

                        {!loading && rows.length === 0 && !error && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-gray-500">
                                    {reason === 'all'
                                        ? 'All results are complete. Nothing to show.'
                                        : 'No enrollments match this filter.'}
                                </td>
                            </tr>
                        )}

                        {rows.map((row) => (
                            <tr key={row.student_curriculum_uuid} className="align-top">
                                <td className="px-4 py-3">
                                    <div className="font-medium text-gray-900">
                                        {row.student.name}
                                    </div>
                                    {row.student.admission_number && (
                                        <div className="text-xs text-gray-400">
                                            #{row.student.admission_number}
                                        </div>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-gray-700">
                                    {row.curriculum.name}
                                </td>
                                <td className="px-4 py-3 text-gray-500">
                                    {row.curriculum.term}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-gray-700">
                                    {row.subjects_offered - row.missing_results}/{row.subjects_offered}{' '}
                                    <span className="text-xs text-gray-400">graded</span>
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex max-w-md flex-wrap gap-1">
                                        {row.missing_subjects.map((subject) => (
                                            <span
                                                key={subject.uuid ?? subject.name ?? ''}
                                                className="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-gray-700"
                                            >
                                                {subject.name}
                                            </span>
                                        ))}
                                        {row.missing_subjects.length === 0 && (
                                            <span className="text-xs text-gray-400">—</span>
                                        )}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <ReasonBadge reason={row.reason} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between text-sm text-gray-600">
                    <span>
                        Page {pagination.current_page} of {pagination.last_page}
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={pagination.current_page <= 1 || loading}
                            onClick={() => setPage((p) => p - 1)}
                            className="inline-flex items-center gap-1 rounded-md border border-gray-200 px-3 py-1.5 font-medium transition hover:bg-gray-50 disabled:opacity-50"
                        >
                            <ChevronLeft className="h-4 w-4" />
                            Previous
                        </button>
                        <button
                            type="button"
                            disabled={pagination.current_page >= pagination.last_page || loading}
                            onClick={() => setPage((p) => p + 1)}
                            className="inline-flex items-center gap-1 rounded-md border border-gray-200 px-3 py-1.5 font-medium transition hover:bg-gray-50 disabled:opacity-50"
                        >
                            Next
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
