import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ProfileImageUpload } from '@/components/ui/profile-image-upload';
import { SearchableSelect } from '@/components/ui/searchable-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { convertToSelectOptions } from '@/helpers';
import type { Student } from '@/types/models';
import type { SelectOption } from '../single-select';

interface StudentFormProps {
    student?: Student | null;
    onSuccess: () => void;
    onCancel: () => void;
}

interface StudentFormData {
    first_name: string;
    last_name: string;
    middle_name: string;
    admission_number: string;
    gender: string;
    date_of_birth: string;
    curriculum_id: string;
    photo: File | null;
}

export function StudentForm({
    student,
    onSuccess,
    onCancel,
}: StudentFormProps) {
    const isEdit = !!student;
    const [curricula, setCurricula] = useState<SelectOption[]>([]);
    const [genders, setGenders] = useState<{ name: string; value: string }[]>(
        [],
    );
    const [showAdmissionNumber, setShowAdmissionNumber] = useState(false);
    const [photoPreview, setPhotoPreview] = useState<string | null>(
        student?.photo ?? null,
    );

    const { data, setData, processing, errors, reset } =
        useForm<StudentFormData>({
            first_name: student?.first_name || '',
            last_name: student?.last_name || '',
            middle_name: student?.middle_name || '',
            admission_number: isEdit ? student?.admission_number || '' : '',
            gender: student?.gender || 'male',
            date_of_birth: student?.date_of_birth || '',
            curriculum_id: student?.curriculum_id?.toString() || '',
            photo: null,
        });

    useEffect(() => {
        let isMounted = true;
        axios
            .get('/api/students/resources')
            .then((res) => {
                if (isMounted) {
                    setCurricula(
                        convertToSelectOptions(
                            res.data.data.curricula || [],
                            'full_name',
                        ),
                    );
                    setGenders(res.data.data.genders || []);
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
        // const options = {
        //     forceFormData: true,
        //     onSuccess: () => {
        //         onSuccess();
        //         reset();
        //     },
        // };
        const formData = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            if (value !== null && value !== undefined) {
                formData.append(key, value);
            }
        });

        if (isEdit) {
            const response = await axios.patch(
                `/api/students/${student.id}`,
                formData,
            );

            if (response.status === 200 || response.status === 201) {
                onSuccess();
                reset();
            }
        } else {
            const response = await axios.post('/api/students', formData);

            if (response.status === 201) {
                onSuccess();
                reset();
            }
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

                <div className="space-y-2">
                    <Label htmlFor="middle_name">Middle Name (Optional)</Label>
                    <Input
                        id="middle_name"
                        value={data.middle_name}
                        onChange={(e) => setData('middle_name', e.target.value)}
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

                                if (!e.target.checked) {
                                    setData('admission_number', '');
                                }
                            }}
                            className="h-4 w-4 rounded border-gray-300"
                        />
                        <Label
                            htmlFor="manual_admission"
                            className="cursor-pointer text-sm font-normal"
                        >
                            Manually set admission number
                        </Label>
                    </div>
                    {showAdmissionNumber && (
                        <>
                            <Input
                                id="admission_number"
                                placeholder="Enter admission number"
                                value={data.admission_number}
                                onChange={(e) =>
                                    setData('admission_number', e.target.value)
                                }
                            />
                            {errors.admission_number && (
                                <p className="text-xs text-destructive">
                                    {errors.admission_number}
                                </p>
                            )}
                        </>
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
                </div>

                <div className="space-y-2">
                    <Label>Assigned Class</Label>
                    <SearchableSelect
                        placeholder="Search for a class..."
                        options={curricula}
                        value={curricula.find(
                            (opt) => opt.value === data.curriculum_id,
                        )}
                        onChange={(opt: any) =>
                            setData('curriculum_id', opt?.value || '')
                        }
                        error={!!errors.curriculum_id}
                    />
                    {errors.curriculum_id && (
                        <p className="text-xs text-destructive">
                            {errors.curriculum_id}
                        </p>
                    )}
                </div>
            </div>

            <div className="flex justify-end gap-3 border-t pt-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? (
                        <Spinner className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <Save className="mr-2 h-4 w-4" />
                    )}
                    {isEdit ? 'Update Student' : 'Create Student'}
                </Button>
            </div>
        </form>
    );
}
