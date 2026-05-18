import { usePage } from '@inertiajs/react';
import { StudentSubjectsSection } from '@/components/students/subjects/student-subjects-section';
import { handleBack } from '@/helpers';
import type { Student, StudentCurriculum } from '@/types/models';

export default function Show() {
    const { student, studentCurriculum } = usePage().props as unknown as {
        student: { data: Student };
        studentCurriculum: { data: StudentCurriculum };
    };
    console.log(student, studentCurriculum);

    return (
        <div className="p-10">
            <div className="flex">
                <button
                    className="btn btn-ghost btn-sm btn-icon cursor-pointer p-4"
                    onClick={handleBack}
                    title="Back to curricula"
                    style={{ fontSize: 14 }}
                >
                    ← Go back
                </button>
            </div>
            <StudentSubjectsSection
                student={student.data}
                studentCurriculum={studentCurriculum.data}
            />
        </div>
    );
}
