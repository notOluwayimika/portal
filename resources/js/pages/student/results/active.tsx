import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { CurriculumCardFinal } from '@/components/curriculum-card-final';
import type {
    CurriculumCardProps,
    ResultRow,
} from '@/components/student-results/shared';
import {
    gradeForScore,
    GradeKeyTable,
    gradePointForScore,
    nextGradeForScore,
    toNum,
    totalGradePoint,
} from '@/components/student-results/shared';
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
    /*
     * The table itself is allowed to break across pages (a curriculum with
     * many subjects/long comments can be taller than one sheet) — only
     * individual rows are protected so a subject's row never splits across
     * a page boundary and gets visually cut in half.
     */
    .student-result-card tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    /*
     * The table wrappers use overflow-x-auto/overflow-hidden on screen
     * (so a wide table scrolls instead of blowing out the layout), but a
     * scrollable/clipped region only shows its current viewport when
     * printed — the rest of the content gets cut off. Let it flow freely
     * on the page instead.
     */
    .print-page .overflow-hidden,
    .print-page .overflow-x-auto {
        overflow: visible;
    }
    /*
     * Each curriculum's full report (header + card + grade key + comments)
     * is grouped under .print-page so it starts on its own sheet and never
     * shares a page with the next curriculum. Height is intentionally left
     * auto so a report taller than one sheet flows onto following pages
     * instead of being clipped.
     */
    .print-page {
        page-break-after: always;
        break-after: page;
    }
    .print-page:last-child {
        page-break-after: auto;
        break-after: auto;
    }
}
`;

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------
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
            const name =
                cs.subject?.name || `Subject ${cs.subject_id ?? ''}`.trim();
            const code = cs.subject?.code || '';

            const own = ss.own_result;
            const score = own ? toNum(own.total_score) : null;
            const grade = own?.grade || gradeForScore(score, boundaries);

            const classAvg =
                cs.class_average != null ? toNum(cs.class_average) : null;

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

export function ResultDetails({
    curricula,
    picture,
}: {
    curricula: StudentCurriculum;
    picture?: string;
}) {
    const { auth } = usePage().props;
    const school = auth.school;

    return (
        <div className="relative">
            <div className="flex items-center justify-center">
                <AppLogoIcon />
            </div>
            <div className="mb-1 text-center leading-tight">
                <h1 className="text-lg font-bold uppercase">
                    {school?.name ?? 'School'}
                </h1>
                {school?.name_on_result && (
                    <p className="text-sm text-slate-600">
                        {school.name_on_result}
                    </p>
                )}
                {school?.address && (
                    <p className="text-sm text-slate-600">{school.address}</p>
                )}
                {school?.website && (
                    <p className="text-sm text-slate-600">
                        Website: {school.website}
                    </p>
                )}
                <div className="mt-1">
                    <p className="text-sm font-bold text-slate-600">
                        {curricula.curriculum.is_ccm
                            ? 'CROSS CURRICULAR MONITORING'
                            : 'END OF TERM RESULT'}
                    </p>
                    <p className="text-sm font-bold text-slate-600">
                        {curricula.curriculum.term?.full_name}
                    </p>
                </div>
            </div>
            <div className="absolute right-0 bottom-0">
                {/* if picture exists display image other wise display avatar placeholder image */}
                {picture ? (
                    <img
                        src={picture}
                        alt="Student"
                        className="h-32 w-28 border-2 border-black object-cover"
                    />
                ) : (
                    <img
                        src={
                            'https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png'
                        }
                        alt="Student"
                        className="h-20 w-20 border-2 border-black object-cover"
                    />
                )}
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
        // Browsers use document.title as the default "Save as PDF" filename,
        // so swap it to SurnameFirstname_session_term_date for the dialog
        // and restore it afterwards.
        const curriculum = curricula[0]?.curriculum;
        const sanitize = (v: string) => v.replace(/[^\w-]+/g, '');
        const fileName = [
            `${sanitize(student.data.last_name)}${sanitize(student.data.first_name)}`,
            sanitize(
                (curriculum?.academic_session?.name ?? '').replace(/\//g, '-'),
            ),
            sanitize(curriculum?.term?.name ?? ''),
            curriculum?.is_ccm ? 'CCM' : '',
            new Date().toISOString().slice(0, 10),
        ]
            .filter(Boolean)
            .join('_');

        const originalTitle = document.title;
        document.title = fileName;
        window.print();
        document.title = originalTitle;
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

            <div className="relative z-10 mx-auto max-w-3xl p-4 font-sans text-slate-800 print:max-w-none print:p-0">
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

                {curricula.length === 0 && (
                    <p className="rounded border border-slate-300 bg-slate-50 px-3 py-4 text-sm text-slate-500">
                        No results to display, once the results are available
                        you will be able to view them here.
                    </p>
                )}
                <div className="overflow-x-auto print:overflow-visible">
                    {curricula.map((sc) => {
                        const boundaries =
                            sc.curriculum.exam_type?.grade_boundaries?.length &&
                            sc.curriculum.exam_type?.grade_boundaries?.length >
                                0
                                ? sc.curriculum.exam_type?.grade_boundaries
                                : defaultGradeBoundaries.data;

                        if (
                            sc.curriculum.is_ccm &&
                            sc.curriculum.grading_mode !== 'categorical'
                        ) {
                            return (
                                <div
                                    key={sc.id}
                                    className="print-page min-w-160 print:min-w-0"
                                >
                                    <ResultDetails
                                        curricula={sc}
                                        picture={student.data.photo}
                                    />
                                    <CurriculumCard
                                        sc={sc}
                                        defaultBoundaries={
                                            defaultGradeBoundaries.data
                                        }
                                        studentId={student.data.id}
                                        student={student.data}
                                    />
                                    <div className="grid grid-cols-2">
                                        <div></div>
                                        <GradeKeyTable
                                            boundaries={boundaries}
                                        />
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
                                            Parents and students are encouraged
                                            to attend CCM meetings at the
                                            beginning of every term and half
                                            term to review results.
                                        </p>
                                    </div>
                                </div>
                            );
                        } else {
                            return (
                                <div
                                    key={sc.id}
                                    className="print-page min-w-160 print:min-w-0"
                                >
                                    <ResultDetails
                                        curricula={sc}
                                        picture={student.data.photo}
                                    />
                                    <CurriculumCardFinal
                                        sc={sc}
                                        defaultBoundaries={
                                            defaultGradeBoundaries.data
                                        }
                                        studentId={student.data.id}
                                        student={student.data}
                                        boundaries={boundaries}
                                    />
                                </div>
                            );
                        }
                    })}
                </div>
            </div>
        </>
    );
}
