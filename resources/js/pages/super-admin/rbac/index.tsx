// ═══════════════════════════════════════════════════════════════════════════
// SUPER-ADMIN RBAC CONSOLE
//
// Built to docs/ui-ux-design-system.md. Replaces a single-role pill editor that
// showed no counts, no grouping, no search and no history — so the one surface
// where privilege is granted could not answer who holds what, or how many
// people a change would affect.
// ═══════════════════════════════════════════════════════════════════════════

import { Head, router, usePage } from '@inertiajs/react';
import { KeyRound, Layers, ShieldCheck, Unplug } from 'lucide-react';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { cn } from '@/lib/utils';
import type { RbacPageProps, RbacTab } from '@/types/rbac';
import { CatalogTab } from './catalog-tab';
import { HistoryTab } from './history-tab';
import { RolesTab } from './roles-tab';

export default function RbacConsole() {
    const page = usePage<RbacPageProps & { errors: Record<string, string> }>();
    const { groups, roles, sodPairs, stats, tab, errors } = page.props;

    // The tab lives in the URL rather than component state: the write path returns back(), so a
    // save from Roles has to land on Roles, and an admin tool is something people paste links to.
    const go = (next: RbacTab) =>
        router.get(
            '/super-admin/rbac',
            { tab: next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const tabs: { key: RbacTab; label: string; count?: number }[] = [
        { key: 'catalog', label: 'Catalog', count: stats.permissionCount },
        { key: 'roles', label: 'Roles', count: stats.roleCount },
        { key: 'history', label: 'History' },
    ];

    return (
        <>
            <Head title="Roles & permissions" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero ─────────────────────────────────────────── */}
                    <div className="rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex items-center gap-3">
                            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950 dark:to-violet-950">
                                <ShieldCheck
                                    className="h-6 w-6 text-indigo-600 dark:text-indigo-400"
                                    aria-hidden
                                />
                            </span>
                            <div className="min-w-0">
                                <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                    Roles &amp; permissions
                                </h1>
                                <p className="text-xs text-slate-500">
                                    Permissions and roles are defined in code —
                                    this console edits which role is granted
                                    what.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* ── Stats ────────────────────────────────────────── */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FinanceStatCard
                            icon={KeyRound}
                            tone="indigo"
                            label="Permissions"
                            value={String(stats.permissionCount)}
                            subText={`across ${stats.groupCount} groups`}
                        />
                        <FinanceStatCard
                            icon={Layers}
                            tone="emerald"
                            label="Grants"
                            value={String(stats.grantCount)}
                            subText={`over ${stats.roleCount} roles`}
                        />
                        <FinanceStatCard
                            icon={Unplug}
                            tone={
                                stats.unusedPermissionCount > 0
                                    ? 'amber'
                                    : 'slate'
                            }
                            label="Unused permissions"
                            value={String(stats.unusedPermissionCount)}
                            subText="granted to no role"
                        />
                        <FinanceStatCard
                            icon={ShieldCheck}
                            tone="slate"
                            label="Roles requiring 2FA"
                            value={`${stats.twoFactorRoleCount} of ${stats.roleCount}`}
                            subText={
                                stats.rolesWithoutHolders > 0
                                    ? `${stats.rolesWithoutHolders} role${stats.rolesWithoutHolders === 1 ? '' : 's'} with no holders`
                                    : 'every role has holders'
                            }
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

                    {tab === 'catalog' && <CatalogTab groups={groups} />}
                    {tab === 'roles' && (
                        <RolesTab
                            roles={roles}
                            groups={groups}
                            sodPairs={sodPairs}
                            errors={errors ?? {}}
                        />
                    )}
                    {tab === 'history' && <HistoryTab />}
                </div>
            </div>
        </>
    );
}
