import axios from 'axios';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { Guardian } from '@/types/models';

interface Option {
    name: string;
    value: string;
}

interface EditPivotModalProps {
    isOpen: boolean;
    onClose: () => void;
    studentUuid: string;
    guardian: Guardian | null;
    onSaved: () => void;
}

/*
 * THE SEEDING HAPPENS AT MOUNT, NOT IN AN EFFECT — CAUSE 1 of the two that
 * `resources/js/pages/admin/internal-audit/review-queue.tsx` records for
 * `react-hooks/set-state-in-effect`.
 *
 * This component carried an effect that ran four setStates SYNCHRONOUSLY in its body to copy the
 * `guardian` prop into state whenever the modal opened. That is the docblock's first cause exactly,
 * and its remedy there was to stop synchronising and let the FIRST RENDER ALREADY BE RIGHT — the
 * loading flag moved into `useState(true)`.
 *
 * The same remedy here is a split: this wrapper holds NO hooks, so it may return early, and the
 * body below is mounted only while the dialog is open. Its `useState` initialisers do the seeding,
 * so the first render already has the guardian's values and there is nothing to synchronise.
 *
 * `key={guardian.id}` PRESERVES THE ONE BEHAVIOUR THE OLD DEP LIST HAD: the effect listed
 * `[isOpen, guardian]`, so a guardian swapped while the modal stayed open re-seeded the form. The
 * key reproduces that by remounting on the same event. Without it the split would be a silent
 * behaviour change rather than a refactor.
 */
export function EditPivotModal(props: EditPivotModalProps) {
    if (!props.isOpen || !props.guardian) {
        return null;
    }

    return <EditPivotModalBody key={props.guardian.id} {...props} />;
}

function EditPivotModalBody({
    onClose,
    studentUuid,
    guardian,
    onSaved,
}: EditPivotModalProps) {
    const [relationship, setRelationship] = useState(
        guardian?.relationship ?? '',
    );
    const [isPrimary, setIsPrimary] = useState(guardian?.is_primary ?? false);
    const [canLogin, setCanLogin] = useState(guardian?.can_login ?? false);
    const [relationships, setRelationships] = useState<Option[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // MOUNT-ONLY, and it is the same number of fetches as before: the old effect was keyed on
    // `[isOpen, guardian]` and this body mounts on exactly those transitions.
    useEffect(() => {
        axios
            .get('/api/guardians/resources')
            .then((res) => {
                const data = res.data?.data ?? res.data;

                setRelationships(data?.relationships ?? []);
            })
            .catch(() => {});
    }, []);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!guardian) {
            return;
        }

        setError(null);
        setSubmitting(true);

        try {
            await axios.put(
                `/api/students/${studentUuid}/guardians/${guardian.id}`,
                {
                    relationship,
                    is_primary: isPrimary,
                    can_login: canLogin,
                },
            );
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
            const firstErr = resp?.errors
                ? Object.values(resp.errors)[0]?.[0]
                : null;

            setError(firstErr || resp?.message || 'Failed to update.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            // Always true here: this body is only mounted while the dialog is open.
            isOpen
            onClose={onClose}
            title={`Edit relationship — ${guardian?.full_name ?? ''}`}
            size="md"
        >
            <form onSubmit={handleSubmit} className="space-y-5">
                {/* Relationship */}
                <div className="space-y-2">
                    <Label>Relationship</Label>
                    <Select
                        value={relationship}
                        onValueChange={setRelationship}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select relationship" />
                        </SelectTrigger>
                        <SelectContent>
                            {relationships.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                    {r.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Is Primary */}
                <label className="flex cursor-pointer items-center gap-3 text-sm">
                    <Checkbox
                        checked={isPrimary}
                        onCheckedChange={(c) => setIsPrimary(Boolean(c))}
                    />
                    <div>
                        <p className="font-medium">Primary Guardian</p>
                        <p className="text-xs text-muted-foreground">
                            Turning this on will remove primary status from the
                            current primary guardian.
                        </p>
                    </div>
                </label>

                {/* Can Login */}
                <label className="flex cursor-pointer items-center gap-3 text-sm">
                    <Checkbox
                        checked={canLogin}
                        onCheckedChange={(c) => setCanLogin(Boolean(c))}
                    />
                    <div>
                        <p className="font-medium">Can Log In</p>
                        <p className="text-xs text-muted-foreground">
                            Allows this guardian to log in to the parent portal.
                        </p>
                    </div>
                </label>

                {error && <p className="text-xs text-destructive">{error}</p>}

                <div className="flex items-center justify-end gap-2 border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={submitting}>
                        {submitting ? (
                            <Spinner className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Save className="mr-2 h-4 w-4" />
                        )}
                        Save
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
