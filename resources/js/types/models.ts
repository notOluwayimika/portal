import type { User } from './auth';

export interface AcademicSession {
    id: string;
    school_id: string;
    name: string;
    slug: string;
    is_current: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface School {
    id: string;
    name: string;
    slug: string;
    current_session?: AcademicSession;
    created_at?: string;
    updated_at?: string;
}

export interface Session {
    id: string;
    name: string;
    is_current: boolean;
    school_id: string;
    school: School;
    created_at?: string;
    updated_at?: string;
}
export interface ClassLevel {
    id: string;
    school_id?: string;
    school?: School;
    arms?: Arm[];
    name: string;
    order: number;
    created_at?: string;
    updated_at?: string;
}

export interface Arm {
    id: string;
    school_id?: string;
    school?: School;
    label: string;
    created_at?: string;
    updated_at?: string;
}

export interface ClassLevelArm {
    id: string;
    class_level_id?: string;
    class_level: ClassLevel;
    arm_id?: string;
    arm: Arm;
    stream_id?: string | null;
    stream: Stream | null;
    name?: string;
    created_at?: string;
    updated_at?: string;
}

export interface ExamType {
    id: string;
    school_id?: string;
    school?: School;
    name: string;
    slug: string;
    created_at?: string;
    updated_at?: string;
}

export interface Subject {
    id: string;
    school_id: string;
    school: School;
    name: string;
    code: string;
    created_at?: string;
    updated_at?: string;
}

export interface GradeBoundary {
    id: string;
    school_id?: string;
    school?: School;
    exam_type_id?: string;
    exam_type?: ExamType;
    min_score: number;
    max_score: number;
    grade: string;
    label: string;
    created_at?: string;
    updated_at?: string;
}

export interface Student {
    id: string;
    school_id: string;
    school: School;
    user_id?: string;
    user?: User;
    first_name: string;
    last_name: string;
    middle_name?: string;
    full_name: string;
    admission_number: string;
    photo?: string;
    gender: string;
    date_of_birth?: string;
    status: string;
    class_details: {
        level: string;
        arm: string;
        stream: string | null;
        full_class: string;
    };
    curriculum_id?: number;
    promoted_to_id?: number;
    created_at?: string;
    updated_at?: string;
}

export interface Teacher {
    id: string;
    school_id: string;
    school?: School;
    user_id?: string;
    user?: User;
    first_name: string;
    last_name: string;
    full_name: string;
    staff_number?: string;
    photo?: string | null;
    gender?: string;
    date_of_birth?: string;
    phone?: string;
    address?: string;
    qualification?: string;
    hire_date?: string;
    status: string;
    created_at?: string;
    updated_at?: string;
    deleted_at?: string;
}

export interface TeacherSubjectAssignment {
    id: string;
    curriculum_subject: {
        id: string;
        subject: { name: string; code?: string };
        curriculum: {
            id: string;
            class_level_arm: { name: string };
            term?: { name: string };
        };
        is_compulsory: boolean;
    };
}

export interface Term {
    id: string;
    name: string;
    full_name: string;
    slug: string;
    order: number;
    status: string;
    start_date?: string;
    end_date?: string;
    academic_session?: AcademicSession;
    registration_deadline?: string;
    result_visible_at?: string;
}

export interface Curriculum {
    id: string;
    school_id?: string;
    school?: School;
    term_id?: string;
    term?: Term;
    academic_session?: AcademicSession;
    class_level_arm_id?: string;

    class_level_arm?: ClassLevelArm;
    exam_type_id?: string;
    exam_type?: ExamType;
    min_subjects: number;
    status: string;
    created_at?: string;
    updated_at?: string;
}

export interface SetupData {
    school: School;
    current_session: AcademicSession | null;
    sessions: number;
    class_levels: number;
    arms: number;
    exam_types: number;
    subjects: number;
    grade_boundaries: number;
    students: number;
    curricula: number;
}

export interface Stream {
    id: string;
    name: string;
    code: string;
    sort_order: number;
    created_at?: string;
    updated_at?: string;
}

export interface CurriculumSubject {
    id: string;
    curriculum_id: string;
    curriculum?: Curriculum;
    subject: Subject;
    is_compulsory: boolean;
    display_order: number;
    students: Student[];
    teachers: TeacherCurriculumSubject[];
    marking_components: MarkingComponent[];
}
export interface TeacherCurriculumSubject {
    id: string;
    teacher: Teacher;
    curriculum_subject: CurriculumSubject;
}

export interface MarkingComponent {
    id: string;
    curriculum_subject_id: string;
    name: string;
    weight: number; // stored as 0.0–1.0, e.g. 0.3
}
