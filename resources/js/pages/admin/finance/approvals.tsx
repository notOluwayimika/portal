import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    RefreshCw,
    Search,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { TableToolbar } from '@/components/finance/table-toolbar';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { useClientTable } from '@/hooks/use-client-table';
import {
    APPROVAL_FEEDS,
    feedFor,
    rowSubject,
} from '@/lib/finance/approval-feeds';
import { formatNaira } from '@/lib/format';
import type { PendingApproval } from '@/types/finance';

const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';

/**
 * The checker's UNIFIED pending-approvals queue (Ph3 + Ph3b, generalised by §9 step 5a) — a PURE
 * CONSUMER of every declared pending feed, merged into one datatable in the finance-module style
 * with a TYPE column.
 *
 * IT RENDERS EVERY TYPE FROM A DECLARED LIST — lib/finance/approval-feeds.ts — and holds no
 * knowledge of any individual one. It used to import two feeds by hand while FOUR were live at the
 * API, so fee-schedule changes and discount-policy changes were reachable, ability-gated and shown
 * nowhere; an approver holding finance.fee-schedule.change.approve had no screen. That is what a
 * page which cannot enumerate its feeds costs, and it is why the fix is the enumeration rather than
 * two more imports. Everything per-type — the badge, the SUBJECT line, the decision urls — comes off
 * the feed entry. Read that module's docblock before adding a type.
 *
 * Approve / Reject are driven by the server-computed `can_approve` / `can_reject` (a checker cannot
 * act on their OWN submission — maker ≠ checker); the Policy is the real guard, these flags only
 * shape the UI, and the page never infers them from abilities it holds locally. A feed with no
 * decision urls shows where its decisions are taken instead of dead buttons. All money via
 * formatNaira. Search + pagination are CLIENT-side (a decision queue is small).
 */
export default function FinanceApprovalsQueue() {
    const [rows, setRows] = useState<PendingApproval[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [rejectFor, setRejectFor] = useState<PendingApproval | null>(null);
    const [reason, setReason] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        // Fetch every declared feed independently. Each is gated by its own permission, so a
        // checker who holds one ability 403s on the rest, and that must not blank the queue —
        // allSettled is here for exactly that, and each rejection contributes no rows.
        const settled = await Promise.allSettled(
            APPROVAL_FEEDS.map((feed) =>
                axios.get<{ data: PendingApproval[] }>(feed.pendingUrl()),
            ),
        );

        // THE ERROR RULE IS "ALL REJECTED", NOT "SOME REJECTED", and the difference is the whole
        // point at N feeds. It read "both rejected" when there were two, which was right then and
        // silently wrong the moment a third existed. A checker holding ONE of the five approve
        // abilities gets four 403s on every load: that is the EXPECTED case, not a failure, and a
        // rule keyed on "any rejection" or "not all fulfilled" would show them a broken queue for
        // doing nothing wrong. Only a total wipe-out — every feed down, i.e. the network — is an
        // error worth a retry button. Keep it phrased over the whole array, so it stays true at six.
        if (settled.every((result) => result.status === 'rejected')) {
            setError(true);
            setLoading(false);

            return;
        }

        const merged: PendingApproval[] = settled
            .flatMap((result) =>
                result.status === 'fulfilled' ? result.value.data.data : [],
            )
            .sort(
                (a, b) =>
                    new Date(b.created_at).getTime() -
                    new Date(a.created_at).getTime(),
            );

        setRows(merged);
        setLoading(false);
    }, []);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    // The decision urls come off the row's own feed entry, never off a type ternary — a ternary is
    // how a fee-schedule-change uuid would end up POSTed at the credit-note approve endpoint.
    const approve = async (row: PendingApproval) => {
        const decide = feedFor(row)?.decide;

        if (decide === undefined) {
            return;
        }

        setBusyId(row.id);

        try {
            await axios.post(decide.approve(row.id));
            toast.success(
                `${rowSubject(row)} approved — ${decide.approvedMessage}.`,
            );
            await load();
        } catch (err: unknown) {
            const message =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? err.response.data.message
                    : 'Could not approve this request.';
            toast.error(message);
        } finally {
            setBusyId(null);
        }
    };

    const submitReject = async () => {
        if (rejectFor === null || reason.trim() === '') {
            return;
        }

        const decide = feedFor(rejectFor)?.decide;

        if (decide === undefined) {
            return;
        }

        setBusyId(rejectFor.id);

        try {
            await axios.post(decide.reject(rejectFor.id), {
                reason: reason.trim(),
            });
            toast.success(`${rowSubject(rejectFor)} rejected.`);
            setRejectFor(null);
            setReason('');
            await load();
        } catch (err: unknown) {
            const message =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? err.response.data.message
                    : 'Could not reject this request.';
            toast.error(message);
        } finally {
            setBusyId(null);
        }
    };

    // Client-side filter + page over the merged pending set (the shared datatable behaviour).
    // Only the fields EVERY type carries are searched directly; the invoice number exists on the
    // invoice-bound types alone, so it is read through the same presence check the column uses.
    const { search, setSearch, filtered, paged, meta, setPage, setLimit } =
        useClientTable(rows, (r) => [
            rowSubject(r),
            'invoice_display_number' in r ? r.invoice_display_number : null,
            r.submitted_by_name,
            r.note,
        ]);

    return (
        <>
            <Head title="Finance — pending approvals" />

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
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href="/finance"
                                            className="text-slate-400 transition-colors hover:text-indigo-600"
                                            title="Back to accounts"
                                        >
                                            <ArrowLeft className="h-4 w-4" />
                                        </Link>
                                        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            Pending approvals
                                        </h1>
                                    </div>
                                    <p className="text-xs text-slate-500">
                                        Everything awaiting a second person's
                                        sign-off — you see only the types you
                                        may check. Nothing here has taken effect
                                        yet.
                                    </p>
                                </div>
                            </div>
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

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <TableToolbar
                            value={search}
                            onChange={setSearch}
                            shown={paged.length}
                            total={filtered.length}
                            placeholder="Search by subject, invoice or submitter…"
                        />

                        {/* Table */}
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className={TH}>Type</th>
                                        <th className={TH}>Subject</th>
                                        <th className={TH}>Invoice</th>
                                        <th className={TH}>Submitted by</th>
                                        <th className={TH}>Reason / note</th>
                                        <th className={TH}>Date</th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Amount
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Decision
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {loading ? (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : error ? (
                                        <tr>
                                            <td colSpan={8} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Could not load the queue
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
                                            <td colSpan={8} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-500 dark:bg-emerald-900/20">
                                                        <Check className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        Nothing awaiting
                                                        approval
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        Submitted requests
                                                        appear here for a
                                                        checker to decide.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : filtered.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Search className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        No results
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        No pending requests
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
                                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide uppercase ${feedFor(row)?.badgeClass ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}`}
                                                    >
                                                        {feedFor(row)?.label ??
                                                            row.type}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {rowSubject(row)}
                                                </td>
                                                {/* Not every type hangs off an invoice; the governance
                                                    changes and opening-balance batches do not. */}
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {('invoice_display_number' in
                                                    row
                                                        ? row.invoice_display_number
                                                        : null) ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {row.submitted_by_name ??
                                                        '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {row.note ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {new Date(
                                                        row.created_at,
                                                    ).toLocaleDateString()}
                                                </td>
                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                    {row.amount
                                                        ? formatNaira(
                                                              row.amount,
                                                          )
                                                        : '—'}
                                                </td>
                                                {/* A type whose decisions are taken elsewhere says so
                                                    rather than rendering buttons that cannot work —
                                                    see lib/finance/approval-feeds.ts. */}
                                                <td className="px-4 py-2.5">
                                                    {feedFor(row)?.decide ===
                                                    undefined ? (
                                                        <p className="text-right text-[11px] text-slate-400 italic">
                                                            {feedFor(row)
                                                                ?.decidedElsewhere ??
                                                                'Decided elsewhere'}
                                                        </p>
                                                    ) : (
                                                        <div className="flex justify-end gap-1.5">
                                                            <Button
                                                                size="sm"
                                                                onClick={() =>
                                                                    void approve(
                                                                        row,
                                                                    )
                                                                }
                                                                disabled={
                                                                    !row.can_approve ||
                                                                    busyId ===
                                                                        row.id
                                                                }
                                                                title={
                                                                    row.can_approve
                                                                        ? undefined
                                                                        : 'You cannot approve your own submission'
                                                                }
                                                                className="h-7 rounded-lg bg-emerald-600 text-xs font-semibold text-white hover:bg-emerald-700"
                                                            >
                                                                <Check className="mr-1 h-3.5 w-3.5" />
                                                                Approve
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => {
                                                                    setReason(
                                                                        '',
                                                                    );
                                                                    setRejectFor(
                                                                        row,
                                                                    );
                                                                }}
                                                                disabled={
                                                                    !row.can_reject ||
                                                                    busyId ===
                                                                        row.id
                                                                }
                                                                title={
                                                                    row.can_reject
                                                                        ? undefined
                                                                        : 'You cannot reject your own submission'
                                                                }
                                                                className="h-7 rounded-lg text-xs"
                                                            >
                                                                <X className="mr-1 h-3.5 w-3.5" />
                                                                Reject
                                                            </Button>
                                                        </div>
                                                    )}
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

            <Modal
                isOpen={rejectFor !== null}
                onClose={() => setRejectFor(null)}
                title={
                    rejectFor
                        ? `Reject ${rowSubject(rejectFor)}`
                        : 'Reject request'
                }
                size="md"
            >
                <div className="space-y-4">
                    <div>
                        <Label htmlFor="reject-reason">Reason (required)</Label>
                        <Input
                            id="reject-reason"
                            placeholder="Why is this request rejected?"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                            Rejecting never moves money; the request stays for
                            audit.
                        </p>
                    </div>
                    <div className="flex justify-end gap-2 border-t pt-3">
                        <Button
                            variant="outline"
                            onClick={() => setRejectFor(null)}
                            disabled={busyId !== null}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={() => void submitReject()}
                            disabled={reason.trim() === '' || busyId !== null}
                        >
                            Reject
                        </Button>
                    </div>
                </div>
            </Modal>
        </>
    );
}

FinanceApprovalsQueue.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Approvals', href: '/finance/approvals' },
    ],
};
