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
    uuid?: string;
    name: string;
    slug: string;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    website?: string | null;
    name_on_result?: string | null;
    active?: boolean;
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
    class_level_arms?: ClassLevelArm[];
    name: string;
    order: number;
    grading_mode?: 'numeric' | 'categorical';
    grading_scheme?: GradingScheme | null;
    created_at?: string;
    updated_at?: string;
}

export interface GradingSchemeItem {
    id: string;
    code: string;
    label: string;
    display_order: number;
}

export interface GradingScheme {
    id: string;
    name: string;
    mode: 'categorical';
    version: number;
    items: GradingSchemeItem[];
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
    curricula?: Curriculum[];
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
    grade_boundaries?: GradeBoundary[];
    created_at?: string;
    updated_at?: string;
}

export interface Subject {
    id: string;
    school_id?: string;
    school?: School;
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
    grade_point: string;
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
    students_count?: number;
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

export interface SportHouse {
    id: number;
    uuid: string;
    name: string;
}

export interface Scholarship {
    id: number;
    uuid: string;
    name: string;
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
    admission_date?: string | null;
    address?: string | null;
    nationality?: string | null;
    other_nationality?: string | null;
    state_of_origin?: string | null;
    religion?: string | null;
    previous_school?: string | null;
    sport_house_id?: number | null;
    sport_house?: SportHouse | null;
    scholarship_id?: number | null;
    scholarship?: Scholarship | null;
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

export type TeacherAssignmentRole =
    | 'boarding_parent'
    | 'form_teacher'
    | 'head_of_school';

export interface ClassLevelArmTeacher {
    id: string;
    role: TeacherAssignmentRole;
    gender?: 'male' | 'female' | null;
    teacher?: Teacher;
    class_level_arm?: ClassLevelArm;
    assigned_by?: User | null;
    created_at?: string;
}

export type BehavioralGrade = 'A' | 'B' | 'C' | 'D' | 'E';

export interface BehavioralAssessment {
    id: string;
    punctuality: BehavioralGrade;
    mental_alertness: BehavioralGrade;
    respect: BehavioralGrade;
    neatness: BehavioralGrade;
    politeness: BehavioralGrade;
    honesty: BehavioralGrade;
    relationship_with_peers: BehavioralGrade;
    teamwork: BehavioralGrade;
    perseverance: BehavioralGrade;
    comment?: string | null;
    updated_at?: string;
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
    is_ccm: boolean;
    grading_mode?: 'numeric' | 'categorical';
    grading_scheme?: GradingScheme | null;
    curriculum_subjects?: CurriculumSubject;
    student_curricula?: StudentCurriculum[];
    created_at?: string;
    updated_at?: string;
}

export interface SetupCurrentTerm {
    name: string;
    order: number;
    status: string;
    start_date: string | null;
    end_date: string | null;
}

export interface SetupData {
    school: School;
    current_session: AcademicSession | null;
    current_term: SetupCurrentTerm | null;
    terms_in_session: number;
    sessions: number;
    class_levels: number;
    arms: number;
    class_level_arms: number;
    exam_types: number;
    subjects: number;
    grade_boundaries: number;
    curricula: number;
    students: number;
    teachers: number;
    guardians: number;
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
    subject_id?: number;
    is_compulsory: boolean;
    display_order: number;
    active: boolean;
    archived_at?: string | null;
    archived_by_user_id?: number | null;
    students?: StudentSubject[];
    scores?: Score[];
    teachers?: TeacherCurriculumSubject[];
    marking_components?: MarkingComponent[];
    class_average?: number | null;
    result_status?: SubjectResultStatus;
    student_results?: StudentResult[];
}
export interface StudentResult {
    id: string;
    student: Student;
    total_score: string | number | null;
    grade: string | null;
    grading_item?: GradingSchemeItem | null;
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
    promoted_to?: Curriculum;
    subjects?: StudentSubject[];
    ended_at?: string | null;
    ended_by_user_id?: number | null;
    end_reason?: string | null;
    is_ended?: boolean;
    form_teacher_comment?: string | null;
    head_of_school_comment?: string | null;
    behavioral_assessments?: BehavioralAssessment[];
}

export type StudentSubjectStatus = 'active' | 'dropped';

export interface StudentSubject {
    id: string;
    status: StudentSubjectStatus;
    student_curriculum?: StudentCurriculum;
    curriculum_subject: CurriculumSubject;
    own_result?: {
        total_score: string | number | null;
        grade: string | null;
        grading_item?: GradingSchemeItem | null;
    } | null;
    dropped_at?: string | null;
    drop_reason?: string | null;
    dropped_by?: { id: number; full_name: string } | null;
    restored_at?: string | null;
    restored_by?: { id: number; full_name: string } | null;
    comment?: string;
    commented_by?: string;
}

export interface StudentSubjectsGrouped {
    enrollment: {
        id: string;
        ended_at: string | null;
        is_ended: boolean;
    };
    compulsory_active: StudentSubject[];
    optional_active: StudentSubject[];
    optional_dropped: StudentSubject[];
    optional_available: Array<{
        id: string;
        subject_name: string;
        subject_code: string | null;
        is_compulsory: boolean;
        active: boolean;
    }>;
}

export interface SubjectResultStatus {
    id: string;
    status: string;
    rejection_reason: string;
    curriculum_subject: CurriculumSubject;
    updated_at: string;
    updated_by: User;
}

export interface StudentResult {
    id: string;
    student_id?: number;
    student?: Student;
    total_score: string | number;
    grade: string;
    status: string;
}

export interface BroadsheetGroup {
    curriculum_id: string;
    term: { name: string; full_name: string };
    exam_type: string;
    is_ccm: boolean;
    status: string;
    arms: string[];
    arm_count: number;
}

export interface BroadsheetColumn {
    key: string;
    label: string;
    name: string;
}

export interface BroadsheetSubject {
    subject_id: number;
    name: string;
    columns: BroadsheetColumn[];
}

export interface BroadsheetCell {
    [columnKey: string]: string | number | null;
}

export interface BroadsheetStudentRow {
    sn: number;
    name: string;
    gender: string;
    subjects: Record<string, BroadsheetCell>;
    gpa: string | null;
}

export interface BroadsheetClass {
    label: string;
    students: BroadsheetStudentRow[];
}

export interface Broadsheet {
    school_name: string;
    class_level: string;
    term: { name: string; full_name: string };
    exam_type: string;
    is_ccm: boolean;
    status: string;
    subjects: BroadsheetSubject[];
    classes: BroadsheetClass[];
}
