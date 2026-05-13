import axios from 'axios';
import { AlertTriangle, Plus, UserMinus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import Modal from '@/components/ui/Modal';
import { AddGuardianModal } from '@/components/students/add-guardian-modal';

export interface StudentGuardian {
    id: string;            // guardian uuid
    full_name: string;
    first_name?: string;
    last_name?: string;
    phone?: string;
    email?: string | null;
    occupation?: string | null;
    relationship: string;
    is_primary: boolean;
    can_login: boolean;
}

interface Option { name: string; value: string }
interface Resources { relationships: Option[] }

interface StudentGuardiansPanelProps {
    isOpen: boolean;
    onClose: () => void;
    studentUuid: string;
    studentName: string;
    onChanged?: () => void;
}

export function StudentGuardiansPanel({
    isOpen,
    onClose,
    studentUuid,
    studentName,
    onChanged,
}: StudentGuardiansPanelProps) {
    const [guardians, setGuardians] = useState<StudentGuardian[]>([]);
    const [loading, setLoading] = useState(false);
    const [resources, setResources] = useState<Resources>({ relationships: [] });
    const [detachTarget, setDetachTarget] = useState<StudentGuardian | null>(null);
    const [showAddModal, setShowAddModal] = useState(false);

    const fetchGuardians = useCallback(async () => {
        if (!studentUuid) return;
        setLoading(true);
        try {
            const res = await axios.get(`/api/students/${studentUuid}`);
            const studentData = res.data?.data ?? res.data;
            // We need a dedicated endpoint or guardians eager-load on the student show.
            // Fall back to a query if the show endpoint doesn't include guardians.
            if (Array.isArray(studentData?.guardians)) {
                setGuardians(studentData.guardians);
            } else {
                const list = await axios.get(`/api/students/${studentUuid}/guardians`);
                setGuardians(list.data?.data ?? []);
            }
        } catch (err) {
            console.error('Failed to load guardians', err);
        } finally {
            setLoading(false);
        }
    }, [studentUuid]);

    useEffect(() => {
        if (!isOpen) return;
        axios.get('/api/guardians/resources')
            .then((res) => setResources(res.data?.data ?? res.data ?? { relationships: [] }))
            .catch(() => {});
        fetchGuardians();
    }, [isOpen, fetchGuardians]);

    const handlePivotChange = async (guardian: StudentGuardian, patch: Partial<StudentGuardian>) => {
        try {
            await axios.put(`/api/students/${studentUuid}/guardians/${guardian.id}`, {
                relationship: patch.relationship ?? guardian.relationship,
                is_primary:   patch.is_primary ?? guardian.is_primary,
                can_login:    patch.can_login ?? guardian.can_login,
            });
            await fetchGuardians();
            onChanged?.();
        } catch (err) {
            console.error('Pivot update failed', err);
        }
    };

    return (
        <>
            <Modal
                isOpen={isOpen}
                onClose={onClose}
                title={`Guardians for ${studentName}`}
                size="3xl"
            >
                <div className="space-y-4">
                    <div className="flex items-start justify-between gap-3">
                        <p className="text-muted-foreground text-xs">
                            Edit how this guardian relates to <strong>{studentName}</strong>. Changes here only affect
                            this student. Editing the guardian's own details (name, contact info) must be done from
                            the Guardians page — those changes apply to every student linked to that guardian.
                        </p>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => setShowAddModal(true)}
                            disabled={loading}
                            className="shrink-0"
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add Guardian
                        </Button>
                    </div>

                    {loading && (
                        <div className="flex items-center justify-center py-12">
                            <Spinner className="h-6 w-6 animate-spin" />
                        </div>
                    )}

                    {!loading && guardians.length === 0 && (
                        <div className="border-muted-foreground/30 text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">
                            No guardians attached. Click "Add Guardian" to attach one.
                        </div>
                    )}

                    {!loading && guardians.map((g) => (
                        <div key={g.id} className="space-y-3 rounded-lg border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold">{g.full_name}</span>
                                        {g.is_primary && (
                                            <span className="bg-primary/10 text-primary rounded-full px-2 py-0.5 text-xs font-medium">
                                                Primary
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {g.phone}
                                        {g.email && <> · {g.email}</>}
                                    </p>
                                    {g.occupation && (
                                        <p className="text-muted-foreground text-xs">{g.occupation}</p>
                                    )}
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive"
                                    onClick={() => setDetachTarget(g)}
                                >
                                    <UserMinus className="mr-1 h-4 w-4" />
                                    Remove from this student
                                </Button>
                            </div>

                            <div className="grid grid-cols-1 gap-3 border-t pt-3 md:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label className="text-xs">Relationship</Label>
                                    <Select
                                        value={g.relationship}
                                        onValueChange={(v) => handlePivotChange(g, { relationship: v })}
                                    >
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {resources.relationships.map((r) => (
                                                <SelectItem key={r.value} value={r.value}>{r.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <label className="flex cursor-pointer items-center gap-2 pt-6 text-sm">
                                    <Checkbox
                                        checked={g.is_primary}
                                        onCheckedChange={(c) => handlePivotChange(g, { is_primary: Boolean(c) })}
                                    />
                                    Primary
                                </label>
                                <label className="flex cursor-pointer items-center gap-2 pt-6 text-sm">
                                    <Checkbox
                                        checked={g.can_login}
                                        onCheckedChange={(c) => handlePivotChange(g, { can_login: Boolean(c) })}
                                    />
                                    Can log in
                                </label>
                            </div>
                        </div>
                    ))}
                </div>
            </Modal>

            {detachTarget && (
                <DetachGuardianModal
                    isOpen={!!detachTarget}
                    onClose={() => setDetachTarget(null)}
                    studentUuid={studentUuid}
                    target={detachTarget}
                    candidates={guardians.filter((g) => g.id !== detachTarget.id)}
                    onDetached={async () => {
                        setDetachTarget(null);
                        await fetchGuardians();
                        onChanged?.();
                    }}
                />
            )}

            <AddGuardianModal
                isOpen={showAddModal}
                onClose={() => setShowAddModal(false)}
                studentUuid={studentUuid}
                studentName={studentName}
                forcePrimary={guardians.length === 0}
                onAdded={async () => {
                    setShowAddModal(false);
                    await fetchGuardians();
                    onChanged?.();
                }}
            />
        </>
    );
}

// -- detach modal ---------------------------------------------------------

interface DetachModalProps {
    isOpen: boolean;
    onClose: () => void;
    studentUuid: string;
    target: StudentGuardian;
    candidates: StudentGuardian[];
    onDetached: () => void;
}

function DetachGuardianModal({ isOpen, onClose, studentUuid, target, candidates, onDetached }: DetachModalProps) {
    const [replacementUuid, setReplacementUuid] = useState<string>('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const needsReplacement = target.is_primary;
    const wouldOrphanStudent = candidates.length === 0;

    const handleConfirm = async () => {
        setError(null);
        if (wouldOrphanStudent) {
            setError('Cannot remove the only guardian. Attach another guardian first.');
            return;
        }
        if (needsReplacement && !replacementUuid) {
            setError('Pick a replacement primary guardian before removing this one.');
            return;
        }

        setSubmitting(true);
        try {
            await axios.delete(`/api/students/${studentUuid}/guardians/${target.id}`, {
                data: needsReplacement ? { replacement_primary_guardian_uuid: replacementUuid } : {},
            });
            onDetached();
        } catch (err: unknown) {
            const message = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
                ?.response?.data;
            const firstErr = message?.errors ? Object.values(message.errors)[0]?.[0] : null;
            setError(firstErr || message?.message || 'Failed to remove guardian.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Remove guardian from this student" size="md">
            <div className="space-y-4">
                <div className="bg-destructive/10 text-destructive flex items-start gap-2 rounded-md p-3 text-sm">
                    <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <div>
                        Removing <strong>{target.full_name}</strong> from this student detaches the link only —
                        the guardian record stays in the system (other students may still be linked).
                    </div>
                </div>

                {wouldOrphanStudent && (
                    <p className="text-destructive text-sm">
                        This is the only guardian on the student. Attach another guardian before removing this one.
                    </p>
                )}

                {!wouldOrphanStudent && needsReplacement && (
                    <div className="space-y-2">
                        <Label>Replacement primary guardian</Label>
                        <Select value={replacementUuid} onValueChange={setReplacementUuid}>
                            <SelectTrigger><SelectValue placeholder="Choose a remaining guardian" /></SelectTrigger>
                            <SelectContent>
                                {candidates.map((c) => (
                                    <SelectItem key={c.id} value={c.id}>
                                        {c.full_name} ({c.relationship})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground text-xs">
                            A student must have exactly one primary guardian.
                        </p>
                    </div>
                )}

                {error && <p className="text-destructive text-xs">{error}</p>}

                <div className="flex items-center justify-end gap-2 border-t pt-3">
                    <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={handleConfirm}
                        disabled={submitting || wouldOrphanStudent}
                    >
                        {submitting && <Spinner className="mr-2 h-4 w-4 animate-spin" />}
                        Remove
                    </Button>
                </div>
            </div>
        </Modal>
    );
}

// Add-guardian button can re-use the GuardianSubForm in single-row mode; left
// as a follow-up so the panel doesn't grow further. The detach modal already
// guides the user to "attach another guardian first" when needed.
export { DetachGuardianModal };
