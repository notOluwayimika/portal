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

export function EditPivotModal({
    isOpen,
    onClose,
    studentUuid,
    guardian,
    onSaved,
}: EditPivotModalProps) {
    const [relationship, setRelationship] = useState('');
    const [isPrimary, setIsPrimary] = useState(false);
    const [canLogin, setCanLogin] = useState(false);
    const [relationships, setRelationships] = useState<Option[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Seed form with current guardian values when opened
    useEffect(() => {
        if (!isOpen || !guardian) {
            return;
        }

        setRelationship(guardian.relationship);
        setIsPrimary(guardian.is_primary);
        setCanLogin(guardian.can_login);
        setError(null);

        // Fetch relationship options
        axios
            .get('/api/guardians/resources')
            .then((res) => {
                const data = res.data?.data ?? res.data;

                setRelationships(data?.relationships ?? []);
            })
            .catch(() => {});
    }, [isOpen, guardian]);

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
            isOpen={isOpen}
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
                        <p className="text-muted-foreground text-xs">
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
                        <p className="text-muted-foreground text-xs">
                            Allows this guardian to log in to the parent portal.
                        </p>
                    </div>
                </label>

                {error && (
                    <p className="text-destructive text-xs">{error}</p>
                )}

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
