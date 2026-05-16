export type Severity = 'critical' | 'warning' | 'notice' | 'info';

export interface ActivityCauser {
    id: number | string | null;
    name: string;
    role: string | null;
    avatar: string | null;
    deleted: boolean;
}

export interface ActivitySubject {
    type: string;
    id: number | string | null;
    uuid?: string | null;
    label: string;
    exists: boolean;
}

export interface ActivityItem {
    id: number;
    log_name: string | null;
    event: string | null;
    severity: Severity;
    description: string;
    causer: ActivityCauser;
    subject: ActivitySubject | null;
    batch_uuid: string | null;
    has_diff: boolean;
    is_system: boolean;
    school_id?: number | string | null;
    created_at: string;
}

export interface DiffRow {
    field: string;
    old: unknown;
    new: unknown;
    masked: boolean;
}

export interface ActivityDetail extends ActivityItem {
    properties: Record<string, unknown>;
    diff: DiffRow[];
    updated_at: string | null;
    batch?: {
        uuid: string;
        count: number;
        related: ActivityItem[];
    };
}

export interface Pagination {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface FilterOptions {
    causers: { id: number | string; name: string; avatar: string | null }[];
    subject_types: { value: string; label: string }[];
    events: string[];
    log_names: string[];
}

export interface ActivityStats {
    events_today: number;
    events_this_week: number;
    events_this_month: number;
    active_users_24h: number;
    critical_7d: number;
    failed_logins_24h: number;
    top_causers: { id: number; name: string; avatar: string | null; count: number }[];
    by_event: Record<string, number>;
    by_severity: Record<string, number>;
    heatmap: Record<string, number>;
}

export interface ActivityFilters {
    search?: string;
    causer_id?: (number | string)[];
    subject_type?: string;
    event?: string[];
    log_name?: string[];
    severity?: Severity[];
    batch_uuid?: string;
    date_from?: string;
    date_to?: string;
    include_system?: boolean;
}

/** Capabilities passed from the API/permissions to drive optional UI. */
export interface ActivityCapabilities {
    canViewSystem: boolean;
    canExport: boolean;
}
