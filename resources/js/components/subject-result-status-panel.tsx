import axios from 'axios';
import { useState } from 'react';
import type { User } from '@/types';

// ---------- Types ----------

export type ResultStatus = 'draft' | 'submitted' | 'approved' | 'rejected';

export interface SubjectResultStatusData {
    status: ResultStatus | null;
    rejection_reason: string | null;
    updated_by: User | null;
    updated_at: string | null;
}

export type ReviewerRole = 'teacher' | 'admin' | 'head_of_school' | string;

interface Props {
    curriculumSubjectId: string;
    status: SubjectResultStatusData;
    userRole: ReviewerRole;
    onChanged?: (next: SubjectResultStatusData) => void;
}

// ---------- Helpers ----------

const STATUS_STYLES: Record<ResultStatus | 'none', string> = {
    none: 'bg-gray-100 text-gray-700 ring-gray-200',
    draft: 'bg-gray-100 text-gray-800 ring-gray-200',
    submitted: 'bg-amber-50 text-amber-800 ring-amber-200',
    approved: 'bg-green-50 text-green-800 ring-green-200',
    rejected: 'bg-red-50 text-red-800 ring-red-200',
};

const STATUS_LABEL: Record<ResultStatus | 'none', string> = {
    none: 'Not started',
    draft: 'Draft',
    submitted: 'Submitted',
    approved: 'Approved',
    rejected: 'Rejected',
};

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

const isReviewer = (role: ReviewerRole) =>
    role === 'admin' || role === 'head_of_school';

// ---------- Component ----------

export default function SubjectResultStatusPanel({
    curriculumSubjectId,
    status,
    userRole,
    onChanged,
}: Props) {
    const [current, setCurrent] = useState<SubjectResultStatusData>(status);
    const [busy, setBusy] = useState<null | 'submit' | 'approve' | 'reject'>(
        null,
    );
    const [error, setError] = useState<string | null>(null);
    const [rejectOpen, setRejectOpen] = useState(false);

    const statusKey: ResultStatus | 'none' = current.status ?? 'none';

    const post = async (
        endpoint: string,
        kind: 'submit' | 'approve' | 'reject',
        body: Record<string, unknown> = {},
    ) => {
        setBusy(kind);
        setError(null);

        try {
            const { data } = await axios.post(endpoint, body);
            const next: SubjectResultStatusData = data.status ?? data;
            setCurrent(next);
            onChanged?.(next);

            return true;
        } catch (e: unknown) {
            const err = e as {
                response?: {
                    data?: {
                        message?: string;
                        errors?: Record<string, string[]>;
                    };
                };
            };
            setError(
                err?.response?.data?.errors?.rejection_reason?.[0] ??
                    err?.response?.data?.message ??
                    'Action failed.',
            );

            return false;
        } finally {
            setBusy(null);
        }
    };

    const handleSubmit = () => {
        post(
            `/api/curriculum-subjects/${curriculumSubjectId}/submit`,
            'submit',
        );
    };

    const handleApprove = () => {
        post(
            `/api/curriculum-subjects/${curriculumSubjectId}/approve`,
            'approve',
        );
    };

    const handleReject = async (reason: string) => {
        const ok = await post(
            `/api/curriculum-subjects/${curriculumSubjectId}/reject`,
            'reject',
            { rejection_reason: reason },
        );

        if (ok) {
            setRejectOpen(false);
        }
    };

    return (
        <div className="rounded-lg border bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0 space-y-2">
                    <div className="flex items-center gap-3">
                        <h2 className="text-sm font-medium text-gray-500">
                            Result status
                        </h2>
                        <span
                            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_STYLES[statusKey]}`}
                        >
                            {STATUS_LABEL[statusKey]}
                        </span>
                    </div>

                    {current.updated_by && (
                        <p className="text-sm text-gray-600">
                            Last updated by{' '}
                            <span className="font-medium text-gray-900">
                                {current.updated_by.full_name}
                            </span>
                            {/* {current.updated_by.role && (
                                <span className="text-gray-500">
                                    {' '}
                                    (
                                    {current.updated_by.role.replace(/_/g, ' ')}
                                    )
                                </span>
                            )} */}
                            {current.updated_at && (
                                <span className="text-gray-500">
                                    {' '}
                                    · {formatDateTime(current.updated_at)}
                                </span>
                            )}
                        </p>
                    )}

                    {current.status === 'rejected' &&
                        current.rejection_reason && (
                            <div className="mt-2 rounded-md border border-red-200 bg-red-50 p-3">
                                <p className="text-xs font-semibold tracking-wide text-red-700 uppercase">
                                    Rejection reason
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-red-800">
                                    {current.rejection_reason}
                                </p>
                            </div>
                        )}

                    {error && (
                        <p className="text-sm text-red-600" role="alert">
                            {error}
                        </p>
                    )}
                </div>

                {/* Actions */}
                <div className="flex shrink-0 items-center gap-2">
                    {userRole === 'teacher' && (
                        <button
                            type="button"
                            onClick={handleSubmit}
                            disabled={
                                busy !== null ||
                                current.status === 'approved' ||
                                current.status === 'submitted'
                            }
                            className="inline-flex items-center rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                        >
                            {busy === 'submit'
                                ? 'Submitting…'
                                : 'Submit for review'}
                        </button>
                    )}

                    {isReviewer(userRole) && (
                        <>
                            <button
                                type="button"
                                onClick={handleApprove}
                                disabled={
                                    busy !== null ||
                                    current.status === 'approved' ||
                                    current.status === null ||
                                    current.status === 'draft'
                                }
                                className="inline-flex items-center rounded-md bg-green-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-500 disabled:cursor-not-allowed disabled:bg-green-300"
                            >
                                {busy === 'approve' ? 'Approving…' : 'Accept'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setRejectOpen(true)}
                                disabled={
                                    busy !== null ||
                                    current.status === 'rejected' ||
                                    current.status === null ||
                                    current.status === 'draft'
                                }
                                className="inline-flex items-center rounded-md border border-red-300 bg-white px-3.5 py-2 text-sm font-medium text-red-700 shadow-sm hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Reject
                            </button>
                        </>
                    )}
                </div>
            </div>

            <RejectModal
                open={rejectOpen}
                busy={busy === 'reject'}
                onClose={() => setRejectOpen(false)}
                onConfirm={handleReject}
            />
        </div>
    );
}

// ---------- Reject Modal ----------

function RejectModal({
    open,
    busy,
    onClose,
    onConfirm,
}: {
    open: boolean;
    busy: boolean;
    onClose: () => void;
    onConfirm: (reason: string) => void;
}) {
    const [reason, setReason] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);

    if (!open) {
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

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reject-modal-title"
        >
            <div className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h3
                    id="reject-modal-title"
                    className="text-base font-semibold text-gray-900"
                >
                    Reject submission
                </h3>
                <p className="mt-1 text-sm text-gray-600">
                    Let the teacher know what needs to change before they can
                    resubmit.
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
                        onClick={() => {
                            setReason('');
                            setLocalError(null);
                            onClose();
                        }}
                        disabled={busy}
                        className="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
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
