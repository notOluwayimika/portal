import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Clock,
    Layers,
    Loader2,
    Play,
    RefreshCw,
    Search,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { StatusPill } from '@/components/finance/status-pill';
import type { StatusTone } from '@/components/finance/status-pill';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { bulkInvoiceRuns, RUN_IN_FLIGHT } from '@/services/bulk-invoice-runs';
import type {
    BulkInvoiceRunPreview,
    BulkInvoiceRunRecord,
    ClassLevelOption,
    RunStatus,
    TermOption,
} from '@/services/bulk-invoice-runs';

/**
 * BILL A COHORT (U6 commit 4) — the bursar picks a term and a class level, reads what would happen,
 * and starts a run; the table below is every run this school has made.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE PREVIEW IS NOT A COURTESY, IT IS THE CONTROL THIS SCREEN IS BUILT AROUND
 *
 * Nothing undoes a bulk run. Every invoice it raises is undone by its own maker-checker void request
 * — one submission and one approval PER CHILD — so a run over a class level of forty that should not
 * have been started is forty two-signature reversals. So Start is unreachable until a preview for the
 * CURRENT coordinates has been read: changing either select discards the preview, because a
 * confirmation that names last query's cohort size is worse than no number at all.
 *
 * AND THE CONFIRMATION NAMES THE COHORT SIZE, on the pattern of the opening-balance queue's, which is
 * the only other irreversible act in Finance. Not a bare "Are you sure?": the figure a bursar can
 * disagree with is the number of children about to be billed.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SCHEDULE IS DISPLAY, NOT A CHOICE. `active` is the only billable status, so there is exactly
 * one candidate per (term, class level) — a dropdown here would have one option in it and would
 * imply an authority this screen does not have. What version was pinned is shown; which version is
 * pinned is not asked.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TERM IS DEFAULTED AND SHOWN; THE CLASS LEVEL IS THE ONE THING ASKED FOR.
 *
 * `default_term_id` arrives as a prop, resolved by `App\Support\CurrentTerm` from the school's
 * current session — the same expression the setup endpoint reads, not a copy. The operator normally
 * touches one control.
 *
 * IT STAYS CHANGEABLE, and that is the half a "smart default" usually gets wrong. Billing a PAST term
 * is a real act: a child who enrols late is billed for the term they enrolled in. So the default is
 * pre-filled and OVERRIDABLE, and the override is deliberate — the term control is collapsed behind
 * "Change" so the common path is one decision and the uncommon one is still reachable. When an
 * override is in force the screen SAYS SO, because a screen quietly billing a term other than the
 * current one is worse than one that asks.
 *
 * `default_term_id` IS NULL when the school has no current session, and the term control is then open
 * from the start with nothing pre-selected. A screen that invented a default there would be choosing
 * a term on behalf of a school that has not said which one it is in.
 *
 * "TERM" IS `terms.id` EVERYWHERE ON THIS SCREEN — the row, never an ordinal. The `curricula.term`
 * ordinal (1 | 2 | 3) that the two could once be confused for no longer exists; it was dropped with
 * `curricula.academic_session_id` and replaced by a `term_id` FK, and the live `curricula_unique_key`
 * is `(school_id, class_level_arm_id, term_id, exam_type_id, is_ccm)`. That same key is why the term
 * cannot be DERIVED from the class level: one arm holds a curriculum row per term.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A NULL COUNT IS NOT A ZERO — the rule this file most has to hold.
 *
 * A pending run has not been picked up, a running run is mid-cohort, and a run stopped by a per-run
 * condition never reached its reconciliation at all, so `counts` are null in all three. `has_figures`
 * is the SERVER's answer to whether they may be rendered; where it is false this table renders an em
 * dash — this codebase's no-value glyph — and never `0`. That is §26's state-collapse defect, which
 * this project has shipped five times, and it is the one that would be most damaging here: "0 billed"
 * over a run that is still going is a false statement about a school's money.
 *
 * NO MONEY ON THIS SCREEN. A run reports counts; `finance_bulk_invoice_runs` carries no money column
 * at all. bin/ci-money-lint is irrelevant to this file by construction rather than by care.
 *
 * TOASTS ARE `sonner`, matching bank-accounts, fee-schedules and discount-policies. react-toastify is
 * the older library and no new Finance file imports it — docs/handoff/tickets/two-toast-libraries.md.
 *
 * LAID OUT TO docs/ui-ux-design-system.md: page shell → hero card → the act → filter/table card, with
 * loading, error and empty as three DISTINCT spanning rows rather than one spinner and a toast.
 */

const STATUS_TONE: Record<RunStatus, StatusTone> = {
    pending: 'amber',
    running: 'blue',
    completed: 'emerald',
    failed: 'red',
};

const STATUS_LABEL: Record<RunStatus, string> = {
    pending: 'Queued',
    running: 'Running',
    completed: 'Completed',
    failed: 'Failed',
};

function StatusIcon({ status }: { status: RunStatus }) {
    if (status === 'running') {
        return <Loader2 className="h-3.5 w-3.5 animate-spin" />;
    }

    if (status === 'pending') {
        return <Clock className="h-3.5 w-3.5" />;
    }

    if (status === 'failed') {
        return <XCircle className="h-3.5 w-3.5" />;
    }

    return <CheckCircle2 className="h-3.5 w-3.5" />;
}

/**
 * A figure, or the no-value glyph when the run has not reported one.
 *
 * `has_figures` decides, NOT the status: the nobody-billed rule writes all eight counts and then
 * marks the run `failed`, so one of the five routes into `failed` is fully counted and hiding its
 * figures would hide the entire diagnosis of the one failure where they matter most.
 */
function figure(run: BulkInvoiceRunRecord, value: number | null): string {
    return run.has_figures && value !== null ? String(value) : '—';
}

export default function BulkInvoiceRunsIndex({
    terms,
    class_levels: classLevels,
    default_term_id: defaultTermId,
}: {
    terms: TermOption[];
    class_levels: ClassLevelOption[];
    default_term_id: number | null;
}) {
    // THE DEFAULT IS THE SERVER'S, NOT `terms[0]`. The list is ordered newest-session-first, which is
    // a display order and not a statement about which term the school is in — reading a default off
    // it would be this screen guessing at a fact CurrentTerm already answers.
    const [termId, setTermId] = useState<number | ''>(defaultTermId ?? '');
    const [classLevelId, setClassLevelId] = useState<number | ''>(
        classLevels[0]?.id ?? '',
    );

    // The term control is collapsed while the default stands. Opened by the operator, or open from
    // the start when there is no default to stand on — a school with no current session must be
    // asked, never given a term this screen picked.
    const [termOpen, setTermOpen] = useState(defaultTermId === null);

    const [preview, setPreview] = useState<BulkInvoiceRunPreview | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [previewError, setPreviewError] = useState(false);

    const [runs, setRuns] = useState<BulkInvoiceRunRecord[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    const [confirming, setConfirming] = useState(false);
    const [starting, setStarting] = useState(false);

    const loadRuns = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get<{ data: BulkInvoiceRunRecord[] }>(
                bulkInvoiceRuns.listUrl(),
            );
            setRuns(data.data ?? []);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // Same disable the sibling finance screens carry: the initial fetch is the effect's whole
        // purpose, and its loading flag is set synchronously inside it.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void loadRuns();
    }, [loadRuns]);

    /*
     * A QUEUED RUN FINISHES ASYNCHRONOUSLY, so the list refetches while any run on it is still in
     * flight and stops when none is. The condition is stated over the NON-terminal set —
     * `pending | running`, the enum's own two — rather than as `!== 'completed'`, which would spin
     * forever beside a failed run.
     *
     * The refetch is silent: it does not raise `loading`, because a spinner replacing a table the
     * operator is reading every three seconds is worse than a stale row.
     */
    const inFlight = runs.some((run) => RUN_IN_FLIGHT.includes(run.status));

    useEffect(() => {
        if (!inFlight) {
            return;
        }

        const id = setInterval(() => {
            void (async () => {
                try {
                    const { data } = await axios.get<{
                        data: BulkInvoiceRunRecord[];
                    }>(bulkInvoiceRuns.listUrl());
                    setRuns(data.data ?? []);
                } catch {
                    /* keep polling — a transient 500 must not strand the screen */
                }
            })();
        }, 3000);

        return () => clearInterval(id);
    }, [inFlight]);

    /*
     * THE PREVIEW IS DISCARDED WHENEVER THE COORDINATES MOVE. Without this the operator can preview
     * JSS 1, switch the select to JSS 3, and be shown a confirmation naming JSS 1's cohort size over
     * a run that would bill JSS 3 — a number that is real, current-looking and about the wrong
     * children. Start keys off the preview being present, so discarding it also closes the door.
     */
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setPreview(null);

        setPreviewError(false);
    }, [termId, classLevelId]);

    const runPreview = async () => {
        if (termId === '' || classLevelId === '') {
            return;
        }

        setPreviewing(true);
        setPreviewError(false);

        try {
            const { data } = await axios.get<BulkInvoiceRunPreview>(
                bulkInvoiceRuns.previewUrl(termId, classLevelId),
            );
            setPreview(data);
        } catch {
            setPreviewError(true);
        } finally {
            setPreviewing(false);
        }
    };

    const start = async () => {
        if (termId === '' || classLevelId === '') {
            return;
        }

        setStarting(true);

        try {
            const { data } = await axios.post<BulkInvoiceRunRecord>(
                bulkInvoiceRuns.storeUrl(),
                { term_id: termId, class_level_id: classLevelId },
            );

            setConfirming(false);
            toast.success('The run has been queued.');
            // Straight to the run's own report — the only screen that can say what became of each
            // student, and the one the operator now needs to watch.
            router.visit(bulkInvoiceRuns.pageUrl(data.uuid));
        } catch {
            toast.error('Could not start the run.');
        } finally {
            setStarting(false);
        }
    };

    const termLabel = useMemo(
        () => terms.find((t) => t.id === termId)?.label ?? null,
        [terms, termId],
    );
    const defaultTermLabel = useMemo(
        () => terms.find((t) => t.id === defaultTermId)?.label ?? null,
        [terms, defaultTermId],
    );
    // An override in force — the term is set and it is NOT the school's current one.
    const overriding =
        defaultTermId !== null && termId !== '' && termId !== defaultTermId;
    const classLevelLabel = useMemo(
        () => classLevels.find((c) => c.id === classLevelId)?.name ?? null,
        [classLevels, classLevelId],
    );

    // Start needs a preview OF THESE COORDINATES that did not refuse. A refusal is a fact about the
    // price list, and the run would fail on it before writing a single row.
    const startable = preview !== null && preview.refusal === null && !starting;

    return (
        <>
            <Head title="Bulk invoice runs" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <Layers className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Bulk invoice runs
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Raise the term bill for a whole class
                                        level at once, and read back exactly who
                                        was and was not billed.
                                    </p>
                                </div>
                            </div>

                            {/* No <Can> gate on any control here. This page's route and all four of
                                its API routes carry the one ability `finance.invoice.generate`, so
                                there is no action below that the backend would refuse to a seat
                                that reached this screen. */}
                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void loadRuns()}
                                    disabled={loading}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <RefreshCw
                                        className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`}
                                    />
                                    Refresh
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* ── Start a run ──────────────────────────────────────────── */}
                    <div className="rounded-xl border-none bg-white p-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                            Start a run
                        </h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Pick the term and the class level. Nothing is
                            created until you have read what would happen and
                            confirmed it.
                        </p>

                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label
                                    className="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300"
                                    htmlFor="bir-term"
                                >
                                    Term
                                </label>

                                {/* THE DEFAULT, SHOWN AS A FACT rather than as a field. The control
                                    appears when the operator asks for it — or immediately, when
                                    there is no current session to default from. */}
                                {!termOpen ? (
                                    <div className="flex h-9 items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 dark:border-slate-700 dark:bg-slate-900/40">
                                        <span
                                            className="truncate text-xs font-semibold text-slate-700 dark:text-slate-200"
                                            data-testid="bir-default-term"
                                        >
                                            {defaultTermLabel ?? '—'}
                                        </span>
                                        <button
                                            type="button"
                                            id="bir-term-change"
                                            onClick={() => setTermOpen(true)}
                                            className="shrink-0 text-[11px] font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            Change
                                        </button>
                                    </div>
                                ) : (
                                    <Select
                                        id="bir-term"
                                        value={termId === '' ? null : termId}
                                        onChange={(val) =>
                                            setTermId(
                                                val === null ||
                                                    val === undefined
                                                    ? ''
                                                    : Number(val),
                                            )
                                        }
                                        placeholder="Select a term"
                                        options={terms.map((t) => ({
                                            label: t.label,
                                            value: t.id,
                                        }))}
                                    />
                                )}

                                {/* AN OVERRIDE IS ANNOUNCED. A screen quietly billing a term other
                                    than the current one is worse than one that asks. */}
                                <p className="mt-1 text-[11px] text-slate-400">
                                    {defaultTermId === null
                                        ? 'This school has no current session, so there is no term to default to. Pick the term being billed.'
                                        : overriding
                                          ? 'Not the current term — this run will bill the term selected above.'
                                          : 'The school’s current term. Change it to bill a past term.'}
                                </p>
                            </div>

                            <div>
                                <label
                                    className="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300"
                                    htmlFor="bir-class-level"
                                >
                                    Class level
                                </label>
                                <Select
                                    id="bir-class-level"
                                    value={
                                        classLevelId === ''
                                            ? null
                                            : classLevelId
                                    }
                                    onChange={(val) =>
                                        setClassLevelId(
                                            val === null || val === undefined
                                                ? ''
                                                : Number(val),
                                        )
                                    }
                                    placeholder="Select a class level"
                                    options={classLevels.map((c) => ({
                                        label: c.name,
                                        value: c.id,
                                    }))}
                                />
                            </div>

                            <div className="flex items-end gap-2 sm:col-span-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void runPreview()}
                                    disabled={
                                        previewing ||
                                        termId === '' ||
                                        classLevelId === ''
                                    }
                                    className="rounded-lg"
                                >
                                    {previewing ? (
                                        <Spinner className="mr-1.5 h-4 w-4" />
                                    ) : (
                                        <Search className="mr-1.5 h-4 w-4" />
                                    )}
                                    {previewing
                                        ? 'Checking…'
                                        : 'Preview this run'}
                                </Button>

                                <Button
                                    size="sm"
                                    onClick={() => setConfirming(true)}
                                    disabled={!startable}
                                    title={
                                        startable
                                            ? undefined
                                            : 'Preview the run first — starting one cannot be undone.'
                                    }
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <Play className="mr-1.5 h-4 w-4" />
                                    Start run
                                </Button>
                            </div>
                        </div>

                        {/* THE PREVIEW'S FOUR STATES, KEPT DISTINCT. Idle says what the button is
                            for; the in-flight state is the button's own spinner; a failed preview
                            says so and offers a retry rather than rendering as "nothing found"; and
                            a loaded preview is below. A failed fetch reading as an empty school is
                            §26's instance one and has shipped three times. */}
                        {previewError && (
                            <div className="mt-4 flex items-start gap-3 rounded-lg border border-red-100 bg-red-50 p-3 dark:border-red-900/30 dark:bg-red-950/20">
                                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-red-500" />
                                <div className="text-xs">
                                    <p className="font-semibold text-slate-700 dark:text-slate-200">
                                        Could not check these coordinates
                                    </p>
                                    <p className="text-slate-500">
                                        Nothing was created. Try again — Start
                                        stays closed until a preview succeeds.
                                    </p>
                                </div>
                            </div>
                        )}

                        {!previewError && preview === null && !previewing && (
                            <p className="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-900/40 dark:text-slate-400">
                                Preview first. Starting a run cannot be undone —
                                reversing one is a void request and a second
                                signature for every child it billed.
                            </p>
                        )}

                        {!previewError && preview !== null && (
                            <div className="mt-4 space-y-3">
                                {preview.refusal !== null && (
                                    <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/40 dark:bg-amber-950/20">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                                        <div className="text-xs">
                                            <p className="font-semibold text-slate-700 dark:text-slate-200">
                                                This run would fail before
                                                billing anybody
                                            </p>
                                            {/* THE SERVER'S OWN SENTENCE, verbatim. It is the same
                                                string the job would write into failure_reason —
                                                either FeeScheduleLineMapper's exception message or
                                                ProcessBulkInvoiceRun's no-schedule sentence — so
                                                the preview and the run cannot disagree about why. */}
                                            <p className="mt-0.5 text-slate-600 dark:text-slate-300">
                                                {preview.refusal}
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <PreviewFigure
                                        label="Students in this cohort"
                                        value={String(preview.cohort_size)}
                                        note="Billable enrollments at these coordinates."
                                    />
                                    <PreviewFigure
                                        label="Already billed"
                                        value={String(preview.already_billed)}
                                        note="Of that cohort. They will be recorded, not billed again."
                                    />
                                    <PreviewFigure
                                        label="Would be billed"
                                        value={String(
                                            preview.cohort_size -
                                                preview.already_billed,
                                        )}
                                        note="Cohort minus those already carrying a term bill."
                                    />
                                </div>

                                {/* THE SCHEDULE, SHOWN AND NOT ASKED. One candidate exists per
                                    (term, class level), because `active` is the only billable
                                    status — so this states which version would be pinned for the
                                    whole run rather than offering a choice of one. */}
                                <div className="rounded-lg border border-slate-100 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900/40">
                                    <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                        Fee schedule
                                    </p>
                                    {preview.schedule === null ? (
                                        <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                            No active fee schedule at these
                                            coordinates. There is nothing to
                                            price from.
                                        </p>
                                    ) : (
                                        <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                            <span className="font-semibold text-slate-800 dark:text-slate-100">
                                                {preview.schedule.label}
                                            </span>{' '}
                                            ({preview.schedule.status})
                                            {preview.schedule
                                                .mandatory_item_count !==
                                                null && (
                                                <>
                                                    {' · '}
                                                    {String(
                                                        preview.schedule
                                                            .mandatory_item_count,
                                                    )}{' '}
                                                    mandatory item(s) on every
                                                    invoice
                                                </>
                                            )}
                                        </p>
                                    )}
                                    <p className="mt-1 text-[11px] text-slate-400">
                                        Only mandatory items are billed. Bus,
                                        lunch and anything else optional is
                                        added per child afterwards — nothing in
                                        the schema records which child takes
                                        them.
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* ── Runs table ───────────────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                            <div>
                                <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                    Recent runs
                                </h2>
                                <p className="text-[11px] text-slate-500">
                                    A run is a permanent record. There is no
                                    delete — re-running is safe and is the
                                    recovery path.
                                </p>
                            </div>
                            {inFlight && (
                                <span className="flex items-center gap-1.5 text-[11px] font-medium text-blue-600 dark:text-blue-400">
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                    Refreshing while a run is in flight
                                </span>
                            )}
                        </div>

                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Coordinates
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Status
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Schedule
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Billed
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Cohort
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Started by
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Report
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {loading ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : error ? (
                                        <tr>
                                            <td colSpan={7} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            Could not load the
                                                            runs
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            This says nothing
                                                            about whether any
                                                            run exists.
                                                        </p>
                                                    </div>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            void loadRuns()
                                                        }
                                                        className="rounded-lg"
                                                    >
                                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                                        Retry
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : runs.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Layers className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            No runs yet
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            Preview a term and a
                                                            class level above to
                                                            see what a run would
                                                            bill.
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        runs.map((run) => (
                                            <tr
                                                key={run.uuid}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                data-testid="bulk-invoice-run-row"
                                            >
                                                <td className="px-4 py-2.5">
                                                    <p className="font-semibold text-slate-700 dark:text-slate-200">
                                                        {run.class_level_label ??
                                                            '—'}
                                                    </p>
                                                    <p className="text-[11px] text-slate-400">
                                                        {run.term_label ?? '—'}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <span className="text-slate-400">
                                                            <StatusIcon
                                                                status={
                                                                    run.status
                                                                }
                                                            />
                                                        </span>
                                                        <StatusPill
                                                            tone={
                                                                STATUS_TONE[
                                                                    run.status
                                                                ]
                                                            }
                                                            label={
                                                                STATUS_LABEL[
                                                                    run.status
                                                                ]
                                                            }
                                                        />
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                    {run.fee_schedule?.label ??
                                                        '—'}
                                                </td>
                                                {/* NEVER `0` FOR A RUN THAT HAS NOT REPORTED. See
                                                    figure() — the em dash is the no-value glyph and
                                                    cannot be read as a real zero, which a genuine
                                                    0-billed completed run still renders. */}
                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-700 tabular-nums dark:text-slate-200">
                                                    {figure(
                                                        run,
                                                        run.counts.billed,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-right text-slate-600 tabular-nums dark:text-slate-300">
                                                    {figure(
                                                        run,
                                                        run.counts.cohort,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                    {run.started_by_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-right">
                                                    {/* EVERY ROW LINKS, unconditionally — including a
                                                        failed one, whose report is the only place its
                                                        reason is readable. */}
                                                    <a
                                                        href={bulkInvoiceRuns.pageUrl(
                                                            run.uuid,
                                                        )}
                                                        className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                                    >
                                                        Open
                                                        <ArrowRight className="h-3.5 w-3.5" />
                                                    </a>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/*
             * THE CONFIRMATION, ON THE OPENING-BALANCE QUEUE'S PATTERN — the only other irreversible
             * act in Finance. It names the COHORT SIZE and the coordinates, because those are the
             * figures a bursar can disagree with; a bare "Are you sure?" is a dialog people learn to
             * click through, which is how the one that matters gets clicked through.
             *
             * It is a COURTESY, NEVER A CONTROL. The ability on all four routes, the School scope on
             * the models and the per-episode unique index on finance_invoices all hold against a
             * client that never renders this.
             */}
            <Modal
                isOpen={confirming}
                onClose={() => setConfirming(false)}
                title="Start this run? It cannot be undone."
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                            disabled={starting}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={() => void start()}
                            disabled={!startable}
                        >
                            {starting ? (
                                <Spinner className="mr-2 h-4 w-4" />
                            ) : (
                                <Play className="mr-2 h-4 w-4" />
                            )}
                            {starting
                                ? 'Starting…'
                                : `Bill ${String(preview?.cohort_size ?? 0)} student(s)`}
                        </Button>
                    </div>
                }
            >
                {preview !== null && (
                    <div className="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                        <p>
                            This raises the term bill for{' '}
                            <span className="font-bold text-slate-900 dark:text-white">
                                {String(preview.cohort_size)}
                            </span>{' '}
                            student(s) in{' '}
                            <span className="font-semibold">
                                {classLevelLabel ?? '—'}
                            </span>
                            , for{' '}
                            <span className="font-semibold">
                                {termLabel ?? '—'}
                            </span>
                            .
                        </p>
                        <p>
                            <span className="font-bold text-slate-900 dark:text-white">
                                {String(preview.already_billed)}
                            </span>{' '}
                            of them already carry a term bill and will be
                            recorded as already billed, not billed twice.
                        </p>
                        {/* THE OVERRIDE, RESTATED AT THE POINT OF COMMITMENT. The term was
                            defaulted; if the operator moved it, the confirmation is the last place
                            they can notice — and billing the wrong term is not correctable by
                            re-running, it is correctable one void request at a time. */}
                        {overriding && (
                            <p className="rounded-lg bg-amber-50 p-3 text-xs font-semibold text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                This is NOT the school&rsquo;s current term. You
                                have chosen {termLabel ?? '—'} in place of{' '}
                                {defaultTermLabel ?? '—'}.
                            </p>
                        )}
                        <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                            There is no undo. Reversing an invoice this run
                            raises takes a void request and a second
                            person&rsquo;s approval, one at a time, for every
                            child it billed.
                        </p>
                    </div>
                )}
            </Modal>
        </>
    );
}

/** One preview figure. A plain count — nothing on this screen is money. */
function PreviewFigure({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note: string;
}) {
    return (
        <div className="rounded-lg border border-slate-100 bg-white px-4 py-3 shadow-sm dark:border-white/5 dark:bg-card">
            <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                {label}
            </p>
            <p className="mt-1 text-2xl font-extrabold tracking-tight text-slate-800 tabular-nums dark:text-slate-100">
                {value}
            </p>
            <p className="mt-1 text-[11px] text-slate-400">{note}</p>
        </div>
    );
}

BulkInvoiceRunsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Bulk invoice runs', href: '/finance/bulk-invoice-runs' },
    ],
};
