import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, ArrowRight, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Can } from '@/components/can';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';

interface Named {
    id: string;
    label: string;
}

interface RolloverPlan {
    kind: string;
    is_runnable: boolean;
    is_empty: boolean;
    blocked_by: string[];
    batch_name: string;
    pupil_count: number;
    curricula: Named[];
    progression_check_ran: boolean;
    progression_is_acyclic: boolean;
    progression_cycle: string[] | null;
    ccm_blockers: Named[];
    warnings: string[];
}

interface BatchRow {
    id: string;
    name: string;
    total_jobs: number;
    pending_jobs: number;
    failed_jobs: number;
    done_jobs: number;
    is_draining: boolean;
    finished_at: string | null;
}

interface RolloverPageProps {
    sessions: Named[];
}

/**
 * The rollover operator surface.
 *
 * ── WHAT DISPATCHED IS NOT WHAT WAS PREVIEWED, AND THE SCREEN MUST SAY SO ────────────────────────
 * The commit RE-PLANS (see RolloverController): a cycle or a CCM curriculum introduced between the
 * operator reading a preview and confirming it must block, so the plan that runs is computed at
 * dispatch time and can legitimately differ from the one on screen.
 *
 * That makes echoing the preview's numbers as the OUTCOME a lie of exactly the kind this project
 * has already shipped once — "previewed 240, dispatched 238, screen says 240". So the result panel
 * renders the COMMIT's own returned plan, never the previewed one, and when the two disagree it
 * says so explicitly rather than quietly showing the newer number. An operator who is told 240 moved
 * when 238 did has no way to discover the two missing classes.
 *
 * ── THE CONFIRM NAMES WHAT IT IS ABOUT TO DO ─────────────────────────────────────────────────────
 * This is the single most destructive action in the system — every pupil in a school, across a year
 * boundary, unqueueable once the batch is out. A bare "Are you sure?" is not friction; it is a
 * reflex. The dialogue names the sessions, the class count and the pupil count, because the number
 * is the thing an operator can actually recognise as wrong.
 */
export default function RolloverPage({ sessions }: RolloverPageProps) {
    const [source, setSource] = useState('');
    const [target, setTarget] = useState('');
    const [plan, setPlan] = useState<RolloverPlan | null>(null);
    const [loading, setLoading] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [batches, setBatches] = useState<BatchRow[]>([]);

    /** The COMMIT's own plan — what actually dispatched. Never the previewed one. */
    const [result, setResult] = useState<{
        message: string;
        queued_jobs: number;
        plan: RolloverPlan;
        previewed_jobs: number;
    } | null>(null);

    const loadBatches = () => {
        axios
            .get('/api/rollover/batches')
            .then((r) => setBatches(r.data?.data ?? []))
            .catch(() => setBatches([]));
    };

    useEffect(() => {
        loadBatches();
    }, []);

    const preview = async () => {
        setLoading(true);
        setPlan(null);
        setResult(null);

        try {
            const res = await axios.post('/api/rollover/end-of-year/preview', {
                source_session_id: source,
                target_session_id: target,
            });
            setPlan(res.data);
        } catch (err: unknown) {
            const data = (
                err as {
                    response?: {
                        data?: {
                            errors?: Record<string, string[]>;
                            message?: string;
                        };
                    };
                }
            )?.response?.data;

            toast.error(
                data?.errors
                    ? Object.values(data.errors)[0]?.[0]
                    : (data?.message ?? 'Could not preview this rollover.'),
            );
        } finally {
            setLoading(false);
        }
    };

    const commit = async () => {
        if (!plan) {
            return;
        }

        const previewedJobs = plan.curricula.length;
        setCommitting(true);

        try {
            const res = await axios.post('/api/rollover/end-of-year', {
                source_session_id: source,
                target_session_id: target,
            });

            // The COMMIT's plan is the outcome. `previewed_jobs` is carried only so the panel can
            // say when the two disagreed — it is never displayed as the result.
            setResult({ ...res.data, previewed_jobs: previewedJobs });
            setConfirmOpen(false);
            setPlan(null);
            loadBatches();
        } catch (err: unknown) {
            const data = (
                err as {
                    response?: {
                        data?: { message?: string; plan?: RolloverPlan };
                    };
                }
            )?.response?.data;

            // A refusal at commit means the world changed since the preview — show the FRESH plan,
            // which is what the gate actually decided on.
            if (data?.plan) {
                setPlan(data.plan);
            }

            setConfirmOpen(false);
            toast.error(data?.message ?? 'This rollover could not run.');
        } finally {
            setCommitting(false);
        }
    };

    const sourceLabel = sessions.find((s) => s.id === source)?.label ?? '—';
    const targetLabel = sessions.find((s) => s.id === target)?.label ?? '—';

    return (
        <>
            <Head title="Year rollover" />

            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-lg font-semibold">Year rollover</h1>
                    <p className="text-sm text-muted-foreground">
                        Move every pupil in a final-slot class into the next
                        academic session.
                    </p>
                </div>

                {/* The whole surface is gated: a seat without academics.rollover never sees the
                    control, and the server refuses it regardless. */}
                <Can
                    permission="academics.rollover"
                    fallback={
                        <p className="rounded-md bg-muted px-4 py-3 text-sm text-muted-foreground">
                            You do not have permission to run a rollover.
                        </p>
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs">Closing session</Label>
                            <select
                                className="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                                value={source}
                                onChange={(e) => setSource(e.target.value)}
                            >
                                <option value="">Select…</option>
                                {sessions.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <Label className="text-xs">Pupils move into</Label>
                            <select
                                className="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                                value={target}
                                onChange={(e) => setTarget(e.target.value)}
                            >
                                <option value="">Select…</option>
                                {sessions.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex items-end">
                            <Button
                                onClick={preview}
                                disabled={!source || !target || loading}
                                className="w-full"
                            >
                                {loading ? 'Checking…' : 'Preview'}
                            </Button>
                        </div>
                    </div>

                    {loading && <Spinner className="mx-auto" />}

                    {plan && (
                        <PlanPanel
                            plan={plan}
                            onRun={() => setConfirmOpen(true)}
                        />
                    )}

                    {result && (
                        <ResultPanel
                            message={result.message}
                            queued={result.queued_jobs}
                            previewed={result.previewed_jobs}
                        />
                    )}

                    <BatchPanel batches={batches} onRefresh={loadBatches} />
                </Can>
            </div>

            <Modal
                isOpen={confirmOpen}
                onClose={() => setConfirmOpen(false)}
                title="Run this rollover?"
                size="md"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setConfirmOpen(false)}
                            disabled={committing}
                        >
                            Cancel
                        </Button>
                        <Button onClick={commit} disabled={committing}>
                            {committing ? 'Queueing…' : 'Run rollover'}
                        </Button>
                    </div>
                }
            >
                {/* NAMES WHAT IT IS ABOUT TO DO. The count is the part an operator can recognise as
                    wrong — "are you sure?" is a reflex, "1,240 pupils" is a decision. */}
                <div className="space-y-3 text-sm">
                    <p className="flex items-center gap-2 font-medium">
                        {sourceLabel} <ArrowRight className="h-4 w-4" />{' '}
                        {targetLabel}
                    </p>
                    <p>
                        This will move{' '}
                        <strong>{plan?.pupil_count ?? 0} pupil(s)</strong>{' '}
                        across{' '}
                        <strong>{plan?.curricula.length ?? 0} class(es)</strong>
                        .
                    </p>
                    <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                        The rollover is queued, not instant. It is checked again
                        at this moment — if anything changed since the preview,
                        it will be refused rather than run.
                    </p>
                </div>
            </Modal>
        </>
    );
}

/** The plan, its gates, and its warnings. */
function PlanPanel({ plan, onRun }: { plan: RolloverPlan; onRun: () => void }) {
    return (
        <div className="space-y-4 rounded-md border p-4">
            <div className="flex items-center justify-between">
                <p className="text-sm">
                    <strong>{plan.curricula.length}</strong> class(es),{' '}
                    <strong>{plan.pupil_count}</strong> pupil(s) would move.
                </p>
                <Button
                    onClick={onRun}
                    disabled={!plan.is_runnable || plan.is_empty}
                >
                    Run rollover
                </Button>
            </div>

            {/* ── THE CYCLE GATE, IN THREE STATES ────────────────────────────────────────────────
                `progression_cycle: null` means BOTH "acyclic" and "never checked" — an end-of-term
                plan does not consult the graph at all. Collapsing them would tell an operator the
                graph is fine when it was never looked at, so the applicability flag is read first
                and the three states render differently. */}
            {!plan.progression_check_ran ? (
                <p className="text-xs text-muted-foreground">
                    Progression graph: not applicable to this rollover.
                </p>
            ) : plan.progression_is_acyclic ? (
                <p className="text-xs text-emerald-700 dark:text-emerald-400">
                    Progression graph: checked, no cycles.
                </p>
            ) : (
                <div className="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                    <p className="flex items-center gap-1.5 font-semibold">
                        <AlertTriangle className="h-3.5 w-3.5" />
                        The progression graph contains a cycle.
                    </p>
                    {/* NAMES THE RING — the whole reason the planner calls the walk directly rather
                        than reading a command's exit code. */}
                    <p className="mt-1 font-mono">
                        {plan.progression_cycle?.join(' → ')}
                    </p>
                    <a
                        href="/class-structure"
                        className="mt-1 inline-block underline"
                    >
                        Fix the progression config
                    </a>
                </div>
            )}

            {plan.ccm_blockers.length > 0 && (
                <div className="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                    <p className="font-semibold">
                        {plan.ccm_blockers.length} CCM class(es) sit in a final
                        slot and must be moved first.
                    </p>
                    <ul className="mt-1 list-inside list-disc">
                        {plan.ccm_blockers.map((c) => (
                            <li key={c.id}>{c.label}</li>
                        ))}
                    </ul>
                </div>
            )}

            {plan.warnings.map((w) => (
                <p
                    key={w}
                    className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                >
                    {w}
                </p>
            ))}

            {plan.is_empty && plan.is_runnable && (
                <p className="text-xs text-muted-foreground">
                    Nothing to migrate — no active non-CCM final-slot classes in
                    this session.
                </p>
            )}
        </div>
    );
}

/**
 * What ACTUALLY dispatched.
 *
 * `queued` is the commit's own count. `previewed` is shown only to say the two DISAGREED — never as
 * the result. A screen that echoes the preview after a re-plan is the moved-vs-skipped lie: the
 * operator reads a number that describes a plan which did not run.
 */
function ResultPanel({
    message,
    queued,
    previewed,
}: {
    message: string;
    queued: number;
    previewed: number;
}) {
    const diverged = queued !== previewed;

    return (
        <div className="space-y-2 rounded-md border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
            <p className="text-sm font-medium">{message}</p>

            {diverged && (
                <p className="rounded-md bg-amber-100 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                    The preview showed {previewed} class(es); {queued} were
                    queued. The plan is recomputed at the moment you confirm, so
                    something changed in between — check the batch below and the
                    classes that did not move.
                </p>
            )}
        </div>
    );
}

/** Queued / done / failed — never a boolean, and never the word "complete" while pending. */
function BatchPanel({
    batches,
    onRefresh,
}: {
    batches: BatchRow[];
    onRefresh: () => void;
}) {
    return (
        <div className="rounded-md border p-4">
            <div className="mb-3 flex items-center justify-between">
                <p className="text-sm font-medium">Recent rollover batches</p>
                <Button variant="outline" size="sm" onClick={onRefresh}>
                    <Loader2 className="mr-1.5 h-3.5 w-3.5" />
                    Refresh
                </Button>
            </div>

            {batches.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No rollover batches for this school.
                </p>
            ) : (
                <table className="w-full text-xs">
                    <thead>
                        <tr className="text-left text-muted-foreground">
                            <th className="py-1">Batch</th>
                            <th className="py-1">Done</th>
                            <th className="py-1">Queued</th>
                            <th className="py-1">Failed</th>
                            <th className="py-1">State</th>
                        </tr>
                    </thead>
                    <tbody>
                        {batches.map((b) => (
                            <tr key={b.id} className="border-t">
                                <td className="py-1 font-mono">{b.name}</td>
                                <td className="py-1">
                                    {b.done_jobs}/{b.total_jobs}
                                </td>
                                <td className="py-1">{b.pending_jobs}</td>
                                <td className="py-1">{b.failed_jobs}</td>
                                <td className="py-1">
                                    {b.is_draining ? (
                                        <span className="text-amber-700 dark:text-amber-400">
                                            Draining — do not change the current
                                            session yet
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Finished
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

RolloverPage.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Year rollover', href: '/academics/rollover' },
    ],
};
