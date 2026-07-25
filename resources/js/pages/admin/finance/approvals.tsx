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
import {
    approve as approveCredit,
    pending as pendingCredit,
    reject as rejectCredit,
} from '@/actions/App/Finance/Http/Controllers/CreditNoteController';
import {
    approve as approveVoid,
    pending as pendingVoid,
    reject as rejectVoid,
} from '@/actions/App/Finance/Http/Controllers/VoidRequestController';
import { TableToolbar } from '@/components/finance/table-toolbar';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { useClientTable } from '@/hooks/use-client-table';
import { formatNaira } from '@/lib/format';
import type { PendingApproval } from '@/types/finance';

const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';

/** The human label for a queue row — a credit note shows its own number; a void names its invoice. */
function rowLabel(row: PendingApproval): string {
    return row.type === 'credit_note'
        ? row.display_number
        : `Void · ${row.invoice_display_number ?? '—'}`;
}

/**
 * The checker's UNIFIED pending-approvals queue (Ph3 + Ph3b) — a PURE CONSUMER of the two
 * pending feeds (GET /credit-notes/pending and /void-requests/pending), merged into one
 * datatable in the finance-module style with a TYPE column. Both are maker-checker documents:
 * approving a credit note forgives money (posts a compensating credit); approving a void
 * reverses a whole invoice charge. Approve / Reject are driven by the server-computed
 * `can_approve` / `can_reject` (a checker cannot act on their OWN submission — maker ≠ checker);
 * the Policy is the real guard, these flags just shape the UI. All money via formatNaira.
 *
 * Each feed is gated by its own permission, so a checker holding only one side sees only that
 * queue: a 403 (or any error) on one feed degrades to an empty contribution, never a broken page.
 * Search + pagination are CLIENT-side (a decision queue is small).
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

        // Fetch both feeds independently: a checker who only holds one side 403s on the other,
        // which must not blank the whole queue. allSettled → each rejection contributes nothing.
        const [credit, voids] = await Promise.allSettled([
            axios.get<{ data: PendingApproval[] }>(pendingCredit.url()),
            axios.get<{ data: PendingApproval[] }>(pendingVoid.url()),
        ]);

        // A hard failure is only when BOTH feeds error (e.g. the network is down) — a single
        // permission 403 is expected and simply yields no rows from that side.
        if (credit.status === 'rejected' && voids.status === 'rejected') {
            setError(true);
            setLoading(false);

            return;
        }

        const merged: PendingApproval[] = [
            ...(credit.status === 'fulfilled' ? credit.value.data.data : []),
            ...(voids.status === 'fulfilled' ? voids.value.data.data : []),
        ].sort(
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

    const approve = async (row: PendingApproval) => {
        setBusyId(row.id);

        try {
            const url =
                row.type === 'void'
                    ? approveVoid.url(row.id)
                    : approveCredit.url(row.id);
            await axios.post(url);
            toast.success(
                row.type === 'void'
                    ? `${rowLabel(row)} approved — invoice voided.`
                    : `${rowLabel(row)} approved — credit applied.`,
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

        setBusyId(rejectFor.id);

        try {
            const url =
                rejectFor.type === 'void'
                    ? rejectVoid.url(rejectFor.id)
                    : rejectCredit.url(rejectFor.id);
            await axios.post(url, { reason: reason.trim() });
            toast.success(`${rowLabel(rejectFor)} rejected.`);
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
    const { search, setSearch, filtered, paged, meta, setPage, setLimit } =
        useClientTable(rows, (r) => [
            rowLabel(r),
            r.invoice_display_number,
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
                                        Credit notes and invoice voids awaiting
                                        a second person's sign-off. Money moves
                                        only on approval.
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
                            placeholder="Search by request, invoice or submitter…"
                        />

                        {/* Table */}
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className={TH}>Type</th>
                                        <th className={TH}>Request</th>
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
                                                        Submitted credit notes
                                                        and void requests appear
                                                        here for a checker to
                                                        decide.
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
                                                <td className="px-4 py-2.5">
                                                    {row.type === 'void' ? (
                                                        <span className="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold tracking-wide text-amber-700 uppercase dark:bg-amber-900/20 dark:text-amber-400">
                                                            Void
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold tracking-wide text-indigo-700 uppercase dark:bg-indigo-900/20 dark:text-indigo-400">
                                                            Credit note
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {rowLabel(row)}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {row.invoice_display_number ??
                                                        '—'}
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
                                                <td className="px-4 py-2.5">
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
                                                                setReason('');
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
                        ? `Reject ${rowLabel(rejectFor)}`
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
