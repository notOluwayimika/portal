import { Head, router } from '@inertiajs/react';
import { Save, ShieldBan, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'react-toastify';

interface MatrixRole {
    name: string;
    editable: boolean;
    permissions: string[];
}

interface RbacMatrixProps {
    permissions: string[];
    roles: MatrixRole[];
}

/** Group dotted permission names by their prefix ('result.approve' → 'result'). */
function groupPermissions(permissions: string[]): Record<string, string[]> {
    const groups: Record<string, string[]> = {};

    for (const permission of permissions) {
        const dot = permission.indexOf('.');
        const group = dot === -1 ? 'general' : permission.slice(0, dot);
        (groups[group] ??= []).push(permission);
    }

    return groups;
}

/**
 * The super-admin RBAC matrix (C6). Grants only — roles and permissions are
 * code (the enum + seeder); nothing is created here. The super_admin row is
 * immutable (c6-brief D1) and renders read-only; the SoD pairing rule
 * (c6-brief D2) is enforced server-side and surfaced via validation errors.
 */
export default function RbacMatrix({ permissions, roles }: RbacMatrixProps) {
    const editableRoles = roles.filter((role) => role.editable);
    const superAdminRow = roles.find((role) => !role.editable);

    const [activeRole, setActiveRole] = useState<string>(
        editableRoles[0]?.name ?? '',
    );
    const [draft, setDraft] = useState<Record<string, string[]>>(() =>
        Object.fromEntries(roles.map((role) => [role.name, role.permissions])),
    );
    const [saving, setSaving] = useState(false);

    const grouped = useMemo(() => groupPermissions(permissions), [permissions]);

    const original = roles.find((role) => role.name === activeRole);
    const current = draft[activeRole] ?? [];
    const dirty =
        original !== undefined &&
        (current.length !== original.permissions.length ||
            current.some(
                (permission) => !original.permissions.includes(permission),
            ));

    const toggle = (permission: string) => {
        setDraft((state) => {
            const set = state[activeRole] ?? [];

            return {
                ...state,
                [activeRole]: set.includes(permission)
                    ? set.filter((entry) => entry !== permission)
                    : [...set, permission],
            };
        });
    };

    const save = () => {
        setSaving(true);
        router.put(
            `/super-admin/rbac/roles/${activeRole}/permissions`,
            { permissions: current },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Permissions updated for ${activeRole}`),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors).flat().join(' ') ||
                            'Failed to update permissions',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="space-y-6 p-4">
            <Head title="RBAC Matrix" />
            <div>
                <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-900">
                    <ShieldCheck className="h-5 w-5" /> Role permissions
                </h1>
                <p className="text-sm text-gray-500">
                    Site-wide role→permission grants. Roles and permissions are
                    defined in code; this page edits which grants each role
                    holds. Changes are recorded in the activity log and survive
                    re-seeding.
                </p>
            </div>

            <div className="flex flex-wrap gap-2">
                {editableRoles.map((role) => (
                    <button
                        key={role.name}
                        type="button"
                        onClick={() => setActiveRole(role.name)}
                        aria-pressed={activeRole === role.name}
                        className={`rounded-full px-3 py-1 text-sm font-medium transition-colors ${
                            activeRole === role.name
                                ? 'bg-blue-700 text-white'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                        }`}
                    >
                        {role.name}
                    </button>
                ))}
                {superAdminRow && (
                    <span
                        className="flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-400"
                        title="The super_admin row is immutable: its authority is the platform bypass, and its explicit grants are frozen."
                    >
                        <ShieldBan className="h-3.5 w-3.5" /> super_admin —
                        locked
                    </span>
                )}
            </div>

            <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div className="mb-4 flex items-center justify-between">
                    <p className="font-medium text-gray-900">{activeRole}</p>
                    <button
                        type="button"
                        onClick={save}
                        disabled={!dirty || saving}
                        className="flex items-center gap-1.5 rounded-md bg-blue-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-40"
                    >
                        <Save className="h-3.5 w-3.5" />
                        {saving ? 'Saving…' : 'Save changes'}
                    </button>
                </div>

                <div className="space-y-4">
                    {Object.entries(grouped).map(([group, names]) => (
                        <div key={group}>
                            <p className="mb-1.5 text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                {group}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {names.map((permission) => {
                                    const active = current.includes(permission);

                                    return (
                                        <button
                                            key={permission}
                                            type="button"
                                            onClick={() => toggle(permission)}
                                            aria-pressed={active}
                                            className={`rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors ${
                                                active
                                                    ? 'bg-blue-600 text-white'
                                                    : 'bg-gray-100 text-gray-500 hover:bg-gray-200'
                                            }`}
                                        >
                                            {permission}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
