// ═══════════════════════════════════════════════════════════════════════════
// SCHOOL-ADMIN RBAC CONSOLE (/setup/users)
//
// The school-scoped sibling of the super-admin console, built to the same
// design system and the same tab structure — with Users as the primary tab,
// because assigning roles is what a school admin actually does here.
//
// Everything except user→role is READ-ONLY: role→permission editing belongs to
// the super admin (SyncRolePermissionsRequest refuses anyone else), so the
// Roles and Permissions tabs inform the assignment rather than offering an
// edit the server would reject.
// ═══════════════════════════════════════════════════════════════════════════

import { Head, router, usePage } from '@inertiajs/react';
import { KeyRound, ShieldCheck, UserCog, Users } from 'lucide-react';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { CatalogPanel } from '@/components/rbac/catalog-panel';
import { HistoryPanel } from '@/components/rbac/history-panel';
import { cn } from '@/lib/utils';
import type { SchoolRbacPageProps, SchoolRbacTab } from '@/types/rbac';
import { RolesTab } from './roles-tab';
import { UsersTab } from './users-tab';

export default function SchoolRbacConsole() {
    const page = usePage<
        SchoolRbacPageProps & { errors: Record<string, string> }
    >();
    const {
        users,
        roles,
        groups,
        assignableRoles,
        stats,
        filters,
        school,
        tab,
        errors,
    } = page.props;

    // Tab in the URL: syncRoles returns back(), so a save has to land on the same tab with the
    // same search and page still applied.
    const go = (next: SchoolRbacTab) =>
        router.get(
            '/setup/users',
            {
                tab: next,
                search: filters.search || undefined,
                role: filters.role || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const tabs: { key: SchoolRbacTab; label: string; count?: number }[] = [
        { key: 'users', label: 'Users', count: stats.userCount },
        { key: 'roles', label: 'Roles', count: stats.roleCount },
        {
            key: 'permissions',
            label: 'Permissions',
            count: groups.reduce((n, g) => n + g.permissionCount, 0),
        },
        { key: 'history', label: 'History' },
    ];

    return (
        <>
            <Head title="Users & roles" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero ─────────────────────────────────────────── */}
                    <div className="rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex items-center gap-3">
                            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950 dark:to-violet-950">
                                <UserCog
                                    className="h-6 w-6 text-indigo-600 dark:text-indigo-400"
                                    aria-hidden
                                />
                            </span>
                            <div className="min-w-0">
                                <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                    Users &amp; roles
                                </h1>
                                <p className="text-xs text-slate-500">
                                    Who holds which role in {school.name}.
                                    Changes take effect immediately and are
                                    recorded in the activity log.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* ── Stats ────────────────────────────────────────── */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FinanceStatCard
                            icon={Users}
                            tone="indigo"
                            label="Users with a role here"
                            value={String(stats.userCount)}
                            subText={`${stats.multiRoleUserCount} hold more than one`}
                        />
                        <FinanceStatCard
                            icon={ShieldCheck}
                            tone="emerald"
                            label="Roles in use"
                            value={`${stats.roleCount - stats.unusedRoleCount} of ${stats.roleCount}`}
                            subText={
                                stats.unusedRoleCount > 0
                                    ? `${stats.unusedRoleCount} nobody holds`
                                    : 'every role is held'
                            }
                        />
                        <FinanceStatCard
                            icon={UserCog}
                            tone="slate"
                            label="You can assign"
                            value={String(stats.assignableRoleCount)}
                            subText={`of ${stats.roleCount} roles`}
                        />
                        <FinanceStatCard
                            icon={KeyRound}
                            tone="slate"
                            label="Permissions"
                            value={String(
                                groups.reduce(
                                    (n, g) => n + g.permissionCount,
                                    0,
                                ),
                            )}
                            subText={`across ${groups.length} groups`}
                        />
                    </div>

                    {/* ── Tabs ─────────────────────────────────────────── */}
                    <div
                        role="tablist"
                        aria-label="RBAC views"
                        className="flex flex-wrap gap-1 rounded-xl border-none bg-white p-1 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card"
                    >
                        {tabs.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                role="tab"
                                aria-selected={tab === t.key}
                                onClick={() => go(t.key)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-lg px-4 py-2 text-xs font-semibold transition-colors',
                                    tab === t.key
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800',
                                )}
                            >
                                {t.label}
                                {t.count !== undefined && (
                                    <span
                                        className={cn(
                                            'rounded-full px-1.5 py-0.5 text-[10px] font-bold',
                                            tab === t.key
                                                ? 'bg-white/20 text-white'
                                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                                        )}
                                    >
                                        {t.count}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>

                    {tab === 'users' && (
                        <UsersTab
                            users={users}
                            roles={roles}
                            assignableRoles={assignableRoles}
                            filters={filters}
                            errors={errors ?? {}}
                        />
                    )}
                    {tab === 'roles' && (
                        <RolesTab roles={roles} groups={groups} />
                    )}
                    {tab === 'permissions' && <CatalogPanel groups={groups} />}
                    {tab === 'history' && <HistoryPanel />}
                </div>
            </div>
        </>
    );
}
