import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, ArrowRight, Loader2 } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
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
    no_next_slot: Record<string, string>;
    warnings: string[];
    placement: Placement;
}

interface PlacementPupil {
    id: number;
    name: string;
    admission_number: string | null;
}

interface PlacementRow {
    source: string;
    destination: string;
    /** null means the rollover would CREATE this destination — so it would have no subjects. */
    destination_curriculum_id: number | null;
    destination_key: string;
    destination_is_unconfigured: boolean;
    pupil_count: number;
    pupils: PlacementPupil[];
}

interface Placement {
    advancers: PlacementRow[];
    repeaters: PlacementRow[];
    unplaceable: {
        source: string;
        reason: string;
        explanation: string;
        pupils: PlacementPupil[];
    }[];
    graduating: {
        source: string;
        pupils: PlacementPupil[];
    }[];
    /** Pupils in every bucket combined — reconciled against `pupil_count` so none can go missing. */
    accounted_pupils: number;
    unconfigured_count: number;
    /**
     * OPAQUE. Echoed back on commit exactly as received, never rebuilt from the rows above.
     *
     * The server compares this against a freshly-planned set built by the same identity function,
     * so reconstructing it here would be a SECOND implementation of that identity — and a drift
     * between the two fails in the unsafe direction: a genuinely new unconfigured destination read
     * as a match, and pupils placed into it with no subjects.
     */
    unconfigured_keys: string[];
}

interface BatchRow {
    id: string;
    name: string;
    total_jobs: number;
    pending_jobs: number;
    failed_jobs: number;
    done_jobs: number;
    is_draining: boolean;
    /** 'finished' all succeeded · 'stopped' all resolved but some failed · 'cancelled' · null = still draining. */
    settled_state: 'finished' | 'stopped' | 'cancelled' | null;
    /** 'ccm-fold' or 'rollover'. Rendered, not merely carried — see the panel. */
    kind: string;
    /** The guard's sentences behind a failure. Empty on a clean batch. */
    failure_reasons: string[];
    finished_at: string | null;
}

interface RolloverPageProps {
    sessions: Named[];
    terms: Named[];
}

/**
 * The two rollover kinds share every concept — the gates, the plan, queued-not-done, the batch
 * panel — and differ only in what identifies the thing being closed: a TERM, or a pair of SESSIONS.
 * So they are one screen with a switch rather than two screens duplicating the whole surface for a
 * difference of one input.
 *
 * They are NOT interchangeable underneath: end-of-term never consults the progression graph (it does
 * not advance a level), which is why the plan carries `progression_check_ran` and the panel renders
 * "not applicable" rather than "no cycles" for it. The switch changes the inputs and the endpoint;
 * the server decides what each kind checks.
 */
type RolloverKind = 'end-of-year' | 'end-of-term';

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
export default function RolloverPage({ sessions, terms }: RolloverPageProps) {
    const [kind, setKind] = useState<RolloverKind>('end-of-year');
    const [source, setSource] = useState('');
    const [target, setTarget] = useState('');
    const [term, setTerm] = useState('');
    const [plan, setPlan] = useState<RolloverPlan | null>(null);
    const [loading, setLoading] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [folding, setFolding] = useState(false);
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

    /** The endpoint and body for the current kind — written once so preview and commit cannot
     *  disagree about which rollover they are running. */
    const endpoint = (suffix: '' | '/preview') =>
        `/api/rollover/${kind}${suffix}`;

    const payload = () =>
        kind === 'end-of-year'
            ? { source_session_id: source, target_session_id: target }
            : { term_id: term };

    /**
     * The commit body: the preview body plus the acknowledgment, ECHOED OPAQUELY.
     *
     * `plan.placement.unconfigured_keys` is sent back exactly as the server produced it. It is not
     * derived from the rendered rows, and it must never be — the server checks it against a freshly
     * planned set built by the same identity function, so a client-side reconstruction would be a
     * second implementation of that identity, and a drift would let a destination the operator never
     * saw take pupils with no subjects.
     */
    const commitPayload = () =>
        kind === 'end-of-year'
            ? {
                  ...payload(),
                  acknowledged_unconfigured:
                      plan?.placement?.unconfigured_keys ?? [],
              }
            : payload();

    const ready =
        kind === 'end-of-year' ? Boolean(source && target) : Boolean(term);

    /**
     * Fold the CCM classes blocking this term's rollover.
     *
     * Dispatches and RE-PREVIEWS — but deliberately does NOT claim the gate is clear. The folds are
     * queued (202), retried up to three times before a refusal is reported, and a fold can abort
     * permanently on config the operator has to fix. So this refreshes the batch panel and the
     * plan, and lets the operator watch the drain; it never renders "unblocked" on their behalf.
     */
    const foldCcm = async () => {
        setFolding(true);

        try {
            const res = await axios.post('/api/rollover/fold-ccm', {
                term_id: term,
            });

            toast.success(res.data?.message ?? 'Folds queued.');
            loadBatches();
            // Re-preview so the gate reflects reality — which, mid-drain, is still BLOCKED.
            await preview();
        } catch (err: unknown) {
            const data = (err as { response?: { data?: { message?: string } } })
                ?.response?.data;
            toast.error(data?.message ?? 'Could not queue the folds.');
        } finally {
            setFolding(false);
        }
    };

    const preview = async () => {
        setLoading(true);
        setPlan(null);
        setResult(null);

        try {
            const res = await axios.post(endpoint('/preview'), payload());
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
            const res = await axios.post(endpoint(''), commitPayload());

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
    const termLabel = terms.find((t) => t.id === term)?.label ?? '—';

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
                    {/* THE KIND SWITCH. Changing it clears any plan on screen — a plan for the
                        other kind still rendered would invite confirming the wrong rollover. */}
                    <div className="flex gap-2">
                        {(
                            [
                                ['end-of-year', 'End of year'],
                                ['end-of-term', 'End of term'],
                            ] as [RolloverKind, string][]
                        ).map(([value, label]) => (
                            <Button
                                key={value}
                                variant={kind === value ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => {
                                    setKind(value);
                                    setPlan(null);
                                    setResult(null);
                                }}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>

                    {kind === 'end-of-term' ? (
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="sm:col-span-2">
                                <Label className="text-xs">Closing term</Label>
                                <select
                                    className="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                                    value={term}
                                    onChange={(e) => setTerm(e.target.value)}
                                >
                                    <option value="">Select…</option>
                                    {terms.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-end">
                                <Button
                                    onClick={preview}
                                    disabled={!ready || loading}
                                    className="w-full"
                                >
                                    {loading ? 'Checking…' : 'Preview'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <Label className="text-xs">
                                    Closing session
                                </Label>
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
                                <Label className="text-xs">
                                    Pupils move into
                                </Label>
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
                                    disabled={!ready || loading}
                                    className="w-full"
                                >
                                    {loading ? 'Checking…' : 'Preview'}
                                </Button>
                            </div>
                        </div>
                    )}

                    {loading && <Spinner className="mx-auto" />}

                    {plan && (
                        <PlanPanel
                            plan={plan}
                            onRun={() => setConfirmOpen(true)}
                            onFold={
                                kind === 'end-of-term' ? foldCcm : undefined
                            }
                            folding={folding}
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
                title={
                    kind === 'end-of-year'
                        ? 'Run this year rollover?'
                        : 'Run this term rollover?'
                }
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
                    {/* NAMES WHAT IS ACTUALLY CLOSING. A dialogue reading "2025/2026 → 2026/2027"
                        while a TERM rollover is about to run would describe a different action from
                        the one queued — the confirm is the last place that can still be true. */}
                    {kind === 'end-of-year' ? (
                        <p className="flex items-center gap-2 font-medium">
                            {sourceLabel} <ArrowRight className="h-4 w-4" />{' '}
                            {targetLabel}
                        </p>
                    ) : (
                        <p className="font-medium">Closing term: {termLabel}</p>
                    )}
                    <p>
                        This will move{' '}
                        <strong>{plan?.pupil_count ?? 0} pupil(s)</strong>{' '}
                        across{' '}
                        <strong>{plan?.curricula.length ?? 0} class(es)</strong>
                        .
                    </p>
                    {/* THE UNSKIMMABLE HALF OF THE SUBJECT WARNING. The pre-flight panel states the
                        rule; this states the CONSEQUENCE of proceeding right now, in the one place
                        the operator cannot scroll past. It earns that place because the failure has
                        no recovery path — every caller of autoAttachCompulsorySubjects fires at
                        enrollment-creation time, so nothing re-attaches subjects afterwards. A
                        warning you must click past is a different object from one you can leave
                        unread. */}
                    {kind === 'end-of-year' &&
                        (plan?.placement?.unconfigured_count ?? 0) > 0 && (
                            <p className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs font-medium text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                                {plan?.placement.unconfigured_count} destination
                                {plan?.placement.unconfigured_count === 1
                                    ? ''
                                    : 's'}{' '}
                                {plan?.placement.unconfigured_count === 1
                                    ? 'has'
                                    : 'have'}{' '}
                                no curriculum set up. Pupils placed there will
                                have <strong>no subjects</strong>, and nothing
                                will attach them afterwards — set the subjects
                                up first if you can.
                            </p>
                        )}
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
function PlanPanel({
    plan,
    onRun,
    onFold,
    folding,
}: {
    plan: RolloverPlan;
    onRun: () => void;
    /** Only end-of-term can fold — the gate's blockers are that plan's. */
    onFold?: () => void;
    folding?: boolean;
}) {
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
                    <div className="flex items-start justify-between gap-3">
                        <p className="font-semibold">
                            {plan.ccm_blockers.length} CCM class(es) sit in a
                            final slot and must be moved first.
                        </p>
                        {/* ── RESOLUTION WHERE THE BLOCK IS FELT ──────────────────────────────
                            "Must be moved first" named the action and offered nothing that
                            performs it — the endpoint existed and no screen called it. That is a
                            dead end for precisely the operators who meet this: the ones who
                            configured a CCM slot rather than hand-creating the curriculum, and who
                            have therefore never touched the API or a console. */}
                        {onFold && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onFold}
                                disabled={folding}
                            >
                                {folding ? 'Queueing…' : 'Fold these now'}
                            </Button>
                        )}
                    </div>
                    <ul className="mt-1 list-inside list-disc">
                        {plan.ccm_blockers.map((c) => (
                            <li key={c.id}>{c.label}</li>
                        ))}
                    </ul>
                </div>
            )}

            {/* ── NOWHERE TO GO ────────────────────────────────────────────────────────────────
                Named, with the reason, because "3 classes will not move" is not actionable on a
                panel listing twelve. When EVERY class is stuck the plan is blocked and the warning
                says to run an end-of-year rollover instead — the operator picked the wrong kind. */}
            {Object.keys(plan.no_next_slot).length > 0 && (
                <div className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                    <p className="font-semibold">
                        These classes have no next term slot and will not move:
                    </p>
                    <ul className="mt-1 list-inside list-disc">
                        {Object.entries(plan.no_next_slot).map(
                            ([label, why]) => (
                                <li key={label}>
                                    {label} — {why}
                                </li>
                            ),
                        )}
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

            <PlacementPanel plan={plan} />
        </div>
    );
}

/**
 * WHERE EVERY PUPIL LANDS, and which destinations are not ready for them.
 *
 * ── END-OF-YEAR ONLY, AND THAT IS NOT AN OPTIMISATION ───────────────────────────────────────────
 * End-of-term keeps its class level and CLONES the curriculum's subjects onto the target
 * (MoveFromTermJob::cloneCurriculumSubjects), so there is no arm distribution to show and no
 * destination that can arrive subject-less. Rendering a subject warning there would warn about
 * something that cannot happen — and a screen that warns falsely teaches operators to skip
 * warnings, which costs more than the panel is worth. The server sends an empty placement for
 * end-of-term; this renders nothing rather than an empty table.
 */
function PlacementPanel({ plan }: { plan: RolloverPlan }) {
    const placement = plan.placement;

    if (
        !placement ||
        (placement.advancers.length === 0 &&
            placement.repeaters.length === 0 &&
            placement.unplaceable.length === 0)
    ) {
        return null;
    }

    return (
        <div className="space-y-3 border-t pt-3">
            {/* ── THE ORDERING RULE, WHERE THE PERSON ABOUT TO RUN IT WILL READ IT ──────────────
                Not "you may set subjects up first" — there is no second chance from the app. Every
                caller of autoAttachCompulsorySubjects fires at enrollment-creation time (the job,
                the observer's remediation fallback, CurriculumEnrollmentService,
                CurriculumReassignmentService), and all of them have run by the time anyone
                notices. */}
            {placement.unconfigured_count > 0 && (
                <div className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                    <p className="font-medium">
                        {placement.unconfigured_count} destination
                        {placement.unconfigured_count === 1 ? '' : 's'} ha
                        {placement.unconfigured_count === 1 ? 's' : 've'} no
                        curriculum yet.
                    </p>
                    <p className="mt-1">
                        End-of-year does not carry subjects across — the new
                        class level defines its own. The rollover will create
                        these curricula empty, pupils will land with no
                        subjects, and{' '}
                        <strong>nothing attaches them afterwards</strong>. Set
                        them up first if you can.
                    </p>
                    {/* THE THREE THINGS THAT MUST MATCH, because a prepared curriculum the job does
                        not FIND is worse than none — it creates a second one and leaves the
                        prepared one orphaned, which looks identical to having done nothing. */}
                    <ul className="mt-2 list-disc space-y-0.5 pl-4">
                        <li>
                            the class level&apos;s <strong>first</strong>{' '}
                            participating slot — not term 1 by assumption
                        </li>
                        <li>
                            the exam type the rollover resolves: the
                            pupil&apos;s own if the new level runs it, otherwise
                            that level&apos;s default
                        </li>
                        <li>
                            <strong>every arm</strong> of the receiving level —
                            a cohort is spread across all of them
                        </li>
                    </ul>
                </div>
            )}

            <PlacementTable title="Moving up" rows={placement.advancers} />
            <PlacementTable
                title="Held (repeating)"
                rows={placement.repeaters}
            />

            {placement.graduating.length > 0 && (
                <div className="rounded-md bg-muted px-3 py-2 text-xs">
                    <p className="font-medium">
                        Graduating — not moving (
                        {placement.graduating.reduce(
                            (n, g) => n + g.pupils.length,
                            0,
                        )}
                        ):
                    </p>
                    <ul className="mt-1 space-y-0.5">
                        {placement.graduating.map((g) => (
                            <li key={g.source}>
                                <strong>{g.source}</strong> — terminal class
                                level, nobody is promoted out of it (
                                {g.pupils.length} pupil
                                {g.pupils.length === 1 ? '' : 's'})
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {placement.unplaceable.length > 0 && (
                <div className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                    <p className="font-medium">
                        Would not move ({placement.unplaceable.length}):
                    </p>
                    <ul className="mt-1 space-y-0.5">
                        {placement.unplaceable.map((u) => (
                            <li key={`${u.source}-${u.reason}`}>
                                <strong>{u.source}</strong> — {u.explanation} (
                                {u.pupils.length} pupil
                                {u.pupils.length === 1 ? '' : 's'})
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* THE TOTAL HAS TO CLOSE. The headline above says "N pupils across M classes"; if the
                buckets do not sum to N, the difference is pupils nobody has accounted for — and a
                screen whose own numbers disagree on a bulk destructive action is the count-honesty
                failure this milestone already paid for once. Rendered only when it does NOT
                reconcile, so the normal case stays quiet. */}
            {placement.accounted_pupils !== plan.pupil_count && (
                <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                    {plan.pupil_count} pupil(s) are in this plan but only{' '}
                    {placement.accounted_pupils} appear above — check the
                    difference before running this.
                </p>
            )}
        </div>
    );
}

/**
 * One bucket, grouped by destination, expandable to names.
 *
 * GROUPED, NOT FLAT: a year group is hundreds of rows, and the question an operator actually asks is
 * "which class goes where, and is anyone stranded". Counts answer that; the names answer the
 * follow-up when a row looks wrong.
 */
function PlacementTable({
    title,
    rows,
}: {
    title: string;
    rows: PlacementRow[];
}) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">
                {title}
            </p>
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={`${row.source}-${row.destination_key}`}
                                className="border-b last:border-0"
                            >
                                <td className="py-1 pr-2">{row.source}</td>
                                <td className="py-1 pr-2 text-muted-foreground">
                                    →
                                </td>
                                <td className="py-1 pr-2">
                                    {row.destination}
                                    {row.destination_is_unconfigured && (
                                        <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-800 dark:bg-red-950/60 dark:text-red-300">
                                            no curriculum yet
                                        </span>
                                    )}
                                </td>
                                <td className="py-1 pr-2 text-right whitespace-nowrap">
                                    {row.pupil_count} pupil
                                    {row.pupil_count === 1 ? '' : 's'}
                                </td>
                                <td className="py-1">
                                    <details>
                                        <summary className="cursor-pointer text-muted-foreground">
                                            names
                                        </summary>
                                        <ul className="mt-1 space-y-0.5">
                                            {row.pupils.map((p) => (
                                                <li key={p.id}>
                                                    {p.name}
                                                    {p.admission_number
                                                        ? ` (${p.admission_number})`
                                                        : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
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
                            <Fragment key={b.id}>
                                <tr className="border-t">
                                    <td className="py-1 font-mono">
                                        {/* THE KIND IS RENDERED, NOT JUST CARRIED. Its entire job
                                            is stopping an operator reading a draining FOLD as a
                                            draining ROLLOVER — the two are dispatched from this
                                            screen seconds apart and mean opposite things. A
                                            distinction that lives only in the payload is a
                                            distinction the operator does not have. */}
                                        <span
                                            className={`mr-2 rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                                b.kind === 'ccm-fold'
                                                    ? 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}
                                        >
                                            {b.kind === 'ccm-fold'
                                                ? 'CCM fold'
                                                : 'Rollover'}
                                        </span>
                                        {b.name}
                                    </td>
                                    <td className="py-1">
                                        {b.done_jobs}/{b.total_jobs}
                                    </td>
                                    <td className="py-1">{b.pending_jobs}</td>
                                    <td className="py-1">{b.failed_jobs}</td>
                                    <td className="py-1">
                                        {/* NOT keyed on finished_at — a batch holding a failed job
                                            never gets one, so this used to read "Draining" forever
                                            over a batch no worker would touch again. The server
                                            derives the terminal state from the counts; see
                                            RolloverController::settledState. */}
                                        {b.is_draining ? (
                                            <span className="text-amber-700 dark:text-amber-400">
                                                Draining — do not change the
                                                current session yet
                                            </span>
                                        ) : b.settled_state === 'cancelled' ? (
                                            <span className="text-destructive">
                                                Cancelled
                                            </span>
                                        ) : b.failed_jobs > 0 ? (
                                            /* "Stopped", not "Finished": these jobs are still
                                               pending in the queue's own sense, awaiting a retry
                                               someone must issue. Saying "finished" would claim a
                                               completeness the batch has not reached. */
                                            <span className="text-destructive">
                                                Stopped with {b.failed_jobs}{' '}
                                                failure(s) — it will not resume
                                                on its own
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Finished
                                            </span>
                                        )}
                                    </td>
                                </tr>

                                {/* ── THE RETRY WINDOW ────────────────────────────────────────
                                    A fold that will abort is neither done nor failed for THREE
                                    attempts: $tries = 3, and the reason only reaches failed_jobs
                                    once they exhaust. So there is a real interval where a doomed
                                    fold reads as ordinary draining, and an optimistic panel would
                                    invite the operator to confirm a rollover against it. Said
                                    plainly rather than left to look like progress. */}
                                {b.kind === 'ccm-fold' && b.is_draining && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="pb-2 text-[11px] text-amber-700 dark:text-amber-400"
                                        >
                                            A fold is retried up to 3 times
                                            before it reports a failure — wait
                                            for this batch to finish before
                                            re-previewing, and do not treat
                                            “draining” as “succeeded”.
                                        </td>
                                    </tr>
                                )}

                                {b.failure_reasons.length > 0 && (
                                    <tr>
                                        <td colSpan={5} className="pb-2">
                                            {/* The REASON, because the refusal is deterministic
                                                config — retrying never clears it. "Failed" alone
                                                would re-block the operator one layer in. */}
                                            <ul className="list-inside list-disc rounded-md bg-destructive/10 px-3 py-2 text-[11px] text-destructive">
                                                {b.failure_reasons.map((r) => (
                                                    <li key={r}>{r}</li>
                                                ))}
                                            </ul>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
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
