import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type {
    CurriculumSubject,
    MarkingComponent,
    Score,
    Student,
    StudentSubject,
} from '@/types/models';

type CellStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

interface CellState {
    value: string;
    status: CellStatus;
    error?: string;
}

// ---------- Helpers ----------

const cellKey = (studentId: string, mcId: string) => `${studentId}:${mcId}`;

const maxForComponent = (mc: MarkingComponent) => Math.round(mc.weight * 100);

const fullName = (s: Student) =>
    [s.last_name, s.first_name, s.middle_name].filter(Boolean).join(' ');

// ---------- Page ----------

export default function ScoreEntryPage({
    cs,
    addToast,
}: {
    cs: CurriculumSubject;
    addToast: (message: string, type?: 'success' | 'error') => void;
}) {
    const [markingComponents] = useState<MarkingComponent[]>(
        cs.marking_components,
    );
    const [students] = useState<StudentSubject[]>(cs.students);
    const [scores] = useState<Score[]>(cs.scores ?? []);
    const [query, setQuery] = useState('');
    const initialCells = useMemo<Record<string, CellState>>(() => {
        const map: Record<string, CellState> = {};

        for (const s of scores) {
            map[cellKey(s.student.id, s.marking_component.id)] = {
                value: String(s.score),
                status: 'idle',
            };
        }

        return map;
    }, [scores]);
    // console.log(initialCells);

    const [cells, setCells] = useState<Record<string, CellState>>(initialCells);
    const debounceRef = useRef<Record<string, ReturnType<typeof setTimeout>>>(
        {},
    );
    const savedFlashRef = useRef<Record<string, ReturnType<typeof setTimeout>>>(
        {},
    );

    // Clean up timers on unmount.
    useEffect(() => {
        return () => {
            Object.values(debounceRef.current).forEach(clearTimeout);
            Object.values(savedFlashRef.current).forEach(clearTimeout);
        };
    }, []);

    const getCell = useCallback(
        (studentId: string, mcId: string): CellState =>
            cells[cellKey(studentId, mcId)] ?? {
                value: '',
                status: 'idle',
            },
        [cells],
    );

    const setCell = useCallback((key: string, patch: Partial<CellState>) => {
        setCells((prev) => {
            const cur = prev[key] ?? {
                value: '',
                status: 'idle' as CellStatus,
            };

            return { ...prev, [key]: { ...cur, ...patch } };
        });
    }, []);

    const persist = useCallback(
        async (studentId: string, mc: MarkingComponent, raw: string) => {
            const key = cellKey(studentId, mc.id);

            // Empty input: leave the existing server value alone, return to idle.
            if (raw.trim() === '') {
                setCell(key, { value: '', status: 'idle', error: undefined });

                return;
            }

            const num = Number(raw);
            const max = maxForComponent(mc);

            if (!Number.isFinite(num) || num < 0 || num > max) {
                setCell(key, {
                    status: 'error',
                    error: `0–${max}`,
                });

                return;
            }

            setCell(key, { status: 'saving', error: undefined });

            try {
                const payload = {
                    curriculum_subject_id: cs.id,
                    student_id: studentId,
                    marking_component_id: mc.id,
                    score: num,
                };
                await axios.post(
                    '/api/curriculum-subjects/' + cs.id + '/scores',
                    payload,
                );

                setCell(key, { status: 'saved', error: undefined });

                if (savedFlashRef.current[key]) {
                    clearTimeout(savedFlashRef.current[key]);
                }

                savedFlashRef.current[key] = setTimeout(() => {
                    setCells((prev) => {
                        const cur = prev[key];

                        if (!cur || cur.status !== 'saved') {
                            return prev;
                        }

                        return { ...prev, [key]: { ...cur, status: 'idle' } };
                    });
                }, 1200);
            } catch (e: unknown) {
                const err = e as {
                    response?: {
                        data?: {
                            message?: string;
                            errors?: Record<string, string[]>;
                        };
                    };
                };
                const msg =
                    err?.response?.data?.errors?.score?.[0] ??
                    err?.response?.data?.message ??
                    'Save failed';
                setCell(key, { status: 'error', error: msg });
            }
        },
        [setCell, cs.id],
    );

    const handleChange = (
        studentId: string,
        mc: MarkingComponent,
        raw: string,
    ) => {
        const key = cellKey(studentId, mc.id);
        setCell(key, { value: raw, status: 'dirty', error: undefined });

        if (debounceRef.current[key]) {
            clearTimeout(debounceRef.current[key]);
        }

        debounceRef.current[key] = setTimeout(() => {
            void persist(studentId, mc, raw);
        }, 600);
    };

    const handleBlur = (studentId: string, mc: MarkingComponent) => {
        const key = cellKey(studentId, mc.id);

        if (debounceRef.current[key]) {
            clearTimeout(debounceRef.current[key]);
            delete debounceRef.current[key];
        }

        const cell = getCell(studentId, mc.id);

        if (cell.status === 'dirty') {
            void persist(studentId, mc, cell.value);
        }
    };

    // ---------- Derived ----------

    const filteredStudents = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return students;
        }

        return students.filter((s) => {
            const name = fullName(s.student_curriculum.student).toLowerCase();
            const adm =
                s.student_curriculum.student.admission_number?.toLowerCase() ??
                '';

            return name.includes(q) || adm.includes(q);
        });
    }, [students, query]);

    const rowTotal = useCallback(
        (studentId: string) => {
            let total = 0;
            let anyValue = false;

            for (const mc of markingComponents) {
                const v = getCell(studentId, mc.id).value;

                if (v === '') {
                    continue;
                }

                const n = Number(v);

                if (Number.isFinite(n)) {
                    total += n;
                    anyValue = true;
                }
            }

            return anyValue ? Math.round(total * 100) / 100 : null;
        },
        [markingComponents, getCell],
    );

    // ---------- Render ----------

    return (
        <>
            <Head title={`Scores – ${cs.subject?.name}`} />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                {/* Header */}
                <div className="rounded-lg border bg-white p-5 shadow-sm">
                    <h1 className="text-xl font-semibold text-gray-900">
                        {cs.subject?.name}
                    </h1>
                    <p className="mt-1 text-sm text-gray-600">
                        {/* {[
                            tcs.class_label,
                            `Term ${tcs.term}`,
                            tcs.exam_type,
                            tcs.session,
                        ]
                            .filter(Boolean)
                            .join(' • ')} */}
                        {cs.curriculum?.full_name}
                    </p>
                </div>

                {/* Toolbar */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <input
                        type="search"
                        placeholder="Search by name or admission number…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="w-72 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />
                    <div className="flex items-center gap-4 text-xs text-gray-500">
                        <Legend status="saving" label="Saving" />
                        <Legend status="saved" label="Saved" />
                        <Legend status="error" label="Error" />
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-lg border bg-white shadow-sm">
                    <table className="min-w-full border-collapse text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="sticky left-0 z-10 w-64 bg-gray-50 px-4 py-3 text-left font-medium text-gray-700">
                                    Student
                                </th>
                                {markingComponents?.map((mc) => (
                                    <th
                                        key={mc.id}
                                        className="px-3 py-3 text-left font-medium text-gray-700"
                                    >
                                        <div>{mc.name}</div>
                                        <div className="text-xs font-normal text-gray-500">
                                            / {maxForComponent(mc)}
                                        </div>
                                    </th>
                                ))}
                                <th className="px-3 py-3 text-right font-medium text-gray-700">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filteredStudents?.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={markingComponents?.length + 2}
                                        className="px-4 py-8 text-center text-gray-500"
                                    >
                                        No students match your search.
                                    </td>
                                </tr>
                            )}
                            {filteredStudents?.map((s) => {
                                const total = rowTotal(
                                    s.student_curriculum.student.id,
                                );

                                return (
                                    <tr key={s.id} className="hover:bg-gray-50">
                                        <td className="sticky left-0 z-10 w-64 bg-white px-4 py-2 align-middle">
                                            <div className="font-medium text-gray-900">
                                                {fullName(
                                                    s.student_curriculum
                                                        .student,
                                                )}
                                            </div>
                                            {s.student_curriculum.student
                                                .admission_number && (
                                                <div className="text-xs text-gray-500">
                                                    {
                                                        s.student_curriculum
                                                            .student
                                                            .admission_number
                                                    }
                                                </div>
                                            )}
                                        </td>
                                        {markingComponents?.map((mc) => {
                                            const cell = getCell(
                                                s.student_curriculum.student.id,
                                                mc.id,
                                            );

                                            return (
                                                <td
                                                    key={mc.id}
                                                    className="px-3 py-2"
                                                >
                                                    <ScoreCell
                                                        cell={cell}
                                                        max={maxForComponent(
                                                            mc,
                                                        )}
                                                        onChange={(v) =>
                                                            handleChange(
                                                                s
                                                                    .student_curriculum
                                                                    .student.id,
                                                                mc,
                                                                v,
                                                            )
                                                        }
                                                        onBlur={() =>
                                                            handleBlur(
                                                                s
                                                                    .student_curriculum
                                                                    .student.id,
                                                                mc,
                                                            )
                                                        }
                                                    />
                                                </td>
                                            );
                                        })}
                                        <td className="px-3 py-2 text-right font-semibold text-gray-900">
                                            {total === null ? (
                                                <span className="text-gray-400">
                                                    —
                                                </span>
                                            ) : (
                                                total
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

// ---------- Sub-components ----------

function ScoreCell({
    cell,
    max,
    onChange,
    onBlur,
}: {
    cell: CellState;
    max: number;
    onChange: (v: string) => void;
    onBlur: () => void;
}) {
    const [value, setValue] = useState(cell.value);
    // console.log(cell);
    const borderClass =
        cell.status === 'error'
            ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
            : cell.status === 'saving'
              ? 'border-amber-300 focus:border-amber-500 focus:ring-amber-500'
              : cell.status === 'saved'
                ? 'border-green-400 focus:border-green-500 focus:ring-green-500'
                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500';

    return (
        <div className="relative">
            <input
                type="number"
                inputMode="decimal"
                step="0.01"
                min={0}
                max={max}
                value={value}
                onChange={(e) => {
                    setValue(e.target.value);
                    onChange(e.target.value);
                }}
                onBlur={onBlur}
                className={`w-20 rounded-md border px-2 py-1 text-right text-sm shadow-sm focus:ring-1 focus:outline-none ${borderClass}`}
            />
            <StatusDot status={cell.status} />
            {cell.status === 'error' && cell.error && (
                <div className="absolute top-full left-0 z-20 mt-1 rounded bg-red-600 px-2 py-0.5 text-xs whitespace-nowrap text-white shadow">
                    {cell.error}
                </div>
            )}
        </div>
    );
}

function StatusDot({ status }: { status: CellStatus }) {
    if (status === 'idle' || status === 'dirty') {
        return null;
    }

    const color =
        status === 'saving'
            ? 'bg-amber-400'
            : status === 'saved'
              ? 'bg-green-500'
              : 'bg-red-500';

    return (
        <span
            className={`pointer-events-none absolute top-1 right-1 h-1.5 w-1.5 rounded-full ${color}`}
            aria-hidden
        />
    );
}

function Legend({ status, label }: { status: CellStatus; label: string }) {
    const color =
        status === 'saving'
            ? 'bg-amber-400'
            : status === 'saved'
              ? 'bg-green-500'
              : 'bg-red-500';

    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={`h-2 w-2 rounded-full ${color}`} />
            {label}
        </span>
    );
}
