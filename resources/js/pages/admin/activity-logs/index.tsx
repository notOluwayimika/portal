import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity as ActivityIcon,
    AlertTriangle,
    Download,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityDetailDrawer } from '@/components/activity-logs/activity-detail-drawer';
import { ActivityFilterBar } from '@/components/activity-logs/activity-filter-bar';
import { ActivityStatCard } from '@/components/activity-logs/activity-stat-card';
import { ActivityTimeline } from '@/components/activity-logs/activity-timeline';
import type {
    ActivityCapabilities,
    ActivityFilters,
    ActivityItem,
    ActivityStats,
    FilterOptions,
    Pagination,
} from '@/components/activity-logs/types';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

const PER_PAGE = 25;

export default function ActivityLogIndex() {
    const page = usePage<{
        auth: { roles?: string[] };
        initialActivityId?: string;
    }>();
    const roles = page.props.auth?.roles ?? [];
    const capabilities: ActivityCapabilities = {
        canViewSystem: roles.includes('super_admin'),
        canExport:
            roles.includes('admin') ||
            roles.includes('head_of_school') ||
            roles.includes('super_admin'),
    };

    const [items, setItems] = useState<ActivityItem[]>([]);
    const [pagination, setPagination] = useState<Pagination | null>(null);
    const [stats, setStats] = useState<ActivityStats | null>(null);
    const [options, setOptions] = useState<FilterOptions | null>(null);
    const [filters, setFilters] = useState<ActivityFilters>({});
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [openId, setOpenId] = useState<number | null>(
        page.props.initialActivityId
            ? Number(page.props.initialActivityId)
            : null,
    );

    const debounced = useRef<ReturnType<typeof setTimeout>>(null);

    const buildParams = useCallback(
        (pageNum: number) => ({
            ...filters,
            page: pageNum,
            per_page: PER_PAGE,
        }),
        [filters],
    );

    const fetchPage = useCallback(
        async (pageNum: number, append: boolean) => {
            append ? setLoadingMore(true) : setLoading(true);
            try {
                const res = await axios.get<{
                    data: ActivityItem[];
                    pagination: Pagination;
                }>('/api/activity-logs', { params: buildParams(pageNum) });
                setItems((prev) =>
                    append
                        ? [...prev, ...res.data.data]
                        : res.data.data,
                );
                setPagination(res.data.pagination);
            } catch {
                if (!append) setItems([]);
            } finally {
                setLoading(false);
                setLoadingMore(false);
            }
        },
        [buildParams],
    );

    // Debounced refetch whenever filters change.
    useEffect(() => {
        if (debounced.current) clearTimeout(debounced.current);
        debounced.current = setTimeout(() => fetchPage(1, false), 300);
        return () => {
            if (debounced.current) clearTimeout(debounced.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters]);

    useEffect(() => {
        axios
            .get('/api/activity-logs/stats')
            .then((r) => setStats(r.data.data))
            .catch(() => undefined);
        axios
            .get('/api/activity-logs/filters/options')
            .then((r) => setOptions(r.data.data))
            .catch(() => undefined);
    }, []);

    // Cmd/Ctrl+K focuses search.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                document
                    .querySelector<HTMLInputElement>(
                        'input[placeholder^="Search description"]',
                    )
                    ?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    /**
     * Real-time hook point (Section 13 — NOT wired in this task).
     * A future WebSocket listener can call this to prepend a freshly
     * received activity with a slide-in. The feed is intentionally a
     * mutable list so this stays a small additive change.
     */
    const appendActivity = useCallback((activity: ActivityItem) => {
        setItems((prev) => [activity, ...prev]);
    }, []);
    void appendActivity;

    const exportUrl = `/api/activity-logs/export?${new URLSearchParams(
        Object.entries(filters).flatMap(([k, v]) =>
            v == null
                ? []
                : Array.isArray(v)
                  ? v.map((x) => [k + '[]', String(x)])
                  : [[k, String(v)]],
        ) as [string, string][],
    ).toString()}`;

    const savePreset = async () => {
        const name = window.prompt('Name this filter preset');
        if (!name) return;
        await axios.post('/api/activity-logs/saved-filters', {
            name,
            filters,
        });
    };

    return (
        <>
            <Head title="Activity Log" />
            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-xl font-semibold">
                                Activity Log
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                System-wide audit feed
                            </p>
                        </div>
                        {capabilities.canExport && (
                            <Button asChild variant="outline" size="sm">
                                <a href={exportUrl}>
                                    <Download className="mr-1 h-4 w-4" />
                                    Export
                                </a>
                            </Button>
                        )}
                    </div>

                    {/* Stats strip */}
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <ActivityStatCard
                            label="Events today"
                            value={stats?.events_today ?? '—'}
                            icon={ActivityIcon}
                            onClick={() =>
                                setFilters((f) => ({
                                    ...f,
                                    date_from: new Date()
                                        .toISOString()
                                        .slice(0, 10),
                                }))
                            }
                        />
                        <ActivityStatCard
                            label="Active users (24h)"
                            value={stats?.active_users_24h ?? '—'}
                            icon={Users}
                        />
                        <ActivityStatCard
                            label="Critical (7d)"
                            value={stats?.critical_7d ?? '—'}
                            icon={ShieldAlert}
                            tone="danger"
                            badge={
                                stats && stats.critical_7d > 0
                                    ? '!'
                                    : null
                            }
                            onClick={() =>
                                setFilters((f) => ({
                                    ...f,
                                    severity: ['critical'],
                                }))
                            }
                        />
                        <ActivityStatCard
                            label="Failed logins (24h)"
                            value={stats?.failed_logins_24h ?? '—'}
                            icon={AlertTriangle}
                            tone="warning"
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[300px_1fr]">
                        <div className="lg:sticky lg:top-4 lg:self-start">
                            <ActivityFilterBar
                                filters={filters}
                                options={options}
                                capabilities={capabilities}
                                onChange={setFilters}
                                onClear={() => setFilters({})}
                                onSavePreset={savePreset}
                            />
                        </div>

                        <div>
                            {loading ? (
                                <div className="space-y-3">
                                    {Array.from({ length: 6 }).map((_, i) => (
                                        <Skeleton
                                            key={i}
                                            className="h-16 w-full rounded-lg"
                                        />
                                    ))}
                                </div>
                            ) : items.length === 0 ? (
                                <div className="rounded-xl border bg-white p-10 text-center dark:bg-slate-900/40">
                                    <p className="font-medium">
                                        No activity matches your filters.
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Try widening your date range or
                                        clearing some filters.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-4"
                                        onClick={() => setFilters({})}
                                    >
                                        Clear all filters
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <ActivityTimeline
                                        items={items}
                                        onOpen={setOpenId}
                                    />
                                    {pagination &&
                                        pagination.current_page <
                                            pagination.last_page && (
                                            <div className="mt-5 text-center">
                                                <Button
                                                    variant="outline"
                                                    disabled={loadingMore}
                                                    onClick={() =>
                                                        fetchPage(
                                                            pagination.current_page +
                                                                1,
                                                            true,
                                                        )
                                                    }
                                                >
                                                    {loadingMore
                                                        ? 'Loading…'
                                                        : 'Load more'}
                                                </Button>
                                            </div>
                                        )}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <ActivityDetailDrawer
                activityId={openId}
                onClose={() => setOpenId(null)}
                onOpenRelated={setOpenId}
            />
        </>
    );
}

ActivityLogIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Activity Log', href: '/activity-logs' },
    ],
};
