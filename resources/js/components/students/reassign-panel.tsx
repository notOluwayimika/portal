import { router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import type { StudentCurriculum } from '@/types/models';

/**
 * Move one pupil into a sibling arm — 8B -> 8S — after the migration jobs have placed them.
 *
 * ── THE PICKER IS THE PRIMARY DEFENCE, THE 422 IS THE BACKSTOP ────────────────────────────────────
 * Same shape as the arm-map panel: the destination list comes from the server's own sibling query,
 * so the only classes an operator can pick are the ones the rule will accept. The field error below
 * exists for anything that bypasses this UI, and for the two cases the list cannot express — a stale
 * page whose episode has since been vacated, and a class closed between load and submit.
 *
 * ── THE WHOLE LIST IS RE-FETCHED AFTER A MOVE, NOT PATCHED ────────────────────────────────────────
 * A reassignment changes THREE rows at once: this episode becomes Reassigned, the destination
 * episode becomes active (possibly revived from an earlier stint, so it may not be on the page at
 * all), and a promotion link elsewhere may have been cleared. Hand-patching that from one response
 * is how a screen drifts out of step with the database, so the page reloads its own props instead.
 */

type Destination = { id: string; label: string };

type Options = {
    episode: {
        id: string;
        status: string | null;
        status_label: string | null;
        curriculum: string;
    };
    destinations: Destination[];
};

export function ReassignPanel({
    episode,
    onClose,
}: {
    episode: StudentCurriculum;
    onClose: () => void;
}) {
    const [options, setOptions] = useState<Options | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [selected, setSelected] = useState<string>('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const load = async () => {
            try {
                const response = await axios.get(
                    `/api/student-curricula/${episode.id}/reassignment-options`,
                );
                setOptions(response.data);
            } catch {
                toast.error(
                    'Failed to load the classes this pupil can move to',
                );
            } finally {
                setLoading(false);
            }
        };

        load();
    }, [episode.id]);

    const submit = useCallback(async () => {
        if (!selected) {
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await axios.post(
                `/api/student-curricula/${episode.id}/reassign`,
                {
                    destination_curriculum_id: selected,
                    reason: reason.trim() || null,
                },
            );

            // The server's own sentence — "Reassigned from Year 8 B to Year 8 S". Not rebuilt here,
            // so the toast, the audit row and the API response cannot word it three ways.
            toast.success(response.data.audit_line);
            onClose();
            router.reload({ only: ['student'] });
        } catch (e) {
            if (axios.isAxiosError(e) && e.response?.status === 422) {
                setError(
                    e.response.data?.errors?.destination_curriculum_id?.[0] ??
                        'That class cannot be used.',
                );

                return;
            }

            toast.error('Failed to reassign the pupil');
        } finally {
            setSaving(false);
        }
    }, [episode.id, selected, reason, onClose]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reassign-modal-title"
        >
            <div className="flex w-full max-w-lg flex-col rounded-lg bg-white shadow-xl">
                <div className="border-b p-5">
                    <h3
                        id="reassign-modal-title"
                        className="text-base font-semibold text-gray-900"
                    >
                        Reassign to another arm
                    </h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Moving out of{' '}
                        <span className="font-medium text-gray-900">
                            {options?.episode.curriculum ?? '…'}
                        </span>
                        . The pupil stays in the same year group and term.
                    </p>
                </div>

                <div className="p-5">
                    {loading ? (
                        <p className="py-6 text-center text-sm text-gray-500">
                            Loading…
                        </p>
                    ) : options && options.destinations.length === 0 ? (
                        // A one-arm year group is a normal configuration, not a fault — say which
                        // it is rather than showing an empty picker the operator cannot act on.
                        <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-800">
                            There is no other arm in this year group and term to
                            move this pupil into.
                        </p>
                    ) : (
                        <>
                            <span className="block text-sm font-medium text-gray-700">
                                Move into
                            </span>

                            <div className="mt-2 overflow-hidden rounded-md border">
                                <ul className="divide-y divide-gray-100">
                                    {options?.destinations.map(
                                        (destination) => (
                                            <li key={destination.id}>
                                                <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50">
                                                    <input
                                                        type="radio"
                                                        name="reassign-destination"
                                                        value={destination.id}
                                                        checked={
                                                            selected ===
                                                            destination.id
                                                        }
                                                        onChange={() =>
                                                            setSelected(
                                                                destination.id,
                                                            )
                                                        }
                                                    />
                                                    <span className="font-medium text-gray-900">
                                                        {destination.label}
                                                    </span>
                                                </label>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>

                            <label
                                htmlFor="reassign-reason"
                                className="mt-4 block text-sm font-medium text-gray-700"
                            >
                                Reason{' '}
                                <span className="font-normal text-gray-500">
                                    (optional)
                                </span>
                            </label>
                            <textarea
                                id="reassign-reason"
                                rows={2}
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                maxLength={500}
                                placeholder="e.g. placed in the wrong arm at rollover"
                                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                            />

                            {error && (
                                <p className="mt-2 text-sm text-red-600">
                                    {error}
                                </p>
                            )}

                            <p className="mt-3 text-xs text-gray-500">
                                Marks already entered stay with the class the
                                pupil is leaving — they do not follow.
                            </p>
                        </>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t p-4">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={saving}
                        className="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={saving || !selected}
                        className="rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
                    >
                        {saving ? 'Reassigning…' : 'Reassign'}
                    </button>
                </div>
            </div>
        </div>
    );
}
