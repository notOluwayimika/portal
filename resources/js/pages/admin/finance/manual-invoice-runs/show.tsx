import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
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
import { formatNaira } from '@/lib/format';
import { manualInvoiceRuns as runsPage } from '@/routes/admin/finance';
import {
    MANUAL_RUN_IN_FLIGHT,
    manualInvoiceRuns,
} from '@/services/manual-invoice-runs';
import type {
    ManualInvoiceRunDetail,
    ManualRunOutcome,
    ManualRunStatus,
} from '@/services/manual-invoice-runs';

/**
 * ONE MANUAL INVOICE RUN'S REPORT — the ONLY oversight this feature has.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS PAGE CARRIES MORE WEIGHT THAN THE BULK RUN'S REPORT BESIDE IT
 *
 * Brookstone ruled on 30 August 2026 that bulk manual invoicing issues DIRECTLY — no maker-checker,
 * no second signature. There is therefore no second human anywhere between a bursar's tick list and
 * ninety real charges, and nothing reverses one except a void request and a second person's approval
 * PER INVOICE. So this page is not a status screen: it is the whole of the after-the-fact control,
 * and submit lands here rather than on a toast for exactly that reason.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `target_count` IS THE BURSAR'S OWN NUMBER, AND IT IS THE FIRST THING ON THE PAGE
 *
 * It is counted server-side from `finance_manual_invoice_run_targets` — the list that was submitted
 * — and NOT from the run's `target_count` column, which is the job's own tally written at the end of
 * its walk. It is the one figure on this page a bursar can check against the list they ticked, and
 * it exists from the moment the run is created rather than from the moment it finishes.
 *
 * `billed + failed + unplaceable` IS SHOWN AGAINST IT, and `reconciliation.balances` is the SERVER's
 * answer to whether they agree — never re-derived here. Two independent sources is the only reason
 * asserting the equality is worth anything; a client adding up the same three numbers it was handed
 * and comparing them to a fourth it was also handed would be a third opinion, and the one on screen.
 *
 * `balances` IS NULL WHILE THE RUN IS NOT TERMINAL and renders as "not yet answerable", never as
 * `false`. Mid-run a shortfall is the normal state — rows are written as the list is walked — so a
 * red alarm on every healthy run teaches a bursar to ignore the one signal standing where a second
 * signature would otherwise be.
 *
 * `claimed` IS SHOWN AND IS NEVER A TERM OF THE EQUALITY. A claimed row is a student the run cannot
 * account for; it is the diagnosis of a shortfall rather than a part of one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE UNPLACEABLE ARE NAMED, AND THEY ARE RENDERED FIRST
 *
 * Admission number, not a count — brief §2 requires the unresolved be reported rather than dropped,
 * and "six of your ninety could not be placed" is not something a bursar can act on. Six admission
 * numbers is. They are the list somebody has to do something about, so they lead.
 *
 * A ROW WHOSE STUDENT CANNOT BE DISPLAYED IS STILL RENDERED, carrying its row uuid. Dropping it
 * would be the silent omission this whole report exists to make visible.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * TRUNCATION IS ANNOUNCED, BECAUSE A CUT LIST THAT LOOKS COMPLETE IS A FALSE ALL-CLEAR
 *
 * Each bucket is capped server-side at 200 rows with a `truncated` flag beside it, and on the
 * UNPLACEABLE bucket that is the one place this report can still hide a name — from row 201. The
 * flag is rendered in words at the foot of the list rather than as a subtle ellipsis, and the ticket
 * is open:
 * docs/handoff/tickets/the-manual-run-report-truncates-at-200-and-the-unplaceable-can-hide.md
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT EVERYONE WAS CHARGED IS ON THE PAGE, with the destination account per line — one set of lines
 * for the whole run, so this is a short table and it is the only record of what the operator typed.
 * Money is rendered through `formatNaira` and nothing here computes any.
 */

const STATUS_TONE: Record<ManualRunStatus, StatusTone> = {
    pending: 'amber',
    running: 'blue',
    completed: 'emerald',
    failed: 'red',
};

const STATUS_LABEL: Record<ManualRunStatus, string> = {
    pending: 'Queued',
    running: 'Running',
    completed: 'Completed',
    failed: 'Failed',
};

/** Order is meaning: the two lists somebody must act on come first. */
const BUCKET_ORDER: ManualRunOutcome[] = [
    'unplaceable',
    'failed',
    'claimed',
    'billed',
];

const BUCKET_COPY: Record<
    ManualRunOutcome,
    { title: string; blurb: string; empty: string; tone: StatusTone }
> = {
    unplaceable: {
        title: 'Could not be placed',
        // IT NAMES WHERE TO GO AND DOES NOT LINK THERE, on the bulk report's reasoning: the
        // per-student academic record lives behind `student_curriculum.view`, an ability a finance
        // seat does not hold, and it is a read-only view besides — so it is not where a term or a
        // class level gets set either. Offering a bursar a link that 403s is worse than a sentence.
        blurb: 'You selected these students and they have no current enrolment to bill, so no invoice was raised for them. They are not billed and nothing about them is pending — someone with academic access has to give each of them a current enrolment, and only then can they be billed. Check these admission numbers against your own list.',
        empty: 'Every student you selected resolved to a current enrolment.',
        tone: 'amber',
    },
    failed: {
        title: 'Failed',
        blurb: 'The run tried to bill these and could not. Each carries its own reason; the run itself carried on with the rest of your list.',
        empty: 'Nothing failed.',
        tone: 'red',
    },
    claimed: {
        // NOT A TERM OF THE EQUALITY, and the wording has to say what it is rather than imply an
        // outcome. A claimed row is one the run wrote before billing and never came back to — so
        // whether a charge exists for that student is precisely what this report cannot tell you.
        title: 'Unaccounted for',
        blurb: 'The run claimed these students and never recorded what became of them — a worker that stopped mid-list leaves rows in this state. This is the one bucket that does not say whether an invoice exists. Read the student’s statement before doing anything about it, and do NOT simply run the list again: a second run bills everyone on it a second time.',
        empty: 'Nothing is unaccounted for.',
        tone: 'violet',
    },
    billed: {
        title: 'Billed',
        blurb: 'This run raised one supplementary invoice for each of these students, carrying the lines below.',
        empty: 'This run billed nobody.',
        tone: 'emerald',
    },
};

function StatusIcon({ status }: { status: ManualRunStatus }) {
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

export default function ManualInvoiceRunShow({ runUuid }: { runUuid: string }) {
    const [run, setRun] = useState<ManualInvoiceRunDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get<ManualInvoiceRunDetail>(
                manualInvoiceRuns.showUrl(runUuid),
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
     * than as `!== 'completed'`, which would spin for ever beside a failed run.
     *
     * Silent: it does not raise `loading`, so the report does not flash a spinner every three
     * seconds while the operator is reading it.
     */
    const inFlight = run !== null && MANUAL_RUN_IN_FLIGHT.includes(run.status);

    useEffect(() => {
        if (!inFlight) {
            return;
        }

        const id = setInterval(() => {
            void (async () => {
                try {
                    const { data } = await axios.get<ManualInvoiceRunDetail>(
                        manualInvoiceRuns.showUrl(runUuid),
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
            <Head title="Manual invoice run" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 text-indigo-600 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    {run === null ? (
                                        <Clock className="h-5 w-5" />
                                    ) : (
                                        <StatusIcon status={run.status} />
                                    )}
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Manual invoice run
                                    </h1>
                                    <p className="font-mono text-xs text-slate-500">
                                        {runUuid}
                                    </p>
                                </div>
                                {run !== null && (
                                    <StatusPill
                                        tone={STATUS_TONE[run.status]}
                                        label={STATUS_LABEL[run.status]}
                                    />
                                )}
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Link href={runsPage.url()}>
                                    <Button size="sm" variant="outline">
                                        <ArrowLeft className="mr-1.5 h-4 w-4" />
                                        New run
                                    </Button>
                                </Link>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void load()}
                                    disabled={loading}
                                >
                                    <RefreshCw className="mr-1.5 h-4 w-4" />
                                    Refresh
                                </Button>
                            </div>
                        </div>
                    </div>

                    {loading && run === null && (
                        <div className="flex items-center justify-center rounded-xl bg-white py-16 dark:bg-card">
                            <Spinner className="h-6 w-6" />
                        </div>
                    )}

                    {error && run === null && (
                        <div className="rounded-xl border border-red-100 bg-white px-6 py-10 text-center dark:border-red-900/30 dark:bg-card">
                            <p className="text-sm font-semibold text-red-600">
                                Could not load this run.
                            </p>
                            <p className="mt-1 text-xs text-slate-500">
                                Nothing here is a statement about what the run
                                did — only that this page could not read it.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="mt-4"
                                onClick={() => void load()}
                            >
                                Retry
                            </Button>
                        </div>
                    )}

                    {run !== null && (
                        <>
                            {/* ── The reconciliation ─────────────────────── */}
                            <div className="rounded-xl bg-white p-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                                <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                    What became of your selection
                                </h2>
                                {/* THE BURSAR'S OWN NUMBER, said in words before any breakdown. It
                                    is the only figure on this page they can check against their own
                                    tick list. */}
                                <p className="mt-1 text-xs text-slate-500">
                                    You selected{' '}
                                    <span className="font-bold text-slate-800 dark:text-slate-100">
                                        {String(run.target_count)}
                                    </span>{' '}
                                    student(s). This run has accounted for{' '}
                                    <span className="font-bold text-slate-800 dark:text-slate-100">
                                        {String(
                                            run.reconciliation.accounted_for,
                                        )}
                                    </span>{' '}
                                    of them.
                                </p>

                                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                    <Figure
                                        label="Selected"
                                        value={String(run.target_count)}
                                        note="Counted from the list you submitted"
                                    />
                                    <Figure
                                        label="Billed"
                                        value={String(run.counts.billed)}
                                        note="One supplementary invoice each"
                                    />
                                    <Figure
                                        label="Failed"
                                        value={String(run.counts.failed)}
                                        note="Each row carries its own reason"
                                    />
                                    <Figure
                                        label="Unplaceable"
                                        value={String(run.counts.unplaceable)}
                                        note="No current enrolment to bill"
                                    />
                                    <Figure
                                        label="Unaccounted for"
                                        value={String(run.counts.claimed)}
                                        note="Never a term of the check below"
                                    />
                                </div>

                                {/* THE SERVER'S ANSWER, RENDERED — not this screen's. Three states,
                                    and the null one is a sentence rather than a colour. */}
                                <div className="mt-4">
                                    {run.reconciliation.balances === null && (
                                        <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/40 dark:text-slate-300">
                                            This run is still going, so whether
                                            every student is accounted for
                                            cannot be answered yet. A shortfall
                                            here is normal until it finishes.
                                        </p>
                                    )}
                                    {run.reconciliation.balances === true && (
                                        <p className="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-800 dark:bg-emerald-950/20 dark:text-emerald-300">
                                            Every student you selected is
                                            accounted for: billed, failed or
                                            unplaceable.
                                        </p>
                                    )}
                                    {run.reconciliation.balances === false && (
                                        <p className="rounded-lg bg-red-50 p-3 text-xs font-semibold text-red-800 dark:bg-red-950/20 dark:text-red-300">
                                            This run has finished and{' '}
                                            {String(
                                                run.target_count -
                                                    run.reconciliation
                                                        .accounted_for,
                                            )}{' '}
                                            of the students you selected are not
                                            accounted for. Do not re-run the
                                            same list — a second run bills
                                            everyone on it again. Read the
                                            unaccounted-for list below, then the
                                            statements of the students in it.
                                        </p>
                                    )}
                                    {run.reconciliation
                                        .recorded_matches_rows === false && (
                                        <p className="mt-2 rounded-lg bg-red-50 p-3 text-xs font-semibold text-red-800 dark:bg-red-950/20 dark:text-red-300">
                                            The run’s own counters disagree with
                                            the rows it wrote. Treat every
                                            figure on this page as unproven and
                                            raise it before acting on them.
                                        </p>
                                    )}
                                </div>
                            </div>

                            {/* ── What everyone was charged ──────────────── */}
                            <div className="rounded-xl bg-white p-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                                <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                    What every student on the list was charged
                                </h2>
                                <p className="mt-1 text-xs text-slate-500">
                                    One set of lines for the whole run — every
                                    billed student carries exactly these.
                                </p>
                                <div className="custom-scrollbar mt-3 overflow-x-auto">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b border-slate-100 bg-slate-50/50 text-left dark:border-slate-800 dark:bg-slate-900/30">
                                                <th className="px-3 py-2 font-semibold text-slate-500">
                                                    Description
                                                </th>
                                                <th className="px-3 py-2 font-semibold text-slate-500">
                                                    Amount
                                                </th>
                                                <th className="px-3 py-2 font-semibold text-slate-500">
                                                    Paid into
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {run.lines.map((line) => (
                                                <tr
                                                    key={line.sort_order}
                                                    className="border-b border-slate-50 dark:border-slate-800/60"
                                                >
                                                    <td className="px-3 py-2 text-slate-700 dark:text-slate-200">
                                                        {line.description}
                                                    </td>
                                                    <td className="px-3 py-2 font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                        {formatNaira(
                                                            line.amount,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 text-slate-600 dark:text-slate-300">
                                                        {line.bank_account
                                                            ?.label ?? '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* ── The four buckets ───────────────────────── */}
                            {BUCKET_ORDER.map((outcome) => {
                                const bucket = run.buckets[outcome];
                                const copy = BUCKET_COPY[outcome];

                                return (
                                    <div
                                        key={outcome}
                                        className="rounded-xl bg-white p-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card"
                                    >
                                        <div className="flex flex-wrap items-center gap-3">
                                            <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                                {copy.title}
                                            </h2>
                                            <StatusPill
                                                tone={copy.tone}
                                                label={`${String(bucket?.total ?? 0)}`}
                                            />
                                        </div>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {copy.blurb}
                                        </p>

                                        {(bucket?.rows.length ?? 0) === 0 ? (
                                            <p className="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/40">
                                                {inFlight
                                                    ? 'Nothing here yet — this run is still going.'
                                                    : copy.empty}
                                            </p>
                                        ) : (
                                            <div className="custom-scrollbar mt-3 overflow-x-auto">
                                                <table className="w-full text-xs">
                                                    <thead>
                                                        <tr className="border-b border-slate-100 bg-slate-50/50 text-left dark:border-slate-800 dark:bg-slate-900/30">
                                                            <th className="px-3 py-2 font-semibold text-slate-500">
                                                                Admission no.
                                                            </th>
                                                            <th className="px-3 py-2 font-semibold text-slate-500">
                                                                Student
                                                            </th>
                                                            <th className="px-3 py-2 font-semibold text-slate-500">
                                                                Reason
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {bucket?.rows.map(
                                                            (row) => (
                                                                <tr
                                                                    key={
                                                                        row.uuid
                                                                    }
                                                                    className="border-b border-slate-50 dark:border-slate-800/60"
                                                                >
                                                                    <td className="px-3 py-2 font-mono text-slate-700 dark:text-slate-200">
                                                                        {row
                                                                            .student
                                                                            ?.admission_number ??
                                                                            '—'}
                                                                    </td>
                                                                    {/* A row the port cannot display is
                                                                        RENDERED, not dropped — it carries
                                                                        its own uuid so it can still be
                                                                        chased. */}
                                                                    <td className="px-3 py-2 text-slate-700 dark:text-slate-200">
                                                                        {row
                                                                            .student
                                                                            ?.name ?? (
                                                                            <span className="text-slate-400">
                                                                                Not
                                                                                in
                                                                                the
                                                                                live
                                                                                directory
                                                                                ·
                                                                                row{' '}
                                                                                {
                                                                                    row.uuid
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </td>
                                                                    <td className="px-3 py-2 text-slate-600 dark:text-slate-300">
                                                                        {row.reason ??
                                                                            '—'}
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>

                                                {/* ANNOUNCED, NEVER SILENT. A cut list that looks
                                                    complete is a false all-clear, and on the
                                                    unplaceable bucket it is where a name can hide. */}
                                                {bucket?.truncated === true && (
                                                    <p className="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-xs font-semibold text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                                        <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                        <span>
                                                            This list is cut off
                                                            at 200 rows. There
                                                            are{' '}
                                                            {String(
                                                                bucket.total,
                                                            )}{' '}
                                                            in total, so{' '}
                                                            {String(
                                                                bucket.total -
                                                                    bucket.rows
                                                                        .length,
                                                            )}{' '}
                                                            of them are NOT
                                                            shown here and this
                                                            page cannot name
                                                            them.
                                                        </span>
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}

                            {run.failure_reason != null &&
                                run.failure_reason !== '' && (
                                    <div className="flex items-start gap-2 rounded-xl bg-red-50 p-4 text-xs font-semibold text-red-800 dark:bg-red-950/20 dark:text-red-300">
                                        <UserX className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{run.failure_reason}</span>
                                    </div>
                                )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

/** One figure on the reconciliation strip. A plain count — nothing on this screen is money. */
function Figure({
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

ManualInvoiceRunShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        {
            title: 'Bulk manual invoicing',
            href: '/finance/manual-invoice-runs',
        },
    ],
};
