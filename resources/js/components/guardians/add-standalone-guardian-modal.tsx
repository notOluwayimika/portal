import { router } from '@inertiajs/react';
import axios from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import Modal from '@/components/ui/Modal';

interface Option { name: string; value: string; }
interface Resources { genders: Option[]; relationships: Option[]; id_types: Option[]; marital_statuses: Option[]; }
interface StudentLink { admission_number: string; relationship: string; is_primary: boolean; }

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

const EMPTY_FORM = {
    first_name: '', middle_name: '', last_name: '', gender: '',
    phone: '', whatsapp_number: '', email: '',
    city: '', state: '', country: '', postal_code: '',
    occupation: '', employer_name: '', marital_status: '',
    emergency_contact: '', id_type: '', id_number: '', id_expiry_date: '',
    can_login: false,
};

export function AddStandaloneGuardianModal({ isOpen, onClose }: Props) {
    const [form, setForm] = useState(EMPTY_FORM);
    const [studentLinks, setStudentLinks] = useState<StudentLink[]>([]);
    const [resources, setResources] = useState<Resources>({ genders: [], relationships: [], id_types: [], marital_statuses: [] });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        axios.get('/api/guardians/resources').then((r) => setResources(r.data.data ?? r.data));
    }, [isOpen]);

    const set = (key: string, val: string | boolean) => setForm(f => ({ ...f, [key]: val }));

    const addLink = () => setStudentLinks(l => [...l, { admission_number: '', relationship: '', is_primary: false }]);
    const removeLink = (i: number) => setStudentLinks(l => l.filter((_, idx) => idx !== i));
    const setLink = (i: number, key: keyof StudentLink, val: string | boolean) =>
        setStudentLinks(l => l.map((lk, idx) => idx === i ? { ...lk, [key]: val } : lk));

    const handleSubmit = async () => {
        setErrors({});
        setSubmitting(true);
        try {
            const res = await axios.post('/api/guardians', {
                ...form,
                student_links: studentLinks.filter(l => l.admission_number),
            });
            const redirect = res.data?.data?.redirect ?? res.data?.redirect;
            onClose();
            setForm(EMPTY_FORM);
            setStudentLinks([]);
            if (redirect) router.visit(redirect);
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                setErrors(err.response.data?.errors ?? {});
            }
        } finally {
            setSubmitting(false);
        }
    };

    const field = (label: string, key: string, type = 'text', required = false) => (
        <div>
            <Label>{label}{required && <span className="text-destructive"> *</span>}</Label>
            <Input type={type} value={(form as Record<string, string>)[key] ?? ''} onChange={(e) => set(key, e.target.value)} className="mt-1" />
            {errors[key] && <p className="mt-0.5 text-xs text-destructive">{errors[key]}</p>}
        </div>
    );

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Add Guardian" size="4xl">
            <div className="space-y-6 overflow-y-auto max-h-[75vh] pr-1">
                {/* Personal */}
                <section>
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Personal</p>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        {field('First Name', 'first_name', 'text', true)}
                        {field('Middle Name', 'middle_name')}
                        {field('Last Name', 'last_name', 'text', true)}
                        <div>
                            <Label>Gender</Label>
                            <Select value={form.gender} onValueChange={(v) => set('gender', v)}>
                                <SelectTrigger className="mt-1"><SelectValue placeholder="Select" /></SelectTrigger>
                                <SelectContent>{resources.genders.map(o => <SelectItem key={o.value} value={o.value}>{o.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Marital Status</Label>
                            <Select value={form.marital_status} onValueChange={(v) => set('marital_status', v)}>
                                <SelectTrigger className="mt-1"><SelectValue placeholder="Select" /></SelectTrigger>
                                <SelectContent>{resources.marital_statuses.map(o => <SelectItem key={o.value} value={o.value}>{o.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    </div>
                </section>

                {/* Contact */}
                <section>
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Contact</p>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        {field('Phone', 'phone', 'tel', true)}
                        {field('WhatsApp', 'whatsapp_number', 'tel')}
                        {field('Email', 'email', 'email')}
                        {field('City', 'city')}
                        {field('State', 'state')}
                        {field('Country', 'country')}
                        {field('Postal Code', 'postal_code')}
                        {field('Emergency Contact', 'emergency_contact')}
                    </div>
                </section>

                {/* Employment */}
                <section>
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Employment</p>
                    <div className="grid grid-cols-2 gap-4">
                        {field('Occupation', 'occupation')}
                        {field('Employer', 'employer_name')}
                    </div>
                </section>

                {/* ID */}
                <section>
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Identification</p>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <Label>ID Type</Label>
                            <Select value={form.id_type} onValueChange={(v) => set('id_type', v)}>
                                <SelectTrigger className="mt-1"><SelectValue placeholder="Select" /></SelectTrigger>
                                <SelectContent>{resources.id_types.map(o => <SelectItem key={o.value} value={o.value}>{o.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        {field('ID Number', 'id_number')}
                        {field('ID Expiry', 'id_expiry_date', 'date')}
                    </div>
                </section>

                {/* Login */}
                <section>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={form.can_login} onCheckedChange={(c) => set('can_login', !!c)} />
                        Enable portal login
                    </label>
                    {form.can_login && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Email is required when login is enabled.
                        </p>
                    )}
                </section>

                {/* Student links */}
                <section>
                    <div className="mb-2 flex items-center justify-between">
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Link to Students (optional)</p>
                        <Button variant="outline" size="sm" onClick={addLink}>
                            <Plus className="mr-1 h-3.5 w-3.5" />Add
                        </Button>
                    </div>
                    {studentLinks.map((lk, i) => (
                        <div key={i} className="mb-2 grid grid-cols-[1fr_1fr_auto_auto] items-end gap-3">
                            <div>
                                <Label className="text-xs">Admission Number</Label>
                                <Input
                                    value={lk.admission_number}
                                    onChange={(e) => setLink(i, 'admission_number', e.target.value)}
                                    placeholder="e.g. GFA/024/001"
                                    className="mt-1 text-xs"
                                />
                            </div>
                            <div>
                                <Label className="text-xs">Relationship</Label>
                                <Select value={lk.relationship} onValueChange={(v) => setLink(i, 'relationship', v)}>
                                    <SelectTrigger className="mt-1"><SelectValue placeholder="Select" /></SelectTrigger>
                                    <SelectContent>{resources.relationships.map(o => <SelectItem key={o.value} value={o.value}>{o.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <label className="flex items-center gap-1 text-xs pb-2">
                                <Checkbox checked={lk.is_primary} onCheckedChange={(c) => setLink(i, 'is_primary', !!c)} />
                                Primary
                            </label>
                            <Button variant="ghost" size="icon" onClick={() => removeLink(i)} className="pb-1">
                                <Trash2 className="h-4 w-4 text-destructive" />
                            </Button>
                        </div>
                    ))}
                </section>

                <div className="flex justify-end gap-2 pt-2">
                    <Button variant="outline" onClick={onClose} disabled={submitting}>Cancel</Button>
                    <Button onClick={handleSubmit} disabled={submitting}>
                        {submitting ? 'Saving…' : 'Add Guardian'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
