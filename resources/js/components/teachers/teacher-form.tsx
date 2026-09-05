import axios from 'axios';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ProfileImageUpload } from '@/components/ui/profile-image-upload';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Teacher } from '@/types/models';

interface TeacherFormProps {
    teacher?: Teacher | null;
    onSuccess: () => void;
    onCancel: () => void;
    formId?: string;
    onProcessingChange?: (v: boolean) => void;
}

interface TeacherFormData {
    first_name: string;
    last_name: string;
    email: string;
    staff_number: string;
    gender: string;
    date_of_birth: string;
    phone: string;
    address: string;
    qualification: string;
    hire_date: string;
    status: string;
    photo: File | null;
}

type FormErrors = Partial<Record<keyof TeacherFormData, string>>;

export function TeacherForm({
    teacher,
    onSuccess,
    formId = 'teacher-form',
    onProcessingChange,
}: TeacherFormProps) {
    const isEdit = !!teacher;

    const initialData: TeacherFormData = {
        first_name: teacher?.first_name || '',
        last_name: teacher?.last_name || '',
        email: teacher?.email || '',
        staff_number: teacher?.staff_number || '',
        gender: teacher?.gender || 'male',
        date_of_birth: teacher?.date_of_birth || '',
        phone: teacher?.phone || '',
        address: teacher?.address || '',
        qualification: teacher?.qualification || '',
        hire_date: teacher?.hire_date || '',
        status: teacher?.status || 'active',
        photo: null,
    };

    const [data, setFormData] = useState<TeacherFormData>(initialData);
    const [processing, setProcessing] = useState(false);

    // THE DEPENDENCY IS NAMED, NOT REF'D AWAY. All three parents pass a bare `useState`
    // setter — students/show.tsx:547, students/index.tsx:838, teachers/index.tsx:618 — and
    // React guarantees a setter's identity is stable for the component's lifetime, so this
    // effect fires exactly as often as it did before the dependency was added: when
    // `processing` changes. There is nothing to memoise and no reason for a latest-callback
    // ref, and the ref would be the worse answer beyond today: if a future parent passes an
    // inline arrow the effect will re-run more and that will be VISIBLE, whereas a ref would
    // swallow it. The dep array keeps a parent's instability the parent's problem.
    useEffect(() => {
        onProcessingChange?.(processing);
    }, [processing, onProcessingChange]);
    const [errors, setErrors] = useState<FormErrors>({});
    const [genders, setGenders] = useState<{ name: string; value: string }[]>(
        [],
    );
    const [statuses, setStatuses] = useState<{ name: string; value: string }[]>(
        [],
    );
    const [photoPreview, setPhotoPreview] = useState<string | null>(
        teacher?.photo ?? null,
    );
    const [manualStaffNumber, setManualStaffNumber] = useState(false);
    const [changeStaffNumber, setChangeStaffNumber] = useState(false);

    const setData = <K extends keyof TeacherFormData>(
        key: K,
        value: TeacherFormData[K],
    ) => {
        setFormData((prev) => ({ ...prev, [key]: value }));
    };

    useEffect(() => {
        if (!isEdit || !teacher) {
            return;
        }

        let isMounted = true;
        axios
            .get(`/api/teachers/${teacher.id}`)
            .then((res) => {
                const t = res.data;

                if (!isMounted || !t) {
                    return;
                }

                setFormData((prev) => ({
                    ...prev,
                    first_name: t.first_name || prev.first_name,
                    last_name: t.last_name || prev.last_name,
                    email: t.email || prev.email,
                    staff_number: t.staff_number || prev.staff_number,
                    gender: t.gender || prev.gender,
                    date_of_birth: t.date_of_birth || prev.date_of_birth,
                    phone: t.phone || prev.phone,
                    address: t.address || prev.address,
                    qualification: t.qualification || prev.qualification,
                    hire_date: t.hire_date || prev.hire_date,
                    status: t.status || prev.status,
                }));

                if (t.photo) {
                    setPhotoPreview(t.photo);
                }
            })
            .catch(() => {});

        return () => {
            isMounted = false;
        };
        // `isEdit` AND `teacher` NAMED, AND THE COST WAS MEASURED RATHER THAN ASSUMED. An earlier
        // report claimed the naive dependency costs an extra GET per parent render; that was an
        // inference and it was WRONG. The parent passes `teacher={currentTeacher}` where
        // `currentTeacher` is a `useState` value (teachers/index.tsx:77), so its identity is
        // stable between renders and changes only when `setCurrentTeacher` runs — which is when
        // `teacher?.id` already changes, except for one narrow case: a refreshed copy carrying the
        // SAME id after a save, which costs one extra GET once. `isEdit` is `!!teacher` and is
        // therefore derived and harmless.
    }, [teacher?.id, teacher, isEdit]);

    useEffect(() => {
        let isMounted = true;
        axios
            .get('/api/teachers/resources')
            .then((res) => {
                if (isMounted) {
                    setGenders(res.data.data.genders || []);
                    setStatuses(res.data.data.statuses || []);
                }
            })
            .catch((err) => console.error('Failed to fetch resources:', err));

        return () => {
            isMounted = false;
        };
    }, []);

    const handlePhotoChange = (file: File) => {
        setData('photo', file);
        const url = URL.createObjectURL(file);
        setPhotoPreview((prev) => {
            if (prev && prev.startsWith('blob:')) {
                URL.revokeObjectURL(prev);
            }

            return url;
        });
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const formData = new FormData();
        (Object.keys(data) as (keyof TeacherFormData)[]).forEach((key) => {
            const value = data[key];

            if (key === 'photo') {
                if (value instanceof File) {
                    formData.append('photo', value);
                }
            } else if (value !== null && value !== undefined) {
                formData.append(key, value as string);
            }
        });

        try {
            if (isEdit) {
                formData.append('_method', 'PATCH');
                await axios.post(`/api/teachers/${teacher.id}`, formData);
            } else {
                await axios.post('/api/teachers', formData);
            }

            onSuccess();
        } catch (err: any) {
            const raw = err?.response?.data?.errors;

            if (raw) {
                const flat: FormErrors = {};
                (Object.keys(raw) as (keyof TeacherFormData)[]).forEach((k) => {
                    flat[k] = Array.isArray(raw[k]) ? raw[k][0] : raw[k];
                });
                setErrors(flat);
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <form id={formId} onSubmit={handleSubmit} className="space-y-6">
            <ProfileImageUpload
                preview={photoPreview}
                onChange={handlePhotoChange}
                error={errors.photo}
            />

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="first_name">First Name</Label>
                    <Input
                        id="first_name"
                        value={data.first_name}
                        onChange={(e) => setData('first_name', e.target.value)}
                        required
                    />
                    {errors.first_name && (
                        <p className="text-xs text-destructive">
                            {errors.first_name}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="last_name">Last Name</Label>
                    <Input
                        id="last_name"
                        value={data.last_name}
                        onChange={(e) => setData('last_name', e.target.value)}
                        required
                    />
                    {errors.last_name && (
                        <p className="text-xs text-destructive">
                            {errors.last_name}
                        </p>
                    )}
                </div>

                <div className="col-span-1 space-y-2 md:col-span-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        placeholder="e.g. teacher@school.edu"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required={!isEdit}
                    />
                    {errors.email && (
                        <p className="text-xs text-destructive">
                            {errors.email}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="staff_number">Staff Number</Label>
                    {isEdit ? (
                        <>
                            <Input
                                id="staff_number"
                                value={data.staff_number}
                                onChange={(e) =>
                                    setData('staff_number', e.target.value)
                                }
                                disabled={!changeStaffNumber}
                                placeholder="e.g. STF/2024/001"
                            />
                            <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                                <input
                                    type="checkbox"
                                    checked={changeStaffNumber}
                                    onChange={(e) => {
                                        setChangeStaffNumber(e.target.checked);

                                        if (!e.target.checked) {
                                            setData(
                                                'staff_number',
                                                teacher?.staff_number || '',
                                            );
                                        }
                                    }}
                                    className="h-3.5 w-3.5 rounded border-input accent-primary"
                                />
                                Change staff number
                            </label>
                        </>
                    ) : (
                        <>
                            {manualStaffNumber ? (
                                <Input
                                    id="staff_number"
                                    placeholder="e.g. STF/2024/001"
                                    value={data.staff_number}
                                    onChange={(e) =>
                                        setData('staff_number', e.target.value)
                                    }
                                    autoFocus
                                />
                            ) : (
                                <div className="flex h-9 items-center rounded-md border border-dashed border-input bg-muted/40 px-3 text-sm text-muted-foreground">
                                    Auto-generated on save
                                </div>
                            )}
                            <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                                <input
                                    type="checkbox"
                                    checked={manualStaffNumber}
                                    onChange={(e) => {
                                        setManualStaffNumber(e.target.checked);

                                        if (!e.target.checked) {
                                            setData('staff_number', '');
                                        }
                                    }}
                                    className="h-3.5 w-3.5 rounded border-input accent-primary"
                                />
                                Enter staff number manually
                            </label>
                        </>
                    )}
                    {errors.staff_number && (
                        <p className="text-xs text-destructive">
                            {errors.staff_number}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label>Gender</Label>
                    <Select
                        value={data.gender}
                        onValueChange={(v) => setData('gender', v)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select gender" />
                        </SelectTrigger>
                        <SelectContent>
                            {genders.map((g) => (
                                <SelectItem key={g.value} value={g.value}>
                                    {g.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.gender && (
                        <p className="text-xs text-destructive">
                            {errors.gender}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="date_of_birth">Date of Birth</Label>
                    <Input
                        id="date_of_birth"
                        type="date"
                        value={data.date_of_birth}
                        onChange={(e) =>
                            setData('date_of_birth', e.target.value)
                        }
                    />
                    {errors.date_of_birth && (
                        <p className="text-xs text-destructive">
                            {errors.date_of_birth}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="phone">Phone</Label>
                    <Input
                        id="phone"
                        type="tel"
                        placeholder="e.g. +234 800 000 0000"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                    />
                    {errors.phone && (
                        <p className="text-xs text-destructive">
                            {errors.phone}
                        </p>
                    )}
                </div>

                <div className="col-span-1 space-y-2 md:col-span-2">
                    <Label htmlFor="address">Address</Label>
                    <Input
                        id="address"
                        placeholder="Residential address"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                    />
                    {errors.address && (
                        <p className="text-xs text-destructive">
                            {errors.address}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="qualification">Qualification</Label>
                    <Input
                        id="qualification"
                        placeholder="e.g. B.Ed Mathematics"
                        value={data.qualification}
                        onChange={(e) =>
                            setData('qualification', e.target.value)
                        }
                    />
                    {errors.qualification && (
                        <p className="text-xs text-destructive">
                            {errors.qualification}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="hire_date">Hire Date</Label>
                    <Input
                        id="hire_date"
                        type="date"
                        value={data.hire_date}
                        onChange={(e) => setData('hire_date', e.target.value)}
                    />
                    {errors.hire_date && (
                        <p className="text-xs text-destructive">
                            {errors.hire_date}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label>Status</Label>
                    <Select
                        value={data.status}
                        onValueChange={(v) => setData('status', v)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select status" />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.status && (
                        <p className="text-xs text-destructive">
                            {errors.status}
                        </p>
                    )}
                </div>
            </div>
        </form>
    );
}
