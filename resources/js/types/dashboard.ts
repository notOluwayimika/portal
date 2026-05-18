export interface EntityVolume {
    total: number;
    active: number;
    soft_deleted: number;
    earliest_created_at: string | null;
    latest_created_at: string | null;
    created_last_7d: number;
    created_last_30d: number;
    created_last_90d: number;
}

export interface DailyCount {
    date: string;
    count: number;
}

export interface ModuleThreshold {
    active_threshold: number;
    dormant_threshold: number;
    recency_window_days: number;
}

export type ModuleStatus = 'active' | 'dormant' | 'empty';

export interface ModuleAnalysis {
    status: ModuleStatus;
    primary_table_rows: number;
    last_activity_at: string | null;
    daily_counts_30d: DailyCount[];
    threshold_used: ModuleThreshold;
}

export type GapSeverity = 'info' | 'warning' | 'critical';

export interface DataGap {
    type: string;
    count: number;
    severity: GapSeverity;
    resolution_path: string;
}

export interface RecentActivity {
    description: string;
    log_name: string;
    created_at: string;
}

export interface DistributionItem {
    name: string;
    count: number;
}

export interface ScoreEntryItem {
    label: string;
    pct: number;
    filled: number;
    total: number;
}

export interface DashboardAnalysis {
    school_id: string | null;
    school_name: string | null;
    analyzed_at: string;
    active_modules_count: number;
    is_onboarding_state: boolean;
    entities: Record<string, EntityVolume>;
    modules: Record<string, ModuleAnalysis>;
    data_gaps: DataGap[];
    distributions: {
        students_by_class_level?: DistributionItem[];
        score_entry_by_section?: ScoreEntryItem[];
    };
    recent_activities: RecentActivity[];
    richness: Record<string, unknown>;
}

export interface SelectedWidget {
    id: string;
    component: string;
    priority: number;
    dataKey: string;
}

export interface OnboardingStep {
    key: string;
    title: string;
    description: string;
    is_complete: boolean;
    action_label: string;
    action_href: string;
}

export interface OnboardingState {
    is_onboarding: boolean;
    steps: OnboardingStep[];
    completed_count: number;
    total_count: number;
}
