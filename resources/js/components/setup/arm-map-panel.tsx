import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Can } from '@/components/can';
import { Confirm, Modal } from '@/components/setup/setup-ui';
import type { ClassLevel } from '@/types/models';

/**
 * The explicit arm progression map for one class level: which of its arms feeds which arm of the
 * level its pupils move into.
 *
 * ── THE PICKER IS THE PRIMARY DEFENCE ─────────────────────────────────────────────────────────────
 * A target outside the progression level is accepted by every foreign key — both arms exist, both
 * are in the same school — and then REFUSED by MoveToNextYearJob at rollover, which leaves the pupil
 * unplaced with only a log line. So the surest fix is not to offer the choice: the target select
 * lists ONLY the arms of `next_class_level_id`, straight from the server. The 422 rule behind it is
 * the backstop for anything that bypasses the UI.
 *
 * ── STALE ROWS ARE SHOWN, NOT SILENTLY DROPPED ────────────────────────────────────────────────────
 * Change the level's progression target and every existing mapping points somewhere these pupils no
 * longer go. Nothing in the database notices. The server flags those rows and this panel shows them
 * in place, with the one honest recovery — clear the map and remake it — rather than pretending a
 * row-by-row repair is possible when nothing about the old targets is salvageable.
 */

type ArmRef = { id: string; label: string | null; stream: string | null };

type Mapping = {
    source_arm: ArmRef;
    target_arm: ArmRef | null;
    is_stale: boolean;
};

type ArmMap = {
    is_terminal: boolean;
    target_level: { id: string; name: string } | null;
    mappings: Mapping[];
    target_arms: ArmRef[];
    warnings: string[];
};

const armName = (arm: ArmRef): string =>
    arm.stream ? `${arm.label} (${arm.stream})` : (arm.label ?? '—');

export function ArmMapPanel({
    classLevel,
    onClose,
}: {
    classLevel: ClassLevel;
    onClose: () => void;
}) {
    const [data, setData] = useState<ArmMap | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [confirmClear, setConfirmClear] = useState(false);
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const hydrate = useCallback((payload: ArmMap) => {
        setData(payload);
        setDraft(
            Object.fromEntries(
                payload.mappings.map((mapping) => [
                    mapping.source_arm.id,
                    mapping.target_arm?.id ?? '',
                ]),
            ),
        );
    }, []);

    useEffect(() => {
        const load = async () => {
            try {
                const response = await axios.get(
                    `/api/class-levels/${classLevel.id}/arm-map`,
                );
                hydrate(response.data);
            } catch {
                toast.error('Failed to load the arm mapping');
            } finally {
                setLoading(false);
            }
        };

        load();
    }, [classLevel.id, hydrate]);

    const save = async () => {
        setSaving(true);
        setErrors({});

        try {
            const response = await axios.put(
                `/api/class-levels/${classLevel.id}/arm-map`,
                {
                    mappings: Object.entries(draft).map(
                        ([sourceId, targetId]) => ({
                            source_arm_id: sourceId,
                            // '' is an explicit "no mapping for this arm", which REMOVES any existing
                            // row — different from omitting the arm entirely.
                            target_arm_id: targetId || null,
                        }),
                    ),
                },
            );
            hydrate(response.data);
            toast.success('Arm mapping updated');
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                setErrors(error.response.data?.errors ?? {});

                return;
            }

            toast.error('Failed to update the arm mapping');
        } finally {
            setSaving(false);
        }
    };

    const clearAll = async () => {
        try {
            const response = await axios.delete(
                `/api/class-levels/${classLevel.id}/arm-map`,
            );
            hydrate(response.data);
            toast.success('Arm mapping cleared');
        } catch {
            toast.error('Failed to clear the arm mapping');
        } finally {
            setConfirmClear(false);
        }
    };

    // Errors come back keyed by index (mappings.0.target_arm_id); map them onto the row so the
    // message lands under the select that caused it.
    const errorFor = (index: number, field: string): string | null =>
        errors[`mappings.${index}.${field}`]?.[0] ?? null;

    return (
        <Modal
            title={`Arm mapping — ${classLevel.name}`}
            onClose={onClose}
            large
        >
            {loading || !data ? (
                <p className="p-4 text-sm text-gray-500">Loading…</p>
            ) : data.is_terminal ? (
                <p className="p-4 text-sm text-gray-500">
                    {classLevel.name} is a terminal year — nobody progresses out
                    of it, so there is nowhere for an arm mapping to point. Set
                    where its pupils move to first.
                </p>
            ) : (
                <div className="space-y-4">
                    <p className="text-xs text-gray-500">
                        Pupils move into{' '}
                        <span className="font-medium">
                            {data.target_level?.name}
                        </span>
                        . An arm left unmapped falls back to matching by label,
                        then to this level’s placement setting.
                    </p>

                    {data.warnings.map((warning) => (
                        <p
                            key={warning}
                            className="rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                        >
                            {warning}
                        </p>
                    ))}

                    {errors.mappings?.[0] && (
                        <p className="text-xs text-red-600 dark:text-red-400">
                            {errors.mappings[0]}
                        </p>
                    )}

                    <table className="w-full text-xs">
                        <thead>
                            <tr className="text-left text-gray-500">
                                <th className="py-1">
                                    Arm in {classLevel.name}
                                </th>
                                <th className="py-1">
                                    Moves into ({data.target_level?.name})
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.mappings.map((mapping, index) => (
                                <tr
                                    key={mapping.source_arm.id}
                                    className={
                                        mapping.is_stale
                                            ? 'bg-amber-50 dark:bg-amber-950/40'
                                            : ''
                                    }
                                >
                                    <td className="py-1 pr-2">
                                        {armName(mapping.source_arm)}
                                        {mapping.is_stale && (
                                            <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800">
                                                points outside{' '}
                                                {data.target_level?.name}
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-1">
                                        <select
                                            className="input w-full"
                                            value={
                                                draft[mapping.source_arm.id] ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                setDraft((current) => ({
                                                    ...current,
                                                    [mapping.source_arm.id]:
                                                        event.target.value,
                                                }))
                                            }
                                        >
                                            <option value="">
                                                No mapping — match by label
                                            </option>
                                            {/*
                                                ONLY the target level's arms. Offering anything else
                                                would let an operator save a mapping the rollover
                                                silently refuses.
                                            */}
                                            {data.target_arms.map((arm) => (
                                                <option
                                                    key={arm.id}
                                                    value={arm.id}
                                                >
                                                    {armName(arm)}
                                                </option>
                                            ))}
                                        </select>
                                        {errorFor(index, 'target_arm_id') && (
                                            <p className="mt-1 text-red-600 dark:text-red-400">
                                                {errorFor(
                                                    index,
                                                    'target_arm_id',
                                                )}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <Can permission="academic_setup.manage">
                        <div className="flex items-center gap-2">
                            <button
                                className="btn btn-primary btn-sm"
                                onClick={save}
                                disabled={saving}
                            >
                                {saving ? 'Saving…' : 'Save mapping'}
                            </button>
                            <button
                                className="btn btn-ghost btn-sm"
                                onClick={() => setConfirmClear(true)}
                            >
                                Clear all
                            </button>
                        </div>
                    </Can>
                </div>
            )}

            {confirmClear && (
                <Confirm
                    msg="Clear every arm mapping for this level? Pupils will fall back to label matching and this level's placement setting."
                    onConfirm={clearAll}
                    onClose={() => setConfirmClear(false)}
                />
            )}
        </Modal>
    );
}
