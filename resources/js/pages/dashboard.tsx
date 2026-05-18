import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { ActivityFeedWidget } from '@/components/dashboard/activity-feed-widget';
import { DashboardOnboarding } from '@/components/dashboard/dashboard-onboarding';
import { DataGapsPanel } from '@/components/dashboard/data-gaps-panel';
import { DistributionChart } from '@/components/dashboard/distribution-chart';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { QuickActionsPanel } from '@/components/dashboard/quick-actions-panel';
import { ScoreEntryProgress } from '@/components/dashboard/score-entry-progress';
import { TrendChart } from '@/components/dashboard/trend-chart';
import { WidgetErrorBoundary } from '@/components/dashboard/widget-error-boundary';
import { dashboard } from '@/routes';
import type { DashboardAnalysis, DailyCount, OnboardingState, SelectedWidget } from '@/types/dashboard';

interface DashboardProps {
    analysis: DashboardAnalysis;
    widgets: SelectedWidget[];
    onboarding: OnboardingState;
    lastRefreshedAt: string | null;
}

function timeAgoLabel(dateStr: string | null): string {
    if (!dateStr) return '';
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} minute${mins === 1 ? '' : 's'} ago`;
    const hrs = Math.floor(mins / 60);
    return `${hrs} hour${hrs === 1 ? '' : 's'} ago`;
}

const KPI_META: Record<string, { label: string; entityKey: string; href: string }> = {
    students_kpi: { label: 'Total students', entityKey: 'students', href: '/students' },
    guardians_kpi: { label: 'Total guardians', entityKey: 'guardians', href: '/guardians' },
    enrollments_kpi: { label: 'Active enrollments', entityKey: 'student_curricula', href: '/setup' },
    assessments_kpi: { label: 'Scores entered', entityKey: 'scores', href: '/setup' },
};

function getModuleDailyCounts(analysis: DashboardAnalysis, dataKey: string): DailyCount[] {
    const parts = dataKey.split('.');
    // dataKey format: "modules.{moduleName}.daily_counts_30d"
    if (parts[0] === 'modules' && parts[2] === 'daily_counts_30d') {
        return analysis.modules[parts[1]]?.daily_counts_30d ?? [];
    }
    return [];
}

export default function Dashboard({ analysis, widgets, onboarding, lastRefreshedAt }: DashboardProps) {
    const [refreshing, setRefreshing] = useState(false);

    const kpiWidgets = widgets.filter((w) => w.component === 'KpiCard').slice(0, 4);
    const trendWidget = widgets.find((w) => w.component === 'TrendChart');
    const distWidget = widgets.find((w) => w.component === 'DistributionChart');
    const activityWidget = widgets.find((w) => w.component === 'ActivityFeedWidget');
    const dataGapsWidget = widgets.find((w) => w.component === 'DataGapsPanel');
    const scoreEntryWidget = widgets.find((w) => w.component === 'ScoreEntryProgress');

    function handleRefresh() {
        if (refreshing) return;
        setRefreshing(true);
        axios
            .post('/dashboard/refresh')
            .then(() => router.reload({ only: ['analysis', 'widgets', 'onboarding', 'lastRefreshedAt'] }))
            .catch(() => {})
            .finally(() => setRefreshing(false));
    }

    return (
        <>
            <Head title="Dashboard" />

            {analysis.is_onboarding_state ? (
                <div className="p-5 bg-slate-50 dark:bg-slate-950 min-h-full">
                    <DashboardOnboarding onboarding={onboarding} schoolName={analysis.school_name} />

                    {/* Hybrid preview: show available KPI widgets even during onboarding */}
                    {kpiWidgets.length > 0 && (
                        <div className="max-w-2xl mx-auto mt-6 px-4">
                            <p className="text-xs text-slate-400 mb-3">Early preview — data available so far:</p>
                            <div className="grid grid-cols-2 gap-3">
                                {kpiWidgets.map((w) => {
                                    const meta = KPI_META[w.id];
                                    const entity = meta ? analysis.entities[meta.entityKey] : null;
                                    const last30 = entity?.created_last_30d ?? 0;
                                    return (
                                        <WidgetErrorBoundary key={w.id} widgetId={w.id}>
                                            <KpiCard
                                                label={meta?.label ?? w.id}
                                                value={entity?.active ?? 0}
                                                subText={last30 > 0 ? `+${last30} in last 30 days` : undefined}
                                                tone={last30 > 0 ? 'up' : 'neutral'}
                                                href={meta?.href}
                                            />
                                        </WidgetErrorBoundary>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            ) : (
                <div className="p-5 bg-slate-50 dark:bg-slate-950 min-h-full">
                    {/* Row 1: KPI strip */}
                    {kpiWidgets.length > 0 && (
                        <div
                            className="grid gap-3 mb-5"
                            style={{ gridTemplateColumns: `repeat(${Math.min(kpiWidgets.length, 4)}, minmax(0, 1fr))` }}
                        >
                            {kpiWidgets.map((w) => {
                                const meta = KPI_META[w.id];
                                const entity = meta ? analysis.entities[meta.entityKey] : null;
                                const last30 = entity?.created_last_30d ?? 0;
                                const sparkline = trendWidget
                                    ? getModuleDailyCounts(analysis, trendWidget.dataKey)
                                    : [];
                                return (
                                    <WidgetErrorBoundary key={w.id} widgetId={w.id}>
                                        <KpiCard
                                            label={meta?.label ?? w.id}
                                            value={entity?.active ?? 0}
                                            subText={last30 > 0 ? `+${last30} in last 30 days` : undefined}
                                            tone={last30 > 0 ? 'up' : 'neutral'}
                                            sparklineData={sparkline}
                                            href={meta?.href}
                                        />
                                    </WidgetErrorBoundary>
                                );
                            })}
                        </div>
                    )}

                    {/* Row 2: Primary visualizations */}
                    {(trendWidget || distWidget) && (
                        <div className="grid grid-cols-2 gap-3.5 mb-5">
                            {trendWidget && (
                                <WidgetErrorBoundary widgetId={trendWidget.id}>
                                    <TrendChart
                                        data={getModuleDailyCounts(analysis, trendWidget.dataKey)}
                                        label={
                                            trendWidget.id === 'assessments_trend'
                                                ? 'Score entries — last 30 days'
                                                : 'Student activity — last 30 days'
                                        }
                                        fullSize
                                    />
                                </WidgetErrorBoundary>
                            )}
                            {distWidget && (
                                <WidgetErrorBoundary widgetId={distWidget.id}>
                                    <DistributionChart
                                        data={analysis.distributions.students_by_class_level ?? []}
                                    />
                                </WidgetErrorBoundary>
                            )}
                        </div>
                    )}

                    {/* Row 3: Operational widgets */}
                    <div className="grid grid-cols-3 gap-3.5 mb-5">
                        {activityWidget && (
                            <WidgetErrorBoundary widgetId={activityWidget.id}>
                                <ActivityFeedWidget activities={analysis.recent_activities} />
                            </WidgetErrorBoundary>
                        )}
                        {dataGapsWidget && analysis.data_gaps.length > 0 && (
                            <WidgetErrorBoundary widgetId={dataGapsWidget.id}>
                                <DataGapsPanel gaps={analysis.data_gaps} />
                            </WidgetErrorBoundary>
                        )}
                        <WidgetErrorBoundary widgetId="quick-actions">
                            <QuickActionsPanel gaps={analysis.data_gaps} />
                        </WidgetErrorBoundary>
                    </div>

                    {/* Row 4: Score entry progress */}
                    {scoreEntryWidget && (
                        <div className="grid grid-cols-3 gap-3.5 mb-5">
                            <WidgetErrorBoundary widgetId={scoreEntryWidget.id}>
                                <ScoreEntryProgress
                                    data={analysis.distributions.score_entry_by_section ?? []}
                                />
                            </WidgetErrorBoundary>
                        </div>
                    )}

                    {/* Row 5: Footer / meta */}
                    <div className="flex items-center justify-between pt-4 border-t border-slate-200 dark:border-slate-700 mt-2">
                        <p className="text-xs text-slate-400">
                            {lastRefreshedAt
                                ? `Dashboard data refreshed ${timeAgoLabel(lastRefreshedAt)}`
                                : 'Dashboard data is current'}
                        </p>
                        <button
                            onClick={handleRefresh}
                            disabled={refreshing}
                            className="inline-flex items-center gap-1.5 text-xs text-primary hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <RefreshCw size={12} className={refreshing ? 'animate-spin' : ''} />
                            {refreshing ? 'Refreshing…' : 'Refresh dashboard'}
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
