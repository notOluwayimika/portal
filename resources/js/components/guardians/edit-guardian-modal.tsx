import axios from 'axios';
import {
    Briefcase,
    CreditCard,
    MapPin,
    Phone,
    Save,
    User2,
} from 'lucide-react';
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
        first_name: g.first_name ?? '',
        middle_name: g.middle_name ?? '',
        last_name: g.last_name ?? '',
        gender: g.gender ?? '',
        marital_status: g.marital_status ?? '',
        phone: g.phone ?? '',
        whatsapp_number: g.whatsapp_number ?? '',
        email: g.email ?? '',
        city: g.city ?? '',
        state: g.state ?? '',
        country: g.country ?? '',
        postal_code: g.postal_code ?? '',
        occupation: g.occupation ?? '',
        employer_name: g.employer_name ?? '',
        emergency_contact: g.emergency_contact ?? '',
        id_type: g.id_type ?? '',
        id_number: g.id_number ?? '',
        id_expiry_date: g.id_expiry_date ?? '',
    };
}

function FieldError({ msg }: { msg?: string }) {
    if (!msg) {
        return null;
    }

    return <p className="mt-0.5 text-xs text-destructive">{msg}</p>;
}

/**
 * ONE FIELD, AND IT TAKES FIELD-SHAPED PROPS ON PURPOSE.
 *
 * DECLARED AT MODULE SCOPE because a component defined INSIDE another is a NEW COMPONENT TYPE on
 * every render: React cannot reconcile it, so it unmounts and remounts the whole subtree instead of
 * updating it, taking any DOM state — focus, cursor, selection, a popover's open flag — with it.
 * `react-hooks/static-components` fires on exactly that, and it fired here three times.
 *
 * MEASURED BEFORE THE FIX, because "a rule fired" is not the same as "a user is hurt": typing five
 * characters into `eg-first_name` kept focus and the caret (`selectionStart: 10`), and the gender
 * popover stayed open across a parent re-render. So this was LATENT rather than live — the plain
 * `<Input {...field(…)} />` fields sit outside this component, so the remounted subtree was never
 * the one being typed into. It is fixed as a correctness matter, not as a bug report.
 *
 * `value` / `onChange` / `error` RATHER THAN `form` / `setForm`: a component handed the whole form
 * object and its setter can write anything; a component handed a value and a change handler renders
 * one thing. Six props, every one scoped to the field — the moment a seventh appears and it is
 * FORM-shaped, this is the wrong shape and the interface should be reconsidered rather than
 * threaded.
 */
function SelectField({
    id,
    label,
    value,
    onChange,
    options,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    error?: string;
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id}>
                    <SelectValue
                        placeholder={`Select ${label.toLowerCase()}`}
                    />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <FieldError msg={error} />
        </div>
    );
}

/*
 * THE SEEDING HAPPENS AT MOUNT, NOT IN AN EFFECT — CAUSE 1's SECOND REMEDY, the one
 * `resources/js/pages/admin/internal-audit/review-queue.tsx` records for exactly this shape: an
 * effect that exists to copy props into state has no handler to move the setState into and no
 * promise to chain, so the answer is to make the state INITIALISE rather than update.
 *
 * This wrapper holds NO hooks, so it may return early, and the body is mounted only while the
 * dialog is open — its `useState(() => toFormState(guardian))` initialiser already does the
 * seeding. `key={guardian.id}` reproduces the one behaviour the old dep list had: it listed
 * `[isOpen, guardian]`, so a guardian swapped while the modal stayed open re-seeded the form.
 * Without the key this split would be a silent behaviour change rather than a refactor.
 */
export function EditGuardianModal(props: EditGuardianModalProps) {
    if (!props.isOpen) {
        return null;
    }

    return (
        <EditGuardianModalBody key={props.guardian?.id ?? 'new'} {...props} />
    );
}

function EditGuardianModalBody({
    onClose,
    guardian,
    linkedStudents,
    onSaved,
}: EditGuardianModalProps) {
    const [form, setForm] = useState<FormState>(() => toFormState(guardian));
    const [resources, setResources] = useState<Resources>({
        genders: [],
        id_types: [],
        marital_statuses: [],
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);

    // MOUNT-ONLY, and the same number of fetches as before: the old effect was keyed on
    // `[isOpen, guardian]` and this body mounts on exactly those transitions.
    useEffect(() => {
        axios
            .get('/api/guardians/resources')
            .then((res) => {
                const data = res.data?.data ?? res.data;
                setResources({
                    genders: data.genders ?? [],
                    id_types: data.id_types ?? [],
                    marital_statuses: data.marital_statuses ?? [],
                });
            })
            .catch(() => {});
    }, []);

    const field = (key: keyof FormState) => ({
        value: form[key],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            setForm((f) => ({ ...f, [key]: e.target.value })),
    });

    const handleSaveClick = (e: React.FormEvent<HTMLFormElement>) => {
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
            if (form[k] !== '') {
                payload[k] = form[k];
            }
        });

        try {
            await axios.put(`/api/guardians/${guardian.id}`, payload);
            onSaved();
        } catch (err: unknown) {
            const resp = (
                err as {
                    response?: {
                        data?: {
                            message?: string;
                            errors?: Record<string, string[]>;
                        };
                    };
                }
            )?.response?.data;

            if (resp?.errors) {
                const flat: Record<string, string> = {};
                Object.entries(resp.errors).forEach(([k, v]) => {
                    flat[k] = v[0];
                });
                setErrors(flat);
            } else {
                setErrors({
                    _general: resp?.message ?? 'Failed to save guardian.',
                });
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <>
            <Modal
                // Always true here: this body is only mounted while the dialog is open.
                isOpen
                onClose={onClose}
                title="Edit Guardian"
                size="3xl"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="edit-guardian-form"
                            disabled={submitting}
                        >
                            {submitting ? (
                                <Spinner className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Save className="mr-2 h-4 w-4" />
                            )}
                            Save Changes
                        </Button>
                    </div>
                }
            >
                <form
                    id="edit-guardian-form"
                    onSubmit={handleSaveClick}
                    className="space-y-5"
                >
                    {linkedStudents.length > 1 && (
                        <MultiStudentWarning students={linkedStudents} />
                    )}

                    {errors._general && (
                        <p className="text-xs text-destructive">
                            {errors._general}
                        </p>
                    )}

                    {/* ── Personal Information ── */}
                    <div className="flex items-center gap-2 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                        <User2 className="h-3.5 w-3.5" />
                        Personal Information
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="eg-first_name">
                                First Name{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="eg-first_name"
                                {...field('first_name')}
                            />
                            <FieldError msg={errors.first_name} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-middle_name">Middle Name</Label>
                            <Input
                                id="eg-middle_name"
                                {...field('middle_name')}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-last_name">
                                Last Name{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input id="eg-last_name" {...field('last_name')} />
                            <FieldError msg={errors.last_name} />
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField
                            id="eg-gender"
                            label="Gender"
                            value={form.gender}
                            onChange={(v) =>
                                setForm((f) => ({ ...f, gender: v }))
                            }
                            options={resources.genders}
                            error={errors.gender}
                        />
                        <SelectField
                            id="eg-marital_status"
                            label="Marital Status"
                            value={form.marital_status}
                            onChange={(v) =>
                                setForm((f) => ({ ...f, marital_status: v }))
                            }
                            options={resources.marital_statuses}
                            error={errors.marital_status}
                        />
                    </div>

                    {/* ── Contact Details ── */}
                    <div className="flex items-center gap-2 border-t border-slate-100 pt-4 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                        <Phone className="h-3.5 w-3.5" />
                        Contact Details
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="eg-phone">
                                Phone{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input id="eg-phone" {...field('phone')} />
                            <FieldError msg={errors.phone} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-whatsapp">WhatsApp Number</Label>
                            <Input
                                id="eg-whatsapp"
                                {...field('whatsapp_number')}
                            />
                        </div>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="eg-email">Email</Label>
                        <Input id="eg-email" type="email" {...field('email')} />
                        <FieldError msg={errors.email} />
                    </div>

                    {/* ── Address ── */}
                    <div className="flex items-center gap-2 border-t border-slate-100 pt-4 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                        <MapPin className="h-3.5 w-3.5" />
                        Address
                    </div>
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
                            <Input
                                id="eg-postal_code"
                                {...field('postal_code')}
                            />
                        </div>
                    </div>

                    {/* ── Work & Emergency ── */}
                    <div className="flex items-center gap-2 border-t border-slate-100 pt-4 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                        <Briefcase className="h-3.5 w-3.5" />
                        Work & Emergency
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="eg-occupation">Occupation</Label>
                            <Input
                                id="eg-occupation"
                                {...field('occupation')}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-employer_name">
                                Employer Name
                            </Label>
                            <Input
                                id="eg-employer_name"
                                {...field('employer_name')}
                            />
                        </div>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="eg-emergency_contact">
                            Emergency Contact
                        </Label>
                        <Input
                            id="eg-emergency_contact"
                            {...field('emergency_contact')}
                        />
                    </div>

                    {/* ── Identity Documents ── */}
                    <div className="flex items-center gap-2 border-t border-slate-100 pt-4 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                        <CreditCard className="h-3.5 w-3.5" />
                        Identity Documents
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <SelectField
                            id="eg-id_type"
                            label="ID Type"
                            value={form.id_type}
                            onChange={(v) =>
                                setForm((f) => ({ ...f, id_type: v }))
                            }
                            options={resources.id_types}
                            error={errors.id_type}
                        />
                        <div className="space-y-1">
                            <Label htmlFor="eg-id_number">ID Number</Label>
                            <Input id="eg-id_number" {...field('id_number')} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="eg-id_expiry_date">
                                ID Expiry Date
                            </Label>
                            <Input
                                id="eg-id_expiry_date"
                                type="date"
                                {...field('id_expiry_date')}
                            />
                        </div>
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
