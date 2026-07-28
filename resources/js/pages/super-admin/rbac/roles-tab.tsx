// ═══════════════════════════════════════════════════════════════════════════
// ROLES TAB — the one write surface.
//
// Edits accumulate into a per-role draft and save in one request. That is not
// only a UX preference: the segregation-of-duties validators check the
// RESULTING permission set, so swapping result.submit → result.approve is
// legal as a batch and illegal in EITHER order one toggle at a time. Saving
// per toggle would refuse edits that are perfectly valid.
// ═══════════════════════════════════════════════════════════════════════════

import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Save, ShieldCheck, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'react-toastify';
import {
    syncPermissions,
    toggleTwoFactor,
} from '@/actions/App/Http/Controllers/SuperAdmin/RbacMatrixController';
import {
    ExpandChevron,
    FilterRow,
    GroupIcon,
    RbacBadge,
    TableEmptyRow,
} from '@/components/rbac/rbac-ui';
import { cn } from '@/lib/utils';
import type { RbacGroup, RbacRole, RbacSodPair } from '@/types/rbac';

export function RolesTab({
    roles,
    groups,
    sodPairs,
    errors,
}: {
    roles: RbacRole[];
    groups: RbacGroup[];
    sodPairs: RbacSodPair[];
    errors: Record<string, string>;
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState<string | null>(null);
    const [saving, setSaving] = useState<string | null>(null);

    // An ABSENT key means clean. The previous page mirrored props into state once and never
    // re-synced, so `dirty` was computed against a stale baseline and Save stayed enabled after a
    // successful save. Deriving `current` from props unless a draft exists — and deleting the key
    // on success — removes the bug class rather than patching one instance of it.
    const [drafts, setDrafts] = useState<Record<string, string[]>>({});

    const term = query.trim().toLowerCase();

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

    const currentOf = (role: RbacRole) => drafts[role.name] ?? role.permissions;

    const save = (role: RbacRole) => {
        const permissions = currentOf(role);
        setSaving(role.name);

        router.put(
            syncPermissions.url({ roleName: role.name }),
            { permissions },
            {
                preserveScroll: true,
                // Keep the tab in the URL so back() lands here, not on Catalog.
                onSuccess: () => {
                    setDrafts((d) => {
                        const next = { ...d };
                        delete next[role.name];

                        return next;
                    });
                    toast.success(`Permissions updated for ${role.name}`);
                },
                // Errors are rendered INLINE below, not thrown at a toast — they are multi-clause
                // remediation instructions naming a user, two abilities and a school, and a toast
                // truncates and vanishes.
                onFinish: () => setSaving(null),
            },
        );
    };

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <FilterRow
                value={query}
                onChange={setQuery}
                placeholder="Search roles, or a permission a role holds…"
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
                            <th className="px-4 py-2.5 text-left">Holders</th>
                            <th className="px-4 py-2.5 text-right">
                                Permissions
                            </th>
                            <th className="px-4 py-2.5 text-left">2FA</th>
                        </tr>
                    </thead>

                    {visible.length === 0 && (
                        <tbody>
                            <TableEmptyRow
                                colSpan={4}
                                title="No roles match"
                                description="Roles are defined in code (RbacSeeder::ROLES) and cannot be created here."
                                onClear={() => setQuery('')}
                            />
                        </tbody>
                    )}

                    {visible.map((role) => {
                        const expanded = open === role.name;
                        const current = currentOf(role);
                        const added = current.filter(
                            (p) => !role.permissions.includes(p),
                        );
                        const removed = role.permissions.filter(
                            (p) => !current.includes(p),
                        );
                        const dirty = added.length > 0 || removed.length > 0;

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
                                            {!role.editable && (
                                                <RbacBadge
                                                    tone="slate"
                                                    title={
                                                        role.immutableReason ??
                                                        undefined
                                                    }
                                                >
                                                    Immutable
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
                                            {dirty && (
                                                <RbacBadge tone="amber">
                                                    Unsaved
                                                </RbacBadge>
                                            )}
                                        </button>
                                    </td>

                                    <td className="px-4 py-2.5">
                                        {/* People, not pivot rows. Someone holding this role in
                                            three schools is one person — showing assignments as
                                            "users" would treble the apparent blast radius. */}
                                        <span className="font-bold text-slate-900 tabular-nums dark:text-white">
                                            {role.holderCount}
                                        </span>
                                        <span className="text-slate-500">
                                            {' '}
                                            {role.holderCount === 1
                                                ? 'person'
                                                : 'people'}
                                        </span>
                                        {role.assignmentCount !==
                                            role.holderCount && (
                                            <span className="block text-[11px] text-slate-400">
                                                {role.assignmentCount}{' '}
                                                assignments · {role.schoolCount}{' '}
                                                schools
                                            </span>
                                        )}
                                        {role.holderCount === 0 && (
                                            <span className="block text-[11px] text-amber-600 dark:text-amber-400">
                                                nobody holds this
                                            </span>
                                        )}
                                    </td>

                                    <td className="px-4 py-2.5 text-right tabular-nums">
                                        <span className="font-bold text-slate-900 dark:text-white">
                                            {current.length}
                                        </span>
                                        <span className="text-slate-400">
                                            {' '}
                                            granted
                                        </span>
                                    </td>

                                    <td className="px-4 py-2.5">
                                        <TwoFactorToggle role={role} />
                                    </td>
                                </tr>

                                {expanded && (
                                    <tr id={`role-${role.name}`}>
                                        <td
                                            colSpan={4}
                                            className="bg-slate-50/40 px-4 py-3 dark:bg-slate-900/30"
                                        >
                                            <RoleEditor
                                                role={role}
                                                groups={groups}
                                                sodPairs={sodPairs}
                                                current={current}
                                                added={added}
                                                removed={removed}
                                                errors={errors}
                                                saving={saving === role.name}
                                                onChange={(next) =>
                                                    setDrafts((d) => ({
                                                        ...d,
                                                        [role.name]: next,
                                                    }))
                                                }
                                                onReset={() =>
                                                    setDrafts((d) => {
                                                        const n = { ...d };
                                                        delete n[role.name];

                                                        return n;
                                                    })
                                                }
                                                onSave={() => save(role)}
                                            />
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

function TwoFactorToggle({ role }: { role: RbacRole }) {
    const [busy, setBusy] = useState(false);

    if (!role.editable) {
        return (
            <RbacBadge tone={role.twoFactorRequired ? 'emerald' : 'slate'}>
                {role.twoFactorRequired ? 'Required' : 'Not required'}
            </RbacBadge>
        );
    }

    return (
        <button
            type="button"
            disabled={busy}
            aria-pressed={role.twoFactorRequired}
            onClick={() => {
                setBusy(true);
                router.put(
                    toggleTwoFactor.url({ roleName: role.name }),
                    // The endpoint's field is literally named `required`.
                    { required: !role.twoFactorRequired },
                    {
                        preserveScroll: true,
                        onSuccess: () =>
                            toast.success(
                                `Two-factor ${role.twoFactorRequired ? 'no longer required' : 'now required'} for ${role.name}`,
                            ),
                        onError: () =>
                            toast.error('Failed to update two-factor'),
                        onFinish: () => setBusy(false),
                    },
                );
            }}
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold transition-colors disabled:opacity-50',
                role.twoFactorRequired
                    ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300'
                    : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400',
            )}
        >
            {busy ? (
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden />
            ) : (
                <ShieldCheck className="h-3 w-3" aria-hidden />
            )}
            {role.twoFactorRequired ? 'Required' : 'Not required'}
        </button>
    );
}

function RoleEditor({
    role,
    groups,
    sodPairs,
    current,
    added,
    removed,
    errors,
    saving,
    onChange,
    onReset,
    onSave,
}: {
    role: RbacRole;
    groups: RbacGroup[];
    sodPairs: RbacSodPair[];
    current: string[];
    added: string[];
    removed: string[];
    errors: Record<string, string>;
    saving: boolean;
    onChange: (next: string[]) => void;
    onReset: () => void;
    onSave: () => void;
}) {
    const [showAll, setShowAll] = useState(false);
    const dirty = added.length > 0 || removed.length > 0;

    const serverErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('permissions'))
        .map(([, message]) => message);

    // Errors keyed by position map back to the chip that caused them. The server adds
    // `permissions.N` alongside the bag precisely so this needs no prose parsing.
    const erroredNames = new Set(
        Object.entries(errors)
            .filter(([key]) => /^permissions\.\d+$/.test(key))
            .map(([key]) => current[Number(key.split('.')[1])])
            .filter(Boolean),
    );

    /**
     * Would adding `name` put this role on both sides of a maker-checker pair? Caught before the
     * round trip. The server remains the authority — and the USER-level check (a member holding
     * the other side via a different role) cannot be pre-empted here without shipping user data,
     * so that one is left to the server.
     */
    const conflictOf = (name: string): string | null => {
        for (const pair of sodPairs) {
            if (pair.checker === name && current.includes(pair.maker)) {
                return pair.maker;
            }

            if (pair.maker === name && current.includes(pair.checker)) {
                return pair.checker;
            }
        }

        return null;
    };

    if (!role.editable) {
        return (
            <div className="space-y-2">
                <p className="text-[11px] text-slate-500">
                    {role.immutableReason}
                </p>
                <div className="flex flex-wrap gap-1.5">
                    {role.permissions.map((name) => (
                        <RbacBadge key={name} tone="indigo">
                            {name}
                        </RbacBadge>
                    ))}
                </div>
            </div>
        );
    }

    const toggle = (name: string) =>
        onChange(
            current.includes(name)
                ? current.filter((p) => p !== name)
                : [...current, name],
        );

    return (
        <div className="space-y-3">
            {serverErrors.length > 0 && (
                <div className="rounded-lg bg-red-50 px-3 py-2 dark:bg-red-950/40">
                    <p className="flex items-center gap-1.5 text-[11px] font-bold text-red-700 dark:text-red-300">
                        <AlertTriangle className="h-3.5 w-3.5" aria-hidden />
                        This change was refused
                    </p>
                    <ul className="mt-1 list-disc space-y-0.5 pl-4">
                        {serverErrors.map((message) => (
                            <li
                                key={message}
                                className="text-[11px] text-red-700 dark:text-red-300"
                            >
                                {message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {groups.map((group) => {
                const held = group.permissions.filter((p) =>
                    current.includes(p.name),
                );
                const shown = showAll ? group.permissions : held;

                if (shown.length === 0) {
                    return null;
                }

                return (
                    <div key={group.key}>
                        <p className="mb-1 flex items-center gap-1.5 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                            <GroupIcon name={group.icon} className="h-3 w-3" />
                            {group.label}
                            <span className="font-normal normal-case">
                                {held.length}/{group.permissionCount}
                            </span>
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {shown.map((permission) => {
                                const on = current.includes(permission.name);
                                const conflict = !on
                                    ? conflictOf(permission.name)
                                    : null;
                                const errored = erroredNames.has(
                                    permission.name,
                                );

                                return (
                                    <button
                                        key={permission.name}
                                        type="button"
                                        onClick={() => toggle(permission.name)}
                                        aria-pressed={on}
                                        title={
                                            conflict
                                                ? `Segregation of duties: this role already holds ${conflict}`
                                                : permission.label
                                        }
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-mono text-[10px] font-medium transition-colors',
                                            on
                                                ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                                : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400',
                                            errored &&
                                                'ring-2 ring-red-500 ring-offset-1',
                                            conflict && 'ring-1 ring-amber-400',
                                        )}
                                    >
                                        {permission.name}
                                        {on && (
                                            <X
                                                className="h-3 w-3"
                                                aria-hidden
                                            />
                                        )}
                                        {conflict && (
                                            <AlertTriangle
                                                className="h-3 w-3 text-amber-500"
                                                aria-hidden
                                            />
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                );
            })}

            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
                <button
                    type="button"
                    onClick={() => setShowAll((v) => !v)}
                    className="rounded-lg px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    {showAll ? 'Show only granted' : 'Add permissions…'}
                </button>

                {dirty && (
                    <>
                        <span className="text-[11px] text-slate-500">
                            {added.length > 0 && (
                                <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                    +{added.length} granted
                                </span>
                            )}
                            {added.length > 0 && removed.length > 0 && ' · '}
                            {removed.length > 0 && (
                                <span className="font-semibold text-rose-600 dark:text-rose-400">
                                    −{removed.length} revoked
                                </span>
                            )}
                            {/* Blast radius. The number that actually matters for a privilege
                                change, and the one the old page never showed. */}
                            {role.holderCount > 0 && (
                                <span className="text-slate-400">
                                    {' '}
                                    · affects {role.holderCount}{' '}
                                    {role.holderCount === 1
                                        ? 'person'
                                        : 'people'}{' '}
                                    across {role.schoolCount}{' '}
                                    {role.schoolCount === 1
                                        ? 'school'
                                        : 'schools'}
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
                                {saving ? 'Saving…' : 'Save changes'}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
