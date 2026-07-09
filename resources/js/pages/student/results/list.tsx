import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { CurriculumCardFinal } from '@/components/curriculum-card-final';
import { handleBack } from '@/helpers';
import type {
    ClassLevelArm,
    GradeBoundary,
    StudentCurriculum,
} from '@/types/models';
import { CurriculumCard, GradeKeyTable, ResultDetails } from './active';
interface ClassLevelArmPageProps {
    classLevelArms: { data: ClassLevelArm[] };
    defaultGradeBoundaries: { data: GradeBoundary[] };
    [key: string]: unknown;
}
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

export default function List() {
    const { classLevelArms, defaultGradeBoundaries } =
        usePage<ClassLevelArmPageProps>().props;
    const armData = classLevelArms.data;
    // const gradeBoundaries = defaultGradeBoundaries.data;
    const handlePrint = () => {
        // Browsers use document.title as the default "Save as PDF" filename,
        // so swap it to ClassLevelArm_session_term_date (single arm) or
        // ClassLevel_session_term_date (whole class) for the dialog and
        // restore it afterwards.
        const curriculum = armData[0]?.curricula?.[0];
        const sanitize = (v: string) => v.replace(/[^\w-]+/g, '');
        const className =
            armData.length === 1
                ? (armData[0]?.name ?? '')
                : (armData[0]?.class_level?.name ?? '');
        const fileName = [
            sanitize(className),
            sanitize((curriculum?.academic_session?.name ?? '').replace(/\//g, '-')),
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
        <div className="block">
            <style>{PRINT_STYLES}</style>
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
            {/* height a4 */}
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
                    {armData.length > 0 && (
                        <button
                            type="button"
                            onClick={handlePrint}
                            className="rounded bg-blue-700 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-800"
                        >
                            Print / Save as PDF
                        </button>
                    )}
                </div>
            </div>
            {/* height a4 */}
            <div className="print-wrapper mx-auto max-w-3xl">
                {armData.map((classLevelArm) => (
                    <div key={classLevelArm.id}>
                        {/* <p className="pt-4 text-center text-lg font-bold">
                            {classLevelArm.name}
                        </p> */}
                        {classLevelArm.curricula?.map((curriculum) => (
                            <div className="" key={curriculum.id}>
                                {curriculum.student_curricula?.map(
                                    (sc: StudentCurriculum) => {
                                        const boundaries =
                                            sc.curriculum.exam_type
                                                ?.grade_boundaries?.length &&
                                            sc.curriculum.exam_type
                                                ?.grade_boundaries?.length > 0
                                                ? sc.curriculum.exam_type
                                                      ?.grade_boundaries
                                                : defaultGradeBoundaries.data;

                                        return (
                                            <div
                                                key={sc.id}
                                                className="print-page block p-4"
                                            >
                                                <ResultDetails curricula={sc} picture={sc.student.photo} />
                                                {curriculum.is_ccm ? (
                                                    <><CurriculumCard
                                                        key={sc.id}
                                                        sc={sc}
                                                        defaultBoundaries={
                                                            defaultGradeBoundaries.data
                                                        }
                                                        studentId={
                                                            sc.student.id
                                                        }
                                                        student={sc.student}
                                                    />
                                                    <div className="grid grid-cols-2">
                                                    <div></div>

                                                    <GradeKeyTable
                                                        boundaries={boundaries}
                                                    />
                                                </div>
                                                <div>
                                                    <div className="my-1 flex w-full p-1 text-xs font-extralight italic">
                                                        <div>
                                                            <img
                                                                src="/assets/images/signature_secondary.png"
                                                                alt="Brookstone School"
                                                                className={`h-16 w-auto sm:h-20`}
                                                                draggable={
                                                                    false
                                                                }
                                                            />
                                                            <p>
                                                                Principal's
                                                                Signature
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="my-1 w-full border border-black p-1 text-xs font-extralight italic">
                                                        <p>
                                                            Parents and students
                                                            are encouraged to
                                                            attend CCM meetings
                                                            at the beginning of
                                                            every term and half
                                                            term to review
                                                            results.
                                                        </p>
                                                    </div>
                                                </div></>

                                                ) : (
                                                    <><CurriculumCardFinal
                                                        key={sc.id}
                                                        sc={sc}
                                                        defaultBoundaries={
                                                            defaultGradeBoundaries.data
                                                        }
                                                        studentId={
                                                            sc.student.id
                                                        }
                                                        student={sc.student}
                                                        boundaries={boundaries}
                                                    /></>

                                                )}



                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
