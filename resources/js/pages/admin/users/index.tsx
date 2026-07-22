import { Head, router } from '@inertiajs/react';
import { Save, ShieldBan, Users } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'react-toastify';

interface SchoolUser {
    uuid: string;
    name: string;
    email: string;
    is_super_admin: boolean;
    is_self: boolean;
    roles: string[];
}

interface UsersIndexProps {
    users: SchoolUser[];
    assignable_roles: string[];
}

/**
 * The school-admin Users module (C5). The untargetable rows (super admins,
 * yourself) mirror the backend guards (c5-brief D1/D3) — the UI never offers a
 * write the server will refuse. assignable_roles mirrors D2: `admin` appears
 * only when the acting user is a super admin.
 */
function UserRow({
    user,
    assignableRoles,
}: {
    user: SchoolUser;
    assignableRoles: string[];
}) {
    const [roles, setRoles] = useState<string[]>(user.roles);
    const [saving, setSaving] = useState(false);

    const dirty =
        roles.length !== user.roles.length ||
        roles.some((role) => !user.roles.includes(role));

    const toggle = (role: string) => {
        setRoles((current) =>
            current.includes(role)
                ? current.filter((r) => r !== role)
                : [...current, role],
        );
    };

    const save = () => {
        setSaving(true);
        router.put(
            `/setup/users/${user.uuid}/roles`,
            { roles },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Roles updated for ${user.name}`),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors).flat().join(' ') ||
                            'Failed to update roles',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    const locked = user.is_super_admin || user.is_self;

    return (
        <div className="border-b border-gray-100 px-5 py-4 last:border-0">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-medium text-gray-900">{user.name}</p>
                    <p className="text-sm text-gray-500">{user.email}</p>
                </div>

                {locked ? (
                    <span
                        className="flex items-center gap-1.5 rounded-md bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500"
                        title={
                            user.is_super_admin
                                ? 'Super admin accounts cannot be modified here.'
                                : 'You cannot modify your own roles.'
                        }
                    >
                        <ShieldBan className="h-3.5 w-3.5" />
                        {user.is_super_admin
                            ? 'Super admin — not editable'
                            : 'Your account — not editable'}
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={save}
                        disabled={!dirty || saving}
                        className="flex items-center gap-1.5 rounded-md bg-blue-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-40"
                    >
                        <Save className="h-3.5 w-3.5" />
                        {saving ? 'Saving…' : 'Save roles'}
                    </button>
                )}
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
                {user.is_super_admin ? (
                    <span className="rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-700">
                        super_admin
                    </span>
                ) : (
                    assignableRoles.map((role) => {
                        const active = roles.includes(role);

                        return (
                            <button
                                key={role}
                                type="button"
                                disabled={locked}
                                onClick={() => toggle(role)}
                                aria-pressed={active}
                                className={`rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors disabled:cursor-not-allowed ${
                                    active
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-gray-100 text-gray-500 hover:bg-gray-200'
                                }`}
                            >
                                {role}
                            </button>
                        );
                    })
                )}
            </div>
        </div>
    );
}

export default function UsersIndex({
    users,
    assignable_roles,
}: UsersIndexProps) {
    return (
        <div className="space-y-6 p-4">
            <Head title="Users" />
            <div>
                <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-900">
                    <Users className="h-5 w-5" /> Users
                </h1>
                <p className="text-sm text-gray-500">
                    Manage which roles each user holds in this school. Role
                    changes take effect immediately and are recorded in the
                    activity log.
                </p>
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                {users.length === 0 ? (
                    <p className="p-8 text-center text-sm text-gray-500">
                        No users belong to this school yet.
                    </p>
                ) : (
                    users.map((user) => (
                        <UserRow
                            key={user.uuid}
                            user={user}
                            assignableRoles={assignable_roles}
                        />
                    ))
                )}
            </div>
        </div>
    );
}
