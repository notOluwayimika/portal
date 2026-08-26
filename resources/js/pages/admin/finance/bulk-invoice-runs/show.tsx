import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    ExternalLink,
    Layers,
    Loader2,
    RefreshCw,
    UserX,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { StatusPill } from '@/components/finance/status-pill';
import type { StatusTone } from '@/components/finance/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { statement as statementRoute } from '@/routes/admin/finance';
import { bulkInvoiceRuns, RUN_IN_FLIGHT } from '@/services/bulk-invoice-runs';
import type {
    BulkInvoiceRunDetail,
    RunBucket,
    RunOutcome,
    RunStatus,
} from '@/services/bulk-invoice-runs';

/**
 * ONE BULK INVOICE RUN'S REPORT (U6 commit 4) — what became of every billable student while it ran.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE FIGURES ARE RENDERED ONLY WHEN THE RUN HAS REPORTED THEM, AND `has_figures` DECIDES
 *
 * All nine counts are NULL until the job reconciles. A pending run has not been picked up, a
 * running run is mid-cohort, and a run stopped by a per-run condition — no active schedule, a mapper
 * refusal, a worker that died — never reached the reconciliation at all. Rendering any of those as
 * `0` is §26's state-collapse defect, which this project has shipped five times, and here it would be
 * a confident false statement about a school's billing.
 *
 * `has_figures` IS NOT `status !== 'failed'`, and this is the one place that distinction is load
 * bearing. The NOBODY-BILLED RULE writes all nine counts and THEN marks the run `failed` — a run
 * whose whole cohort failed is `failed` AND fully counted, and its figures are the entire diagnosis
 * ("check the rows for the repeated reason before re-running"). So four of the five routes into
 * `failed` show no figures because they have none, and the fifth shows them because it has them.
 * The server answers that question; this file does not re-derive it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `outside_coordinates` IS SCOPE, NEVER A MISS
 *
 * It is the billable students this run did not ENUMERATE, because they are priced at other
 * coordinates. On a single-level run in a seven-level school it is roughly six-sevenths of the
 * roster — on EVERY successful run. Wording it as "students missed" would be wrong every single time
 * and would teach an operator to ignore the numbers that are not. It also under-reports by a known
 * amount, and the caveat is on the screen rather than only in a docblock: student-less episodes
 * collapse into one SQL group, so the figure indicates such episodes exist without counting them.
 *
 * THE UNPLACEABLE LIST IS THE ACTIONABLE ONE and it is rendered first, with the failed list beside
 * it. Those students cannot be billed by ANY run until someone gives them a term and a class level.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * STILL RUNNING AND FINISHED-WITH-NOTHING ARE DIFFERENT SCREENS. An empty bucket says one thing on a
 * completed run ("nobody landed here") and a different thing on a running one ("nothing yet"), and
 * the two are never allowed to share a sentence.
 *
 * WHAT A READER CANNOT REACH FROM HERE. There is no invoice index — that is U7 and its own piece of
 * work — so a `billed` row links to the student's STATEMENT, which already lists their invoices. It
 * does not link to the invoice this run raised. ALL FOUR consequences are stated on the screen where
 * they bite, not only in this docblock: an earlier version of the report claimed that and only two
 * of the four were actually said.
 *
 * NO MONEY. A run reports counts; the table carries no money column at all.
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

/** Order is meaning: the two lists somebody must act on come first. */
const BUCKET_ORDER: RunOutcome[] = [
    'unplaceable',
    'failed',
    // Third, above the two no-action buckets: this list IS an action — somebody has to raise these
    // invoices by hand — it is simply not an action taken in this run.
    'sponsored',
    'billed',
    'already_billed',
];

const BUCKET_COPY: Record<
    RunOutcome,
    { title: string; blurb: string; empty: string; tone: StatusTone }
> = {
    unplaceable: {
        title: 'Could not be placed',
        // IT NAMES WHERE TO GO, and it does not link there. The only control on these rows is
        // Statement, and "give them coordinates" without saying where is an instruction to nowhere.
        // A link is NOT cheap here: the per-student academic record lives at
        // setup/student-curricula/{student}, gated on `student_curriculum.view` — an ability a
        // finance seat does not hold — and it is a READ-ONLY view besides, so it is not where a term
        // or class level is set either. Offering a bursar a link that 403s is worse than a sentence.
        blurb: 'Billable, but with no term or no class level — so no fee schedule can be keyed to them and no run can ever bill them. This is the list to act on, and it is not fixed in Finance: someone with academic access has to give each of these students a term and a class level on their academic record, and then this run can be re-run safely.',
        empty: 'Every billable student in this school has a term and a class level.',
        tone: 'amber',
    },
    failed: {
        title: 'Failed',
        blurb: 'The run tried to bill these and could not. Each carries its own reason; the run itself carried on.',
        empty: 'Nothing failed.',
        tone: 'red',
    },
    billed: {
        title: 'Billed',
        blurb: 'This run raised a term bill for each of these students.',
        empty: 'This run billed nobody.',
        tone: 'emerald',
    },
    already_billed: {
        title: 'Already billed',
        blurb: 'These already carried a term bill for their episode, so this run raised none. That is not an error — it is what a safe re-run looks like.',
        empty: 'Nobody in the cohort was already billed.',
        tone: 'slate',
    },
    sponsored: {
        title: 'Sponsored — billed by hand',
        // IT NAMES WHAT IS OWED AND BY WHOM, because the one thing a bursar can get wrong here is
        // reading it as a miss and re-running to "fix" it. Re-running produces this same list.
        blurb: 'These students are on a sponsored scholarship, so an organisation pays for them on a different fee basis, once a session, off platform. This run left them alone deliberately — they are not a failure and re-running will not bill them. Their invoices are raised by hand, and this is the list to raise them from.',
        empty: 'Nobody in the cohort is on a sponsored scholarship.',
        tone: 'blue',
    },
};

function StatusIcon({ status }: { status: RunStatus }) {
    if (status === 'running') {
        return <Loader2 className="h-5 w-5 animate-spin" />;
    }

    if (status === 'pending') {
        return <Clock className="h-5 w-5" />;
    }

    if (status === 'failed') {
        return <XCircle className="h-5 w-5" />;
    }

    return <CheckCircle2 className="h-5 w-5" />;
}

export default function BulkInvoiceRunShow({ runUuid }: { runUuid: string }) {
    const [run, setRun] = useState<BulkInvoiceRunDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get<BulkInvoiceRunDetail>(
                bulkInvoiceRuns.showUrl(runUuid),
            );
            setRun(data);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, [runUuid]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    /*
     * A QUEUED RUN FINISHES ASYNCHRONOUSLY. The poll runs while the status is one of the two
     * NON-terminal values and stops at the two terminal ones — stated over the in-flight set rather
     * than as `!== 'completed'`, which would spin forever beside a failed run.
     *
     * Silent: it does not raise `loading`, so the report does not flash a spinner every three seconds
     * while the operator is reading it.
     */
    const inFlight = run !== null && RUN_IN_FLIGHT.includes(run.status);

    useEffect(() => {
        if (!inFlight) {
            return;
        }

        const id = setInterval(() => {
            void (async () => {
                try {
                    const { data } = await axios.get<BulkInvoiceRunDetail>(
                        bulkInvoiceRuns.showUrl(runUuid),
                    );
                    setRun(data);
                } catch {
                    /* keep polling — a transient 500 must not strand the screen */
                }
            })();
        }, 3000);

        return () => clearInterval(id);
    }, [inFlight, runUuid]);

    return (
        <>
            <Head title="Bulk invoice run" />

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
                                        {run === null
                                            ? 'Bulk invoice run'
                                            : `${run.class_level_label ?? '—'} · ${run.term_label ?? '—'}`}
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        {run === null
                                            ? 'One run, and what became of every billable student while it ran.'
                                            : `Started by ${run.started_by_name ?? '—'}${run.fee_schedule === null ? '' : ` · priced from ${run.fee_schedule.label}`}`}
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                {run !== null && (
                                    <span className="flex items-center gap-2 text-slate-400">
                                        <StatusIcon status={run.status} />
                                        <StatusPill
                                            tone={STATUS_TONE[run.status]}
                                            label={STATUS_LABEL[run.status]}
                                        />
                                    </span>
                                )}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void load()}
                                    disabled={loading}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <RefreshCw
                                        className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`}
                                    />
                                    Refresh
                                </Button>
                                <a
                                    href="/finance/bulk-invoice-runs"
                                    className="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    <ArrowLeft className="h-3.5 w-3.5" />
                                    All runs
                                </a>
                            </div>
                        </div>
                    </div>

                    {loading && run === null ? (
                        <div className="rounded-xl bg-white py-16 text-center shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                            <Spinner className="mx-auto" />
                        </div>
                    ) : error && run === null ? (
                        /* A FAILED FETCH IS NOT AN EMPTY RUN. It says nothing about what the run did
                           — §26's instance one, which this project has shipped three times. */
                        <div className="rounded-xl bg-white py-16 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                            <div className="flex flex-col items-center gap-3 text-center">
                                <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                    <AlertCircle className="h-6 w-6" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Could not load this run
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        This says nothing about what the run
                                        did. Nothing here has been changed.
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void load()}
                                    className="rounded-lg"
                                >
                                    <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                    Retry
                                </Button>
                            </div>
                        </div>
                    ) : run === null ? null : (
                        <>
                            {/* ── The failure, stated first and in full ─────────── */}
                            {run.failure_reason !== null && (
                                <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/40 dark:bg-red-950/20">
                                    <XCircle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                                    <div>
                                        <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                            This run failed
                                        </p>
                                        {/* THE SERVER'S OWN SENTENCE, verbatim. It is a PER-RUN fact;
                                            a student who could not be billed carries their reason on
                                            their own row and never reaches this column. */}
                                        <p className="mt-1 text-sm text-slate-700 dark:text-slate-200">
                                            {run.failure_reason}
                                        </p>
                                        <p className="mt-2 text-xs text-slate-500">
                                            A failed run does not promise that
                                            nothing was billed — read the
                                            buckets below. Re-running is safe:
                                            anything already billed comes back
                                            as already billed.
                                        </p>
                                    </div>
                                </div>
                            )}

                            {/* ── The run's own figures, or an honest absence ───── */}
                            {run.has_figures ? (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <Figure
                                            label="Cohort"
                                            value={run.counts.cohort}
                                            note="Billable enrollments at this run's coordinates."
                                        />
                                        <Figure
                                            label="Billed"
                                            value={run.counts.billed}
                                            note="Term bills this run raised."
                                        />
                                        <Figure
                                            label="Already billed"
                                            value={run.counts.already_billed}
                                            note="Had a term bill already. Not an error."
                                        />
                                        <Figure
                                            label="Failed"
                                            value={run.counts.failed}
                                            note="Could not be billed. Each row carries its reason."
                                        />
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <Figure
                                            label="Sponsored"
                                            value={run.counts.sponsored}
                                            note="Excluded on purpose. Billed by hand, not by this run."
                                        />
                                        <Figure
                                            label="Could not be placed"
                                            value={run.counts.unplaceable}
                                            note="No term or no class level, so no run can bill them."
                                        />
                                        <Figure
                                            label="Billable in this school"
                                            value={run.counts.billable}
                                            note="Counted school-wide, at the moment this run executed."
                                        />
                                        {/* SCOPE, NEVER A MISS — and its caveat travels with it, on
                                            the screen rather than only in a docblock. */}
                                        <Figure
                                            label="Priced at other coordinates"
                                            value={
                                                run.counts.outside_coordinates
                                            }
                                            note="Billable students this run did not name, because they belong to other terms or class levels. NOT a count of students missed — on a single-level run this is most of the school, every time. It is an indicator and not a headcount: student-less episodes collapse into one, so it can under-report."
                                        />
                                    </div>

                                    {run.reconciliation !== null &&
                                        (!run.reconciliation.cohort_balances ||
                                            !run.reconciliation
                                                .unplaceable_balances) && (
                                            <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
                                                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                                                <div className="text-sm">
                                                    <p className="font-bold text-slate-800 dark:text-slate-100">
                                                        This run&rsquo;s figures
                                                        do not add up
                                                    </p>
                                                    {/* THE RUN'S OWN ALARM. Each equality has a
                                                        persisted-rows side and a walked-list side, so
                                                        a mismatch means a student the run saw has no
                                                        row recording what happened to them. There is
                                                        deliberately no flag column — the equality IS
                                                        the signal, and a screen that renders the eight
                                                        numbers without it renders the alarm as
                                                        decoration. */}
                                                    {!run.reconciliation
                                                        .cohort_balances && (
                                                        <p className="mt-1 text-slate-700 dark:text-slate-200">
                                                            Billed + already
                                                            billed + failed does
                                                            not equal the
                                                            cohort. A student
                                                            the run walked has
                                                            no row saying what
                                                            became of them.
                                                        </p>
                                                    )}
                                                    {!run.reconciliation
                                                        .unplaceable_balances && (
                                                        <p className="mt-1 text-slate-700 dark:text-slate-200">
                                                            The unplaceable rows
                                                            do not match the
                                                            unplaceable list the
                                                            run walked. At least
                                                            one could not be
                                                            recorded.
                                                        </p>
                                                    )}
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Nothing was
                                                        double-billed — this is
                                                        a record that is
                                                        incomplete, not money
                                                        that moved twice. The
                                                        server log for this run
                                                        names the enrollment.
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                </>
                            ) : (
                                /* NO FIGURES, AND THE REASON SAID PLAINLY. Never eight zeroes, and
                                   never eight dashes with no explanation of what is missing. */
                                <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-slate-800 dark:bg-card">
                                    <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                                    <div className="text-sm">
                                        <p className="font-bold text-slate-800 dark:text-slate-100">
                                            This run has not reported any
                                            figures
                                        </p>
                                        <p className="mt-1 text-slate-600 dark:text-slate-300">
                                            {run.status === 'pending'
                                                ? 'It is queued and no worker has picked it up yet. Nothing has been billed.'
                                                : run.status === 'running'
                                                  ? 'It is working through the cohort now. Its counts are written when it reaches the end, so there are none yet — the rows below are what it has recorded so far.'
                                                  : 'It stopped before it could count anything. Its counts were never written, so there are none to show — not zeroes.'}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {/* ── The four buckets ─────────────────────────────── */}
                            {BUCKET_ORDER.map((outcome) => (
                                <Bucket
                                    key={outcome}
                                    outcome={outcome}
                                    bucket={run.buckets[outcome]}
                                    inFlight={inFlight}
                                />
                            ))}

                            {/*
                             * WHAT THIS SCREEN CANNOT REACH, said on the screen rather than only in a
                             * report. There is no invoice index in this application yet — that is U7
                             * — so a billed row links to the student's statement, which lists their
                             * invoices, and not to the invoice this run raised.
                             */}
                            <p className="px-1 text-[11px] text-slate-400">
                                A billed row opens that student&rsquo;s
                                statement, which lists every invoice they carry.
                                There is no invoice index yet, so four things
                                are out of reach from here: the individual
                                invoice this run raised (the statement shows all
                                of them and you pick the term bill out by eye);
                                the invoices of this run as a SET, with no
                                filter, export or total; whether any of them has
                                since been paid, part-paid, voided or credited,
                                which only the statement answers, one student at
                                a time; and, for a failed row, an invoice at all
                                — there isn&rsquo;t one, and the reason is on
                                the row.
                            </p>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

/**
 * One of the run's figures.
 *
 * IT TAKES `number | null` AND RENDERS THE NULL AS AN EM DASH. Every caller here is already inside a
 * `has_figures` branch, so a null arriving is the server and the flag disagreeing — which must show
 * as "no value", never as a zero. Belt and braces on the one defect class this screen cannot afford.
 */
function Figure({
    label,
    value,
    note,
}: {
    label: string;
    value: number | null;
    note: string;
}) {
    return (
        <div className="rounded-2xl border border-white bg-white px-5 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
            <p className="text-xs font-medium text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {value === null ? '—' : String(value)}
            </p>
            <p className="mt-1 text-[11px] text-slate-400">{note}</p>
        </div>
    );
}

/**
 * One outcome's rows.
 *
 * THE TOTAL HERE IS A COUNT OF ROWS, NOT ONE OF THE RUN'S FIGURES, and the label says so while a run
 * is in flight. The two are different facts: the run's `counts` are what it reported when it
 * finished, and this is what is in the table right now.
 *
 * EMPTY MEANS TWO DIFFERENT THINGS AND THEY GET DIFFERENT SENTENCES. On a finished run an empty
 * bucket is a result; on a run still going it is "nothing yet". Collapsing them is the §13 state
 * confusion this project has shipped five times.
 */
function Bucket({
    outcome,
    bucket,
    inFlight,
}: {
    outcome: RunOutcome;
    bucket: RunBucket | undefined;
    inFlight: boolean;
}) {
    const copy = BUCKET_COPY[outcome];

    // Defensive: a payload missing a bucket is a server that changed shape, and rendering it as
    // "nobody landed here" would be a sentence made from nothing.
    if (bucket === undefined) {
        return null;
    }

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                <StatusPill tone={copy.tone} label={copy.title} />
                <span className="text-sm font-bold text-slate-800 tabular-nums dark:text-slate-100">
                    {String(bucket.total)}
                </span>
                <span className="text-[11px] text-slate-400">
                    {inFlight ? 'rows recorded so far' : 'rows'}
                </span>
                {bucket.truncated && (
                    <span className="text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                        Showing the first 200 — this list is cut.
                    </span>
                )}
            </div>

            <p className="px-5 pt-3 text-xs text-slate-500">{copy.blurb}</p>

            <div className="custom-scrollbar mt-2 overflow-x-auto">
                <table className="w-full text-xs">
                    <thead>
                        <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                            <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                Student
                            </th>
                            <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                Admission no.
                            </th>
                            <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                Reason
                            </th>
                            <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                Statement
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {bucket.rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-4 py-8 text-center text-xs text-slate-500"
                                >
                                    {inFlight
                                        ? 'Nothing recorded here yet — the run has not finished.'
                                        : copy.empty}
                                </td>
                            </tr>
                        ) : (
                            bucket.rows.map((row) => (
                                <tr
                                    key={row.uuid}
                                    className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                    data-testid="bulk-invoice-run-outcome-row"
                                >
                                    <td className="px-4 py-2.5">
                                        {row.student === null ? (
                                            /* THE ROW STILL RENDERS. A student that cannot be named
                                               is either an episode with no student at all — legal,
                                               and one of the shapes the reconciliation exists to
                                               surface — or an id that no longer resolves. Dropping
                                               the row would be the silent omission this whole
                                               feature was built to end. */
                                            <span className="inline-flex items-center gap-1.5 text-slate-500">
                                                <UserX className="h-3.5 w-3.5" />
                                                No student on this enrollment
                                            </span>
                                        ) : (
                                            <span className="font-semibold text-slate-700 dark:text-slate-200">
                                                {row.student.name}
                                            </span>
                                        )}
                                        <p className="font-mono text-[10px] text-slate-400">
                                            {row.enrollment_uuid}
                                        </p>
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-600 tabular-nums dark:text-slate-300">
                                        {row.student?.admission_number ?? '—'}
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                        {row.reason ?? '—'}
                                    </td>
                                    <td className="px-4 py-2.5 text-right">
                                        {row.student === null ? (
                                            '—'
                                        ) : (
                                            <a
                                                href={statementRoute.url(
                                                    row.student.uuid,
                                                )}
                                                className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                            >
                                                Statement
                                                <ExternalLink className="h-3 w-3" />
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

BulkInvoiceRunShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Bulk invoice runs', href: '/finance/bulk-invoice-runs' },
        { title: 'Run', href: '#' },
    ],
};
