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
    name: string;
    admission_number: string;
    photo?: string;
    // email: string;
    // phone: string;
    // date_of_birth: string;
    gender: string;
    created_at?: string;
    updated_at?: string;
}

export interface Teacher {
    id: string;
    school_id: string;
    school: School;
    user_id?: string;
    user?: User;
    name: string;
    staff_number: string;
    photo?: string;
    // email: string;
    // phone: string;
    // date_of_birth: string;
    gender: string;
    created_at?: string;
    updated_at?: string;
    deleted_at?: string;
}

export interface Curriculum {
    id: string;
    school_id?: string;
    school?: School;
    academic_session_id?: string;
    academic_session?: AcademicSession;
    class_level_arm_id?: string;

    class_level_arm?: ClassLevelArm;
    exam_type_id?: string;
    exam_type?: ExamType;
    term: number;
    min_subjects: number;
    registration_deadline: string;
    result_visible_at: string;
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
