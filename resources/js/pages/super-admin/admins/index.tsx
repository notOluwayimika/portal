import { Head, router, useForm } from '@inertiajs/react';
import { Building2, Plus, Shield } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import InputError from '@/components/input-error';
import { SchoolChecklist } from '@/components/school-access/school-checklist';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SchoolOption } from '@/types';

type Seat = { role: string; school: string | null };

type AdminRow = {
    uuid: string;
    name: string;
    email: string;
    disabled: boolean;
    schools: SchoolOption[];
    seats: Seat[];
};

type Props = {
    admins: AdminRow[];
    schools: SchoolOption[];
    /**
     * Every role this surface may assign, derived server-side from RbacSeeder::ROLES minus
     * ProvisionUserRequest::NEVER_ASSIGNABLE. Never hardcoded here: a client-side copy would drift
     * and, worse, would look authoritative while the server refused.
     */
    assignable_roles: string[];
};

/** `accounts_officer` → `Accounts officer`. Presentation only; the wire value is the role name. */
function roleLabel(role: string): string {
    const spaced = role.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function CreateUserDialog({
    schools,
    assignableRoles,
    open,
    onClose,
}: {
    schools: SchoolOption[];
    assignableRoles: string[];
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        first_name: string;
        last_name: string;
        email: string;
        password: string;
        roles: string[];
        schools: string[];
    }>({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        roles: [],
        schools: [],
    });

    const toggleSchool = (uuid: string) => {
        setData(
            'schools',
            data.schools.includes(uuid)
                ? data.schools.filter((s) => s !== uuid)
                : [...data.schools, uuid],
        );
    };

    const toggleRole = (role: string) => {
        setData(
            'roles',
            data.roles.includes(role)
                ? data.roles.filter((r) => r !== role)
                : [...data.roles, role],
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/super-admin/admins', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Provision a user</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1">
                            <Label htmlFor="admin-first-name">First name</Label>
                            <Input
                                id="admin-first-name"
                                value={data.first_name}
                                onChange={(e) =>
                                    setData('first_name', e.target.value)
                                }
                                required
                            />
                            <InputError message={errors.first_name} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="admin-last-name">Last name</Label>
                            <Input
                                id="admin-last-name"
                                value={data.last_name}
                                onChange={(e) =>
                                    setData('last_name', e.target.value)
                                }
                                required
                            />
                            <InputError message={errors.last_name} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="admin-email">Email</Label>
                        <Input
                            id="admin-email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            An address that already has an account is granted
                            the seats below — no second account is created, and
                            their password is not changed.
                        </p>
                        <InputError message={errors.email} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="admin-password">Password</Label>
                        <Input
                            id="admin-password"
                            type="password"
                            value={data.password}
                            onChange={(e) =>
                                setData('password', e.target.value)
                            }
                            autoComplete="new-password"
                        />
                        <p className="text-xs text-muted-foreground">
                            Required for a new account only. Roles that require
                            two-factor authentication enrol on first sign-in.
                        </p>
                        <InputError message={errors.password} />
                    </div>

                    <div className="space-y-1">
                        <Label>Roles</Label>
                        <div className="grid max-h-48 grid-cols-2 gap-1 overflow-y-auto rounded-md border p-2">
                            {assignableRoles.map((role) => (
                                <label
                                    key={role}
                                    className="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted"
                                >
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4"
                                        checked={data.roles.includes(role)}
                                        onChange={() => toggleRole(role)}
                                    />
                                    {roleLabel(role)}
                                </label>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Each selected role is granted in each selected
                            school. A combination that would let one person both
                            raise and approve the same thing is refused.
                        </p>
                        <InputError message={errors.roles} />
                    </div>

                    <div className="space-y-1">
                        <Label>School access</Label>
                        <SchoolChecklist
                            schools={schools}
                            selected={data.schools}
                            toggle={toggleSchool}
                        />
                        <InputError message={errors.schools} />
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Provision
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ManageSchoolsDialog({
    admin,
    schools,
    onClose,
}: {
    admin: AdminRow;
    schools: SchoolOption[];
    onClose: () => void;
}) {
    const [selected, setSelected] = useState<string[]>(
        admin.schools.map((s) => s.uuid),
    );
    // WHICH SEAT is being re-scoped, not just which schools. The endpoint syncs ONE role's school
    // set; without this the screen would silently send `admin` for a user who is an executive
    // director, granting a seat nobody chose and revoking one they hold. Defaults to a role they
    // actually have.
    const heldRoles = Array.from(new Set(admin.seats.map((s) => s.role)));
    const [role, setRole] = useState<string>(heldRoles[0] ?? 'admin');
    const [processing, setProcessing] = useState(false);

    const toggle = (uuid: string) => {
        setSelected((prev) =>
            prev.includes(uuid)
                ? prev.filter((s) => s !== uuid)
                : [...prev, uuid],
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.put(
            `/super-admin/admins/${admin.uuid}/schools`,
            { schools: selected, role },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>School access — {admin.name}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="space-y-1">
                        <Label htmlFor="seat-role">Seat</Label>
                        <select
                            id="seat-role"
                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            value={role}
                            onChange={(e) => setRole(e.target.value)}
                        >
                            {(heldRoles.length > 0 ? heldRoles : ['admin']).map(
                                (r) => (
                                    <option key={r} value={r}>
                                        {roleLabel(r)}
                                    </option>
                                ),
                            )}
                        </select>
                        <p className="text-xs text-muted-foreground">
                            The schools below apply to this seat only. Other
                            roles this person holds are not changed.
                        </p>
                    </div>

                    <SchoolChecklist
                        schools={schools}
                        selected={selected}
                        toggle={toggle}
                    />

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save access
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SuperAdminAdmins({
    admins,
    schools,
    assignable_roles: assignableRoles,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [managing, setManaging] = useState<AdminRow | null>(null);

    return (
        <div className="flex flex-col gap-4 p-4">
            <Head title="Users" />

            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold">Users</h1>
                    <p className="text-sm text-muted-foreground">
                        Create accounts, give them a seat, and control which
                        schools they can log into.
                    </p>
                </div>
                <Button onClick={() => setCreating(true)}>
                    <Plus className="h-4 w-4" />
                    Provision user
                </Button>
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-3 font-medium">Name</th>
                            <th className="px-4 py-3 font-medium">Email</th>
                            <th className="px-4 py-3 font-medium">Seats</th>
                            <th className="px-4 py-3 font-medium">Schools</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {admins.map((admin) => (
                            <tr key={admin.uuid} className="border-t">
                                <td className="px-4 py-3 font-medium">
                                    <span className="flex items-center gap-2">
                                        <Shield className="h-4 w-4 text-muted-foreground" />
                                        {admin.name}
                                        {admin.disabled && (
                                            <Badge variant="destructive">
                                                Disabled
                                            </Badge>
                                        )}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-muted-foreground">
                                    {admin.email}
                                </td>
                                <td className="px-4 py-3">
                                    <span className="flex flex-wrap gap-1">
                                        {admin.seats.map((seat, i) => (
                                            <Badge
                                                key={`${seat.role}-${seat.school ?? 'global'}-${i}`}
                                                variant="outline"
                                            >
                                                {roleLabel(seat.role)}
                                                {seat.school
                                                    ? ` · ${seat.school}`
                                                    : ''}
                                            </Badge>
                                        ))}
                                        {admin.seats.length === 0 && (
                                            <span className="text-muted-foreground">
                                                No seat
                                            </span>
                                        )}
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    <span className="flex flex-wrap gap-1">
                                        {admin.schools.map((school) => (
                                            <Badge
                                                key={school.uuid}
                                                variant="secondary"
                                            >
                                                {school.name}
                                            </Badge>
                                        ))}
                                        {admin.schools.length === 0 && (
                                            <span className="text-muted-foreground">
                                                No access
                                            </span>
                                        )}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setManaging(admin)}
                                    >
                                        <Building2 className="h-4 w-4" />
                                        Manage schools
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {admins.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-4 py-8 text-center text-muted-foreground"
                                >
                                    No admins yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {creating && (
                <CreateUserDialog
                    schools={schools}
                    assignableRoles={assignableRoles}
                    open={creating}
                    onClose={() => setCreating(false)}
                />
            )}
            {managing && (
                <ManageSchoolsDialog
                    key={managing.uuid}
                    admin={managing}
                    schools={schools}
                    onClose={() => setManaging(null)}
                />
            )}
        </div>
    );
}
