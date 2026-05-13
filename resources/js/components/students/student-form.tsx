import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ProfileImageUpload } from '@/components/ui/profile-image-upload';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Student } from '@/types/models';

interface StudentFormProps {
    student?: Student | null;
    onSuccess: () => void;
    onCancel: () => void;
}

interface CurriculumOption {
    id: number;
    term: number;
    class_level: string;
    arm: string;
    stream: string;
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

export function StudentForm({ student, onSuccess, onCancel }: StudentFormProps) {
    const isEdit = !!student;
    const [curricula, setCurricula] = useState<CurriculumOption[]>([]);
    const [genders, setGenders] = useState<{ name: string; value: string }[]>([]);
    const [showAdmissionNumber, setShowAdmissionNumber] = useState(false);
    const [photoPreview, setPhotoPreview] = useState<string | null>(student?.photo ?? null);

    const { data, setData, post, patch, processing, errors, reset } = useForm<StudentFormData>({
        first_name: student?.first_name || '',
        last_name: student?.last_name || '',
        middle_name: student?.middle_name || '',
        admission_number: isEdit ? (student?.admission_number || '') : '',
        gender: student?.gender || 'male',
        date_of_birth: student?.date_of_birth || '',
        curriculum_id: student?.curriculum_id?.toString() || '',
        photo: null,
    });

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
        setData('photo', file);
        const url = URL.createObjectURL(file);
        setPhotoPreview((prev) => {
            if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev);
            return url;
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const options = {
            forceFormData: true,
            onSuccess: () => {
                onSuccess();
                reset();
            },
        };

        if (isEdit) {
            patch(`/api/students/${student.id}`, options);
        } else {
            post('/api/students', options);
        }
    };

    const curriculaOptions = curricula.map((c) => ({
        value: c.id.toString(),
        label: `${c.class_level} - ${c.arm}${c.stream ? ` (${c.stream})` : ''}`,
    }));

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
                                if (!e.target.checked) setData('admission_number', '');
                            }}
                            className="h-4 w-4 rounded border-gray-300"
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
                                value={data.admission_number}
                                onChange={(e) => setData('admission_number', e.target.value)}
                            />
                            {errors.admission_number && (
                                <p className="text-destructive text-xs">{errors.admission_number}</p>
                            )}
                        </>
                    )}
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
                </div>

                <div className="space-y-2">
                    <Label>Assigned Class</Label>
                    <SearchableSelect
                        placeholder="Search for a class..."
                        options={curriculaOptions}
                        value={curriculaOptions.find((opt) => opt.value === data.curriculum_id)}
                        onChange={(opt: any) => setData('curriculum_id', opt?.value || '')}
                        error={!!errors.curriculum_id}
                    />
                    {errors.curriculum_id && <p className="text-destructive text-xs">{errors.curriculum_id}</p>}
                </div>
            </div>

            <div className="flex justify-end gap-3 border-t pt-4">
                <Button type="button" variant="outline" onClick={onCancel} disabled={processing}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                    {isEdit ? 'Update Student' : 'Create Student'}
                </Button>
            </div>
        </form>
    );
}
