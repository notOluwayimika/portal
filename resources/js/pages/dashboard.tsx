import { Head, router, usePage } from '@inertiajs/react';
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
import type {
    DashboardAnalysis,
    DailyCount,
    EntityVolume,
    OnboardingState,
    SelectedWidget,
} from '@/types/dashboard';

interface DashboardProps {
    analysis: DashboardAnalysis;
    widgets: SelectedWidget[];
    onboarding: OnboardingState;
    lastRefreshedAt: string | null;
}

function timeAgoLabel(dateStr: string | null): string {
    if (!dateStr) {
        return '';
    }

    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);

    if (mins < 1) {
        return 'just now';
    }

    if (mins < 60) {
        return `${mins} minute${mins === 1 ? '' : 's'} ago`;
    }

    const hrs = Math.floor(mins / 60);

    return `${hrs} hour${hrs === 1 ? '' : 's'} ago`;
}

/**
 * Which numeric field of an EntityVolume a KPI card shows. Only these three are numbers; the rest of
 * EntityVolume is timestamps, so the union keeps the lookup below type-safe.
 */
type KpiValueKey = 'total' | 'active' | 'enrolled_current_session';

const KPI_META: Record<
    string,
    {
        label: string;
        entityKey: string;
        href: string;
        valueKey?: KpiValueKey;
    }
> = {
    students_kpi: {
        // SESSION-SCOPED, and the label says so. `active` on the students entity means "not
        // soft-deleted" and is read school-wide by three server-side onboarding gates, so this card
        // reads a separate display-only field instead of the server changing what `active` means.
        label: 'Students this session',
        entityKey: 'students',
        href: '/students',
        valueKey: 'enrolled_current_session',
    },
    guardians_kpi: {
        label: 'Total guardians',
        entityKey: 'guardians',
        href: '/guardians',
    },
    enrollments_kpi: {
        label: 'Active enrollments',
        entityKey: 'student_curricula',
        href: '/setup',
    },
    assessments_kpi: {
        label: 'Scores entered',
        entityKey: 'scores',
        href: '/setup',
    },
};

/**
 * ONE rule, because the KPI strip is rendered in TWO places (the onboarding preview and the full
 * dashboard). Duplicating `entity?.active ?? 0` at both sites is how one of them keeps the old
 * school-wide number after the other is corrected — the drift this repo has paid for elsewhere.
 */
function kpiValue(
    entity: EntityVolume | null | undefined,
    meta: { valueKey?: KpiValueKey } | undefined,
): number {
    return entity?.[meta?.valueKey ?? 'active'] ?? 0;
}

function getModuleDailyCounts(
    analysis: DashboardAnalysis,
    dataKey: string,
): DailyCount[] {
    const parts = dataKey.split('.');

    // dataKey format: "modules.{moduleName}.daily_counts_30d"
    if (parts[0] === 'modules' && parts[2] === 'daily_counts_30d') {
        return analysis.modules[parts[1]]?.daily_counts_30d ?? [];
    }

    return [];
}

export default function Dashboard({
    analysis,
    widgets,
    onboarding,
    lastRefreshedAt,
}: DashboardProps) {
    const [refreshing, setRefreshing] = useState(false);

    const { auth } = usePage<{
        auth: { roles: string[]; isSuperAdmin?: boolean };
    }>().props;
    const roles = auth?.roles ?? [];
    // Principals get an oversight view without the operational panels. An admin who
    // is also a principal keeps them — only a principal without an operational role
    // has Data Health and Quick Actions hidden.
    const hasOperationalRole =
        roles.includes('admin') ||
        roles.includes('head_of_school') ||
        !!auth?.isSuperAdmin;
    const hideOperationalPanels =
        roles.includes('principal') && !hasOperationalRole;

    const kpiWidgets = widgets
        .filter((w) => w.component === 'KpiCard')
        .slice(0, 4);
    const trendWidget = widgets.find((w) => w.component === 'TrendChart');
    const distWidget = widgets.find((w) => w.component === 'DistributionChart');
    const activityWidget = widgets.find(
        (w) => w.component === 'ActivityFeedWidget',
    );
    const dataGapsWidget = widgets.find((w) => w.component === 'DataGapsPanel');
    const scoreEntryWidget = widgets.find(
        (w) => w.component === 'ScoreEntryProgress',
    );

    function handleRefresh() {
        if (refreshing) {
            return;
        }

        setRefreshing(true);
        axios
            .post('/dashboard/refresh')
            .then(() =>
                router.reload({
                    only: [
                        'analysis',
                        'widgets',
                        'onboarding',
                        'lastRefreshedAt',
                    ],
                }),
            )
            .catch(() => {})
            .finally(() => setRefreshing(false));
    }

    return (
        <>
            <Head title="Dashboard" />

            {analysis.is_onboarding_state ? (
                <div className="min-h-full bg-slate-50 p-5">
                    <DashboardOnboarding
                        onboarding={onboarding}
                        schoolName={analysis.school_name}
                    />

                    {/* Hybrid preview: show available KPI widgets even during onboarding */}
                    {kpiWidgets.length > 0 && (
                        <div className="mx-auto mt-6 max-w-2xl px-4">
                            <p className="mb-3 text-xs text-slate-400">
                                Early preview — data available so far:
                            </p>
                            <div className="grid grid-cols-2 gap-3">
                                {kpiWidgets.map((w) => {
                                    const meta = KPI_META[w.id];
                                    const entity = meta
                                        ? analysis.entities[meta.entityKey]
                                        : null;
                                    const last30 =
                                        entity?.created_last_30d ?? 0;

                                    return (
                                        <WidgetErrorBoundary
                                            key={w.id}
                                            widgetId={w.id}
                                        >
                                            <KpiCard
                                                label={meta?.label ?? w.id}
                                                value={kpiValue(entity, meta)}
                                                subText={
                                                    last30 > 0
                                                        ? `+${last30} in last 30 days`
                                                        : undefined
                                                }
                                                tone={
                                                    last30 > 0
                                                        ? 'up'
                                                        : 'neutral'
                                                }
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
                <div className="min-h-full bg-slate-50 p-5">
                    {/* Row 1: KPI strip */}
                    {kpiWidgets.length > 0 && (
                        <div
                            className="mb-5 grid gap-3"
                            style={{
                                gridTemplateColumns: `repeat(${Math.min(kpiWidgets.length, 4)}, minmax(0, 1fr))`,
                            }}
                        >
                            {kpiWidgets.map((w) => {
                                const meta = KPI_META[w.id];
                                const entity = meta
                                    ? analysis.entities[meta.entityKey]
                                    : null;
                                const last30 = entity?.created_last_30d ?? 0;
                                const sparkline = trendWidget
                                    ? getModuleDailyCounts(
                                          analysis,
                                          trendWidget.dataKey,
                                      )
                                    : [];

                                return (
                                    <WidgetErrorBoundary
                                        key={w.id}
                                        widgetId={w.id}
                                    >
                                        <KpiCard
                                            label={meta?.label ?? w.id}
                                            value={kpiValue(entity, meta)}
                                            subText={
                                                last30 > 0
                                                    ? `+${last30} in last 30 days`
                                                    : undefined
                                            }
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
                        <div className="mb-5 grid grid-cols-2 gap-3.5">
                            {trendWidget && (
                                <WidgetErrorBoundary widgetId={trendWidget.id}>
                                    <TrendChart
                                        data={getModuleDailyCounts(
                                            analysis,
                                            trendWidget.dataKey,
                                        )}
                                        label={
                                            trendWidget.id ===
                                            'assessments_trend'
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
                                        data={
                                            analysis.distributions
                                                .students_by_class_level ?? []
                                        }
                                    />
                                </WidgetErrorBoundary>
                            )}
                        </div>
                    )}

                    {/* Row 3: Operational widgets */}
                    <div className="mb-5 grid grid-cols-3 gap-3.5">
                        {activityWidget && (
                            <WidgetErrorBoundary widgetId={activityWidget.id}>
                                <ActivityFeedWidget
                                    activities={analysis.recent_activities}
                                />
                            </WidgetErrorBoundary>
                        )}
                        {!hideOperationalPanels &&
                            dataGapsWidget &&
                            analysis.data_gaps.length > 0 && (
                                <WidgetErrorBoundary
                                    widgetId={dataGapsWidget.id}
                                >
                                    <DataGapsPanel gaps={analysis.data_gaps} />
                                </WidgetErrorBoundary>
                            )}
                        {!hideOperationalPanels && (
                            <WidgetErrorBoundary widgetId="quick-actions">
                                <QuickActionsPanel gaps={analysis.data_gaps} />
                            </WidgetErrorBoundary>
                        )}
                    </div>

                    {/* Row 4: Score entry progress */}
                    {scoreEntryWidget && (
                        <div className="mb-5 grid grid-cols-3 gap-3.5">
                            <WidgetErrorBoundary widgetId={scoreEntryWidget.id}>
                                <ScoreEntryProgress
                                    data={
                                        analysis.distributions
                                            .score_entry_by_section ?? []
                                    }
                                />
                            </WidgetErrorBoundary>
                        </div>
                    )}

                    {/* Row 5: Footer / meta */}
                    <div className="mt-2 flex items-center justify-between border-t border-slate-200 pt-4">
                        <p className="text-xs text-slate-400">
                            {lastRefreshedAt
                                ? `Dashboard data refreshed ${timeAgoLabel(lastRefreshedAt)}`
                                : 'Dashboard data is current'}
                        </p>
                        <button
                            onClick={handleRefresh}
                            disabled={refreshing}
                            className="inline-flex items-center gap-1.5 text-xs text-[#185FA5] hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <RefreshCw
                                size={12}
                                className={refreshing ? 'animate-spin' : ''}
                            />
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
