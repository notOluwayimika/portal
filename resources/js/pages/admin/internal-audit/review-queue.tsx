import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    Check,
    FileCheck2,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { formatNaira } from '@/lib/format';
import {
    batchView,
    queueView,
    selectAllOnPage,
} from '@/lib/internal-audit-queue';
import type {
    BatchResponse,
    BatchView,
    PendingResponse,
} from '@/lib/internal-audit-queue';

/**
 * INTERNAL AUDIT'S REVIEW QUEUE.
 *
 * Every decision this screen makes lives in `@/lib/internal-audit-queue` and is asserted by
 * `internal-audit-queue.test.ts` under vitest — the only bin/quality step that executes application
 * JS. This file maps those decisions to copy and colour, the same split `parent/finance.tsx` uses
 * over `parent-finance-view.ts`.
 *
 * ── THERE IS NO SEARCH BOX, AND THAT IS A DELIBERATE DEPARTURE ──────────────────────────────────
 *
 * Every sibling finance table opens with `<TableToolbar>`, which is a client-side search over the
 * LOADED PAGE. That component cannot be used without it — the search input is unconditional JSX and
 * `value`/`onChange` are required props — so this screen uses the table card without the toolbar
 * rather than adopting a filter it must not have.
 *
 * A client-side search filters only the loaded page. With a multi-page pending queue, an auditor
 * searching a student, seeing nothing, and concluding that student has no unsigned bill would be
 * wrong — and the screen would have said so confidently. A search here belongs server-side against
 * the whole pending set, and that is a different change.
 *
 * ── THE COUNT IS THE INSTRUMENT ────────────────────────────────────────────────────────────────
 *
 * The stat card reads `pagination.total` — everything awaiting review in this school — and is NOT
 * wired to any client-side filtering. This control's one failure mode that emits no audit row at
 * any severity is a bill NOBODY REVIEWS; a number that grows is the only thing that will ever show
 * it, and a count describing a filtered subset would read as the whole. The endpoint's own docblock
 * carries the same warning about a filter added to that query.
 *
 * THE TWO NUMBERS ARE LABELLED SO THEY CANNOT READ AS CONTRADICTING EACH OTHER: "Awaiting
 * sign-off" is the queue, "N on this page" is what is rendered below. Two bare numbers on one
 * screen is a bug report.
 *
 * ── AN EMPTY QUEUE AND A BROKEN FEED DO NOT RENDER ALIKE ───────────────────────────────────────
 *
 * Nothing pending is reassuring and true; a failed request means the queue is UNKNOWN. Rendered the
 * same, this screen says "all clear" at the moment the truth is "I could not ask" — and says it
 * most convincingly when the network is worst. `queueView` makes them separate cases; here they get
 * the house compositions that already distinguish them elsewhere: an emerald circle with a Check,
 * versus a red circle with an AlertCircle and a Retry.
 */
export default function InternalAuditReviewQueue() {
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [response, setResponse] = useState<PendingResponse | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<BatchView | null>(null);

    /**
     * A PROMISE CHAIN, NOT async/await, AND THE REASON IS THE `catch`.
     *
     * `react-hooks/set-state-in-effect` fired on the effect below, and it took two fixes because
     * there were two causes. The first was the obvious one: this opened with `setLoading(true)`,
     * which runs SYNCHRONOUSLY in the effect body. Gone — the initial loading state is declared in
     * `useState(true)`, so the first render is already "loading".
     *
     * The second survived that fix. With `try { await … } catch { setState() }`, THE CATCH CAN RUN
     * IN THE SAME TICK: if the awaited expression throws before it yields a promise, control
     * arrives synchronously. Measured with probes — a function differing only in having a
     * state-setting catch reproduced the error. A promise continuation is always scheduled, so
     * every setState here is unreachable synchronously by construction rather than by inspection.
     */
    const load = useCallback(
        () =>
            axios
                .get<PendingResponse>('/api/internal-audit/invoices/pending')
                .then(({ data }) => {
                    setResponse(data);
                    setFailed(false);
                })
                .catch(() => {
                    // The response is CLEARED, not left stale: a failure that kept the previous
                    // page would show rows the server has not confirmed, under a banner saying the
                    // feed is down.
                    setResponse(null);
                    setFailed(true);
                })
                .finally(() => setLoading(false)),
        [],
    );

    useEffect(() => {
        void load();
    }, [load]);

    const view = queueView({ loading, failed, response });

    const refresh = () => {
        // An EVENT HANDLER, not an effect — here the transition back to "loading" is real, and a
        // synchronous setState is correct.
        setLoading(true);
        setFailed(false);
        void load();
    };

    const approve = () => {
        if (selected.length === 0) {
            return;
        }

        setBusy(true);

        return axios
            .post<BatchResponse>('/api/internal-audit/invoices/approve', {
                uuids: selected,
            })
            .then(({ data }) => {
                const outcome = batchView(data);

                setResult(outcome);
                setSelected([]);

                // THE TOAST NEVER SAYS "done" ON A PARTIAL BATCH. The endpoint answers 207 with an
                // outcome each precisely so a partial release cannot read as complete, and a
                // success toast over a panel listing refusals is the same lie in a louder place.
                if (outcome.allApproved) {
                    toast.success(
                        `Released ${outcome.approvedCount} bills to their payers.`,
                    );
                } else {
                    toast.warning(
                        `${outcome.approvedCount} released, ${outcome.refusedCount} not released — see the detail below.`,
                    );
                }

                return load();
            })
            .catch(() => {
                setResult(null);
                setFailed(true);
                toast.error(
                    'The release could not be sent. Nothing was changed.',
                );
            })
            .finally(() => setBusy(false));
    };

    const rows = view.kind === 'rows' ? view.rows : [];
    const allOnPageSelected =
        rows.length > 0 && rows.every((r) => selected.includes(r.uuid));

    return (
        <>
            <Head title="Invoice review queue" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero ─────────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <ShieldCheck className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Invoice review queue
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Bills raised and not yet released to
                                        their payer. A bill already counts
                                        against the student&apos;s balance;
                                        releasing it makes it visible to the
                                        parent.
                                    </p>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={refresh}
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

                    {/* ── The count. NOT wired to any filter — see the docblock. ── */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FinanceStatCard
                            icon={FileCheck2}
                            label="Awaiting sign-off"
                            value={
                                view.kind === 'rows'
                                    ? String(view.pendingTotal)
                                    : '0'
                            }
                            subText="Every unreleased bill in this school"
                            tone="indigo"
                            loading={view.kind === 'loading'}
                        />
                        <FinanceStatCard
                            icon={Check}
                            label="On this page"
                            value={String(rows.length)}
                            subText="Rendered below, and the most one release can cover"
                            tone="emerald"
                            loading={view.kind === 'loading'}
                        />
                    </div>

                    {/* ── Table card. No TableToolbar: it cannot be used without its
                        client-side search, and this screen must not have one. ──── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="w-8 px-3 py-2">
                                            {/* Selects THIS PAGE, and its label says so. There is no
                                                cross-page select-all by design: the batch cap is 100
                                                and a page is at most 100, so a control meaning "all
                                                900" builds a request the server refuses. */}
                                            <Checkbox
                                                aria-label="Select all invoices on this page"
                                                checked={allOnPageSelected}
                                                onCheckedChange={(checked) =>
                                                    setSelected(
                                                        checked === true
                                                            ? selectAllOnPage(
                                                                  view,
                                                              )
                                                            : [],
                                                    )
                                                }
                                            />
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Invoice
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Kind
                                        </th>
                                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Total
                                        </th>
                                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Raised
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {view.kind === 'loading' ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : view.kind === 'failed' ? (
                                        <tr>
                                            <td colSpan={5} className="py-12">
                                                {/* THE ALARM. Red, an AlertCircle, and a retry —
                                                    deliberately nothing like the emerald "all
                                                    reviewed" below it. */}
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Could not load the queue
                                                    </p>
                                                    <p className="max-w-sm text-xs text-slate-500">
                                                        This is{' '}
                                                        <strong>not</strong> an
                                                        empty queue — the number
                                                        awaiting review is
                                                        unknown.
                                                    </p>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={refresh}
                                                        className="rounded-lg"
                                                    >
                                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                                        Retry
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : view.kind === 'empty' ? (
                                        <tr>
                                            <td colSpan={5} className="py-12">
                                                {/* THE REASSURANCE. Emerald, a Check, no retry. */}
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-500 dark:bg-emerald-900/20">
                                                        <Check className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Every bill has been
                                                        reviewed
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        Newly raised bills
                                                        appear here for Internal
                                                        Audit to release.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((row) => (
                                            <tr
                                                key={row.uuid}
                                                className="hover:bg-slate-50/50 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-3 py-2.5">
                                                    <Checkbox
                                                        aria-label={`Select invoice ${row.number}`}
                                                        checked={selected.includes(
                                                            row.uuid,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setSelected(
                                                                (prev) =>
                                                                    checked ===
                                                                    true
                                                                        ? [
                                                                              ...prev,
                                                                              row.uuid,
                                                                          ]
                                                                        : prev.filter(
                                                                              (
                                                                                  u,
                                                                              ) =>
                                                                                  u !==
                                                                                  row.uuid,
                                                                          ),
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-2.5 font-mono text-slate-800 dark:text-slate-100">
                                                    {row.number}
                                                </td>
                                                <td className="px-3 py-2.5 text-slate-500">
                                                    {row.kind}
                                                </td>
                                                <td className="px-3 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                    {formatNaira(row.total)}
                                                </td>
                                                <td className="px-3 py-2.5 text-right text-slate-500 tabular-nums">
                                                    {new Date(
                                                        row.issued_at,
                                                    ).toLocaleDateString()}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* ── The batch result, PER INVOICE. ───────────────────────── */}
                    {result !== null && (
                        <div
                            className="overflow-hidden rounded-xl border-none bg-white p-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card"
                            data-testid="batch-result"
                        >
                            {result.allApproved ? (
                                <p
                                    className="text-sm font-semibold text-emerald-700 dark:text-emerald-400"
                                    role="status"
                                >
                                    All {result.approvedCount} selected bills
                                    were released.
                                </p>
                            ) : (
                                <div role="alert">
                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                        {result.approvedCount} released,{' '}
                                        {result.refusedCount} not released.
                                    </p>
                                    <ul className="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-300">
                                        {result.refused.map((row) => (
                                            <li key={row.uuid}>
                                                <span className="font-mono">
                                                    {row.uuid}
                                                </span>{' '}
                                                — {row.message}
                                            </li>
                                        ))}
                                    </ul>
                                    {result.approvedCount > 0 && (
                                        <p className="mt-2 text-xs text-slate-500">
                                            The {result.approvedCount} released
                                            above are done and do not need
                                            repeating.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* ── The page-scoped bulk bar. ───────────────────────────────────── */}
            {selected.length > 0 && (
                <div className="fixed inset-x-4 bottom-4 z-40 rounded-xl border border-slate-200 bg-background/95 px-4 py-3 shadow-lg backdrop-blur sm:inset-x-auto sm:left-1/2 sm:w-auto sm:-translate-x-1/2 sm:px-6 dark:border-slate-800">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-sm font-medium">
                            {selected.length} selected on this page
                        </span>
                        <button
                            type="button"
                            className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                            onClick={() => setSelected([])}
                        >
                            Clear
                        </button>
                        <div className="ml-auto flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                onClick={() => void approve()}
                                disabled={busy}
                            >
                                <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />
                                {/* THE COUNT LIVES IN THE LABEL: this button's scope is the
                                    selection, and saying so in the control is what makes scope and
                                    label unable to disagree. */}
                                Release selected ({selected.length})
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

InternalAuditReviewQueue.layout = {
    breadcrumbs: [
        { title: 'Internal audit', href: '/internal-audit/review-queue' },
        { title: 'Review queue', href: '/internal-audit/review-queue' },
    ],
};
