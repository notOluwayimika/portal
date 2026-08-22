import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    ArrowLeft,
    History,
    RefreshCw,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { TableToolbar } from '@/components/finance/table-toolbar';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClientTable } from '@/hooks/use-client-table';
import {
    DECIDED_FEEDS,
    feedFor,
    rowSubject,
} from '@/lib/finance/approval-feeds';
import {
    INVOICE_KIND_BADGE,
    INVOICE_KIND_LABEL,
} from '@/lib/finance/invoice-kind';
import { formatNaira } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PendingApproval } from '@/types/finance';

const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';

const BADGE =
    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide uppercase';

/**
 * U13 + U14 — WHAT THE APPROVALS QUEUE STOPS SHOWING THE MOMENT SOMEBODY DECIDES IT.
 *
 * THE GAP THIS CLOSES. Every credit-note and void-request read route was `/pending`. Once a checker
 * approved or rejected one, the document left the queue and appeared on no LIST anywhere: it was in
 * the ledger and in the balance, and the only path back to it was to already know which student it
 * belonged to and open that student's statement. This is the list.
 *
 * IT IS A PURE CONSUMER OF THE DECLARED FEED LIST, exactly as approvals.tsx is — the same module,
 * the other url on the same entries (lib/finance/approval-feeds.ts). Badge, label and SUBJECT come
 * off the feed; this file holds no knowledge of any individual type, and adding a sixth decided feed
 * is one entry there and no edit here. Two of the five carry a decided feed today; the other three
 * declare in words why they do not, and ApprovalsQueueFeedCoverageTest fails an entry that declares
 * neither or both.
 *
 * IT IS GATED MORE BROADLY THAN THE QUEUE, AND THAT IS THE POINT. `/finance/approvals` needs a
 * finance CHECKER ability because every row on it is an act about to be taken. This page takes only
 * the route group's `finance.access`: reading what already happened is a different capability from
 * being trusted to decide it, and the seat reconciling a term's corrections is not the seat that
 * signs them. Nothing is widened — the same rows in every status are already served under
 * `finance.access` by the statement's own feed. What was missing was findability.
 *
 * BOTH SIGNATURES ARE COLUMNS, not a detail view. Maker-checker's whole claim is that two different
 * people looked at the thing — the Policy refuses a checker who is the maker and the database CHECK
 * is the backstop under it — so a screen that answers "who approved this" with one name answers
 * nothing. Maker and checker sit side by side, and a rejection carries the checker's reason beside
 * them.
 *
 * THE INVOICE IS NAMED WITH ITS KIND, through @/lib/finance/invoice-kind rather than a literal here.
 * An episode can carry a term bill and several supplementary charges at once (U7), so a number alone
 * stopped saying which document a correction acted on.
 *
 * THERE ARE NO ACTIONS ON THIS PAGE AND THERE MUST NOT BE. Both statuses it renders are terminal;
 * reversing a decision is not a button, and a row that offered one would be offering something no
 * route in the application can do. All money through formatNaira. Search and pagination are
 * CLIENT-side, mirroring the queue — the honest limit is that this set grows for the life of a
 * school while the pending queue does not.
 */
export default function FinanceDecisions() {
    const [rows, setRows] = useState<PendingApproval[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        // Every declared decided feed, fetched independently. They share one permission today, but
        // allSettled is the shape rather than a convenience: a feed that gains its own gate later
        // must not blank the page for a reader who holds the others.
        const settled = await Promise.allSettled(
            DECIDED_FEEDS.map((feed) =>
                axios.get<{ data: PendingApproval[] }>(feed.decidedUrl()),
            ),
        );

        // THE ERROR RULE IS "ALL REJECTED", phrased over the whole array, for the reason the
        // approvals queue's own rule carries: a fixed-arity conjunction is correct at two feeds and
        // silently wrong at three, and a rule of the shape "any rejection" shows a broken page to a
        // reader who is missing exactly one gate. Only a total wipe-out is worth a retry button.
        if (settled.every((result) => result.status === 'rejected')) {
            setError(true);
            setLoading(false);

            return;
        }

        const merged: PendingApproval[] = settled
            .flatMap((result) =>
                result.status === 'fulfilled' ? result.value.data.data : [],
            )
            // Newest DECISION first — not newest submission. The server orders each feed that way
            // and this re-establishes it across the merge. `decided_at` is not on every type in the
            // union, so it is read through the same presence check the columns use; a row without
            // one sorts last rather than crashing the comparator.
            .sort((a, b) => decidedAt(b) - decidedAt(a));

        setRows(merged);
        setLoading(false);
    }, []);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const { search, setSearch, filtered, paged, meta, setPage, setLimit } =
        useClientTable(rows, (r) => [
            rowSubject(r),
            'invoice_display_number' in r ? r.invoice_display_number : null,
            r.submitted_by_name,
            'decided_by_name' in r ? r.decided_by_name : null,
            r.rejection_reason,
            r.note,
        ]);

    return (
        <>
            <Head title="Finance — decided approvals" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero ─────────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-slate-50 to-indigo-50 shadow-sm ring-1 ring-black/5 dark:from-slate-900/50 dark:to-indigo-950/50">
                                    <History className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href="/finance"
                                            className="text-slate-400 transition-colors hover:text-indigo-600"
                                            title="Back to accounts"
                                        >
                                            <ArrowLeft className="h-4 w-4" />
                                        </Link>
                                        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            Decided approvals
                                        </h1>
                                    </div>
                                    <p className="text-xs text-slate-500">
                                        Credit notes and void requests a checker
                                        has already settled, with both
                                        signatures on every row. Nothing here
                                        can be changed.
                                    </p>
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <Link href="/finance/approvals">
                                        <ShieldCheck className="mr-1.5 h-4 w-4" />
                                        Pending approvals
                                    </Link>
                                </Button>
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
                            </div>
                        </div>
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <TableToolbar
                            value={search}
                            onChange={setSearch}
                            shown={paged.length}
                            total={filtered.length}
                            placeholder="Search by document, invoice, submitter, checker or reason…"
                        />

                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className={TH}>Type</th>
                                        <th className={TH}>Document</th>
                                        <th className={TH}>Invoice</th>
                                        <th className={TH}>Submitted by</th>
                                        <th className={TH}>Decided by</th>
                                        <th className={TH}>Decision</th>
                                        <th className={TH}>Decided</th>
                                        <th className={TH}>Reason</th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {loading ? (
                                        <tr>
                                            <td
                                                colSpan={9}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : error ? (
                                        <tr>
                                            <td colSpan={9} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Could not load the
                                                        decisions
                                                    </p>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            void load()
                                                        }
                                                        className="rounded-lg"
                                                    >
                                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                                        Retry
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={9} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <History className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Nothing decided yet
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        Credit notes and void
                                                        requests appear here
                                                        once a checker approves
                                                        or rejects them.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : filtered.length === 0 ? (
                                        <tr>
                                            <td colSpan={9} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Search className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        No results
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        No decided documents
                                                        match your search.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        paged.map((row) => (
                                            <tr
                                                key={`${row.type}:${row.id}`}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                {/* Badge text and colour come off the feed entry — no per-type branch here. */}
                                                <td className="px-4 py-2.5">
                                                    <span
                                                        className={cn(
                                                            BADGE,
                                                            feedFor(row)
                                                                ?.badgeClass ??
                                                                'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                                                        )}
                                                    >
                                                        {feedFor(row)?.label ??
                                                            row.type}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {rowSubject(row)}
                                                </td>
                                                {/* THE INVOICE, WITH ITS KIND. A number alone stopped
                                                    naming one document once an episode could carry a
                                                    term bill and supplementary charges at once. Not
                                                    every type in the union hangs off an invoice, so
                                                    both halves are read through a presence check. */}
                                                <td className="px-4 py-2.5">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        {invoiceKind(row) !==
                                                            null && (
                                                            <span
                                                                className={cn(
                                                                    BADGE,
                                                                    INVOICE_KIND_BADGE[
                                                                        invoiceKind(
                                                                            row,
                                                                        )!
                                                                    ],
                                                                )}
                                                            >
                                                                {
                                                                    INVOICE_KIND_LABEL[
                                                                        invoiceKind(
                                                                            row,
                                                                        )!
                                                                    ]
                                                                }
                                                            </span>
                                                        )}
                                                        <span className="text-slate-500">
                                                            {('invoice_display_number' in
                                                            row
                                                                ? row.invoice_display_number
                                                                : null) ?? '—'}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {row.submitted_by_name ??
                                                        '—'}
                                                </td>
                                                {/* THE SECOND SIGNATURE. Never the same person as the
                                                    column before it — the Policy refuses it and the
                                                    table's CHECK is the backstop — and showing only
                                                    one of the two answers nothing. */}
                                                <td className="px-4 py-2.5 font-medium text-slate-600 dark:text-slate-300">
                                                    {('decided_by_name' in row
                                                        ? row.decided_by_name
                                                        : null) ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <span
                                                        className={cn(
                                                            BADGE,
                                                            row.status ===
                                                                'approved'
                                                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400'
                                                                : 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400',
                                                        )}
                                                    >
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {decidedAt(row) === 0
                                                        ? '—'
                                                        : new Date(
                                                              decidedAt(row),
                                                          ).toLocaleDateString()}
                                                </td>
                                                {/* The checker's reason, which exists only on a
                                                    rejection: an approval refuses one by construction
                                                    (the endpoint takes no reason). The maker's own
                                                    note is the fallback, so an approved row still says
                                                    what was asked for. */}
                                                <td className="max-w-xs px-4 py-2.5 text-slate-500">
                                                    {row.rejection_reason ??
                                                        row.note ??
                                                        '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                    {row.amount
                                                        ? formatNaira(
                                                              row.amount,
                                                          )
                                                        : '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {filtered.length > 0 && (
                            <div className="border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
                                <Pagination
                                    meta={meta}
                                    setPage={setPage}
                                    setLimit={setLimit}
                                />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

/** The decision instant as a sortable number. 0 for a type whose union member carries none. */
function decidedAt(row: PendingApproval): number {
    const value = 'decided_at' in row ? row.decided_at : null;

    return value == null ? 0 : new Date(value).getTime();
}

/** The kind of the invoice this row acts on, when it acts on one. */
function invoiceKind(row: PendingApproval) {
    return ('invoice_kind' in row ? row.invoice_kind : null) ?? null;
}
