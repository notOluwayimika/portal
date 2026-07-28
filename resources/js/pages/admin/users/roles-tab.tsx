// ═══════════════════════════════════════════════════════════════════════════
// ROLES TAB — read-only.
//
// A school admin assigns roles; they do not define what a role grants. That is
// SyncRolePermissionsRequest's territory and it refuses anyone who is not a
// super admin, so offering an edit here would be a control the server rejects.
//
// It earns its place by answering the question you have WHILE assigning:
// "what does head_of_school actually let someone do, and who here already has
// it?" — which the old page could not answer at all.
// ═══════════════════════════════════════════════════════════════════════════

import { useMemo, useState } from 'react';
import {
    ExpandChevron,
    FilterRow,
    GroupIcon,
    RbacBadge,
    TableEmptyRow,
} from '@/components/rbac/rbac-ui';
import type { RbacGroup, SchoolRole } from '@/types/rbac';

export function RolesTab({
    roles,
    groups,
}: {
    roles: SchoolRole[];
    groups: RbacGroup[];
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState<string | null>(null);

    const term = query.trim().toLowerCase();

    // Client-side is right here: the role list is small and complete in props, so the counter
    // below reads the same array it filters and cannot disagree with it.
    const visible = useMemo(
        () =>
            roles.filter(
                (role) =>
                    !term ||
                    role.name.toLowerCase().includes(term) ||
                    role.permissions.some((p) =>
                        p.toLowerCase().includes(term),
                    ),
            ),
        [roles, term],
    );

    const groupOf = useMemo(() => {
        const map: Record<string, RbacGroup> = {};

        for (const group of groups) {
            for (const permission of group.permissions) {
                map[permission.name] = group;
            }
        }

        return map;
    }, [groups]);

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <FilterRow
                value={query}
                onChange={setQuery}
                placeholder="Search roles, or a permission a role grants…"
            >
                <span className="text-[11px] text-slate-400 sm:ml-auto">
                    Showing {visible.length} of {roles.length}
                </span>
            </FilterRow>

            <div className="custom-scrollbar overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="bg-slate-50/50 dark:bg-slate-900/40">
                        <tr className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                            <th className="px-4 py-2.5 text-left">Role</th>
                            <th className="px-4 py-2.5 text-left">
                                In this school
                            </th>
                            <th className="px-4 py-2.5 text-right">Grants</th>
                        </tr>
                    </thead>

                    {visible.length === 0 && (
                        <tbody>
                            <TableEmptyRow
                                colSpan={3}
                                title="No roles match"
                                description="Roles are defined in code and cannot be created here."
                                onClear={() => setQuery('')}
                            />
                        </tbody>
                    )}

                    {visible.map((role) => {
                        const expanded = open === role.name;

                        const byGroup: Record<string, string[]> = {};

                        for (const permission of role.permissions) {
                            const key = groupOf[permission]?.key ?? 'other';
                            (byGroup[key] ??= []).push(permission);
                        }

                        return (
                            <tbody
                                key={role.name}
                                className="divide-y divide-slate-100 dark:divide-slate-800"
                            >
                                <tr className="hover:bg-slate-50/60 dark:hover:bg-slate-900/40">
                                    <td className="px-4 py-2.5">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpen(
                                                    expanded ? null : role.name,
                                                )
                                            }
                                            aria-expanded={expanded}
                                            aria-controls={`role-${role.name}`}
                                            className="flex w-full items-center gap-2 text-left"
                                        >
                                            <ExpandChevron open={expanded} />
                                            <span className="font-mono text-xs font-bold text-slate-900 dark:text-white">
                                                {role.name}
                                            </span>
                                            {!role.assignable && (
                                                <RbacBadge
                                                    tone="slate"
                                                    title={
                                                        role.unassignableReason ??
                                                        undefined
                                                    }
                                                >
                                                    Not assignable here
                                                </RbacBadge>
                                            )}
                                            {role.twoFactorRequired && (
                                                <RbacBadge tone="emerald">
                                                    2FA required
                                                </RbacBadge>
                                            )}
                                            {role.holdsMaker && (
                                                <RbacBadge tone="indigo">
                                                    Maker
                                                </RbacBadge>
                                            )}
                                            {role.holdsChecker && (
                                                <RbacBadge tone="violet">
                                                    Checker
                                                </RbacBadge>
                                            )}
                                        </button>
                                    </td>

                                    <td className="px-4 py-2.5">
                                        {role.holderCount === 0 ? (
                                            <span className="text-[11px] text-amber-600 dark:text-amber-400">
                                                nobody holds this
                                            </span>
                                        ) : (
                                            <>
                                                <span className="font-bold text-slate-900 tabular-nums dark:text-white">
                                                    {role.holderCount}
                                                </span>
                                                <span className="text-slate-500">
                                                    {' '}
                                                    {role.holderCount === 1
                                                        ? 'person'
                                                        : 'people'}
                                                </span>
                                            </>
                                        )}
                                    </td>

                                    <td className="px-4 py-2.5 text-right tabular-nums">
                                        <span className="font-bold text-slate-900 dark:text-white">
                                            {role.permissionCount}
                                        </span>
                                        <span className="text-slate-400">
                                            {' '}
                                            permissions
                                        </span>
                                    </td>
                                </tr>

                                {expanded && (
                                    <tr id={`role-${role.name}`}>
                                        <td
                                            colSpan={3}
                                            className="space-y-2 bg-slate-50/40 px-4 py-3 dark:bg-slate-900/30"
                                        >
                                            {role.permissions.length === 0 ? (
                                                <p className="text-[11px] text-slate-500">
                                                    This role grants nothing.
                                                </p>
                                            ) : (
                                                groups
                                                    .filter(
                                                        (g) =>
                                                            (
                                                                byGroup[
                                                                    g.key
                                                                ] ?? []
                                                            ).length > 0,
                                                    )
                                                    .map((g) => (
                                                        <div key={g.key}>
                                                            <p className="mb-1 flex items-center gap-1.5 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                                                <GroupIcon
                                                                    name={
                                                                        g.icon
                                                                    }
                                                                    className="h-3 w-3"
                                                                />
                                                                {g.label}
                                                                <span className="font-normal normal-case">
                                                                    {
                                                                        byGroup[
                                                                            g
                                                                                .key
                                                                        ].length
                                                                    }
                                                                </span>
                                                            </p>
                                                            <div className="flex flex-wrap gap-1">
                                                                {byGroup[
                                                                    g.key
                                                                ].map((p) => (
                                                                    <code
                                                                        key={p}
                                                                        className="rounded bg-white px-1.5 py-0.5 font-mono text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                                                    >
                                                                        {p}
                                                                    </code>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    ))
                                            )}
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
