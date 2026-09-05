import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    Check,
    CornerUpLeft,
    FileCheck2,
    RefreshCw,
    ShieldCheck,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/hooks/use-permissions';
import { formatNaira } from '@/lib/format';
import {
    batchView,
    countsView,
    queueView,
    RETURN_REASON_MAX,
    returnDialogView,
    returnErrorMessage,
    selectAllOnPage,
} from '@/lib/internal-audit-queue';
import type {
    BatchResponse,
    BatchView,
    PendingInvoice,
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
 * ── THE COUNT IS THE INSTRUMENT, AND THE INSTRUMENT MOVED ──────────────────────────────────────
 *
 * This control's one failure mode that emits no audit row at any severity is a bill NOBODY REVIEWS.
 * A number that grows is the only thing that will ever show it, so which number this screen shows
 * is the whole question.
 *
 * `pagination.total` IS NO LONGER THAT NUMBER. `pending()` filters `whereNull('returned_at')`, so
 * the total describes bills awaiting review AND NOT OUT WITH FINANCE — a true number, correctly
 * driving the pager, and a SUBSET. The unfiltered count is `counts.unreleased_total`, and the
 * endpoint asserts server-side that it equals the two others summed.
 *
 * SO THE DETECTOR IS VISIBLE HERE AS THE SUM OF THE TWO CARDS. "Awaiting sign-off" plus "Returned
 * to Finance" IS every unreleased bill in the school — the same invariant, rendered. An auditor
 * reading both cards is reading the omission detector, and neither card alone is it.
 *
 * THERE IS DELIBERATELY NO THIRD CARD FOR `unreleased_total`, AND THE OMISSION IS DECIDED RATHER
 * THAN OVERLOOKED. This page's own rule is that two bare numbers on one screen is a bug report; a
 * third number that is the SUM of the other two is that rule made worse, because the reader must
 * reconcile it before they can trust any of them. Two cards that add up is the honest shape. If the
 * sum ever needs to be shown, it needs a label saying it is the sum — not a third tile beside its
 * own addends.
 *
 * ── "RETURNED TO FINANCE" IS NOT DECORATION ────────────────────────────────────────────────────
 *
 * Until Phase B builds Finance's own queue, THAT CARD IS THE ONLY PLACE IN THE ENTIRE SYSTEM A
 * RETURNED BILL IS VISIBLE. It is filtered out of the rows below by the change that added the
 * return, it is invisible to the payer because it is still unreleased, and Finance has no screen
 * for it yet. Without the card an auditor returns a bill and watches it vanish from the only screen
 * they have — which is what a LOST bill looks like, and there would be no way to tell the two
 * apart.
 *
 * ── AN UNKNOWN COUNT RENDERS AS `—`, NOT AS `0` ────────────────────────────────────────────────
 *
 * `countsView` returns an em dash while the request is in flight or has failed, and this CORRECTS A
 * LIE THIS PAGE ALREADY TOLD: the awaiting card read `view.kind === 'rows' ? total : '0'`, so a
 * failed request rendered a confident **0** under "Awaiting sign-off". The table below it has
 * always distinguished those states and the section after this one argues at length that they must
 * never render alike — the card was the one place that did not, and it is the place an auditor
 * glances at. It is §26's own recorded defect, "KPI cards rendering a hard `0` above the words
 * Could not load", on a third screen.
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
    /**
     * THE RETURN CONTROL IS GATED, AND THE ROUTE IT POSTS TO IS GATED ON THE SAME ABILITY.
     *
     * §24: "gate write actions by permission". `usePermissions` is the EFFECTIVE set the backend
     * Gate will actually allow in the active school, which is why it is the right source here and
     * a role name would not be.
     *
     * NO SEEDED SEAT IS AFFECTED TODAY, and that is worth saying rather than leaving the gate to
     * look like dead code. `internal_auditor` is the only role holding either checker ability and
     * it holds BOTH — `RbacSeeder` grants approve and reject together and withholds both from
     * `admin` and `accounts_officer` on the maker-checker argument. So every seat that can reach
     * this page can also use this control.
     *
     * IT IS HERE FOR THE SEAT THAT DOES NOT EXIST YET. The page is reached through
     * `permission:finance.invoice.approve` and the return route adds
     * `permission:finance.invoice.reject` on top; the day a seat holds the first and not the
     * second — which is the day the route file's docblock says the GROUP gate must be
     * reconsidered — this button would render and 403 on click. A control that renders and cannot
     * be used is the most expensive defect class §26 records.
     */
    const { can } = usePermissions();
    const mayReturn = can('finance.invoice.reject');

    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [response, setResponse] = useState<PendingResponse | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<BatchView | null>(null);

    /** The bill the return dialog is open for, or null. */
    const [returning, setReturning] = useState<PendingInvoice | null>(null);
    const [reason, setReason] = useState('');
    const [returnBusy, setReturnBusy] = useState(false);
    const [returnError, setReturnError] = useState<string | null>(null);

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
     *
     * AND CAUSE 1 HAS A SECOND REMEDY, for the shape neither of the above fits. When the effect
     * exists to SEED state from props there is no handler to move the setState into and no promise
     * to chain — the write is not caused by an event and is not the tail of a request. The remedy
     * is to make the state INITIALISE rather than update: split the wrapper from the body so the
     * body's `useState` initialisers run with the props already in hand, and put the identity on
     * the wrapper as `key={…}`. The key is what keeps it a refactor rather than a silent behaviour
     * change — it reproduces the old effect's re-seed by remounting when that identity changes,
     * which is exactly what the old dependency array did. Landed in
     * `resources/js/components/students/edit-pivot-modal.tsx`.
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
    const counts = countsView({ loading, failed, response });
    const dialog = returnDialogView({ raw: reason, busy: returnBusy });

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

    const openReturn = (invoice: PendingInvoice) => {
        // The dialog opens CLEAN. A reason left over from a previous bill is the worst possible
        // default in this field: it is a plausible sentence attached to the wrong invoice.
        setReturning(invoice);
        setReason('');
        setReturnError(null);
    };

    const submitReturn = () => {
        if (returning === null || !dialog.canSubmit) {
            return;
        }

        setReturnBusy(true);
        setReturnError(null);

        return axios
            .post(`/api/internal-audit/invoices/${returning.uuid}/return`, {
                reason,
            })
            .then(() => {
                toast.success(
                    `Bill ${returning.number} was returned to Finance.`,
                );
                setReturning(null);
                setReason('');

                // REFETCHED, NEVER DECREMENTED LOCALLY. The two counts are the numbers this screen
                // exists to be trusted on, and a locally-adjusted copy is a second source of truth
                // for exactly them — it drifts silently the first time two auditors work at once,
                // and it drifts in the reassuring direction.
                return load();
            })
            .catch((error: unknown) => {
                const failure =
                    typeof error === 'object' &&
                    error !== null &&
                    'response' in error
                        ? (
                              error as {
                                  response?: {
                                      status?: number;
                                      data?: unknown;
                                  };
                              }
                          ).response
                        : undefined;

                // THE SLOT, NOT A TOAST. The server's sentence names the first returner, or the
                // void-and-credit-note remedy, or the measured length — an operator needs to read
                // it and act on it, and a toast disappears while they are still reading.
                setReturnError(
                    returnErrorMessage(
                        (failure ?? null) as Parameters<
                            typeof returnErrorMessage
                        >[0],
                    ),
                );
            })
            .finally(() => setReturnBusy(false));
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

                    {/* ── The two counts. Their SUM is the omission detector; there is no third
                        card for it, deliberately — see the docblock. Both read `counts`, never
                        `pagination.total`, which is now the filtered subset. ─────────────────── */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FinanceStatCard
                            icon={FileCheck2}
                            label="Awaiting sign-off"
                            value={counts.awaiting}
                            subText="Unreleased and not out with Finance"
                            tone="indigo"
                            loading={view.kind === 'loading'}
                        />
                        <FinanceStatCard
                            icon={Undo2}
                            label="Returned to Finance"
                            value={counts.returned}
                            subText="Sent back for correction, still unreleased"
                            tone="amber"
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
                                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Actions
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
                                            <td colSpan={6} className="py-12">
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
                                                <td className="px-3 py-2.5 text-right">
                                                    {/* PER ROW, AND THERE IS NO BULK EQUIVALENT.
                                                        The release control below is bulk because a
                                                        release carries no payload beyond the
                                                        attestation; a return carries a REASON, and
                                                        one reason applied to a hundred bills is a
                                                        label rather than a reason. The asymmetry is
                                                        the design, not an omission — there is no
                                                        batch return endpoint and there will not be.

                                                        `ghost`, per §15's row-action rule, so the
                                                        one primary action on this view stays the
                                                        release button. */}
                                                    {mayReturn && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 rounded-lg text-xs font-semibold text-slate-600 hover:bg-amber-50 hover:text-amber-700 dark:text-slate-300 dark:hover:bg-amber-900/20 dark:hover:text-amber-400"
                                                            onClick={() =>
                                                                openReturn(row)
                                                            }
                                                        >
                                                            <CornerUpLeft className="mr-1.5 h-3.5 w-3.5" />
                                                            Return
                                                        </Button>
                                                    )}
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

            {/* ── THE RETURN DIALOG. A `Modal` WITH A FORM, NOT `ConfirmDialog`. ─────────────
                §10's table assigns `useApiSweetAlertConfirmation` / `ConfirmDialog` to
                DESTRUCTIVE CONFIRMATION (delete) — a yes/no carrying no payload. A return carries a
                REQUIRED REASON, so it is a form, and §24 forbids a bespoke dialog for it.

                THE SUBMIT IS `default`, NOT `destructive`. §15 scopes the danger variant to
                destructive confirms, and a return destroys nothing: the bill goes back to Finance
                for correction and its ledger charge is untouched. Styling it red would tell an
                auditor they are about to lose something.

                THE `dark:` VARIANTS BELOW ARE UNREACHABLE TODAY, AND ARE WRITTEN ANYWAY. §26
                records that `Modal.tsx` carries ZERO `dark:` variants — measured again here,
                `grep -c 'dark:'` returns 0 — so this form is authored inside a surface that never
                flips, and dark mode is unreachable by any user at all
                (docs/handoff/tickets/dark-mode-is-unreachable-for-every-user.md,
                ui-chrome-components-have-no-dark-variants.md). §26's instruction is explicit:
                "Write them anyway — they are the target". So they are here, and NOBODY HAS SEEN
                THEM RENDER: the day the chrome ticket gives `Modal` its dark surface, these become
                correct without a second visit. Until then they are dead classes on a white card,
                which is the state every form in this application is in. ──────────────────── */}
            <Modal
                isOpen={returning !== null}
                onClose={() => setReturning(null)}
                title={`Return bill ${returning?.number ?? ''} to Finance`}
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => setReturning(null)}
                            disabled={returnBusy}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="return-invoice-form"
                            disabled={!dialog.canSubmit}
                        >
                            {returnBusy ? (
                                <Spinner className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <CornerUpLeft className="mr-2 h-4 w-4" />
                            )}
                            {/* §9: the label swaps while submitting. */}
                            {returnBusy ? 'Returning…' : 'Return to Finance'}
                        </Button>
                    </div>
                }
            >
                <form
                    id="return-invoice-form"
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submitReturn();
                    }}
                >
                    <p className="text-xs text-slate-500">
                        This bill stays unreleased and invisible to the payer.
                        Finance corrects it and raises it again.
                    </p>

                    <div>
                        <Label htmlFor="return-reason">
                            What should Finance correct?
                        </Label>
                        <textarea
                            id="return-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={3}
                            /* maxLength MIRRORS ReturnInvoice::REASON_MAX through the constant in
                               @/lib/internal-audit-queue — never a literal 255 here. There is no
                               PHP→TS constant bridge in this repository, so the two are kept in
                               step by tests/Arch/ReturnReasonMaxHasOneValueTest.php, which reads
                               the TypeScript line and asserts it equals the PHP constant. */
                            maxLength={RETURN_REASON_MAX}
                            placeholder="The tuition line is charged at last term's rate."
                            className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        />
                        <div className="mt-1 flex items-start justify-between gap-3">
                            {/* THE HELPER TEXT IS COMMIT 3's OWED MITIGATION COMING DUE.
                                config/activity_log_sensitive.php records the residual in its own
                                words: this row carries FREE TEXT an auditor typed,
                                `finance.invoice.returned` is deliberately NOT sensitive, so anyone
                                holding `activity_log.view` can read it — and no config setting can
                                close that. The stated mitigation was helper text on this form. This
                                is it. §16's helper style: text-xs text-slate-500. */}
                            <p className="text-xs text-slate-500">
                                Say what Finance must correct. Recorded in the
                                activity log and readable by other staff —
                                describe the bill, not the payer.
                            </p>
                            {/* LABELLED, NOT A BARE NUMBER. The drive screenshot showed a lone
                                `210` beside a sentence, and this page's own docblock rule is that
                                two bare numbers on one screen is a bug report — a counter with no
                                unit is that rule at the level of a single field. Over the cap it
                                counts UP and says `over`, because "-45 left" is not a sentence. */}
                            <span className="shrink-0 text-xs text-slate-400 tabular-nums">
                                {dialog.remaining >= 0
                                    ? `${dialog.remaining} left`
                                    : `${-dialog.remaining} over`}
                            </span>
                        </div>
                    </div>

                    {/* §9's form-level banner, and the server's 422 verbatim. */}
                    {returnError !== null && (
                        <p
                            className="rounded-md bg-destructive/10 p-2 text-sm text-destructive"
                            role="alert"
                        >
                            {returnError}
                        </p>
                    )}
                </form>
            </Modal>

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
