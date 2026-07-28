// ═══════════════════════════════════════════════════════════════════════════
// USERS TAB — the one write surface in the school console.
//
// Search and pagination are SERVER-side here, unlike the super-admin console's
// client-side filtering. That is not inconsistency: a school has hundreds of
// users (847 in the first school on the dev database), so the whole list cannot
// ship with the page — and §7 forbids a client filter that disagrees with a
// server-paginated count. The roles and permissions tabs stay client-side
// because those sets are small and complete.
// ═══════════════════════════════════════════════════════════════════════════

import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Save, ShieldBan } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'react-toastify';
import { Pagination } from '@/components/pagination';
import {
    ExpandChevron,
    FilterRow,
    RbacBadge,
    TableEmptyRow,
} from '@/components/rbac/rbac-ui';
import { cn } from '@/lib/utils';
import type { SchoolRbacPageProps, SchoolRole, SchoolUser } from '@/types/rbac';

export function UsersTab({
    users,
    roles,
    assignableRoles,
    filters,
    errors,
}: {
    users: SchoolRbacPageProps['users'];
    roles: SchoolRole[];
    assignableRoles: string[];
    filters: { search: string | null; role: string | null };
    errors: Record<string, string>;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [open, setOpen] = useState<string | null>(null);
    const [saving, setSaving] = useState<string | null>(null);
    // Absent key means clean — the same shape as the super-admin console, so a successful save
    // cannot leave a stale draft making the Save button look enabled.
    const [drafts, setDrafts] = useState<Record<string, string[]>>({});

    // Debounced, because every keystroke is a server round trip here.
    useEffect(() => {
        if ((filters.search ?? '') === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                '/setup/users',
                {
                    tab: 'users',
                    search: search || undefined,
                    role: filters.role || undefined,
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 350);

        return () => clearTimeout(timer);
    }, [search, filters.search, filters.role]);

    const navigate = (params: Record<string, string | number | undefined>) =>
        router.get(
            '/setup/users',
            {
                tab: 'users',
                search: search || undefined,
                role: filters.role || undefined,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );

    const rolesByName = useMemo(
        () => Object.fromEntries(roles.map((r) => [r.name, r])),
        [roles],
    );

    const currentOf = (user: SchoolUser) => drafts[user.uuid] ?? user.roles;

    const save = (user: SchoolUser) => {
        setSaving(user.uuid);

        router.put(
            `/setup/users/${user.uuid}/roles`,
            { roles: currentOf(user) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDrafts((d) => {
                        const next = { ...d };
                        delete next[user.uuid];

                        return next;
                    });
                    toast.success(`Roles updated for ${user.name}`);
                },
                onFinish: () => setSaving(null),
            },
        );
    };

    const serverErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('roles'))
        .map(([, message]) => message);

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <FilterRow
                value={search}
                onChange={setSearch}
                placeholder="Search by name or email…"
            >
                <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                    <button
                        type="button"
                        onClick={() => navigate({ role: undefined, page: 1 })}
                        className={cn(
                            'rounded-lg px-2.5 py-1 text-[11px] font-semibold transition-colors',
                            !filters.role
                                ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800',
                        )}
                    >
                        All roles
                    </button>
                    {roles
                        .filter((r) => r.holderCount > 0)
                        .map((r) => (
                            <button
                                key={r.name}
                                type="button"
                                onClick={() =>
                                    navigate({ role: r.name, page: 1 })
                                }
                                className={cn(
                                    'rounded-lg px-2.5 py-1 font-mono text-[11px] font-semibold transition-colors',
                                    filters.role === r.name
                                        ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                        : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800',
                                )}
                            >
                                {r.name}
                                <span className="ml-1 text-slate-400">
                                    {r.holderCount}
                                </span>
                            </button>
                        ))}
                    <span className="text-[11px] text-slate-400">
                        {users.pagination.total} total
                    </span>
                </div>
            </FilterRow>

            {serverErrors.length > 0 && (
                <div className="border-b border-red-100 bg-red-50 px-5 py-2 dark:border-red-900 dark:bg-red-950/40">
                    <p className="flex items-center gap-1.5 text-[11px] font-bold text-red-700 dark:text-red-300">
                        <AlertTriangle className="h-3.5 w-3.5" aria-hidden />
                        That change was refused
                    </p>
                    <ul className="mt-1 list-disc space-y-0.5 pl-4">
                        {serverErrors.map((m) => (
                            <li
                                key={m}
                                className="text-[11px] text-red-700 dark:text-red-300"
                            >
                                {m}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="custom-scrollbar overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="bg-slate-50/50 dark:bg-slate-900/40">
                        <tr className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                            <th className="px-4 py-2.5 text-left">User</th>
                            <th className="px-4 py-2.5 text-left">
                                Roles here
                            </th>
                            <th className="px-4 py-2.5 text-right">Access</th>
                        </tr>
                    </thead>

                    {users.data.length === 0 && (
                        <tbody>
                            <TableEmptyRow
                                colSpan={3}
                                title="No users match"
                                description="Only users who already hold a role in this school appear here."
                                onClear={
                                    search || filters.role
                                        ? () => {
                                              setSearch('');
                                              navigate({
                                                  search: undefined,
                                                  role: undefined,
                                                  page: 1,
                                              });
                                          }
                                        : undefined
                                }
                            />
                        </tbody>
                    )}

                    {users.data.map((user) => {
                        const expanded = open === user.uuid;
                        const current = currentOf(user);
                        const added = current.filter(
                            (r) => !user.roles.includes(r),
                        );
                        const removed = user.roles.filter(
                            (r) => !current.includes(r),
                        );
                        const dirty = added.length > 0 || removed.length > 0;

                        // Total permissions this user would end up with — the number that says
                        // what a role change actually does, which naming roles alone does not.
                        const reach = new Set(
                            current.flatMap(
                                (r) => rolesByName[r]?.permissions ?? [],
                            ),
                        ).size;

                        return (
                            <tbody
                                key={user.uuid}
                                className="divide-y divide-slate-100 dark:divide-slate-800"
                            >
                                <tr className="hover:bg-slate-50/60 dark:hover:bg-slate-900/40">
                                    <td className="px-4 py-2.5">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpen(
                                                    expanded ? null : user.uuid,
                                                )
                                            }
                                            aria-expanded={expanded}
                                            aria-controls={`user-${user.uuid}`}
                                            className="flex w-full items-center gap-2 text-left"
                                        >
                                            <ExpandChevron open={expanded} />
                                            <span className="min-w-0">
                                                <span className="block truncate text-xs font-bold text-slate-900 dark:text-white">
                                                    {user.name}
                                                </span>
                                                <span className="block truncate text-[11px] text-slate-500">
                                                    {user.email}
                                                </span>
                                            </span>
                                            {dirty && (
                                                <RbacBadge tone="amber">
                                                    Unsaved
                                                </RbacBadge>
                                            )}
                                        </button>
                                    </td>

                                    <td className="px-4 py-2.5">
                                        {current.length === 0 ? (
                                            <span className="text-[11px] text-amber-600 dark:text-amber-400">
                                                no roles
                                            </span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {current.map((r) => (
                                                    <RbacBadge
                                                        key={r}
                                                        tone="indigo"
                                                    >
                                                        {r}
                                                    </RbacBadge>
                                                ))}
                                            </div>
                                        )}
                                    </td>

                                    <td className="px-4 py-2.5 text-right">
                                        {user.editable ? (
                                            <span className="text-slate-500 tabular-nums">
                                                <span className="font-bold text-slate-900 dark:text-white">
                                                    {reach}
                                                </span>{' '}
                                                permissions
                                            </span>
                                        ) : (
                                            <RbacBadge
                                                tone="slate"
                                                title={
                                                    user.lockReason ?? undefined
                                                }
                                            >
                                                <ShieldBan
                                                    className="h-3 w-3"
                                                    aria-hidden
                                                />
                                                Not editable
                                            </RbacBadge>
                                        )}
                                    </td>
                                </tr>

                                {expanded && (
                                    <tr id={`user-${user.uuid}`}>
                                        <td
                                            colSpan={3}
                                            className="bg-slate-50/40 px-4 py-3 dark:bg-slate-900/30"
                                        >
                                            {!user.editable ? (
                                                <p className="text-[11px] text-slate-500">
                                                    {user.lockReason}
                                                </p>
                                            ) : (
                                                <RolePicker
                                                    assignableRoles={
                                                        assignableRoles
                                                    }
                                                    rolesByName={rolesByName}
                                                    current={current}
                                                    added={added}
                                                    removed={removed}
                                                    saving={
                                                        saving === user.uuid
                                                    }
                                                    onChange={(next) =>
                                                        setDrafts((d) => ({
                                                            ...d,
                                                            [user.uuid]: next,
                                                        }))
                                                    }
                                                    onReset={() =>
                                                        setDrafts((d) => {
                                                            const n = { ...d };
                                                            delete n[user.uuid];

                                                            return n;
                                                        })
                                                    }
                                                    onSave={() => save(user)}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        );
                    })}
                </table>
            </div>

            {users.pagination.last_page > 1 && (
                <div className="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                    <Pagination
                        meta={users.pagination}
                        setPage={(page) => navigate({ page })}
                        setLimit={(per_page) => navigate({ per_page, page: 1 })}
                    />
                </div>
            )}
        </div>
    );
}

function RolePicker({
    assignableRoles,
    rolesByName,
    current,
    added,
    removed,
    saving,
    onChange,
    onReset,
    onSave,
}: {
    assignableRoles: string[];
    rolesByName: Record<string, SchoolRole>;
    current: string[];
    added: string[];
    removed: string[];
    saving: boolean;
    onChange: (next: string[]) => void;
    onReset: () => void;
    onSave: () => void;
}) {
    const dirty = added.length > 0 || removed.length > 0;

    // A role the user holds but this actor may not assign — e.g. `admin` seen by a school admin.
    // Shown, so the picture is honest, but not removable: taking it off would be a write the
    // server refuses, and hiding it would misrepresent what the person can do.
    const unmanageable = current.filter((r) => !assignableRoles.includes(r));

    const toggle = (role: string) =>
        onChange(
            current.includes(role)
                ? current.filter((r) => r !== role)
                : [...current, role],
        );

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-1.5">
                {assignableRoles.map((role) => {
                    const on = current.includes(role);
                    const meta = rolesByName[role];

                    return (
                        <button
                            key={role}
                            type="button"
                            onClick={() => toggle(role)}
                            aria-pressed={on}
                            title={
                                meta
                                    ? `${meta.permissionCount} permissions · ${meta.holderCount} in this school`
                                    : role
                            }
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 font-mono text-[10px] font-medium transition-colors',
                                on
                                    ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                    : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400',
                            )}
                        >
                            {role}
                            {meta && (
                                <span
                                    className={cn(
                                        'tabular-nums',
                                        on ? 'text-white/70' : 'text-slate-400',
                                    )}
                                >
                                    {meta.permissionCount}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {unmanageable.length > 0 && (
                <p className="text-[11px] text-slate-500">
                    Also holds{' '}
                    {unmanageable.map((r) => (
                        <code
                            key={r}
                            className="font-mono text-[10px] text-slate-600 dark:text-slate-300"
                        >
                            {r}{' '}
                        </code>
                    ))}
                    — {rolesByName[unmanageable[0]]?.unassignableReason}
                </p>
            )}

            {dirty && (
                <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
                    <span className="text-[11px] text-slate-500">
                        {added.length > 0 && (
                            <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                +{added.join(', ')}
                            </span>
                        )}
                        {added.length > 0 && removed.length > 0 && ' · '}
                        {removed.length > 0 && (
                            <span className="font-semibold text-rose-600 dark:text-rose-400">
                                −{removed.join(', ')}
                            </span>
                        )}
                    </span>

                    <div className="ml-auto flex items-center gap-2">
                        <button
                            type="button"
                            onClick={onReset}
                            disabled={saving}
                            className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            Discard
                        </button>
                        <button
                            type="button"
                            onClick={onSave}
                            disabled={saving}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1 text-[11px] font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {saving ? (
                                <Loader2
                                    className="h-3.5 w-3.5 animate-spin"
                                    aria-hidden
                                />
                            ) : (
                                <Save className="h-3.5 w-3.5" aria-hidden />
                            )}
                            {saving ? 'Saving…' : 'Save roles'}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
