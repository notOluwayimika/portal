import { useForm } from '@inertiajs/react';
import axios from 'axios';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';
import type { MarkingComponent } from '@/types/models';

/**
 * Editable row state. We keep the weight as a *percentage string*
 * so the input stays controlled and the user can type freely
 * (e.g. "30", "33.3", or "" mid-edit). `id` is null for new rows.
 */
interface Row {
    key: string; // stable React key (also used for new, unsaved rows)
    id: string | null;
    name: string;
    percent: string;
}

/** Tolerance for the 100% check: decimal(4,3) => 0.1% resolution. */
const PERCENT_TOLERANCE = 0.05;

/** Parse a possibly-empty/invalid input string into a number. */
function toNumber(value: string): number {
    const n = parseFloat(value);

    return Number.isFinite(n) ? n : 0;
}

/** weight (0.300) -> percent string ("30"), trimming trailing zeros. */
function weightToPercent(weight: number | string): string {
    const pct = Math.round(Number(weight) * 100 * 10) / 10; // 1 dp

    return String(pct);
}

function makeKey(): string {
    return `new-${Math.random().toString(36).slice(2, 11)}`;
}

export default function MarkingComponents({
    suffix = null,
}: {
    suffix?: string | null;
}) {
    const [components, setComponents] = useState<MarkingComponent[]>([]);
    const [processing, setProcessing] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);
    useEffect(() => {
        async function getMarkingComponents() {
            const response = await axios.get(
                `/api/marking-components${suffix ? `?${suffix}=true` : ''}`,
            );
            setComponents(response.data);
        }
        getMarkingComponents();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recentlySuccessful]);
    const { data, setData, errors } = useForm<{ components: Row[] }>({
        components: components.map((c) => ({
            key: c.id,
            id: c.id,
            name: c.name,
            percent: weightToPercent(c.weight),
        })),
    });
    useEffect(() => {
        if (components.length > 0) {
            setData(
                'components',
                components.map((c) => ({
                    key: c.id,
                    id: c.id,
                    name: c.name,
                    percent: weightToPercent(c.weight),
                })),
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [components]);

    const rows = data.components;

    /** Live total of all entered percentages. */
    const totalPercent = useMemo(
        () => rows.reduce((sum, r) => sum + toNumber(r.percent), 0),
        [rows],
    );

    // Round for display so floating point noise (e.g. 99.99999) is hidden.
    const totalDisplay = Math.round(totalPercent * 10) / 10;
    const isBalanced = Math.abs(totalPercent - 100) <= PERCENT_TOLERANCE;
    const allNamed = rows.every((r) => r.name.trim().length > 0);
    const allWeightsValid = rows.every((r) => {
        const n = toNumber(r.percent);

        return n >= 0 && n <= 100 && r.percent.trim() !== '';
    });
    const canSave =
        rows.length > 0 &&
        isBalanced &&
        allNamed &&
        allWeightsValid &&
        !processing;

    const updateRow = (key: string, patch: Partial<Row>) => {
        setData(
            'components',
            rows.map((r) => (r.key === key ? { ...r, ...patch } : r)),
        );
    };

    const addRow = () => {
        setData('components', [
            ...rows,
            { key: makeKey(), id: null, name: '', percent: '' },
        ]);
    };

    const removeRow = (key: string) => {
        setData(
            'components',
            rows.filter((r) => r.key !== key),
        );
    };

    /** Split 100% evenly across every current row. */
    const distributeEvenly = () => {
        if (rows.length === 0) {
            return;
        }

        // Whole tenths of a percent that sum to exactly 1000 (= 100.0%).
        const base = Math.floor(1000 / rows.length);
        let remainder = 1000 - base * rows.length;
        setData(
            'components',
            rows.map((r) => {
                const tenths = base + (remainder-- > 0 ? 1 : 0);

                return { ...r, percent: String(tenths / 10) };
            }),
        );
    };

    const handleSubmit = async (e: FormEvent) => {
        setProcessing(true);
        e.preventDefault();

        if (!canSave) {
            setProcessing(false);

            return;
        }

        try {
            const response = await axios.post(
                `/api/marking-components${suffix ? `?${suffix}=true` : ''}`,
                data,
            );

            if (response.status === 200) {
                setRecentlySuccessful(true);
                setTimeout(() => {
                    setRecentlySuccessful(false);
                }, 5000);
            }
        } catch (error) {
            console.error('Error saving marking components:', error);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <div className="mx-auto max-w-2xl p-6">
                <div className="page-hdr">
                    <div>
                        <h1>Marking Components {suffix && `(${suffix})`}</h1>
                        <p>
                            These default components apply to curriculum
                            subjects but can be modified per subject later.
                            Weights must add up to exactly 100% before you can
                            save.
                        </p>
                    </div>
                </div>

                {recentlySuccessful && (
                    <div className="mb-4 rounded-md bg-green-50 px-4 py-2 text-sm text-green-700">
                        Marking components saved.
                    </div>
                )}

                {errors.components && (
                    <div className="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">
                        {errors.components}
                    </div>
                )}

                <form onSubmit={handleSubmit}>
                    <div className="space-y-2">
                        {rows.map((row) => {
                            const weight = toNumber(row.percent);
                            const weightInvalid =
                                row.percent.trim() === '' ||
                                weight < 0 ||
                                weight > 100;

                            return (
                                <div
                                    key={row.key}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="text"
                                        value={row.name}
                                        onChange={(e) =>
                                            updateRow(row.key, {
                                                name: e.target.value,
                                            })
                                        }
                                        placeholder="Component name (e.g. CA Test)"
                                        className={`flex-1 rounded-md border px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none ${
                                            row.name.trim() === ''
                                                ? 'border-red-300'
                                                : 'border-gray-300'
                                        }`}
                                    />
                                    <div className="relative w-28">
                                        <input
                                            type="number"
                                            inputMode="decimal"
                                            min={0}
                                            max={100}
                                            step={0.1}
                                            value={row.percent}
                                            onChange={(e) =>
                                                updateRow(row.key, {
                                                    percent: e.target.value,
                                                })
                                            }
                                            placeholder="0"
                                            className={`w-full rounded-md border px-3 py-2 pr-7 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none ${
                                                weightInvalid
                                                    ? 'border-red-300'
                                                    : 'border-gray-300'
                                            }`}
                                        />
                                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-gray-400">
                                            %
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => removeRow(row.key)}
                                        aria-label="Remove component"
                                        className="rounded-md px-2 py-2 text-sm text-gray-400 hover:bg-red-50 hover:text-red-600"
                                    >
                                        &times;
                                    </button>
                                </div>
                            );
                        })}
                    </div>

                    {rows.length === 0 && (
                        <p className="py-4 text-center text-sm text-gray-400">
                            No marking components yet. Add one to get started.
                        </p>
                    )}

                    <div className="mt-3 flex gap-2">
                        <button
                            type="button"
                            onClick={addRow}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            + Add component
                        </button>
                        <button
                            type="button"
                            onClick={distributeEvenly}
                            disabled={rows.length === 0}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        >
                            Distribute evenly
                        </button>
                    </div>

                    {/* Running total */}
                    <div
                        className={`mt-4 flex items-center justify-between rounded-md px-4 py-3 text-sm font-medium ${
                            isBalanced
                                ? 'bg-green-50 text-green-700'
                                : 'bg-amber-50 text-amber-700'
                        }`}
                    >
                        <span>Total</span>
                        <span>
                            {totalDisplay}%
                            {!isBalanced &&
                                ` — ${
                                    totalPercent > 100 ? 'over' : 'under'
                                } by ${Math.round(Math.abs(totalPercent - 100) * 10) / 10}%`}
                        </span>
                    </div>

                    <div className="mt-6 flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={!canSave}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing ? 'Saving…' : 'Save changes'}
                        </button>
                        {!canSave && !processing && (
                            <span className="text-sm text-gray-500">
                                {rows.length === 0
                                    ? 'Add at least one component.'
                                    : !allNamed
                                      ? 'Every component needs a name.'
                                      : !allWeightsValid
                                        ? 'Every weight must be between 0 and 100.'
                                        : 'Weights must total exactly 100%.'}
                            </span>
                        )}
                    </div>
                </form>
            </div>
        </>
    );
}
