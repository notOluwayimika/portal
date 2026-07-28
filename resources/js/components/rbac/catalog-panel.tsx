// ═══════════════════════════════════════════════════════════════════════════
// CATALOG PANEL — read-only, shared by both RBAC consoles.
//
// Answers "what can be granted, and who holds it". Editing lives entirely in
// the Roles tab: one write path means one place for segregation-of-duties
// errors to surface, and one place a half-saved edit could hide.
// ═══════════════════════════════════════════════════════════════════════════

import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { RbacGroup, RbacPermission } from '@/types/rbac';
import {
    CoverageBar,
    ExpandChevron,
    FilterRow,
    GroupIcon,
    PermissionName,
    RbacBadge,
    TableEmptyRow,
} from './rbac-ui';

type Filter = 'all' | 'granted' | 'unused';

export function CatalogPanel({ groups }: { groups: RbacGroup[] }) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [openGroups, setOpenGroups] = useState<Set<string>>(new Set());
    const [openPermissions, setOpenPermissions] = useState<Set<string>>(
        new Set(),
    );

    const term = query.trim().toLowerCase();

    // Client-side filtering is correct HERE, and only here, because the whole catalogue ships with
    // the page — there is no server pagination for it to disagree with. §7's prohibition is about
    // a client filter that contradicts a server-paginated count; the counts below are computed
    // from this same filtered array, so they cannot.
    const matches = (permission: RbacPermission) => {
        if (filter === 'granted' && permission.unused) {
            return false;
        }

        if (filter === 'unused' && !permission.unused) {
            return false;
        }

        if (!term) {
            return true;
        }

        return (
            permission.name.toLowerCase().includes(term) ||
            permission.label.toLowerCase().includes(term) ||
            // Searching a ROLE name surfaces everything that role holds — the question a super
            // admin actually arrives with, and free from the inverted map.
            permission.roles.some((role) => role.toLowerCase().includes(term))
        );
    };

    const visible = useMemo(
        () =>
            groups
                .map((group) => ({
                    ...group,
                    permissions: group.permissions.filter(matches),
                }))
                .filter((group) => group.permissions.length > 0),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [groups, term, filter],
    );

    const shown = visible.reduce((n, g) => n + g.permissions.length, 0);
    const total = groups.reduce((n, g) => n + g.permissionCount, 0);
    const filtering = term !== '' || filter !== 'all';

    // A search is a question about permissions, not about groups — so open the groups that answer
    // it rather than making the user click through to find out which ones matched.
    const isOpen = (key: string) => filtering || openGroups.has(key);

    const toggle = (set: Set<string>, key: string) => {
        const next = new Set(set);

        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }

        return next;
    };

    const clear = () => {
        setQuery('');
        setFilter('all');
    };

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <FilterRow
                value={query}
                onChange={setQuery}
                placeholder="Search permissions, groups or role names…"
            >
                <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                    {(['all', 'granted', 'unused'] as const).map((key) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setFilter(key)}
                            className={cn(
                                'rounded-lg px-2.5 py-1 text-[11px] font-semibold capitalize transition-colors',
                                filter === key
                                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                    : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800',
                            )}
                        >
                            {key}
                        </button>
                    ))}
                    <span className="text-[11px] text-slate-400">
                        Showing {shown} of {total}
                    </span>
                    {filtering && (
                        <button
                            type="button"
                            onClick={clear}
                            className="rounded-lg px-2 py-1 text-[11px] font-semibold text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950"
                        >
                            Clear
                        </button>
                    )}
                </div>
            </FilterRow>

            <div className="custom-scrollbar overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="bg-slate-50/50 dark:bg-slate-900/40">
                        <tr className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                            <th className="px-4 py-2.5 text-left">Group</th>
                            <th className="px-4 py-2.5 text-right">
                                Permissions
                            </th>
                            <th className="px-4 py-2.5 text-left">Coverage</th>
                        </tr>
                    </thead>

                    {visible.length === 0 && (
                        <tbody>
                            <TableEmptyRow
                                colSpan={3}
                                title="No permissions match"
                                description="Try a different term, or clear the filters."
                                onClear={clear}
                            />
                        </tbody>
                    )}

                    {/* One tbody per group. Radix Collapsible renders divs, which are illegal
                        inside a table and get hoisted out by the parser — so expansion is a
                        second <tr> with a spanning cell instead. */}
                    {visible.map((group) => {
                        const open = isOpen(group.key);

                        return (
                            <tbody
                                key={group.key}
                                className="divide-y divide-slate-100 dark:divide-slate-800"
                            >
                                <tr className="hover:bg-slate-50/60 dark:hover:bg-slate-900/40">
                                    <td className="px-4 py-2.5">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpenGroups((s) =>
                                                    toggle(s, group.key),
                                                )
                                            }
                                            aria-expanded={open}
                                            aria-controls={`group-${group.key}`}
                                            className="flex w-full items-center gap-2.5 text-left"
                                        >
                                            <ExpandChevron open={open} />
                                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-indigo-50 to-violet-50 ring-1 ring-black/5 dark:from-indigo-950 dark:to-violet-950">
                                                <GroupIcon
                                                    name={group.icon}
                                                    className="h-4 w-4 text-indigo-600 dark:text-indigo-400"
                                                />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-xs font-bold text-slate-900 dark:text-white">
                                                    {group.label}
                                                </span>
                                                <span className="block text-[11px] text-slate-500">
                                                    {group.description}
                                                </span>
                                            </span>
                                        </button>
                                    </td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">
                                        <span className="font-bold text-slate-900 dark:text-white">
                                            {group.permissions.length}
                                        </span>
                                        {group.permissions.length !==
                                            group.permissionCount && (
                                            <span className="text-slate-400">
                                                {' '}
                                                of {group.permissionCount}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <div className="flex items-center gap-2">
                                            <CoverageBar
                                                granted={group.grantedCount}
                                                total={group.permissionCount}
                                            />
                                            <span className="text-[11px] text-slate-500">
                                                {group.grantedCount} granted
                                            </span>
                                            {group.grantedCount <
                                                group.permissionCount && (
                                                <RbacBadge tone="amber">
                                                    {group.permissionCount -
                                                        group.grantedCount}{' '}
                                                    unused
                                                </RbacBadge>
                                            )}
                                        </div>
                                    </td>
                                </tr>

                                {open && (
                                    <tr id={`group-${group.key}`}>
                                        <td
                                            colSpan={3}
                                            className="bg-slate-50/40 px-4 py-2 dark:bg-slate-900/30"
                                        >
                                            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                                                {group.permissions.map(
                                                    (permission) => (
                                                        <PermissionEntry
                                                            key={
                                                                permission.name
                                                            }
                                                            permission={
                                                                permission
                                                            }
                                                            open={openPermissions.has(
                                                                permission.name,
                                                            )}
                                                            onToggle={() =>
                                                                setOpenPermissions(
                                                                    (s) =>
                                                                        toggle(
                                                                            s,
                                                                            permission.name,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </ul>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        );
                    })}
                </table>
            </div>
        </div>
    );
}

function PermissionEntry({
    permission,
    open,
    onToggle,
}: {
    permission: RbacPermission;
    open: boolean;
    onToggle: () => void;
}) {
    return (
        <li>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                aria-controls={`perm-${permission.name}`}
                className="flex w-full items-center gap-2 py-1.5 text-left"
            >
                <ExpandChevron open={open} />
                <PermissionName name={permission.name} />
                <span className="truncate text-[11px] text-slate-400">
                    {permission.label}
                </span>

                <span className="ml-auto flex shrink-0 items-center gap-1.5">
                    {permission.isChecker && (
                        <RbacBadge
                            tone="violet"
                            title={`Checker action. Excluded from the super-admin bypass (ADR 0040)${permission.matchingMaker ? `. Maker: ${permission.matchingMaker}` : ''}`}
                        >
                            Checker
                        </RbacBadge>
                    )}
                    {permission.unused ? (
                        <RbacBadge
                            tone="amber"
                            title="Granted to no role. Either the feature it gated is gone, or a grant was revoked and never replaced."
                        >
                            Unused
                        </RbacBadge>
                    ) : (
                        <RbacBadge tone="slate">
                            {permission.roleCount}{' '}
                            {permission.roleCount === 1 ? 'role' : 'roles'}
                        </RbacBadge>
                    )}
                </span>
            </button>

            {open && (
                <div id={`perm-${permission.name}`} className="pb-2 pl-6">
                    {permission.roles.length === 0 ? (
                        <p className="text-[11px] text-slate-500">
                            No role holds this permission.
                        </p>
                    ) : (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-[11px] text-slate-500">
                                Held by
                            </span>
                            {permission.roles.map((role) => (
                                <RbacBadge key={role} tone="indigo">
                                    {role}
                                </RbacBadge>
                            ))}
                        </div>
                    )}

                    {permission.matchingMaker && (
                        <p className="mt-1.5 text-[11px] text-slate-500">
                            Signs off{' '}
                            <code className="font-mono text-[10px] text-slate-600 dark:text-slate-300">
                                {permission.matchingMaker}
                            </code>{' '}
                            — no single role may hold both.
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}
