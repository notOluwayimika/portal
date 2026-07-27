import { Head } from '@inertiajs/react';
import axios from 'axios';
import { ChevronDown } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type {
    CommentBand,
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
/**
 * Longest comment the server will accept, matching `student_subjects.comment` and
 * `CommentEntry::MAX_LENGTH`. This used to read 100 while the column and the server rule both
 * said 50, so a teacher who picked the first suggestion in the lowest band (52 characters) passed
 * this check and got a 422. All three now agree; the server pins the pairing with a test.
 */
const COMMENT_MAX_LENGTH = 100;

// ---------- Helpers ----------

const cellKey = (studentId: string, mcId: string) => `${studentId}:${mcId}`;

/**
 * The comments this school offers for `score`.
 *
 * Bands arrive from the server highest-first, and a score belongs to the highest band whose
 * minimum it reaches — the same shape as the hardcoded ladder this replaced, except the ranges and
 * the wording are now the school's own. An empty result is the legitimate day-one state for a
 * school that has not configured any: the datalist renders empty and free text still saves.
 */
const commentsForScore = (bands: CommentBand[], score: number): string[] =>
    bands
        .find((band) => score >= band.min_score)
        ?.comments.map((entry) => entry.body) ?? [];

/**
 * The comments this school offers for a categorical RATING.
 *
 * Ratings are not mapped onto a 0-100 scale to reuse the numeric bands: a grading scheme carries
 * only code/label/display_order and typically includes entries like "Not Applicable", so ordinal
 * position is not a quality ranking. The comments hang off the rating itself.
 */
const commentsForRating = (
    items: GradingSchemeItem[],
    itemId: string | undefined,
): string[] =>
    (itemId
        ? items.find((item) => item.id === itemId)?.comments
        : undefined
    )?.map((entry) => entry.body) ?? [];

const maxForComponent = (mc: MarkingComponent) => Math.round(mc.weight * 100);

const fullName = (s: Student) =>
    [s.last_name, s.first_name, s.middle_name].filter(Boolean).join(' ');

// ---------- Page ----------

export default function ScoreEntryPage({
    cs,
    status,
    defaultGradeBoundaries = [],
    commentBands = [],
}: {
    cs: CurriculumSubject;
    status: SubjectResultStatus;
    defaultGradeBoundaries?: GradeBoundary[];
    // Already resolved server-side for this subject's exam type, highest band first. Categorical
    // curricula band on a RATING instead, so their comments arrive on the grading scheme items.
    commentBands?: CommentBand[];
}) {
    if (cs.curriculum?.grading_mode === 'categorical') {
        return <CategoricalEntryPage cs={cs} status={status} />;
    }

    return (
        <NumericScoreEntryPage
            cs={cs}
            status={status}
            defaultGradeBoundaries={defaultGradeBoundaries}
            commentBands={commentBands}
        />
    );
}

function NumericScoreEntryPage({
    cs,
    status,
    defaultGradeBoundaries,
    commentBands,
}: {
    cs: CurriculumSubject;
    status: SubjectResultStatus;
    defaultGradeBoundaries: GradeBoundary[];
    commentBands: CommentBand[];
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
            // Same null-student guard as the categorical grid: `student` is school-scoped, so a
            // score whose student the viewer cannot see serializes as null and reading `.id` here
            // took the whole page down. Skipping the row degrades one cell instead.
            if (!s.student || !s.marking_component) {
                continue;
            }

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
                                                    locked={
                                                        status.status ===
                                                        'approved'
                                                    }
                                                    commentOptions={commentsForScore(
                                                        commentBands,
                                                        Number(total ?? 0),
                                                    )}
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
        (cs.student_results ?? [])
            // `student` can be null: it is school-scoped, so a result whose student the viewer
            // cannot see serializes as null. That is meant to be unreachable now the page 404s on
            // a foreign curriculum subject, but this used to crash the whole grid with
            // "can't access property id, student is null" — so the grid skips such rows instead
            // of trusting the guard upstream to be the only thing standing.
            .filter((result) => result.student != null)
            .map((result) => [
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

        if (!student) {
            return false;
        }

        const haystack =
            `${student.first_name ?? ''} ${student.last_name ?? ''} ${student.admission_number ?? ''}`.toLowerCase();

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
                                {!cs.curriculum?.is_ccm && (
                                    <th className="px-4 py-3 text-left font-medium text-gray-700">
                                        Comment
                                    </th>
                                )}
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
                                        {!cs.curriculum?.is_ccm && (
                                            <td className="px-4 py-3">
                                                {/* Suggestions follow the RATING, so they change
                                                    as soon as one is picked — the categorical
                                                    equivalent of the numeric grid re-banding when
                                                    a total changes. */}
                                                <CommentCell
                                                    studentSubject={assignment}
                                                    locked={locked}
                                                    commentOptions={commentsForRating(
                                                        items,
                                                        ratings[student.id],
                                                    )}
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

/**
 * The comment box for one student, in either grid.
 *
 * It takes the suggestion list ALREADY RESOLVED rather than resolving it itself, which is what
 * lets the numeric and categorical pages share it: numeric resolves by score, categorical by
 * rating, and neither difference reaches this component. Suggestions ship with the page, so this
 * stays a pure function of its props — no fetch per row and no N+1 on a grid of hundreds.
 */
function CommentCell({
    studentSubject,
    locked,
    commentOptions,
}: {
    studentSubject: StudentSubject;
    locked: boolean;
    commentOptions: string[];
}) {
    const [value, setValue] = useState(studentSubject.comment ?? '');
    const [status, setStatus] = useState<CellStatus>('idle');
    const [error, setError] = useState('');
    const [open, setOpen] = useState(false);
    const [highlight, setHighlight] = useState(-1);
    // The menu is positioned FIXED, not absolute: both grids sit inside a wrapper with
    // `overflow-x-auto` / `overflow-hidden`, which clips an absolutely positioned child. Anchoring
    // to the input's viewport rect is what lets the list escape the scrolling table.
    const [menuRect, setMenuRect] = useState<{
        top: number;
        left: number;
        width: number;
    } | null>(null);

    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const menuRef = useRef<HTMLUListElement | null>(null);

    const isValid = (val: string) => {
        if (val.length > COMMENT_MAX_LENGTH) {
            setError(`Maximum ${COMMENT_MAX_LENGTH} characters allowed`);
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

        closeMenu();
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

    // ── Suggestion menu ────────────────────────────────────────────────────

    // Substring match, so "keep" finds "Outstanding performance. Keep it up". A value that already
    // equals a suggestion shows the whole list rather than just itself — otherwise picking one
    // suggestion would hide every alternative the moment it was chosen.
    const query = value.trim().toLowerCase();
    const filtered =
        query === '' || commentOptions.includes(value)
            ? commentOptions
            : commentOptions.filter((option) =>
                  option.toLowerCase().includes(query),
              );

    const canSuggest = !locked && commentOptions.length > 0;

    const positionMenu = useCallback(() => {
        const rect = inputRef.current?.getBoundingClientRect();

        if (rect) {
            setMenuRect({
                top: rect.bottom + 4,
                left: rect.left,
                width: rect.width,
            });
        }
    }, []);

    const openMenu = () => {
        if (!canSuggest) {
            return;
        }

        positionMenu();
        setOpen(true);
    };

    const closeMenu = () => {
        setOpen(false);
        setHighlight(-1);
    };

    // A fixed-position menu does not travel with its anchor, so it has to be re-measured while the
    // page or the table scrolls. `capture: true` catches the table's own scroll container, not
    // just the window.
    useEffect(() => {
        if (!open) {
            return;
        }

        const reposition = () => positionMenu();
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return () => {
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        };
    }, [open, positionMenu]);

    // Keep the highlighted row visible when arrowing past the edge of the list.
    useEffect(() => {
        if (!open || highlight < 0) {
            return;
        }

        menuRef.current?.children[highlight]?.scrollIntoView({
            block: 'nearest',
        });
    }, [open, highlight]);

    /**
     * Commit a suggestion. Saves IMMEDIATELY rather than waiting out the debounce: picking from a
     * list is a deliberate act, and the 3s wait exists for typing, not for clicking.
     */
    const choose = (option: string) => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        setValue(option);
        closeMenu();
        triggerSave(option);
        inputRef.current?.focus();
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (!canSuggest) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (!open) {
                openMenu();
                setHighlight(0);

                return;
            }

            const step = event.key === 'ArrowDown' ? 1 : -1;
            setHighlight((current) => {
                const next = current + step;

                if (next < 0) {
                    return filtered.length - 1;
                }

                return next >= filtered.length ? 0 : next;
            });

            return;
        }

        if (event.key === 'Enter' && open && highlight >= 0) {
            // Only swallow Enter when a row is actually highlighted; otherwise it stays a plain
            // "I'm done typing" and the normal save path handles it.
            event.preventDefault();
            choose(filtered[highlight]);

            return;
        }

        if (event.key === 'Escape' && open) {
            event.preventDefault();
            closeMenu();
        }
    };

    const borderClass =
        status === 'error'
            ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
            : status === 'saving'
              ? 'border-amber-300 focus:border-amber-500 focus:ring-amber-500'
              : status === 'saved'
                ? 'border-green-400 focus:border-green-500 focus:ring-green-500'
                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500';

    const menuId = `comment-options-${studentSubject.id}`;

    return (
        <div className="relative min-w-[350px]">
            <input
                ref={inputRef}
                type="text"
                role="combobox"
                aria-expanded={open}
                aria-controls={menuId}
                aria-autocomplete="list"
                aria-activedescendant={
                    open && highlight >= 0
                        ? `${menuId}-${highlight}`
                        : undefined
                }
                value={value}
                disabled={locked}
                onChange={(e) => {
                    onChange(e.target.value);

                    // Typing filters the list, so show it — but never force it open on a value
                    // that matches nothing, which would flash an empty box mid-keystroke.
                    if (canSuggest) {
                        setHighlight(-1);
                        openMenu();
                    }
                }}
                onKeyDown={onKeyDown}
                onBlur={onBlur}
                placeholder={
                    locked
                        ? 'Comment locked after approval'
                        : canSuggest
                          ? 'Select or type comment…'
                          : 'Type a comment…'
                }
                className={`w-full rounded-md border py-1 pl-2 text-sm shadow-sm focus:ring-1 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 ${
                    canSuggest ? 'pr-8' : 'pr-2'
                } ${borderClass}`}
            />

            {/* The affordance. Without it nothing on screen says suggestions exist — the
                placeholder is the only hint and it disappears as soon as a comment is saved,
                which is most of the time. Hidden entirely when the school has configured no
                comments, so an empty bank leaves a plain text box rather than a dead control. */}
            {canSuggest && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-label="Show comment suggestions"
                    title="Show comment suggestions"
                    // mousedown, not click: `click` fires after the input's blur, which would
                    // close the menu we just opened (and fire a save mid-interaction).
                    onMouseDown={(event) => {
                        event.preventDefault();

                        if (open) {
                            closeMenu();
                        } else {
                            openMenu();
                            inputRef.current?.focus();
                        }
                    }}
                    className="absolute inset-y-0 right-0 flex w-7 items-center justify-center text-gray-400 hover:text-gray-600"
                >
                    <ChevronDown
                        className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`}
                        aria-hidden
                    />
                </button>
            )}

            {open && menuRect && filtered.length > 0 && (
                <ul
                    ref={menuRef}
                    id={menuId}
                    role="listbox"
                    style={{
                        position: 'fixed',
                        top: menuRect.top,
                        left: menuRect.left,
                        width: menuRect.width,
                    }}
                    className="z-50 max-h-56 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg"
                >
                    {filtered.map((option, index) => (
                        <li
                            key={option}
                            id={`${menuId}-${index}`}
                            role="option"
                            aria-selected={option === value}
                            // mousedown so the input never blurs — a blur here would save the
                            // half-typed value and close the menu before the click landed.
                            onMouseDown={(event) => {
                                event.preventDefault();
                                choose(option);
                            }}
                            onMouseEnter={() => setHighlight(index)}
                            className={`cursor-pointer px-3 py-1.5 ${
                                index === highlight
                                    ? 'bg-indigo-50 text-indigo-900'
                                    : 'text-gray-700'
                            }`}
                        >
                            {option}
                        </li>
                    ))}
                </ul>
            )}

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
