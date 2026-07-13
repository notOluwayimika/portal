import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type {
    CurriculumSubject,
    GradeBoundary,
    GradingSchemeItem,
    MarkingComponent,
    Score,
    Student,
    StudentSubject,
    SubjectResultStatus,
} from '@/types/models';

type CellStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

interface CellState {
    value: string;
    status: CellStatus;
    error?: string;
}
const OUTSTANDING_COMMENTS = [
    'Outstanding performance. Keep it up',
    'Outstanding performance. Keep soaring',
];

const EXCELLENT_COMMENTS = [
    'Excellent result. Keep it up',
    'Excellent result. Do not relent',
    'Excellent performance. Keep soaring',
];

const VERY_GOOD_COMMENTS = [
    'Very good result. Do not relent',
    'Very good result. Keep working hard',
    'Very good result. Aim higher',
];

const GOOD_COMMENTS = [
    'Good result; you can do better',
    'Good result. Do not relent in your effort',
    'Good result. Aim higher',
    'Good result. You can make it better',
    'Good result. Work harder',
];

const FAIR_COMMENTS = [
    'You are encouraged to work harder',
    'You have the potential to improve on this grade',
    'There is room for improvement if you work hard',
    'You are capable of better academic performance',
    'There is potential for growth if you do not relent',
];

const NEEDS_IMPROVEMENT_COMMENTS = [
    'You are encouraged to work harder next term',
    'You are encouraged to improve on this performance',
    'There is room for improvement if you work hard',
    'You need to put more effort in your academics',
    'With determination, you can improve on this result',
    'You can improve on this result; please work harder',
];

const POOR_COMMENTS = [
    'This result is below expectation. Put in more effort',
    'You need to put more effort in your academics',
    'With determination, you can improve on this result',
    'You are encouraged to put in more effort',
    'You are encouraged to study more',
    'Work harder for a better result next term',
    'You are encouraged to focus more',
];

// ---------- Helpers ----------

const cellKey = (studentId: string, mcId: string) => `${studentId}:${mcId}`;

const maxForComponent = (mc: MarkingComponent) => Math.round(mc.weight * 100);

const fullName = (s: Student) =>
    [s.last_name, s.first_name, s.middle_name].filter(Boolean).join(' ');

// ---------- Page ----------

export default function ScoreEntryPage({
    cs,
    status,
    defaultGradeBoundaries = [],
}: {
    cs: CurriculumSubject;
    status: SubjectResultStatus;
    defaultGradeBoundaries?: GradeBoundary[];
}) {
    if (cs.curriculum?.grading_mode === 'categorical') {
        return <CategoricalEntryPage cs={cs} status={status} />;
    }

    return (
        <NumericScoreEntryPage
            cs={cs}
            status={status}
            defaultGradeBoundaries={defaultGradeBoundaries}
        />
    );
}

function NumericScoreEntryPage({
    cs,
    status,
    defaultGradeBoundaries,
}: {
    cs: CurriculumSubject;
    status: SubjectResultStatus;
    defaultGradeBoundaries: GradeBoundary[];
}) {
    const gradeBoundaries = cs.curriculum?.exam_type?.grade_boundaries?.length
        ? cs.curriculum.exam_type.grade_boundaries
        : defaultGradeBoundaries;
    const [markingComponents] = useState<MarkingComponent[]>(
        cs.marking_components,
    );
    const [overlappingMC, setOverlappingMC] = useState<string[]>([]);
    const [students] = useState<StudentSubject[]>(cs.students);
    const [scores] = useState<Score[]>(cs.scores ?? []);
    const [query, setQuery] = useState('');
    const initialCells = useMemo<Record<string, CellState>>(() => {
        const map: Record<string, CellState> = {};

        for (const s of scores) {
            map[cellKey(s.student.id, s.marking_component.id)] = {
                value: String(s.score / s.marking_component.weight),
                status: 'idle',
            };
        }

        return map;
    }, [scores]);

    const [cells, setCells] = useState<Record<string, CellState>>(initialCells);
    const debounceRef = useRef<Record<string, ReturnType<typeof setTimeout>>>(
        {},
    );
    const savedFlashRef = useRef<Record<string, ReturnType<typeof setTimeout>>>(
        {},
    );

    useEffect(() => {
        const getOverlappingMC = async () => {
            const curriculumId = cs.curriculum?.id;

            if (!curriculumId) {
                setOverlappingMC([]);

                return;
            }

            const response = await axios.get(
                `/api/marking-components/overlapping/${curriculumId}`,
            );
            setOverlappingMC(response.data.overlapping);
        };
        getOverlappingMC();
    }, [cs.curriculum?.id, markingComponents]);

    // Clean up timers on unmount.
    useEffect(() => {
        return () => {
            // eslint-disable-next-line react-hooks/exhaustive-deps
            Object.values(debounceRef.current).forEach(clearTimeout);
            // eslint-disable-next-line react-hooks/exhaustive-deps
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
            // if (raw.trim() === '') {
            //     setCell(key, { value: '', status: 'idle', error: undefined });

            //     return;
            // }
            // if (
            //     cs.result_status?.status === 'submitted' ||
            //     cs.result_status?.status === 'approved'
            // ) {
            //     return;
            // }

            const num = Number(raw);

            const max = maxForComponent(mc);
            const value = (num / 100) * max;

            if (!Number.isFinite(value) || value < 0 || value > max) {
                setCell(key, {
                    status: 'error',
                    error: `0–${100}`,
                });

                return;
            }

            setCell(key, { status: 'saving', error: undefined });

            try {
                const payload = {
                    curriculum_subject_id: cs.id,
                    student_id: studentId,
                    marking_component_id: mc.id,
                    score: value,
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
                            error?: string;
                        };
                    };
                };
                const msg =
                    err?.response?.data?.errors?.score?.[0] ??
                    err?.response?.data?.message ??
                    err?.response?.data?.error ??
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

                const n = mc.weight * Number(v);

                if (Number.isFinite(n)) {
                    total += n;
                    anyValue = true;
                }
            }

            return anyValue ? (Math.round(total * 100) / 100).toFixed(1) : null;
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

                <NumericGradingReference boundaries={gradeBoundaries} />

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
                                            / 100
                                        </div>
                                    </th>
                                ))}
                                <th className="px-3 py-3 text-right font-medium text-gray-700">
                                    {cs.curriculum?.is_ccm ? 'CCM' : 'Total'}{' '}
                                    Score
                                </th>
                                {!cs.curriculum?.is_ccm && <th>Comment</th>}
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
                                                        status={status}
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
                                                        disabled={
                                                            overlappingMC.includes(
                                                                mc.name,
                                                            ) &&
                                                            cell.value !== ''
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
                                        {!cs.curriculum?.is_ccm && (
                                            <td>
                                                <CommentCell
                                                    studentSubject={s}
                                                    total={total}
                                                    locked={
                                                        status.status ===
                                                        'approved'
                                                    }
                                                />
                                            </td>
                                        )}
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

function CategoricalEntryPage({
    cs,
    status,
}: {
    cs: CurriculumSubject;
    status: SubjectResultStatus;
}) {
    const items = cs.curriculum?.grading_scheme?.items ?? [];
    const students = cs.students ?? [];
    const initial = Object.fromEntries(
        (cs.student_results ?? []).map((result) => [
            result.student.id,
            result.grading_item?.id ?? '',
        ]),
    );
    const [ratings, setRatings] = useState<Record<string, string>>(initial);
    const [saving, setSaving] = useState<Set<string>>(new Set());
    const [query, setQuery] = useState('');
    const locked = ['submitted', 'approved'].includes(status.status);
    const filtered = students.filter((assignment) => {
        const student = assignment.student_curriculum?.student;
        const haystack =
            `${student?.first_name ?? ''} ${student?.last_name ?? ''} ${student?.admission_number ?? ''}`.toLowerCase();

        return haystack.includes(query.toLowerCase());
    });

    const saveRating = async (studentId: string, itemId: string) => {
        const previous = ratings[studentId] ?? '';
        setRatings((current) => ({ ...current, [studentId]: itemId }));
        setSaving((current) => new Set(current).add(studentId));
        try {
            await axios.put(
                `/api/curriculum-subjects/${cs.id}/categorical-results/${studentId}`,
                { grading_scheme_item_id: itemId },
            );
        } catch {
            setRatings((current) => ({ ...current, [studentId]: previous }));
        } finally {
            setSaving((current) => {
                const next = new Set(current);
                next.delete(studentId);

                return next;
            });
        }
    };

    return (
        <>
            <Head title={`Enter ratings — ${cs.subject.name}`} />
            <div className="space-y-5 p-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">
                        {cs.subject.name} — Progress Ratings
                    </h1>
                    <p className="text-sm text-gray-500">
                        {cs.curriculum?.full_name} ·{' '}
                        {cs.curriculum?.grading_scheme?.name}
                    </p>
                </div>
                <CategoricalGradingReference
                    name={cs.curriculum?.grading_scheme?.name}
                    items={items}
                />
                <input
                    type="search"
                    placeholder="Search by name or admission number…"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    className="w-72 rounded-md border border-gray-300 px-3 py-2 text-sm"
                />
                <div className="overflow-hidden rounded-lg border bg-white shadow-sm">
                    <table className="min-w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-gray-700">
                                    Student
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-700">
                                    Progress rating
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-700">
                                    Description
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filtered.map((assignment) => {
                                const student =
                                    assignment.student_curriculum!.student;
                                const selected = items.find(
                                    (item) => item.id === ratings[student.id],
                                );

                                return (
                                    <tr key={assignment.id}>
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {fullName(student)}
                                            </div>
                                            <div className="text-xs text-gray-500">
                                                {student.admission_number}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <select
                                                value={
                                                    ratings[student.id] ?? ''
                                                }
                                                disabled={
                                                    locked ||
                                                    saving.has(student.id)
                                                }
                                                onChange={(event) =>
                                                    saveRating(
                                                        student.id,
                                                        event.target.value,
                                                    )
                                                }
                                                className="min-w-52 rounded-md border border-gray-300 px-3 py-2"
                                            >
                                                <option value="" disabled>
                                                    Select rating
                                                </option>
                                                {items.map(
                                                    (
                                                        item: GradingSchemeItem,
                                                    ) => (
                                                        <option
                                                            key={item.id}
                                                            value={item.id}
                                                        >
                                                            {item.code} —{' '}
                                                            {item.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {selected?.label ?? '—'}
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

function NumericGradingReference({
    boundaries,
}: {
    boundaries: GradeBoundary[];
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
                <h2 className="text-sm font-semibold text-gray-900">
                    Grade boundaries
                </h2>
                <p className="text-xs text-gray-500">
                    The score ranges used for this curriculum.
                </p>
            </div>
            {boundaries.length === 0 ? (
                <p className="px-4 py-5 text-sm text-gray-500">
                    No grade boundaries are configured.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                            <tr>
                                <th className="px-4 py-2">Grade</th>
                                <th className="px-4 py-2">Range</th>
                                <th className="px-4 py-2">Label</th>
                                <th className="px-4 py-2">Grade point</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {boundaries.map((boundary) => (
                                <tr key={boundary.id}>
                                    <td className="px-4 py-2 font-semibold">
                                        {boundary.grade}
                                    </td>
                                    <td className="px-4 py-2 tabular-nums">
                                        {boundary.min_score}–
                                        {boundary.max_score}
                                    </td>
                                    <td className="px-4 py-2">
                                        {boundary.label}
                                    </td>
                                    <td className="px-4 py-2">
                                        {boundary.grade_point}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function CategoricalGradingReference({
    name,
    items,
}: {
    name?: string;
    items: GradingSchemeItem[];
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
                <h2 className="text-sm font-semibold text-gray-900">
                    {name ?? 'Categorical grading scheme'}
                </h2>
                <p className="text-xs text-gray-500">
                    Select one of these progress ratings for each student.
                </p>
            </div>
            <table className="w-full text-sm">
                <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr>
                        <th className="px-4 py-2">Code</th>
                        <th className="px-4 py-2">Description</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {items.map((item) => (
                        <tr key={item.id}>
                            <td className="px-4 py-2 font-semibold">
                                {item.code}
                            </td>
                            <td className="px-4 py-2">{item.label}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ---------- Sub-components ----------

function CommentCell({
    studentSubject,
    total,
    locked,
}: {
    studentSubject: StudentSubject;
    total?: number;
    locked: boolean;
}) {
    const [value, setValue] = useState(studentSubject.comment ?? '');
    const [status, setStatus] = useState<CellStatus>('idle');
    const [error, setError] = useState('');

    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    function getCommentsForScore(score: number) {
        if (score >= 91) {
            return OUTSTANDING_COMMENTS;
        }

        if (score >= 80) {
            return EXCELLENT_COMMENTS;
        }

        if (score >= 70) {
            return VERY_GOOD_COMMENTS;
        }

        if (score >= 60) {
            return GOOD_COMMENTS;
        }

        if (score >= 50) {
            return FAIR_COMMENTS;
        }

        if (score >= 40) {
            return NEEDS_IMPROVEMENT_COMMENTS;
        }

        return POOR_COMMENTS;
    }

    // Adjust this to match your model
    const score = Number(total ?? 0);

    const commentOptions = useMemo(() => getCommentsForScore(score), [score]);

    const isValid = (val: string) => {
        if (val.length > 100) {
            setError('Maximum 100 characters allowed');
            setStatus('error');

            return false;
        }

        setError('');

        return true;
    };

    const persist = async (studentSubjectId: string, comment: string) => {
        try {
            setStatus('saving');

            await axios.post(
                `/api/student-subjects/${studentSubjectId}/comment`,
                {
                    comment,
                },
            );

            setStatus('saved');
        } catch (e: any) {
            setStatus('error');
            setError(e?.message || 'Failed to save');
        }
    };

    const triggerSave = (val: string) => {
        if (locked) {
            return;
        }

        const trimmed = val.trim();

        if (!trimmed) {
            setStatus('idle');

            return;
        }

        if (!isValid(trimmed)) {
            return;
        }

        persist(studentSubject.id, trimmed);
    };

    const onChange = (val: string) => {
        if (locked) {
            return;
        }

        setValue(val);

        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        timerRef.current = setTimeout(() => {
            triggerSave(val);
        }, 3000);
    };

    const onBlur = () => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        triggerSave(value);
    };

    useEffect(() => {
        return () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
        };
    }, []);

    useEffect(() => {
        if (locked && timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, [locked]);

    const borderClass =
        status === 'error'
            ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
            : status === 'saving'
              ? 'border-amber-300 focus:border-amber-500 focus:ring-amber-500'
              : status === 'saved'
                ? 'border-green-400 focus:border-green-500 focus:ring-green-500'
                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500';

    const datalistId = `comment-options-${studentSubject.id}`;

    return (
        <div className="relative min-w-[350px]">
            <input
                type="text"
                list={datalistId}
                value={value}
                disabled={locked}
                onChange={(e) => onChange(e.target.value)}
                onBlur={onBlur}
                placeholder={
                    locked
                        ? 'Comment locked after approval'
                        : 'Select or type comment...'
                }
                className={`w-full rounded-md border px-2 py-1 text-sm shadow-sm focus:ring-1 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 ${borderClass}`}
            />

            <datalist id={datalistId}>
                {commentOptions.map((comment) => (
                    <option key={comment} value={comment} />
                ))}
            </datalist>

            <StatusDot status={status} />

            {status === 'error' && error && (
                <div className="absolute top-full left-0 z-20 mt-1 rounded bg-red-600 px-2 py-0.5 text-xs whitespace-nowrap text-white shadow">
                    {error}
                </div>
            )}
        </div>
    );
}

function ScoreCell({
    cell,
    max,
    onChange,
    onBlur,
    status,
    disabled = false,
}: {
    cell: CellState;
    max: number;
    onChange: (v: string) => void;
    onBlur: () => void;
    status: SubjectResultStatus;
    disabled?: boolean;
}) {
    const [value, setValue] = useState(
        typeof Number(cell.value) === 'number' && cell.value !== ''
            ? Number(cell.value)
            : '',
    );

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
                step="0.1"
                min={0}
                max={max}
                onWheel={(e) => {
                    e.currentTarget.blur();
                }}
                onInput={(e) => {
                    const val = (e.target as HTMLInputElement).value;

                    // allow empty (user deleting)
                    if (val === '') {
                        return;
                    }

                    // blur if not a valid number
                    if (isNaN(Number(val))) {
                        (e.target as HTMLInputElement).blur();
                    }
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        e.preventDefault();
                    }
                }}
                value={typeof value === 'number' ? value.toFixed(1) : value}
                disabled={
                    status.status === 'submitted' ||
                    status.status === 'approved' ||
                    disabled
                }
                onChange={(e) => {
                    setValue(e.target.value);
                    onChange(e.target.value);
                }}
                onBlur={onBlur}
                className={`w-20 [appearance:textfield] rounded-md border px-2 py-1 text-right text-sm shadow-sm focus:ring-1 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none ${borderClass}`}
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
