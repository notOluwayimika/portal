import axios from 'axios';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { MultiStudentConfirmModal } from '@/components/guardians/multi-student-confirm-modal';
import { MultiStudentWarning } from '@/components/guardians/multi-student-warning';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Guardian, GuardianPivot, Student } from '@/types/models';

interface Option {
    name: string;
    value: string;
}

interface Resources {
    genders: Option[];
    id_types: Option[];
    marital_statuses: Option[];
}

interface EditGuardianModalProps {
    isOpen: boolean;
    onClose: () => void;
    guardian: Guardian;
    linkedStudents: (Student & { pivot: GuardianPivot })[];
    onSaved: () => void;
}

type FormState = {
    first_name: string;
    middle_name: string;
    last_name: string;
    gender: string;
    marital_status: string;
    phone: string;
    whatsapp_number: string;
    email: string;
    city: string;
    state: string;
    country: string;
    postal_code: string;
    occupation: string;
    employer_name: string;
    emergency_contact: string;
    id_type: string;
    id_number: string;
    id_expiry_date: string;
};

function toFormState(g: Guardian): FormState {
    return {
        first_name:        g.first_name ?? '',
        middle_name:       g.middle_name ?? '',
        last_name:         g.last_name ?? '',
        gender:            g.gender ?? '',
        marital_status:    g.marital_status ?? '',
        phone:             g.phone ?? '',
        whatsapp_number:   g.whatsapp_number ?? '',
        email:             g.email ?? '',
        city:              g.city ?? '',
        state:             g.state ?? '',
        country:           g.country ?? '',
        postal_code:       g.postal_code ?? '',
        occupation:        g.occupation ?? '',
        employer_name:     g.employer_name ?? '',
        emergency_contact: g.emergency_contact ?? '',
        id_type:           g.id_type ?? '',
        id_number:         g.id_number ?? '',
        id_expiry_date:    g.id_expiry_date ?? '',
    };
}

function FieldError({ msg }: { msg?: string }) {
    if (!msg) return null;
    return <p className="text-destructive mt-0.5 text-xs">{msg}</p>;
}

export function EditGuardianModal({
    isOpen,
    onClose,
    guardian,
    linkedStudents,
    onSaved,
}: EditGuardianModalProps) {
    const [form, setForm] = useState<FormState>(() => toFormState(guardian));
    const [resources, setResources] = useState<Resources>({ genders: [], id_types: [], marital_statuses: [] });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        setForm(toFormState(guardian));
        setErrors({});
        axios
            .get('/api/guardians/resources')
            .then((res) => {
                const data = res.data?.data ?? res.data;
                setResources({
                    genders:          data.genders ?? [],
                    id_types:         data.id_types ?? [],
                    marital_statuses: data.marital_statuses ?? [],
                });
            })
            .catch(() => {});
    }, [isOpen, guardian]);

    const field = (key: keyof FormState) => ({
        value: form[key],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            setForm((f) => ({ ...f, [key]: e.target.value })),
    });

    const handleSaveClick = (e: React.FormEvent) => {
        e.preventDefault();
        if (linkedStudents.length > 1) {
            setShowConfirm(true);
        } else {
            submitSave();
        }
    };

    const submitSave = async () => {
        setShowConfirm(false);
        setErrors({});
        setSubmitting(true);

        const payload: Record<string, unknown> = {};
        (Object.keys(form) as (keyof FormState)[]).forEach((k) => {
            if (form[k] !== '') payload[k] = form[k];
        });

        try {
            await axios.put(`/api/guardians/${guardian.id}`, payload);
            onSaved();
        } catch (err: unknown) {
            const resp = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
            if (resp?.errors) {
                const flat: Record<string, string> = {};
                Object.entries(resp.errors).forEach(([k, v]) => { flat[k] = v[0]; });
                setErrors(flat);
            } else {
                setErrors({ _general: resp?.message ?? 'Failed to save guardian.' });
            }
        } finally {
            setSubmitting(false);
        }
    };

    const SelectField = ({
        id,
        label,
        fieldKey,
        options,
    }: {
        id: string;
        label: string;
        fieldKey: keyof FormState;
        options: Option[];
    }) => (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={form[fieldKey]}
                onValueChange={(v) => setForm((f) => ({ ...f, [fieldKey]: v }))}
            >
                <SelectTrigger id={id}>
                    <SelectValue placeholder={`Select ${label.toLowerCase()}`} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <FieldError msg={errors[fieldKey]} />
        </div>
    );

    return (
        <>
            <Modal isOpen={isOpen} onClose={onClose} title="Edit Guardian" size="3xl">
                <form onSubmit={handleSaveClick} className="space-y-5">
                    {linkedStudents.length > 1 && (
                        <MultiStudentWarning students={linkedStudents} />
                    )}

                    {errors._general && (
                        <p className="text-destructive text-xs">{errors._general}</p>
                    )}

                    {/* Name */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="eg-first_name">First Name <span className="text-destructive">*</span></Label>
                            <Input id="eg-first_name" {...field('first_name')} />
                            <FieldError msg={errors.first_name} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-middle_name">Middle Name</Label>
                            <Input id="eg-middle_name" {...field('middle_name')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-last_name">Last Name <span className="text-destructive">*</span></Label>
                            <Input id="eg-last_name" {...field('last_name')} />
                            <FieldError msg={errors.last_name} />
                        </div>
                    </div>

                    {/* Identity */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField id="eg-gender" label="Gender" fieldKey="gender" options={resources.genders} />
                        <SelectField id="eg-marital_status" label="Marital Status" fieldKey="marital_status" options={resources.marital_statuses} />
                    </div>

                    {/* Contact */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="eg-phone">Phone <span className="text-destructive">*</span></Label>
                            <Input id="eg-phone" {...field('phone')} />
                            <FieldError msg={errors.phone} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-whatsapp">WhatsApp Number</Label>
                            <Input id="eg-whatsapp" {...field('whatsapp_number')} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="eg-email">Email</Label>
                        <Input id="eg-email" type="email" {...field('email')} />
                        <FieldError msg={errors.email} />
                    </div>

                    {/* Address */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="eg-city">City</Label>
                            <Input id="eg-city" {...field('city')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-state">State</Label>
                            <Input id="eg-state" {...field('state')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-country">Country</Label>
                            <Input id="eg-country" {...field('country')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-postal_code">Postal Code</Label>
                            <Input id="eg-postal_code" {...field('postal_code')} />
                        </div>
                    </div>

                    {/* Employment */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="eg-occupation">Occupation</Label>
                            <Input id="eg-occupation" {...field('occupation')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-employer_name">Employer Name</Label>
                            <Input id="eg-employer_name" {...field('employer_name')} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="eg-emergency_contact">Emergency Contact</Label>
                        <Input id="eg-emergency_contact" {...field('emergency_contact')} />
                    </div>

                    {/* ID */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <SelectField id="eg-id_type" label="ID Type" fieldKey="id_type" options={resources.id_types} />
                        <div className="space-y-1">
                            <Label htmlFor="eg-id_number">ID Number</Label>
                            <Input id="eg-id_number" {...field('id_number')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-id_expiry_date">ID Expiry Date</Label>
                            <Input id="eg-id_expiry_date" type="date" {...field('id_expiry_date')} />
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-2 border-t pt-3">
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Save Changes
                        </Button>
                    </div>
                </form>
            </Modal>

            <MultiStudentConfirmModal
                isOpen={showConfirm}
                students={linkedStudents}
                onConfirm={submitSave}
                onCancel={() => setShowConfirm(false)}
                submitting={submitting}
            />
        </>
    );
}
