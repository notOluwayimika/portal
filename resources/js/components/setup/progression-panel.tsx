import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Can } from '@/components/can';
import { Confirm, Modal } from '@/components/setup/setup-ui';
import type { ClassLevel, ExamType } from '@/types/models';

/**
 * Progression configuration for ONE class level.
 *
 * A panel opened from a class-level row rather than a thirteenth setup tab: progression is a
 * property of a SPECIFIC level — you configure it for Year 11, not in the abstract — so a standalone
 * tab would need its own level picker duplicating the list this opens from.
 *
 * ── FIELD-LEVEL ERRORS ARE THE POINT ──────────────────────────────────────────────────────────────
 * The sibling setup tabs toast a generic string on failure, because their controllers swallow
 * ValidationException into a 500. These endpoints use FormRequests and return 422 with per-field
 * `errors`, and this panel renders them under the field that caused them. Every rule here mirrors a
 * database constraint or a job guard the operator cannot otherwise see, and the whole reason the
 * screens exist is to surface the refusal at configuration time instead of at rollover.
 */

type ProgressionRef = { id: string; name: string };

type Progression = {
    id: string;
    name: string;
    order: number | null;
    next_class_level: ProgressionRef | null;
    is_terminal: boolean;
    default_exam_type: ProgressionRef | null;
    arm_distribution_strategy: 'round_robin' | 'explicit_only';
    participation: { id: string; term_order: number; is_ccm: boolean }[];
    exam_types: ProgressionRef[];
};

type FieldErrors = Record<string, string[]>;

const STRATEGIES: {
    value: Progression['arm_distribution_strategy'];
    label: string;
    hint: string;
}[] = [
    {
        value: 'round_robin',
        label: 'Distribute evenly',
        hint: 'Pupils with no arm mapping are spread across the target level’s arms, by pupil, so the result is the same however the rollover is run.',
    },
    {
        value: 'explicit_only',
        label: 'Explicit mapping only',
        hint: 'Pupils with no arm mapping are left unplaced for a human to position. Nothing is auto-assigned.',
    },
];

/** Field errors from a 422, or a toast for anything else. */
function useApiErrors() {
    const [errors, setErrors] = useState<FieldErrors>({});

    const handle = useCallback((error: unknown, fallback: string) => {
        if (axios.isAxiosError(error) && error.response?.status === 422) {
            setErrors((error.response.data?.errors ?? {}) as FieldErrors);

            return;
        }

        toast.error(fallback);
    }, []);

    return { errors, setErrors, handle };
}

function FieldError({ errors, field }: { errors: FieldErrors; field: string }) {
    const messages = errors[field];

    if (!messages?.length) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-red-600 dark:text-red-400">
            {messages[0]}
        </p>
    );
}

export function ProgressionPanel({
    classLevel,
    levels,
    examTypes,
    onClose,
}: {
    classLevel: ClassLevel;
    /** Same-school levels only — the list this panel was opened from. */
    levels: ClassLevel[];
    /** Same-school exam types only. */
    examTypes: ExamType[];
    onClose: () => void;
}) {
    const [progression, setProgression] = useState<Progression | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [newSlot, setNewSlot] = useState('');
    const [confirmSlot, setConfirmSlot] = useState<string | null>(null);
    // Which slot has a CCM write in flight — disables just that row's control, so a
    // double-click cannot queue a second write against a row the first has not returned.
    const [pendingSlot, setPendingSlot] = useState<string | null>(null);
    const { errors, setErrors, handle } = useApiErrors();

    const [nextId, setNextId] = useState('');
    const [defaultExamTypeId, setDefaultExamTypeId] = useState('');
    const [strategy, setStrategy] =
        useState<Progression['arm_distribution_strategy']>('round_robin');
    const [selectedExamTypes, setSelectedExamTypes] = useState<string[]>([]);

    const hydrate = useCallback((data: Progression) => {
        setProgression(data);
        setNextId(data.next_class_level?.id ?? '');
        setDefaultExamTypeId(data.default_exam_type?.id ?? '');
        setStrategy(data.arm_distribution_strategy);
        setSelectedExamTypes(data.exam_types.map((type) => type.id));
    }, []);

    useEffect(() => {
        const load = async () => {
            try {
                const response = await axios.get(
                    `/api/class-levels/${classLevel.id}/progression`,
                );
                hydrate(response.data.progression);
            } catch {
                toast.error('Failed to load progression settings');
            } finally {
                setLoading(false);
            }
        };

        load();
    }, [classLevel.id, hydrate]);

    const saveProgression = async () => {
        setSaving(true);
        setErrors({});

        try {
            const response = await axios.put(
                `/api/class-levels/${classLevel.id}/progression`,
                {
                    next_class_level_id: nextId || null,
                    default_exam_type_id: defaultExamTypeId || null,
                    arm_distribution_strategy: strategy,
                },
            );
            hydrate(response.data.progression);
            toast.success('Progression updated');
        } catch (error) {
            handle(error, 'Failed to update progression');
        } finally {
            setSaving(false);
        }
    };

    const addSlot = async () => {
        setErrors({});

        try {
            const response = await axios.post(
                `/api/class-levels/${classLevel.id}/participation`,
                { term_order: Number(newSlot) },
            );
            hydrate(response.data.progression);
            setNewSlot('');
        } catch (error) {
            handle(error, 'Failed to add term slot');
        }
    };

    const removeSlot = async (slotId: string) => {
        try {
            const response = await axios.delete(
                `/api/class-levels/${classLevel.id}/participation/${slotId}`,
            );
            hydrate(response.data.progression);
        } catch (error) {
            handle(error, 'Failed to remove term slot');
        } finally {
            setConfirmSlot(null);
        }
    };

    // ── SETS THE FLAG, NEVER FLIPS IT ────────────────────────────────────────
    // The desired state is sent explicitly, because the endpoint behind this is a
    // SETTER: it takes `is_ccm` as `required|boolean` rather than inverting what it
    // finds. Sending `!slot.is_ccm` from the client would reintroduce the same defect
    // one layer up — a double-submit, a retry, or a stale panel would land the flag
    // opposite to what the operator saw, with no error, because inverting twice is
    // legal. `next` is computed once here and is what crosses the wire, so the request
    // carries a decision the server can apply idempotently.
    const setSlotCcm = async (slotId: string, next: boolean) => {
        setErrors({});
        setPendingSlot(slotId);

        try {
            const response = await axios.patch(
                `/api/class-levels/${classLevel.id}/participation/${slotId}`,
                { is_ccm: next },
            );
            hydrate(response.data.progression);
            toast.success(next ? 'Slot marked CCM' : 'CCM removed from slot');
        } catch (error) {
            // NOT rolled back optimistically, because nothing was applied optimistically:
            // the row re-renders from the server's own progression payload on success and
            // is left untouched on failure, so the screen never shows a state the server
            // did not confirm.
            handle(error, 'Failed to update the CCM flag for this slot');
        } finally {
            setPendingSlot(null);
        }
    };

    const saveExamTypes = async () => {
        setErrors({});

        try {
            const response = await axios.put(
                `/api/class-levels/${classLevel.id}/exam-types`,
                { exam_type_ids: selectedExamTypes },
            );
            hydrate(response.data.progression);
            setWarnings(response.data.warnings ?? []);
            toast.success('Exam types updated');
        } catch (error) {
            handle(error, 'Failed to update exam types');
        }
    };

    // Same-school by construction: this is the list the panel was opened from, minus this level —
    // pointing at itself is refused by the server and by a database trigger, so it is not offered.
    const targetOptions = levels.filter((level) => level.id !== classLevel.id);

    return (
        <Modal
            title={`Progression — ${classLevel.name}`}
            onClose={onClose}
            large
        >
            {loading || !progression ? (
                <p className="p-4 text-sm text-gray-500">Loading…</p>
            ) : (
                <div className="space-y-6">
                    {/* ── Where pupils go ──────────────────────────────── */}
                    <section>
                        <h3 className="mb-2 text-sm font-semibold">
                            End of year
                        </h3>

                        <label className="block text-xs font-medium">
                            Pupils move into
                        </label>
                        <select
                            className="input mt-1 w-full"
                            value={nextId}
                            onChange={(event) => setNextId(event.target.value)}
                        >
                            <option value="">
                                Terminal — nobody progresses out of this level
                            </option>
                            {targetOptions.map((level) => (
                                <option key={level.id} value={level.id}>
                                    {level.name}
                                </option>
                            ))}
                        </select>
                        <FieldError
                            errors={errors}
                            field="next_class_level_id"
                        />
                        {progression.is_terminal && !nextId && (
                            <p className="mt-1 text-xs text-gray-500">
                                This is currently the graduating year. Nothing
                                is promoted out of it at rollover.
                            </p>
                        )}

                        <label className="mt-4 block text-xs font-medium">
                            Arm placement when no mapping matches
                        </label>
                        <div className="mt-1 space-y-2">
                            {STRATEGIES.map((option) => (
                                <label
                                    key={option.value}
                                    className="flex items-start gap-2 text-xs"
                                >
                                    <input
                                        type="radio"
                                        name="arm_distribution_strategy"
                                        className="mt-0.5"
                                        checked={strategy === option.value}
                                        onChange={() =>
                                            setStrategy(option.value)
                                        }
                                    />
                                    <span>
                                        <span className="font-medium">
                                            {option.label}
                                        </span>
                                        <span className="block text-gray-500">
                                            {option.hint}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <FieldError
                            errors={errors}
                            field="arm_distribution_strategy"
                        />

                        <label className="mt-4 block text-xs font-medium">
                            Fallback exam type
                        </label>
                        <select
                            className="input mt-1 w-full"
                            value={defaultExamTypeId}
                            onChange={(event) =>
                                setDefaultExamTypeId(event.target.value)
                            }
                        >
                            <option value="">None</option>
                            {examTypes.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-xs text-gray-500">
                            Used when an arriving pupil’s current exam type is
                            not one this level runs.
                        </p>
                        <FieldError
                            errors={errors}
                            field="default_exam_type_id"
                        />

                        <Can permission="academic_setup.manage">
                            <button
                                className="btn btn-primary btn-sm mt-3"
                                onClick={saveProgression}
                                disabled={saving}
                            >
                                {saving ? 'Saving…' : 'Save'}
                            </button>
                        </Can>
                    </section>

                    {/* ── Term slots ───────────────────────────────────── */}
                    <section>
                        <h3 className="mb-1 text-sm font-semibold">
                            Term slots this level runs
                        </h3>
                        <p className="mb-2 text-xs text-gray-500">
                            A slot listed here is a term this level teaches.
                            Remove one and the level simply does not run it —
                            end-of-term skips straight to the next slot listed.
                        </p>

                        {progression.participation.length === 0 ? (
                            <p className="text-xs text-amber-600 dark:text-amber-400">
                                No slots configured. Until at least one is
                                added, the end-of-term and end-of-year
                                migrations will skip this level entirely.
                            </p>
                        ) : (
                            <ul className="space-y-1">
                                {progression.participation.map((slot) => (
                                    <li
                                        key={slot.id}
                                        className="flex items-center justify-between rounded border px-2 py-1 text-xs"
                                    >
                                        <span>
                                            Term {slot.term_order}
                                            {slot.is_ccm && (
                                                <span className="ml-2 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700">
                                                    CCM
                                                </span>
                                            )}
                                        </span>
                                        <Can permission="academic_setup.manage">
                                            <label className="mr-2 flex items-center gap-1.5">
                                                <input
                                                    type="checkbox"
                                                    className="h-3.5 w-3.5"
                                                    checked={slot.is_ccm}
                                                    disabled={
                                                        pendingSlot === slot.id
                                                    }
                                                    onChange={(e) =>
                                                        setSlotCcm(
                                                            slot.id,
                                                            e.target.checked,
                                                        )
                                                    }
                                                    aria-label={`Term ${slot.term_order} is a CCM slot`}
                                                />
                                                <span className="text-[11px] text-gray-500">
                                                    CCM
                                                </span>
                                            </label>
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() =>
                                                    setConfirmSlot(slot.id)
                                                }
                                                aria-label={`Remove term ${slot.term_order}`}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </button>
                                        </Can>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Can permission="academic_setup.manage">
                            <div className="mt-2 flex items-start gap-2">
                                <div>
                                    <input
                                        className="input w-24"
                                        type="number"
                                        min={1}
                                        placeholder="Term"
                                        value={newSlot}
                                        onChange={(event) =>
                                            setNewSlot(event.target.value)
                                        }
                                    />
                                    <FieldError
                                        errors={errors}
                                        field="term_order"
                                    />
                                </div>
                                <button
                                    className="btn btn-secondary btn-sm"
                                    onClick={addSlot}
                                    disabled={!newSlot}
                                >
                                    Add slot
                                </button>
                            </div>
                        </Can>
                    </section>

                    {/* ── Exam types ───────────────────────────────────── */}
                    <section>
                        <h3 className="mb-1 text-sm font-semibold">
                            Exam types this level runs
                        </h3>
                        <p className="mb-2 text-xs text-gray-500">
                            An arriving pupil keeps their exam type if it is
                            listed here; otherwise they fall back to the type
                            selected above.
                        </p>

                        <div className="space-y-1">
                            {examTypes.map((type) => (
                                <label
                                    key={type.id}
                                    className="flex items-center gap-2 text-xs"
                                >
                                    <input
                                        type="checkbox"
                                        checked={selectedExamTypes.includes(
                                            type.id,
                                        )}
                                        onChange={(event) =>
                                            setSelectedExamTypes((current) =>
                                                event.target.checked
                                                    ? [...current, type.id]
                                                    : current.filter(
                                                          (id) =>
                                                              id !== type.id,
                                                      ),
                                            )
                                        }
                                    />
                                    {type.name}
                                </label>
                            ))}
                        </div>
                        <FieldError errors={errors} field="exam_type_ids" />

                        {warnings.map((warning) => (
                            <p
                                key={warning}
                                className="mt-2 text-xs text-amber-600 dark:text-amber-400"
                            >
                                {warning}
                            </p>
                        ))}

                        <Can permission="academic_setup.manage">
                            <button
                                className="btn btn-primary btn-sm mt-3"
                                onClick={saveExamTypes}
                            >
                                Save exam types
                            </button>
                        </Can>
                    </section>
                </div>
            )}

            {confirmSlot && (
                <Confirm
                    msg="Remove this term slot? The level will no longer run that term."
                    onConfirm={() => removeSlot(confirmSlot)}
                    onClose={() => setConfirmSlot(null)}
                />
            )}
        </Modal>
    );
}
