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
    approve as approveAction,
    pending as pendingAction,
    reject as rejectAction,
} from '@/actions/App/Finance/Http/Controllers/CreditNoteController';
import { TableToolbar } from '@/components/finance/table-toolbar';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { useClientTable } from '@/hooks/use-client-table';
import { formatNaira } from '@/lib/format';
import type { CreditNote } from '@/types/finance';

const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';

/**
 * The checker's pending-approvals queue — a PURE CONSUMER of GET /api/v1/finance/credit-notes/
 * pending, presented as a datatable in the finance-module style (filter/search row + count +
 * pagination). Approving a credit note forgives money (posts the compensating ledger credit);
 * it is the checker side of maker-checker. Approve / Reject are driven by the server-computed
 * `can_approve` / `can_reject` (a checker cannot act on their OWN submission — maker ≠ checker);
 * the Policy is the real guard, these flags just shape the UI. All money via formatNaira.
 *
 * Search + pagination are CLIENT-side: the endpoint returns the full pending set (a decision
 * queue is small), so the table filters and pages the rows it already holds.
 */
export default function FinanceApprovalsQueue() {
    const [rows, setRows] = useState<CreditNote[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [rejectFor, setRejectFor] = useState<CreditNote | null>(null);
    const [reason, setReason] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get<{ data: CreditNote[] }>(
                pendingAction.url(),
            );
            setRows(data.data);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const approve = async (row: CreditNote) => {
        setBusyId(row.id);

        try {
            await axios.post(approveAction.url(row.id));
            toast.success(`${row.display_number} approved — credit applied.`);
            await load();
        } catch (err: unknown) {
            const message =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? err.response.data.message
                    : 'Could not approve this credit note.';
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
            await axios.post(rejectAction.url(rejectFor.id), {
                reason: reason.trim(),
            });
            toast.success(`${rejectFor.display_number} rejected.`);
            setRejectFor(null);
            setReason('');
            await load();
        } catch (err: unknown) {
            const message =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? err.response.data.message
                    : 'Could not reject this credit note.';
            toast.error(message);
        } finally {
            setBusyId(null);
        }
    };

    // Client-side filter + page over the loaded pending set (the shared datatable behaviour).
    const { search, setSearch, filtered, paged, meta, setPage, setLimit } =
        useClientTable(rows, (r) => [
            r.display_number,
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
                                        Credit notes awaiting a second person's
                                        sign-off. Money moves only on approval.
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
                            placeholder="Search by credit note, invoice or submitter…"
                        />

                        {/* Table */}
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className={TH}>Credit note</th>
                                        <th className={TH}>Invoice</th>
                                        <th className={TH}>Submitted by</th>
                                        <th className={TH}>Note</th>
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
                                            <td colSpan={7} className="py-12">
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
                                                        appear here for a
                                                        checker to decide.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : filtered.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Search className="h-6 w-6" />
                                                    </div>
                                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                        No results
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        No pending credit notes
                                                        match your search.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        paged.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {row.display_number}
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
                                                    {formatNaira(row.amount)}
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
                        ? `Reject ${rejectFor.display_number}`
                        : 'Reject credit note'
                }
                size="md"
            >
                <div className="space-y-4">
                    <div>
                        <Label htmlFor="reject-reason">Reason (required)</Label>
                        <Input
                            id="reject-reason"
                            placeholder="Why is this credit note rejected?"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                            Rejecting never moves money; the note stays for
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
