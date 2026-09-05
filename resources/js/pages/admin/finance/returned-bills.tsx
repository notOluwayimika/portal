import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    CheckCircle2,
    Clock3,
    CornerUpLeft,
    RefreshCw,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatNaira } from '@/lib/format';
import {
    countsView,
    queueView,
    returnerLabel,
} from '@/lib/returned-bills-queue';
import type { ReturnedResponse } from '@/lib/returned-bills-queue';

/**
 * FINANCE'S RETURNED-BILLS QUEUE — the reader for a write path that shipped without one.
 *
 * Every decision this screen makes lives in `@/lib/returned-bills-queue` and is asserted by
 * `returned-bills-queue.test.ts` under vitest — the only bin/quality step that executes application
 * JS. This file maps those decisions to copy and colour, the same split
 * `admin/internal-audit/review-queue.tsx` uses over `internal-audit-queue.ts`, and this screen is
 * that one's mirror.
 *
 * ── IT READS. IT DOES NOT ACT, AND THERE IS NOT EVEN A DISABLED BUTTON ─────────────────────────
 *
 * No correction, no resubmission, no "mark corrected". What Finance DOES with a returned bill is an
 * open question with Brookstone and the answer changes the schema.
 *
 * A GREYED-OUT CONTROL WOULD BE WORSE THAN NONE, which is why the absence is total rather than
 * cosmetic. A disabled button is a PROMISE — it tells a bursar the verb exists and is merely
 * unavailable to them right now, so they wait for it instead of asking for it, and the screen
 * commits the project to a decision nobody has made. Same family as this repository's standing
 * objection to a control the server never receives: an affordance with nothing behind it does not
 * fail to help, it manufactures an expectation.
 *
 * ── THE TWO NUMBERS, AND THE SECOND ONE IS THE INSTRUMENT ─────────────────────────────────────
 *
 * A COUNT OF 4 LOOKS FINE WHETHER THOSE FOUR ARRIVED THIS MORNING OR THREE WEEKS AGO. A queue that
 * is worked drains and refills and oscillates around a small number; an ABANDONED queue also sits
 * at a small number, permanently, and nothing about the count separates them. The age does, in one
 * glance, and it is the only field here that changes when a returned bill is simply left.
 *
 * It is the Finance-side equivalent of what `pagination.total` is on the auditor's page, and the
 * argument is deliberately the same: the failure this control exists to catch emits no activity row
 * at any severity, throws nothing, and looks exactly like a quiet week.
 *
 * THE AGE CARD CHANGES TONE, NEVER ITS VALUE. `stalled` is a presentation threshold in the lib
 * (`STALLED_AFTER_DAYS`), asserted there; no request, filter or count depends on it, so getting the
 * number wrong makes a card the wrong colour and cannot make a number wrong.
 *
 * ── THIS PAGE PAGES, AND THE MIRROR DOES NOT ──────────────────────────────────────────────────
 *
 * `review-queue.tsx` renders page 1 and reports the true total in its card, with no pager. That is
 * a residual there and it would be a DEFECT here, because the two screens are worked differently: an
 * auditor drains a queue from the top and every release shortens it, whereas a bill Finance cannot
 * reach is a bill nobody corrects — and the oldest, which is the one this screen sorts to the top
 * and measures, is exactly the one that would sit past the end of page 1 as the queue grew.
 *
 * ── OLDEST FIRST, AND THE SERVER DECIDES IT ───────────────────────────────────────────────────
 *
 * There is no sort control. The order is `ORDER BY returned_at ASC` in the endpoint and this screen
 * cannot change it: a client-side re-sort would reorder ONE PAGE of a server-paged set, so "newest
 * first" would show the newest 25 of the oldest 25 — a claim about the whole queue derived from a
 * slice of it, which is the exact shape of lie the `empty`/`failed` split exists to refuse.
 */
export default function ReturnedBills() {
    const [response, setResponse] = useState<ReturnedResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(25);

    /*
     * `load` DOES NOT RAISE THE SPINNER, AND THAT IS THE FIX RATHER THAN A STYLE CHOICE.
     *
     * `setLoading(true)` here would run SYNCHRONOUSLY inside the effect below —
     * `react-hooks/set-state-in-effect`, cause 1 as `admin/internal-audit/review-queue.tsx`
     * records it — because the effect calls this function in its body.
     *
     * THE REMEDY IS THE ONE THAT FILE STATES: the transition belongs in the EVENT HANDLER, where
     * it is real. A person clicking Refresh, a page number or a page size IS the transition, and
     * each of those handlers raises the spinner itself. The effect is left doing only what an
     * effect should — synchronising with an external system — and every setState it causes lands
     * in a `.then`/`.catch`/`.finally` callback, which is exactly what the rule permits.
     *
     * THE FIRST LOAD NEEDS NO HANDLER: `loading` is initialised `true`, so the mount renders the
     * spinner before any request has been made.
     */
    const load = useCallback(() => {
        return axios
            .get<ReturnedResponse>('/api/v1/finance/invoices/returned', {
                params: { page, per_page: limit },
            })
            .then((r) => {
                setResponse(r.data);
                setFailed(false);
            })
            .catch(() => {
                // THE RESPONSE IS CLEARED, NOT KEPT. A stale page under a "could not load" state
                // would show rows the server has not confirmed, and `queueView` would still read
                // `failed` — but the numbers beside them would be the previous request's.
                setResponse(null);
                setFailed(true);
            })
            .finally(() => setLoading(false));
    }, [page, limit]);

    useEffect(() => {
        void load();
    }, [load]);

    const view = queueView({ loading, failed, response });
    const counts = countsView({ loading, failed, response });
    const rows = view.kind === 'rows' ? view.rows : [];

    return (
        <>
            <Head title="Returned to Finance" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero ─────────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-amber-50 to-orange-50 shadow-sm ring-1 ring-black/5 dark:from-amber-950/50 dark:to-orange-950/50">
                                    <Undo2 className="h-6 w-6 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Returned to Finance
                                    </h1>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                        Bills Internal Audit has sent back with
                                        something to correct. They are not
                                        released to the payer and they are not
                                        in the auditor&apos;s queue — the oldest
                                        is first.
                                    </p>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setLoading(true);
                                    void load();
                                }}
                                disabled={loading}
                                className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                            >
                                <RefreshCw
                                    className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`}
                                />
                                Refresh
                            </Button>
                        </div>
                    </div>

                    {/* ── The two numbers. The size, and whether the process is working. ─────── */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FinanceStatCard
                            icon={CornerUpLeft}
                            label="Waiting to be corrected"
                            value={counts.total}
                            subText="Returned by Internal Audit, still unreleased"
                            tone="amber"
                            loading={loading && !response}
                        />
                        <FinanceStatCard
                            icon={Clock3}
                            label="Oldest has waited"
                            value={counts.oldestWaited}
                            subText={
                                counts.stalled
                                    ? 'Longer than a week — this queue is not being worked'
                                    : 'How long the oldest bill has been waiting'
                            }
                            tone={counts.stalled ? 'rose' : 'slate'}
                            loading={loading && !response}
                        />
                    </div>

                    {/* ── The table ────────────────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Bill
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Billed to
                                        </th>
                                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Total
                                        </th>
                                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Returned
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Returned by
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            What to correct
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {view.kind === 'loading' ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : view.kind === 'failed' ? (
                                        <tr>
                                            <td colSpan={6} className="py-12">
                                                {/* THE ALARM. Red, an AlertCircle and a retry —
                                                    deliberately nothing like the emerald "nothing
                                                    returned" below it. */}
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Could not load the queue
                                                    </p>
                                                    <p className="max-w-sm text-xs text-slate-500 dark:text-slate-400">
                                                        This is{' '}
                                                        <strong>not</strong> an
                                                        empty queue — the number
                                                        of bills waiting to be
                                                        corrected is unknown.
                                                    </p>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setLoading(true);
                                                            void load();
                                                        }}
                                                        className="rounded-lg dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                                    >
                                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                                        Retry
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : view.kind === 'empty' ? (
                                        <tr>
                                            <td colSpan={6} className="py-12">
                                                {/* THE REASSURANCE. Emerald, a tick, no retry. */}
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-500 dark:bg-emerald-900/20">
                                                        <CheckCircle2 className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Nothing has been
                                                        returned
                                                    </p>
                                                    <p className="max-w-sm text-xs text-slate-500 dark:text-slate-400">
                                                        Internal Audit has not
                                                        sent any bill back for
                                                        correction.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((row) => (
                                            <tr
                                                key={row.number}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-3 py-2.5 font-mono whitespace-nowrap text-slate-800 dark:text-slate-100">
                                                    {row.number}
                                                </td>
                                                <td className="px-3 py-2.5 text-slate-700 dark:text-slate-200">
                                                    {row.billed_to}
                                                </td>
                                                <td className="px-3 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                    {formatNaira(row.total)}
                                                </td>
                                                <td className="px-3 py-2.5 text-right whitespace-nowrap text-slate-500 tabular-nums dark:text-slate-400">
                                                    {row.returned_at
                                                        ? new Date(
                                                              row.returned_at,
                                                          ).toLocaleDateString()
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2.5 whitespace-nowrap text-slate-700 dark:text-slate-200">
                                                    {returnerLabel(
                                                        row.returned_by,
                                                    )}
                                                </td>
                                                {/* THE REASON, IN FULL. No clamp, no ellipsis, no
                                                    "show more" — it is the entire payload of the
                                                    act, and a reason you have to click to read is
                                                    a reason Finance will not read. It wraps
                                                    (`whitespace-normal`) rather than truncating,
                                                    and the column is given the room to do it. */}
                                                <td className="max-w-md px-3 py-2.5 whitespace-normal text-slate-600 dark:text-slate-300">
                                                    {row.return_reason}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {view.kind === 'rows' && response ? (
                            <div className="border-t border-slate-100 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/20">
                                <Pagination
                                    meta={response.pagination}
                                    setPage={(next) => {
                                        setLoading(true);
                                        setPage(next);
                                    }}
                                    setLimit={(next) => {
                                        setLoading(true);
                                        // BACK TO PAGE 1 ON A SIZE CHANGE. Staying on page 4 while
                                        // the page size grows can land past the last page, which
                                        // renders an empty table under a non-zero count — the
                                        // "empty page, full queue" state `queueView` refuses to
                                        // call empty, arrived at through the control itself.
                                        setLimit(next);
                                        setPage(1);
                                    }}
                                />
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
        </>
    );
}
