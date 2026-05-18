import { usePage } from '@inertiajs/react';
import type { ComponentType } from 'react';
import StudentCurriculaPage from '@/components/student-curricula-page';
import type { Student } from '@/types/models';

const StudentCurriculaPageComponent = StudentCurriculaPage as ComponentType<{
    student: Student;
}>;

export default function Index() {
    const { student } = usePage().props as unknown as {
        student: { data: Student };
    };

    return <StudentCurriculaPageComponent student={student.data} />;
}
