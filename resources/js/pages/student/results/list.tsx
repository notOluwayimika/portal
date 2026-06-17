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
const PRINT_STYLES = `
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
    .print-wrapper,
    .print-wrapper > div,
    .print-wrapper .space-y-4 {
        break-inside: auto;
        page-break-inside: auto;
    }
    .sc-page {
        break-before: page;
        page-break-before: always;
    }
    .sc-page:first-child {
        break-before: auto;
        page-break-before: auto;
    }
}
`;

export default function List() {
    const { classLevelArms, defaultGradeBoundaries } =
        usePage<ClassLevelArmPageProps>().props;
    const armData = classLevelArms.data;
    // const gradeBoundaries = defaultGradeBoundaries.data;
    const handlePrint = () => {
        window.print();
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
                                                className="sc-page block h-[277mm] p-4"
                                            >
                                                <ResultDetails curricula={sc} />
                                                {curriculum.is_ccm ? (
                                                    <CurriculumCard
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
                                                ) : (
                                                    <CurriculumCardFinal
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
                                                )}

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
                                                </div>
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
