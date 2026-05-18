import axios from 'axios';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ProfileImageUpload } from '@/components/ui/profile-image-upload';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Student } from '@/types/models';
import {
    GuardianSubForm,
    emptyGuardianEntry,
    type GuardianFormEntry,
} from '@/components/students/guardian-sub-form';

interface StudentFormProps {
    student?: Student | null;
    onSuccess: () => void;
    onCancel: () => void;
    formId?: string;
    onProcessingChange?: (v: boolean) => void;
}

interface CurriculumOption {
    id: number;
    term: number;
    class_level: string;
    arm: string;
    stream: string;
}

export function StudentForm({ student, onSuccess, onCancel, formId = 'student-form', onProcessingChange }: StudentFormProps) {
    const isEdit = !!student;

    const [curricula, setCurricula] = useState<CurriculumOption[]>([]);
    const [genders, setGenders] = useState<{ name: string; value: string }[]>([]);
    const [showAdmissionNumber, setShowAdmissionNumber] = useState(false);
    const [photoPreview, setPhotoPreview] = useState<string | null>(student?.photo ?? null);
    const [photo, setPhoto] = useState<File | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const [fields, setFields] = useState({
        first_name: student?.first_name ?? '',
        last_name: student?.last_name ?? '',
        middle_name: student?.middle_name ?? '',
        admission_number: isEdit ? (student?.admission_number ?? '') : '',
        gender: student?.gender ?? 'male',
        date_of_birth: student?.date_of_birth ?? '',
        curriculum_id: student?.curriculum_id?.toString() ?? '',
    });

    const [guardians, setGuardians] = useState<GuardianFormEntry[]>(() =>
        isEdit ? [] : [emptyGuardianEntry({ is_primary: true })]
    );

    useEffect(() => { onProcessingChange?.(processing); }, [processing]);

    useEffect(() => {
        let isMounted = true;
        axios.get('/api/students/resources').then((res) => {
            if (isMounted) {
                setCurricula(res.data.data.curricula || []);
                setGenders(res.data.data.genders || []);
            }
        }).catch((err) => console.error('Failed to fetch resources:', err));
        return () => { isMounted = false; };
    }, []);

    const handlePhotoChange = (file: File) => {
        setPhoto(file);
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
        formData.append('first_name', fields.first_name);
        formData.append('last_name', fields.last_name);
        formData.append('middle_name', fields.middle_name);
        formData.append('gender', fields.gender);
        formData.append('date_of_birth', fields.date_of_birth);
        formData.append('curriculum_id', fields.curriculum_id);
        if (fields.admission_number) formData.append('admission_number', fields.admission_number);
        if (photo) formData.append('photo', photo);
        if (!isEdit) {
            const payload = guardians.map(({ looked_up: _l, ...rest }) => rest);
            formData.append('guardians', JSON.stringify(payload));
        }

        try {
            if (isEdit) {
                await axios.patch(`/api/students/${student.id}`, formData);
            } else {
                await axios.post('/api/students', formData);
            }

            onSuccess();
            setFields({ first_name: '', last_name: '', middle_name: '', admission_number: '', gender: 'male', date_of_birth: '', curriculum_id: '' });
            setPhoto(null);
            setPhotoPreview(null);
            setGuardians(isEdit ? [] : [emptyGuardianEntry({ is_primary: true })]);
        } catch (err: any) {
            if (err.response?.status === 422) {
                const flat: Record<string, string> = {};
                Object.entries(err.response.data?.errors ?? {}).forEach(([key, val]) => {
                    flat[key] = Array.isArray(val) ? (val[0] as string) : String(val);
                });
                setErrors(flat);
            }
        } finally {
            setProcessing(false);
        }
    };

    const curriculaOptions = curricula.map((c) => ({
        value: c.id.toString(),
        label: `${c.class_level} - ${c.arm}${c.stream ? ` (${c.stream})` : ''}`,
    }));

    const guardianErrors: Record<string, string> = {};
    Object.entries(errors).forEach(([key, val]) => {
        if (key.startsWith('guardians')) guardianErrors[key] = val;
    });

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
                        value={fields.first_name}
                        onChange={(e) => setFields((f) => ({ ...f, first_name: e.target.value }))}
                        required
                    />
                    {errors.first_name && <p className="text-destructive text-xs">{errors.first_name}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="last_name">Last Name</Label>
                    <Input
                        id="last_name"
                        value={fields.last_name}
                        onChange={(e) => setFields((f) => ({ ...f, last_name: e.target.value }))}
                        required
                    />
                    {errors.last_name && <p className="text-destructive text-xs">{errors.last_name}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="middle_name">Middle Name (Optional)</Label>
                    <Input
                        id="middle_name"
                        value={fields.middle_name}
                        onChange={(e) => setFields((f) => ({ ...f, middle_name: e.target.value }))}
                    />
                </div>

                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <input
                            id="manual_admission"
                            type="checkbox"
                            checked={showAdmissionNumber}
                            onChange={(e) => {
                                setShowAdmissionNumber(e.target.checked);
                                if (!e.target.checked) setFields((f) => ({ ...f, admission_number: '' }));
                            }}
                            className="h-4 w-4 rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800 dark:checked:bg-primary"
                        />
                        <Label htmlFor="manual_admission" className="cursor-pointer text-sm font-normal">
                            Manually set admission number
                        </Label>
                    </div>
                    {showAdmissionNumber && (
                        <>
                            <Input
                                id="admission_number"
                                placeholder="Enter admission number"
                                value={fields.admission_number}
                                onChange={(e) => setFields((f) => ({ ...f, admission_number: e.target.value }))}
                            />
                            {errors.admission_number && (
                                <p className="text-destructive text-xs">{errors.admission_number}</p>
                            )}
                        </>
                    )}
                </div>

                <div className="space-y-2">
                    <Label>Gender</Label>
                    <Select value={fields.gender} onValueChange={(v) => setFields((f) => ({ ...f, gender: v }))}>
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
                        value={fields.date_of_birth}
                        onChange={(e) => setFields((f) => ({ ...f, date_of_birth: e.target.value }))}
                    />
                </div>

                <div className="space-y-2">
                    <Label>Assigned Class</Label>
                    <SearchableSelect
                        placeholder="Search for a class..."
                        options={curriculaOptions}
                        value={curriculaOptions.find((opt) => opt.value === fields.curriculum_id)}
                        onChange={(opt: any) => setFields((f) => ({ ...f, curriculum_id: opt?.value || '' }))}
                        error={!!errors.curriculum_id}
                    />
                    {errors.curriculum_id && <p className="text-destructive text-xs">{errors.curriculum_id}</p>}
                </div>
            </div>

            {!isEdit && (
                <GuardianSubForm value={guardians} onChange={setGuardians} errors={guardianErrors} />
            )}
        </form>
    );
}
