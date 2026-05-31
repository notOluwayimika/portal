import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { handleBack } from '@/helpers';
import type {
    ClassLevelArm,
    GradeBoundary,
    StudentCurriculum,
} from '@/types/models';
import { CurriculumCard, PRINT_STYLES, SCHOOL_NAME } from './active';
interface ClassLevelArmPageProps {
    classLevelArms: { data: ClassLevelArm[] };
    defaultGradeBoundaries: { data: GradeBoundary[] };
    [key: string]: unknown;
}

export default function List() {
    const { classLevelArms, defaultGradeBoundaries } =
        usePage<ClassLevelArmPageProps>().props;
    const armData = classLevelArms.data;
    // const gradeBoundaries = defaultGradeBoundaries.data;
    const handlePrint = () => {
        window.print();
    };

    return (
        <div>
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
            <div className="mx-automax-w-3xl relative z-10 p-4 font-sans text-slate-800">
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
                </div>
            </div>
            <div className="p-4">
                {armData.map((classLevelArm) => (
                    <div key={classLevelArm.id}>
                        <p className="pt-4 text-center text-lg font-bold">
                            {classLevelArm.name}
                        </p>
                        {classLevelArm.curricula?.map((curriculum) => (
                            <div className="space-y-4" key={curriculum.id}>
                                <p className="text-md pb-4 text-center font-bold text-slate-600">
                                    {curriculum.is_ccm
                                        ? 'CROSS CURRICULAR MONITORING'
                                        : ''}
                                </p>
                                {curriculum.student_curricula?.map(
                                    (sc: StudentCurriculum) => {
                                        return (
                                            <CurriculumCard
                                                key={sc.id}
                                                sc={sc}
                                                defaultBoundaries={
                                                    defaultGradeBoundaries.data
                                                }
                                                studentId={sc.student.id}
                                                student={sc.student}
                                            />
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
