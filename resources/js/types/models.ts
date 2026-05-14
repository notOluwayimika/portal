import type { User } from './auth';

export interface Role {
    id: number;
    name: string;
    guard_name: string;
    created_at: string;
    updated_at: string;
}
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

export interface GuardianPivot {
    relationship: string;
    is_primary: boolean;
    can_login: boolean;
}

export interface Guardian {
    id: string;
    full_name: string;
    first_name?: string;
    middle_name?: string | null;
    last_name?: string;
    gender?: string | null;
    marital_status?: string | null;
    phone?: string;
    whatsapp_number?: string | null;
    email?: string | null;
    photo?: string | null;
    occupation?: string | null;
    employer_name?: string | null;
    city?: string | null;
    state?: string | null;
    country?: string | null;
    postal_code?: string | null;
    emergency_contact?: string | null;
    id_type?: string | null;
    id_number?: string | null;
    id_expiry_date?: string | null;
    status?: string;
    // pivot fields (present when loaded via student.guardians)
    relationship?: string;
    is_primary?: boolean;
    can_login?: boolean;
    pivot?: GuardianPivot;
    // login-state fields (present on guardian profile page)
    has_login?: boolean;
    user_disabled_at?: string | null;
    email_verified_at?: string | null;
    never_activated?: boolean;
    deleted_at?: string | null;
    // linked students (on guardian profile)
    students?: (Student & { pivot: GuardianPivot })[];
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
    guardians?: Guardian[];
    student_curricula: StudentCurriculum[];
    created_at?: string;
    updated_at?: string;
}

export interface Teacher {
    id: string;
    school_id: string;
    school?: School;
    user_id?: string;
    user?: User;
    email?: string;
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
    full_name: string;
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
    students: StudentSubject[];
    scores?: Score[];
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

export interface Score {
    id: string;
    student: Student;
    marking_component: MarkingComponent;
    created_by: User;
    score: number;
}

export interface StudentCurriculum {
    id: string;
    student: Student;
    curriculum: Curriculum;
    status: string;
    promoted_to: Curriculum;
}
export interface StudentSubject {
    id: string;
    student_curriculum: StudentCurriculum;
    subject: Subject;
}

export interface SubjectResultStatus {
    id: string;
    status: string;
    rejection_reason: string;
    curriculum_subject: CurriculumSubject;
    updated_at: string;
    updated_by: User;
}
