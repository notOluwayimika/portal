import axios from 'axios';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import {
    emptyGuardianEntry,
    GuardianRow,
    type GuardianFormEntry,
    type GuardianResources,
} from '@/components/students/guardian-sub-form';

interface AddGuardianModalProps {
    isOpen: boolean;
    onClose: () => void;
    studentUuid: string;
    studentName: string;
    /**
     * When true the new guardian MUST be primary (student currently has no guardians).
     * When false, primary is optional and defaults to false so existing primary is preserved.
     */
    forcePrimary: boolean;
    onAdded: () => void;
}

export function AddGuardianModal({
    isOpen,
    onClose,
    studentUuid,
    studentName,
    forcePrimary,
    onAdded,
}: AddGuardianModalProps) {
    const [entry, setEntry] = useState<GuardianFormEntry>(() =>
        emptyGuardianEntry({ is_primary: forcePrimary })
    );
    const [resources, setResources] = useState<GuardianResources>({ genders: [], id_types: [], relationships: [], marital_statuses: [] });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        setEntry(emptyGuardianEntry({ is_primary: forcePrimary }));
        setErrors({});
        axios.get('/api/guardians/resources')
            .then((res) => setResources(res.data?.data ?? res.data ?? { genders: [], id_types: [], relationships: [] }))
            .catch(() => {});
    }, [isOpen, forcePrimary]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});
        setSubmitting(true);

        const payload: Record<string, unknown> = {
            mode:         entry.mode,
            relationship: entry.relationship,
            is_primary:   forcePrimary ? true : entry.is_primary,
            can_login:    entry.can_login,
        };

        if (entry.mode === 'existing') {
            payload.guardian_id = entry.guardian_id;
            payload.identifier  = entry.identifier;
        } else {
            Object.assign(payload, {
                first_name:        entry.first_name,
                middle_name:       entry.middle_name,
                last_name:         entry.last_name,
                gender:            entry.gender,
                phone:             entry.phone,
                whatsapp_number:   entry.whatsapp_number,
                email:             entry.email,
                city:              entry.city,
                state:             entry.state,
                country:           entry.country,
                postal_code:       entry.postal_code,
                occupation:        entry.occupation,
                employer_name:     entry.employer_name,
                marital_status:    entry.marital_status,
                emergency_contact: entry.emergency_contact,
                id_type:           entry.id_type,
                id_number:         entry.id_number,
                id_expiry_date:    entry.id_expiry_date,
            });
        }

        try {
            await axios.post(`/api/students/${studentUuid}/guardians`, payload);
            onAdded();
        } catch (err: unknown) {
            const resp = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
            if (resp?.errors) {
                const flat: Record<string, string> = {};
                Object.entries(resp.errors).forEach(([k, v]) => { flat[k] = v[0]; });
                setErrors(flat);
            } else if (resp?.message) {
                setErrors({ _general: resp.message });
            } else {
                setErrors({ _general: 'Failed to attach guardian.' });
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title={`Add guardian for ${studentName}`} size="3xl">
            <form onSubmit={handleSubmit} className="space-y-4">
                {forcePrimary && (
                    <p className="bg-primary/10 text-primary rounded-md p-3 text-xs">
                        This student has no guardians yet — the one you add will be set as the primary guardian.
                    </p>
                )}

                <GuardianRow
                    index={0}
                    entry={entry}
                    resources={resources}
                    onChange={(patch) => setEntry((curr) => ({ ...curr, ...patch }))}
                    onRemove={() => {}}
                    canRemove={false}
                    getError={(field) => errors[`guardians.0.${field}`] ?? errors[field]}
                />

                {errors._general && (
                    <p className="text-destructive text-xs">{errors._general}</p>
                )}

                <div className="flex items-center justify-end gap-2 border-t pt-3">
                    <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={submitting}>
                        {submitting ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                        Add Guardian
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
