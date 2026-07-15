import { Head, router, useForm } from '@inertiajs/react';
import {
    Building2,
    GraduationCap,
    ImageUp,
    Pencil,
    Plus,
    Trash2,
    UserCog,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SchoolRow = {
    uuid: string;
    name: string;
    slug: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    name_on_result: string | null;
    fallback_signature_url: string | null;
    result_approver_name: string | null;
    active: boolean;
    students_count: number;
    teachers_count: number;
    users_count: number;
};

type Props = {
    schools: SchoolRow[];
};

type SchoolFormData = {
    name: string;
    address: string;
    phone: string;
    email: string;
    website: string;
    name_on_result: string;
    result_approver_name: string;
    active: boolean;
};

function SchoolFormDialog({
    school,
    open,
    onClose,
}: {
    school: SchoolRow | null;
    open: boolean;
    onClose: () => void;
}) {
    const isEdit = !!school;

    const { data, setData, post, put, processing, errors, reset } =
        useForm<SchoolFormData>({
            name: school?.name ?? '',
            address: school?.address ?? '',
            phone: school?.phone ?? '',
            email: school?.email ?? '',
            website: school?.website ?? '',
            name_on_result: school?.name_on_result ?? '',
            result_approver_name: school?.result_approver_name ?? '',
            active: school?.active ?? true,
        });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (isEdit) {
            put(`/super-admin/schools/${school.uuid}`, options);
        } else {
            post('/super-admin/schools', options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${school.name}` : 'Create school'}
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="space-y-1">
                        <Label htmlFor="school-name">Name</Label>
                        <Input
                            id="school-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="school-address">Address</Label>
                        <Input
                            id="school-address"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                        <InputError message={errors.address} />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1">
                            <Label htmlFor="school-phone">Phone</Label>
                            <Input
                                id="school-phone"
                                value={data.phone}
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                            />
                            <InputError message={errors.phone} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="school-email">Email</Label>
                            <Input
                                id="school-email"
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                            <InputError message={errors.email} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="school-website">Website</Label>
                        <Input
                            id="school-website"
                            type="url"
                            placeholder="https://example.sch.ng"
                            value={data.website}
                            onChange={(e) => setData('website', e.target.value)}
                        />
                        <InputError message={errors.website} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="school-name-on-result">
                            Name on result cards
                        </Label>
                        <Input
                            id="school-name-on-result"
                            placeholder="Defaults to the school name"
                            value={data.name_on_result}
                            onChange={(e) =>
                                setData('name_on_result', e.target.value)
                            }
                        />
                        <InputError message={errors.name_on_result} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="school-result-approver-name">
                            Fallback result approver name
                        </Label>
                        <Input
                            id="school-result-approver-name"
                            placeholder="e.g. Dr Jane Doe"
                            value={data.result_approver_name}
                            onChange={(e) =>
                                setData('result_approver_name', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Displayed below the fallback signature as the person
                            who reviewed and approved the result.
                        </p>
                        <InputError message={errors.result_approver_name} />
                    </div>

                    {isEdit && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.active}
                                onCheckedChange={(v) => setData('active', !!v)}
                            />
                            Active
                        </label>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEdit ? 'Save changes' : 'Create school'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FallbackSignatureForm({ school }: { school: SchoolRow }) {
    const form = useForm<{ signature: File | null }>({ signature: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/super-admin/schools/${school.uuid}/fallback-signature`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-2 border-t pt-3">
            <div className="flex items-center justify-between gap-2">
                <p className="font-medium text-foreground">
                    Fallback result signature
                </p>
                {school.fallback_signature_url && (
                    <img
                        src={school.fallback_signature_url}
                        alt={`${school.name} fallback signature`}
                        className="h-10 max-w-28 object-contain"
                    />
                )}
            </div>
            <Input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={(event) =>
                    form.setData('signature', event.target.files?.[0] ?? null)
                }
            />
            <InputError message={form.errors.signature} />
            <div className="flex justify-end gap-2">
                {school.fallback_signature_url && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.delete(
                                `/super-admin/schools/${school.uuid}/fallback-signature`,
                                {
                                    preserveScroll: true,
                                },
                            )
                        }
                    >
                        <Trash2 className="h-4 w-4" /> Remove
                    </Button>
                )}
                <Button
                    type="submit"
                    size="sm"
                    disabled={!form.data.signature || form.processing}
                >
                    <ImageUp className="h-4 w-4" />
                    {school.fallback_signature_url ? 'Replace' : 'Upload'}
                </Button>
            </div>
        </form>
    );
}

export default function SuperAdminSchools({ schools }: Props) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<SchoolRow | null>(null);

    return (
        <div className="flex flex-col gap-4 p-4">
            <Head title="Schools" />

            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold">Schools</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage all schools on the platform.
                    </p>
                </div>
                <Button onClick={() => setCreating(true)}>
                    <Plus className="h-4 w-4" />
                    New school
                </Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {schools.map((school) => (
                    <Card key={school.uuid}>
                        <CardHeader className="flex flex-row items-start justify-between space-y-0">
                            <div className="flex items-center gap-2">
                                <span className="flex h-9 w-9 items-center justify-center rounded-md bg-muted">
                                    <Building2 className="h-4 w-4" />
                                </span>
                                <div>
                                    <CardTitle className="text-base">
                                        {school.name}
                                    </CardTitle>
                                    {!school.active && (
                                        <Badge
                                            variant="destructive"
                                            className="mt-1"
                                        >
                                            Inactive
                                        </Badge>
                                    )}
                                </div>
                            </div>
                            <Button
                                size="icon"
                                variant="ghost"
                                onClick={() => setEditing(school)}
                                aria-label={`Edit ${school.name}`}
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-muted-foreground">
                            {school.address && <p>{school.address}</p>}
                            <div className="flex gap-4">
                                <span className="flex items-center gap-1">
                                    <GraduationCap className="h-4 w-4" />
                                    {school.students_count} students
                                </span>
                                <span className="flex items-center gap-1">
                                    <UserCog className="h-4 w-4" />
                                    {school.teachers_count} teachers
                                </span>
                            </div>
                            <FallbackSignatureForm school={school} />
                        </CardContent>
                    </Card>
                ))}

                {schools.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No schools yet. Create the first one.
                    </p>
                )}
            </div>

            {creating && (
                <SchoolFormDialog
                    school={null}
                    open={creating}
                    onClose={() => setCreating(false)}
                />
            )}
            {editing && (
                <SchoolFormDialog
                    key={editing.uuid}
                    school={editing}
                    open={!!editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}
