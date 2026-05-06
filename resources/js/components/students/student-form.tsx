import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { Save } from 'lucide-react';
import { Spinner } from '@/components/ui/spinner';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Student } from '@/types/models';

interface StudentFormProps {
    student?: Student | null;
    onSuccess: () => void;
    onCancel: () => void;
}

interface CurriculumOption {
    id: number;
    uuid: string;
    term: number;
    class_level: string;
    arm: string;
    stream: string;
}

export function StudentForm({ student, onSuccess, onCancel }: StudentFormProps) {
    const isEdit = !!student;
    const [curricula, setCurricula] = useState<CurriculumOption[]>([]);
    const [genders, setGenders] = useState<{ name: string; value: string }[]>([])
    const [loading, setLoading] = useState(false);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        first_name: student?.first_name || '',
        last_name: student?.last_name || '',
        middle_name: student?.middle_name || '',
        admission_number: student?.admission_number || '',
        gender: student?.gender || 'male',
        date_of_birth: student?.date_of_birth || '',
        curriculum_id: student?.current_curriculum?.curriculum_id?.toString() || '',
        status: student?.status || 'active',
    });

    useEffect(() => {
        let isMounted = true;
        const fetchCurricula = async () => {
            try {
                const response = await axios.get('/api/students/resources');
                if (isMounted) {
                    setCurricula(response.data.data.curricula || []);
                    setGenders(response.data.data.genders || []);
                }
            } catch (error) {
                console.error('Failed to fetch curricula:', error);
            }
        };
        fetchCurricula();
        return () => { isMounted = false; };
    }, []);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const options = {
            onSuccess: () => {
                onSuccess();
                reset();
            },
        };

        if (isEdit) {
            put(`/api/students/${student.uuid}`, options);
        } else {
            post('/api/students', options);
        }
    };

    const statuses = [
        { value: 'active', label: 'Active' },
        { value: 'inactive', label: 'Inactive' },
        { value: 'withdrawn', label: 'Withdrawn' },
        { value: 'graduated', label: 'Graduated' },
        { value: 'suspended', label: 'Suspended' },
        { value: 'expelled', label: 'Expelled' },
    ];

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                    <Label htmlFor="admission_number">Admission Number</Label>
                    <Input
                        id="admission_number"
                        value={data.admission_number}
                        onChange={(e) => setData('admission_number', e.target.value)}
                    />
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
                    <Label>Assigned Class (Curriculum)</Label>
                    <SearchableSelect
                        placeholder="Search for a class..."
                        options={curricula.map((c) => ({
                            value: c.id.toString(),
                            label: `${c.class_level} - ${c.arm}${c.stream ? ` (${c.stream})` : ''}`,
                        }))}
                        value={curricula.map((c) => ({
                            value: c.id.toString(),
                            label: `${c.class_level} - ${c.arm}${c.stream ? ` (${c.stream})` : ''}`,
                        })).find(opt => opt.value === data.curriculum_id)}
                        onChange={(opt: any) => setData('curriculum_id', opt?.value || '')}
                        error={!!errors.curriculum_id}
                    />
                    {errors.curriculum_id && <p className="text-destructive text-xs">{errors.curriculum_id}</p>}
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
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div className="flex justify-end gap-3 pt-4 border-t">
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
