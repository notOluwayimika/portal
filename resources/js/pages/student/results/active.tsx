import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { handleBack } from '@/helpers';
import type {
    CurriculumSubject,
    GradeBoundary,
    Student,
    StudentCurriculum,
} from '@/types/models';

interface StudentResultPageProps {
    student: { data: Student };
    studentCurricula: { data: StudentCurriculum[] };
    defaultGradeBoundaries: { data: GradeBoundary[] };
    [key: string]: unknown;
}

interface ResultRow {
    key: string | number;
    name: string;
    code: string;
    compulsory: boolean;
    score: number | null;
    grade: string;
    classAvg: number | null;
    classAvgGrade: string;
}

// Single source of truth for the school name — used by the header AND the
// printed watermark. Swap this for `student.data.school?.name` (or a page
// prop) once that data is available.
export const SCHOOL_NAME = 'Brookstone School';

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------
const toNum = (v: string | number): number =>
    typeof v === 'number' ? v : parseFloat(v);

function gradeForScore(
    score: number | null,
    boundaries: GradeBoundary[],
): string {
    if (score == null || Number.isNaN(score)) {
        return '—';
    }

    const flooredScore = Math.floor(score);

    for (const b of boundaries) {
        const min = toNum(b.min_score);
        const max = toNum(b.max_score);

        if (flooredScore >= min && flooredScore <= max) {
            return b.grade;
        }
    }

    // include the very top edge in the highest band
    const top = boundaries[0];

    if (top && flooredScore >= toNum(top.max_score)) {
        return top.grade;
    }

    return '—';
}
function nextGradeForScore(
    score: number | null,
    boundaries: GradeBoundary[],
): string {
    if (score == null || Number.isNaN(score)) {
        return '—';
    }

    const flooredScore = Math.floor(score);

    for (let i = 0; i < boundaries.length; i++) {
        const b = boundaries[i];

        const min = toNum(b.min_score);
        const max = toNum(b.max_score);

        if (flooredScore >= min && flooredScore <= max) {
            // return the grade ABOVE the current one
            return boundaries[i - 1]?.grade ?? b.grade;
        }
    }

    // handle top edge
    const top = boundaries[0];

    if (top && flooredScore >= toNum(top.max_score)) {
        return top.grade; // already highest
    }

    return '—';
}
function totalGradePoint(row: ResultRow[], boundaries: GradeBoundary[]) {
    let GP = 0;
    let count = 0;
    row.forEach((r) => {
        if (r.score) {
            const flooredScore = Math.floor(r.score);
            GP += toNum(gradePointForScore(flooredScore, boundaries));
            count++;
        }
    });

    return count > 0 ? (GP / count).toFixed(1) : '—';
}
function gradePointForScore(
    score: number | null,
    boundaries: GradeBoundary[],
): string {
    if (score == null || Number.isNaN(score)) {
        return '—';
    }

    const flooredScore = Math.floor(score);

    for (const b of boundaries) {
        const min = toNum(b.min_score);
        const max = toNum(b.max_score);

        if (flooredScore >= min && flooredScore <= max) {
            return b.grade_point;
        }
    }

    // include the very top edge in the highest band
    const top = boundaries[0];

    if (top && flooredScore >= toNum(top.max_score)) {
        return top.grade_point;
    }

    return '—';
}

// ---------------------------------------------------------------------------
// Print styles — kept inline so this component is fully self-contained.
// ---------------------------------------------------------------------------
export const PRINT_STYLES = `
@media print {
    @page {
        size: A4;
        margin: 1cm;
    }
    html, body {
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .student-result-watermark {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .student-result-card {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}
`;

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------
interface CurriculumCardProps {
    sc: StudentCurriculum;
    defaultBoundaries: GradeBoundary[];
    studentId: number | string;
    student: Student;
}

export function CurriculumCard({
    sc,
    defaultBoundaries,
    studentId,
    student,
}: CurriculumCardProps) {
    const { auth } = usePage().props;
    const roles = auth.roles;
    const boundaries =
        sc.curriculum.exam_type?.grade_boundaries?.length &&
        sc.curriculum.exam_type?.grade_boundaries?.length > 0
            ? sc.curriculum.exam_type?.grade_boundaries
            : defaultBoundaries;

    const rows = useMemo<ResultRow[]>(() => {
        const subjects = (sc.subjects || [])
            .slice()
            .sort(
                (a, b) =>
                    (a.curriculum_subject?.display_order ?? 0) -
                    (b.curriculum_subject?.display_order ?? 0),
            );

        return subjects.map((ss): ResultRow => {
            const cs = ss.curriculum_subject || ({} as CurriculumSubject);
            const results = cs.student_results || [];
            const name =
                cs.subject?.name || `Subject ${cs.subject_id ?? ''}`.trim();
            const code = cs.subject?.code || '';

            const own = results.find((r) => r.student?.id === studentId);
            const score = own ? toNum(own.total_score) : null;
            const grade = own?.grade || gradeForScore(score, boundaries);

            const scored = results
                .map((r) => toNum(r.total_score))
                .filter((n) => !Number.isNaN(n) && n !== 0);
            const classAvg = scored.length
                ? scored.reduce((s, n) => s + n, 0) / scored.length
                : null;

            return {
                key: cs.id ?? name,
                name,
                code,
                compulsory: cs.is_compulsory,
                score,
                grade,
                classAvg,
                classAvgGrade: gradeForScore(classAvg, boundaries),
            };
        });
    }, [sc, studentId, boundaries]);
    const isGuardian = roles.includes('guardian');
    const hasIncompleteResults = rows.some((r) => r.score === null);
    const resultsIncomplete = isGuardian && hasIncompleteResults;

    const overall = useMemo<number | null>(() => {
        const vals = rows
            .map((r) => r.score)
            .filter((n): n is number => n != null && !Number.isNaN(n));

        if (!vals.length) {
            return null;
        }

        return vals.reduce((s, n) => s + n, 0) / vals.length;
    }, [rows]);

    return (
        <div className="student-result-card overflow-hidden border border-slate-300">
            <div className="flex items-center justify-between px-1">
                <div>
                    <p className="text-xs text-black">
                        <span className="inline-block w-12 font-bold">
                            Name:
                        </span>
                        {student.last_name}, {student.first_name}{' '}
                        {student.middle_name}
                    </p>

                    <p className="text-xs text-black">
                        <span className="inline-block w-12 font-bold">
                            Form:
                        </span>
                        {student.class_details.full_class}
                    </p>
                </div>
                {/* <span className="rounded bg-blue-700 px-2 py-1 text-xs font-medium text-white">
                    {rows.length} subjects
                </span> */}
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-xs">
                    <thead>
                        <tr className="bg-blue-100 text-center text-black">
                            <th className="border border-slate-300 px-1 font-semibold">
                                Subject
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                Score %
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                Grade
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                GP
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                Class Avg
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>Target Grade</div>
                                <div>by End of Term</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {resultsIncomplete ? (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="border border-slate-300 px-4 py-6 text-center text-xs text-slate-500"
                                >
                                    Result incomplete — please check back later.
                                </td>
                            </tr>
                        ) : (
                            rows.map((r, i) => (
                                <tr
                                    key={r.key}
                                    className={
                                        i % 2 ? 'bg-slate-50' : 'bg-white'
                                    }
                                >
                                    <td className="border border-slate-300 px-1">
                                        <span className="font-medium text-slate-800">
                                            {r.name}
                                        </span>
                                        {/* {r.code && (
                                        <span className="ml-2 text-xs text-slate-400">
                                            {r.code}
                                        </span>
                                    )} */}
                                        {/* {r.compulsory && (
                                        <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">
                                            Core
                                        </span>
                                    )} */}
                                    </td>
                                    <td className="border border-slate-300 px-1 text-center tabular-nums">
                                        {r.score != null
                                            ? r.score.toFixed(1)
                                            : '—'}
                                    </td>
                                    <td
                                        className={`border border-slate-300 px-1 text-center font-semibold text-black`}
                                    >
                                        {r.grade}
                                    </td>
                                    <td
                                        className={`border border-slate-300 px-1 text-center font-semibold text-black`}
                                    >
                                        {gradePointForScore(
                                            r.score,
                                            boundaries,
                                        )}
                                    </td>

                                    <td className="border border-slate-300 px-1 text-center text-slate-600 tabular-nums">
                                        {r.classAvg != null
                                            ? r.classAvg.toFixed(1)
                                            : '—'}
                                        {/* {r.classAvg != null && (
                                        <span className="ml-1 text-xs text-slate-400">
                                            ({r.classAvgGrade})
                                        </span>
                                    )} */}
                                    </td>
                                    <td className="border border-slate-300 px-1 text-center text-slate-600 tabular-nums">
                                        {nextGradeForScore(r.score, boundaries)}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                    {overall != null && !resultsIncomplete && (
                        <tfoot>
                            <tr className="bg-blue-300 font-semibold text-black">
                                <td className="border border-slate-300 px-1">
                                    Actual GPA
                                </td>
                                <td></td>
                                <td></td>
                                <td className="border border-slate-300 px-1 text-center">
                                    {totalGradePoint(rows, boundaries)}
                                </td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}

interface GradeKeyTableProps {
    boundaries: GradeBoundary[];
}

export function GradeKeyTable({ boundaries }: GradeKeyTableProps) {
    return (
        <div className="overflow-hidden border border-slate-300 shadow-sm">
            <div className="bg-slate-700 px-4">
                <h3 className="text-sm font-bold text-white">Keys</h3>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-xs">
                    <thead>
                        <tr className="bg-slate-100 text-left text-slate-700">
                            <th className="border border-slate-300 px-1 font-semibold">
                                Grade
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Score Range
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Label
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Grade Point
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {boundaries.map((b, i) => (
                            <tr
                                key={b.id ?? b.grade}
                                className={i % 2 ? 'bg-slate-50' : 'bg-white'}
                            >
                                <td
                                    className={`border border-slate-300 px-1 font-bold text-black`}
                                >
                                    {b.grade}
                                </td>
                                <td className="border border-slate-300 px-1 text-slate-600 tabular-nums">
                                    {toNum(b.min_score)} – {toNum(b.max_score)}
                                </td>
                                <td className="border border-slate-300 px-1 text-slate-700">
                                    {b.label}
                                </td>
                                <td className="border border-slate-300 px-1 text-slate-700">
                                    {b.grade_point}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export default function StudentResultTable() {
    const { student, studentCurricula, defaultGradeBoundaries } =
        usePage<StudentResultPageProps>().props;
    const curricula: StudentCurriculum[] = studentCurricula.data || [];

    const handlePrint = () => {
        window.print();
    };

    return (
        <>
            <style>{PRINT_STYLES}</style>

            {/*
              Print watermark — fixed-position element. Browsers repeat
              fixed-positioned content on every printed page, so this draws
              the school logo on each sheet. Hidden on screen via `hidden`,
              shown in print via `print:flex`.

              To use a school logo from your data instead of AppLogoIcon, swap
              the <AppLogoIcon ... /> for:
                  <img
                      src={student.data.school?.logo_url ?? '/fallback-logo.png'}
                      alt=""
                      className="h-[500px] w-[500px] object-contain"
                  />
            */}
            <div
                aria-hidden="true"
                className="student-result-watermark pointer-events-none fixed inset-0 top-1/2 left-1/2 z-9999 hidden -translate-x-1/2 -translate-y-1/2 items-center justify-center print:flex"
            >
                <div
                    className="select-none"
                    style={{
                        opacity: 0.05,
                    }}
                >
                    <AppLogoIcon className="h-125! w-125!" />
                </div>
            </div>

            <div className="relative z-10 mx-auto max-w-3xl p-4 font-sans text-slate-800">
                <div className="flex items-center justify-between print:hidden">
                    <button
                        className="btn btn-ghost btn-sm btn-icon cursor-pointer p-4"
                        onClick={handleBack}
                        title="Back to curricula"
                        style={{ fontSize: 14 }}
                    >
                        ← Go back
                    </button>
                    {curricula.length > 0 && (
                        <button
                            type="button"
                            onClick={handlePrint}
                            className="rounded bg-blue-700 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-800"
                        >
                            Print / Save as PDF
                        </button>
                    )}
                </div>

                <div className="flex items-center justify-center">
                    <AppLogoIcon />
                </div>
                <div className="mb-1 text-center">
                    <h1 className="text-lg font-bold uppercase">
                        {SCHOOL_NAME}
                    </h1>
                    <p className="text-sm text-slate-600">
                        SECONDARY AND FOUNDATION(PRE-DEGREE)
                    </p>
                    <p className="text-sm text-slate-600">
                        International Airport Road Igwuruta
                    </p>
                    <p className="text-sm text-slate-600">
                        Website: www.brookstoneng.org
                    </p>
                    {curricula.length > 0 && (
                        <div className="mt-2">
                            <p className="text-sm font-bold text-slate-600">
                                {curricula[0].curriculum.is_ccm
                                    ? 'CROSS CURRICULAR MONITORING'
                                    : ''}
                            </p>
                            <p className="text-sm font-bold text-slate-600">
                                {curricula[0].curriculum.term?.full_name}
                            </p>
                        </div>
                    )}
                </div>

                {curricula.length === 0 && (
                    <p className="rounded border border-slate-300 bg-slate-50 px-3 py-4 text-sm text-slate-500">
                        No results to display, once the results are available
                        you will be able to view them here.
                    </p>
                )}
                <div className="">
                    {curricula.map((sc) => {
                        return (
                            <CurriculumCard
                                key={sc.id}
                                sc={sc}
                                defaultBoundaries={defaultGradeBoundaries.data}
                                studentId={student.data.id}
                                student={student.data}
                            />
                        );
                    })}
                    <div className="grid grid-cols-2">
                        <div></div>
                        {curricula.map((sc, i) => {
                            const boundaries =
                                sc.curriculum.exam_type?.grade_boundaries
                                    ?.length &&
                                sc.curriculum.exam_type?.grade_boundaries
                                    ?.length > 0
                                    ? sc.curriculum.exam_type?.grade_boundaries
                                    : defaultGradeBoundaries.data;

                            return i === 0 ? (
                                <GradeKeyTable boundaries={boundaries} />
                            ) : null;
                        })}
                    </div>
                </div>
                <div className="my-1 flex w-full p-1 text-xs font-extralight italic">
                    <div>
                        <img
                            src="/assets/images/signature_secondary.png"
                            alt="Brookstone School"
                            className={`h-16 w-auto sm:h-20`}
                            draggable={false}
                        />
                        <p>Principal's Signature</p>
                    </div>
                </div>
                <div className="my-1 w-full border border-black p-1 text-xs font-extralight italic">
                    <p>
                        Parents and students are encouraged to attend CCM
                        meetings at the beginning of every term and half term to
                        review results.
                    </p>
                </div>
            </div>
        </>
    );
}
