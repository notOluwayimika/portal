import axios from 'axios';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ProfileImageUpload } from '@/components/ui/profile-image-upload';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Teacher } from '@/types/models';

interface TeacherFormProps {
    teacher?: Teacher | null;
    onSuccess: () => void;
    onCancel: () => void;
}

interface TeacherFormData {
    first_name: string;
    last_name: string;
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

export function TeacherForm({ teacher, onSuccess, onCancel }: TeacherFormProps) {
    const isEdit = !!teacher;

    const initialData: TeacherFormData = {
        first_name:    teacher?.first_name    || '',
        last_name:     teacher?.last_name     || '',
        staff_number:  teacher?.staff_number  || '',
        gender:        teacher?.gender        || 'male',
        date_of_birth: teacher?.date_of_birth || '',
        phone:         teacher?.phone         || '',
        address:       teacher?.address       || '',
        qualification: teacher?.qualification || '',
        hire_date:     teacher?.hire_date     || '',
        status:        teacher?.status        || 'active',
        photo:         null,
    };

    const [data, setFormData]       = useState<TeacherFormData>(initialData);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors]       = useState<FormErrors>({});
    const [genders, setGenders]     = useState<{ name: string; value: string }[]>([]);
    const [statuses, setStatuses]   = useState<{ name: string; value: string }[]>([]);
    const [photoPreview, setPhotoPreview]       = useState<string | null>(teacher?.photo ?? null);
    const [manualStaffNumber, setManualStaffNumber] = useState(false);
    const [changeStaffNumber, setChangeStaffNumber] = useState(false);

    const setData = <K extends keyof TeacherFormData>(key: K, value: TeacherFormData[K]) => {
        setFormData((prev) => ({ ...prev, [key]: value }));
    };

    useEffect(() => {
        let isMounted = true;
        axios.get('/api/teachers/resources').then((res) => {
            if (isMounted) {
                setGenders(res.data.data.genders   || []);
                setStatuses(res.data.data.statuses || []);
            }
        }).catch((err) => console.error('Failed to fetch resources:', err));
        return () => { isMounted = false; };
    }, []);

    const handlePhotoChange = (file: File) => {
        setData('photo', file);
        const url = URL.createObjectURL(file);
        setPhotoPreview((prev) => {
            if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev);
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
                if (value instanceof File) formData.append('photo', value);
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
        <form onSubmit={handleSubmit} className="space-y-6">
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
                    {errors.first_name && <p className="text-destructive text-xs">{errors.first_name}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="last_name">Last Name</Label>
                    <Input
                        id="last_name"
                        value={data.last_name}
                        onChange={(e) => setData('last_name', e.target.value)}
                        required
                    />
                    {errors.last_name && <p className="text-destructive text-xs">{errors.last_name}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="staff_number">Staff Number</Label>
                    {isEdit ? (
                        <>
                            <Input
                                id="staff_number"
                                value={data.staff_number}
                                onChange={(e) => setData('staff_number', e.target.value)}
                                disabled={!changeStaffNumber}
                                placeholder="e.g. STF/2024/001"
                            />
                            <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                                <input
                                    type="checkbox"
                                    checked={changeStaffNumber}
                                    onChange={(e) => {
                                        setChangeStaffNumber(e.target.checked);
                                        if (!e.target.checked) setData('staff_number', teacher?.staff_number || '');
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
                                    onChange={(e) => setData('staff_number', e.target.value)}
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
                                        if (!e.target.checked) setData('staff_number', '');
                                    }}
                                    className="h-3.5 w-3.5 rounded border-input accent-primary"
                                />
                                Enter staff number manually
                            </label>
                        </>
                    )}
                    {errors.staff_number && <p className="text-destructive text-xs">{errors.staff_number}</p>}
                </div>

                <div className="space-y-2">
                    <Label>Gender</Label>
                    <Select value={data.gender} onValueChange={(v) => setData('gender', v)}>
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
                    {errors.gender && <p className="text-destructive text-xs">{errors.gender}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="date_of_birth">Date of Birth</Label>
                    <Input
                        id="date_of_birth"
                        type="date"
                        value={data.date_of_birth}
                        onChange={(e) => setData('date_of_birth', e.target.value)}
                    />
                    {errors.date_of_birth && <p className="text-destructive text-xs">{errors.date_of_birth}</p>}
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
                    {errors.phone && <p className="text-destructive text-xs">{errors.phone}</p>}
                </div>

                <div className="col-span-1 space-y-2 md:col-span-2">
                    <Label htmlFor="address">Address</Label>
                    <Input
                        id="address"
                        placeholder="Residential address"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                    />
                    {errors.address && <p className="text-destructive text-xs">{errors.address}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="qualification">Qualification</Label>
                    <Input
                        id="qualification"
                        placeholder="e.g. B.Ed Mathematics"
                        value={data.qualification}
                        onChange={(e) => setData('qualification', e.target.value)}
                    />
                    {errors.qualification && <p className="text-destructive text-xs">{errors.qualification}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="hire_date">Hire Date</Label>
                    <Input
                        id="hire_date"
                        type="date"
                        value={data.hire_date}
                        onChange={(e) => setData('hire_date', e.target.value)}
                    />
                    {errors.hire_date && <p className="text-destructive text-xs">{errors.hire_date}</p>}
                </div>

                <div className="space-y-2">
                    <Label>Status</Label>
                    <Select value={data.status} onValueChange={(v) => setData('status', v)}>
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
                    {errors.status && <p className="text-destructive text-xs">{errors.status}</p>}
                </div>
            </div>

            <div className="flex justify-end gap-3 border-t pt-4">
                <Button type="button" variant="outline" onClick={onCancel} disabled={processing}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                    {isEdit ? 'Update Teacher' : 'Create Teacher'}
                </Button>
            </div>
        </form>
    );
}
