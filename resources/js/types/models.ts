export interface AcademicSession {
    id: string;
    school_id: string;
    name: string;
    slug: string;
    is_current: boolean;
    created_at: string;
    updated_at: string;
}

export interface School {
    id: string;
    name: string;
    slug: string;
    created_at: string;
    updated_at: string;
}
