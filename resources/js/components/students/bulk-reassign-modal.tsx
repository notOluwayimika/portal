import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';

interface Destination {
    id: string;
    label: string;
}

interface BulkReassignModalProps {
    isOpen: boolean;
    onClose: () => void;
    /** Episode uuids — NOT student uuids. See the request class on why. */
    episodeIds: string[];
    /** Any one episode of the cohort; the destination list is a property of the cohort, not the pupil. */
    sampleEpisodeId: string | null;
    onReassigned: () => void;
}

/**
 * Move the selected cohort into a sibling arm.
 *
 * The destination list is fetched from M3's `reassignment-options` endpoint for ONE episode of the
 * batch, which is sound only because the cohort lock guarantees every selected episode shares a
 * curriculum — so "the siblings of this pupil's class" and "the siblings of the batch's class" are
 * the same list. The server recomputes it from the same `CohortSiblings` definition and refuses
 * anything else, so this list is an offer, never the authority.
 *
 * An empty list is a legitimate state, not an error: a year group with one arm has nowhere to move
 * to. The panel says so rather than rendering an empty picker the operator can stare at.
 */
export function BulkReassignModal({
    isOpen,
    onClose,
    episodeIds,
    sampleEpisodeId,
    onReassigned,
}: BulkReassignModalProps) {
    const [destinations, setDestinations] = useState<Destination[]>([]);
    const [currentLabel, setCurrentLabel] = useState<string>('');
    const [destination, setDestination] = useState('');
    const [reason, setReason] = useState('');
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!isOpen || !sampleEpisodeId) {
            return;
        }

        let isMounted = true;

        // Resetting on open is the point of this effect, not a side effect of it:
        // the modal is mounted once and reopened for different cohorts, so a stale
        // destination or a stale error from the previous selection would otherwise
        // still be on screen. Suppressed with a reason, which is this codebase's
        // convention for this rule rather than an escape from it.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setLoading(true);
        setErrors({});
        setDestination('');

        axios
            .get(
                `/api/student-curricula/${sampleEpisodeId}/reassignment-options`,
            )
            .then((res) => {
                if (!isMounted) {
                    return;
                }

                setDestinations(res.data?.destinations ?? []);
                setCurrentLabel(res.data?.episode?.curriculum ?? '');
            })
            .catch(() => {
                if (!isMounted) {
                    return;
                }

                setErrors({
                    _general:
                        'Could not load the classes these pupils can be moved into.',
                });
            })
            .finally(() => {
                if (isMounted) {
                    setLoading(false);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [isOpen, sampleEpisodeId]);

    const handleSubmit = async () => {
        setErrors({});
        setSubmitting(true);

        try {
            const res = await axios.post('/api/students/bulk-reassign', {
                episode_ids: episodeIds,
                destination_curriculum_id: destination,
                reason: reason || undefined,
            });

            toast.success(res.data?.message ?? 'Pupils reassigned.');
            onReassigned();
            onClose();
            setReason('');
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

            // Field errors first, then a message-only body, then a floor. A status code alone must
            // never be the whole story here: the 422s this endpoint raises are the only thing that
            // tells an operator WHICH pupil went stale.
            if (resp?.errors) {
                const flat: Record<string, string> = {};
                Object.entries(resp.errors).forEach(([k, v]) => {
                    flat[k] = v[0];
                });
                setErrors(flat);
            } else if (resp?.message) {
                setErrors({ _general: resp.message });
            } else {
                setErrors({ _general: 'Failed to reassign these pupils.' });
            }
        } finally {
            setSubmitting(false);
        }
    };

    const noDestinations = !loading && destinations.length === 0;

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`Reassign ${episodeIds.length} ${episodeIds.length === 1 ? 'pupil' : 'pupils'}`}
            size="md"
            footer={
                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={submitting || !destination || noDestinations}
                    >
                        {submitting ? 'Reassigning…' : 'Reassign'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                {errors._general && (
                    <p className="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                        {errors._general}
                    </p>
                )}

                {/* Named, not implied: an all-or-nothing batch should say so before it runs. */}
                <p className="text-xs text-muted-foreground">
                    All {episodeIds.length} move together. If any one of them
                    cannot be moved, none of them are.
                </p>

                {loading ? (
                    <Spinner className="mx-auto" />
                ) : noDestinations ? (
                    <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                        {currentLabel || 'This class'} has no other arm to move
                        these pupils into.
                    </p>
                ) : (
                    <>
                        <div>
                            <Label className="text-xs">Currently in</Label>
                            <p className="mt-1 text-sm font-medium">
                                {currentLabel || '—'}
                            </p>
                        </div>

                        <div>
                            <Label className="text-xs">Move to</Label>
                            <select
                                className="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                                value={destination}
                                onChange={(e) => setDestination(e.target.value)}
                            >
                                <option value="">Select a class…</option>
                                {destinations.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.label}
                                    </option>
                                ))}
                            </select>
                            {errors.destination_curriculum_id && (
                                <p className="mt-1 text-xs text-destructive">
                                    {errors.destination_curriculum_id}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label className="text-xs">Reason (optional)</Label>
                            <Input
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="mt-1"
                                placeholder="e.g. class rebalancing"
                            />
                        </div>
                    </>
                )}

                {/* The stale-episode 422 lands here, and it names the pupils — which is the whole
                    reason the endpoint takes episode uuids rather than student uuids. */}
                {errors.episode_ids && (
                    <p className="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                        {errors.episode_ids}
                    </p>
                )}
            </div>
        </Modal>
    );
}
