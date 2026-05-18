import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import type { SubjectResultStatus } from '@/types/models';

// ---------- Types ----------

type RowStatus = 'idle' | 'approving' | 'rejecting';

interface RowState {
    status: RowStatus;
    error?: string | null;
}

// ---------- Helpers ----------

const formatDateTime = (iso: string | null) => {
    if (!iso) {
        return null;
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
};

// ---------- Page ----------

export default function PendingReviewsPage({
    subjectResults,
}: {
    subjectResults: SubjectResultStatus[];
}) {
    const [items, setItems] = useState<SubjectResultStatus[]>(subjectResults);
    const [rowState, setRowState] = useState<Record<string, RowState>>({});
    const [query, setQuery] = useState('');
    const [rejectTarget, setRejectTarget] =
        useState<SubjectResultStatus | null>(null);

    const setRow = (id: string, patch: Partial<RowState>) =>
        setRowState((prev) => ({
            ...prev,
            [id]: { ...(prev[id] ?? { status: 'idle' }), ...patch },
        }));

    const removeItem = (id: string) =>
        setItems((prev) => prev.filter((i) => i.id !== id));

    const approve = async (item: SubjectResultStatus) => {
        setRow(item.id, { status: 'approving', error: null });

        try {
            await axios.post(
                `/api/curriculum-subjects/${item.curriculum_subject.id}/approve`,
            );
            removeItem(item.id);
        } catch (e: unknown) {
            const err = e as { response?: { data?: { message?: string } } };
            setRow(item.id, {
                status: 'idle',
                error: err?.response?.data?.message ?? 'Approve failed.',
            });
        }
    };

    const reject = async (item: SubjectResultStatus, reason: string) => {
        setRow(item.id, { status: 'rejecting', error: null });

        try {
            await axios.post(
                `/api/curriculum-subjects/${item.curriculum_subject.id}/reject`,
                { rejection_reason: reason },
            );
            removeItem(item.id);
            setRejectTarget(null);
        } catch (e: unknown) {
            const err = e as {
                response?: {
                    data?: {
                        message?: string;
                        errors?: Record<string, string[]>;
                    };
                };
            };
            setRow(item.id, {
                status: 'idle',
                error:
                    err?.response?.data?.errors?.rejection_reason?.[0] ??
                    err?.response?.data?.message ??
                    'Reject failed.',
            });
        }
    };

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return items;
        }

        return items.filter((i) => {
            const haystack = [
                i.curriculum_subject?.subject.name,
                i.curriculum_subject?.curriculum?.class_level_arm?.name,
                i.curriculum_subject?.curriculum?.term?.name,
                i.curriculum_subject?.curriculum?.exam_type?.name,
                i.curriculum_subject?.curriculum?.academic_session?.name,
                ...(i.curriculum_subject?.teachers?.map(
                    (t) => t.teacher?.full_name,
                ) ?? []),
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return haystack.includes(q);
        });
    }, [items, query]);

    return (
        <>
            <Head title="Pending reviews" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900 dark:text-white">
                        Pending reviews
                    </h1>
                    <p className="mt-1 text-sm text-gray-600 dark:text-slate-400">
                        Submitted results awaiting approval.{' '}
                        <span className="font-medium text-gray-900 dark:text-white">
                            {items.length}
                        </span>{' '}
                        item{items.length === 1 ? '' : 's'} in queue.
                    </p>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <input
                        type="search"
                        placeholder="Search subject, class, teacher…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="w-80 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-1 focus:ring-primary focus:outline-none"
                    />
                </div>

                <div className="overflow-x-auto rounded-lg border bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <table className="min-w-full border-collapse text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-800">
                            <tr>
                                <Th>Subject</Th>
                                <Th>Class</Th>
                                <Th>Term</Th>
                                <Th>Exam</Th>
                                <Th>Session</Th>
                                <Th>Teacher(s)</Th>
                                <Th>Submitted</Th>
                                <Th className="text-right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                            {filtered.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-10 text-center text-gray-500"
                                    >
                                        {items.length === 0
                                            ? 'No submissions waiting for review.'
                                            : 'No items match your search.'}
                                    </td>
                                </tr>
                            )}

                            {filtered.map((item) => {
                                const state = rowState[item.id] ?? {
                                    status: 'idle' as RowStatus,
                                };
                                const busy = state.status !== 'idle';

                                return (
                                    <tr
                                        key={item.id}
                                        className="hover:bg-gray-50 dark:hover:bg-slate-800/50"
                                    >
                                        <Td>
                                            <div className="font-medium text-gray-900 dark:text-white">
                                                {item.curriculum_subject
                                                    ?.subject?.name ?? '—'}
                                            </div>
                                        </Td>
                                        <Td>
                                            {item.curriculum_subject?.curriculum
                                                ?.class_level_arm?.name ?? '—'}
                                        </Td>
                                        <Td>
                                            {item.curriculum_subject?.curriculum
                                                ?.term?.name != null
                                                ? `Term ${item.curriculum_subject?.curriculum?.term?.name}`
                                                : '—'}
                                        </Td>
                                        <Td>
                                            {item.curriculum_subject?.curriculum
                                                ?.exam_type?.name ?? '—'}
                                        </Td>
                                        <Td>
                                            {item.curriculum_subject?.curriculum
                                                ?.academic_session?.name ?? '—'}
                                        </Td>
                                        <Td>
                                            {item.curriculum_subject?.teachers
                                                .length === 0 ? (
                                                <span className="text-gray-400">
                                                    —
                                                </span>
                                            ) : (
                                                <span>
                                                    {item.curriculum_subject?.teachers
                                                        .map(
                                                            (t) =>
                                                                t.teacher
                                                                    .full_name,
                                                        )
                                                        .join(', ')}
                                                </span>
                                            )}
                                        </Td>
                                        <Td>
                                            <span className="text-gray-700">
                                                {formatDateTime(
                                                    item.updated_at,
                                                ) ?? '—'}
                                            </span>
                                        </Td>
                                        <Td className="text-right">
                                            <div className="inline-flex items-center gap-2">
                                                <a
                                                    target="_blank"
                                                    href={`/setup/curriculum-subject/${item.curriculum_subject?.id}`}
                                                    className="text-sm font-medium text-primary hover:text-primary"
                                                >
                                                    View
                                                </a>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        approve(item)
                                                    }
                                                    disabled={busy}
                                                    className="inline-flex items-center rounded-md bg-green-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-green-500 disabled:bg-green-300"
                                                >
                                                    {state.status ===
                                                    'approving'
                                                        ? 'Approving…'
                                                        : 'Accept'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setRejectTarget(item)
                                                    }
                                                    disabled={busy}
                                                    className="inline-flex items-center rounded-md border border-red-300 bg-white px-2.5 py-1.5 text-xs font-medium text-red-700 shadow-sm hover:bg-red-50 disabled:opacity-50"
                                                >
                                                    Reject
                                                </button>
                                            </div>
                                            {state.error && (
                                                <p className="mt-1 text-xs text-red-600">
                                                    {state.error}
                                                </p>
                                            )}
                                        </Td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            <RejectModal
                item={rejectTarget}
                busy={
                    rejectTarget
                        ? (rowState[rejectTarget.id]?.status ?? 'idle') ===
                          'rejecting'
                        : false
                }
                onClose={() => setRejectTarget(null)}
                onConfirm={(reason) =>
                    rejectTarget && reject(rejectTarget, reason)
                }
            />
        </>
    );
}

// ---------- Sub-components ----------

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

function RejectModal({
    item,
    busy,
    onClose,
    onConfirm,
}: {
    item: SubjectResultStatus | null;
    busy: boolean;
    onClose: () => void;
    onConfirm: (reason: string) => void;
}) {
    const [reason, setReason] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);

    if (!item) {
        return null;
    }

    const handleConfirm = () => {
        const trimmed = reason.trim();

        if (trimmed.length < 3) {
            setLocalError('Please provide a reason (at least 3 characters).');

            return;
        }

        setLocalError(null);
        onConfirm(trimmed);
    };

    const handleClose = () => {
        if (busy) {
            return;
        }

        setReason('');
        setLocalError(null);
        onClose();
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reject-modal-title"
        >
            <div className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl dark:bg-slate-900 dark:border dark:border-slate-700">
                <h3
                    id="reject-modal-title"
                    className="text-base font-semibold text-gray-900 dark:text-white"
                >
                    Reject submission
                </h3>
                <p className="mt-1 text-sm text-gray-600 dark:text-slate-400">
                    {item.curriculum_subject.subject.name}
                    {item.curriculum_subject.curriculum?.class_level_arm?.name
                        ? ` · ${item.curriculum_subject.curriculum.class_level_arm.name}`
                        : ''}
                    {item.curriculum_subject.curriculum?.term?.name != null
                        ? ` · Term ${item.curriculum_subject.curriculum.term.name}`
                        : ''}
                </p>

                <label
                    htmlFor="rejection_reason"
                    className="mt-4 block text-sm font-medium text-gray-700"
                >
                    Rejection reason
                </label>
                <textarea
                    id="rejection_reason"
                    rows={4}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    autoFocus
                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 focus:outline-none"
                    placeholder="e.g. Several scores look inconsistent with the marking scheme."
                />
                {localError && (
                    <p className="mt-1 text-sm text-red-600">{localError}</p>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={handleClose}
                        disabled={busy}
                        className="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleConfirm}
                        disabled={busy}
                        className="rounded-md bg-red-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-500 disabled:bg-red-300"
                    >
                        {busy ? 'Rejecting…' : 'Confirm rejection'}
                    </button>
                </div>
            </div>
        </div>
    );
}
