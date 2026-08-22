import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    ArrowLeft,
    Landmark,
    Plus,
    RefreshCw,
    Scale,
    Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { forStudent } from '@/actions/App/Finance/Http/Controllers/InvoiceController';
// The receipt page route (U11). Wayfinder-generated from the controller, so the path lives in
// exactly one place — routes/web.php — and a rename cannot leave a dead link here.
import receiptUrl from '@/actions/App/Finance/Http/Controllers/PaymentReceiptController';
import { Can } from '@/components/can';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { IssueCreditNoteModal } from '@/components/finance/issue-credit-note-modal';
import { NewInvoiceModal } from '@/components/finance/new-invoice-modal';
import { RecordPaymentModal } from '@/components/finance/record-payment-modal';
import { RequestVoidModal } from '@/components/finance/request-void-modal';
import { TableToolbar } from '@/components/finance/table-toolbar';
import { Pagination } from '@/components/pagination';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClientTable } from '@/hooks/use-client-table';
import { useInitials } from '@/hooks/use-initials';
import { formatNaira } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Invoice, Statement } from '@/types/finance';

const PAGINATION_FOOTER =
    'border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30';

type Props = {
    student: { uuid: string; name: string };
};

const SECTION_CARD =
    'overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card';

// Settlement axis presentation (the DERIVED state, distinct from the issued/void document badge).
const SETTLEMENT_LABEL: Record<'unpaid' | 'part_paid' | 'settled', string> = {
    unpaid: 'Unpaid',
    part_paid: 'Part-paid',
    settled: 'Settled',
};
const SETTLEMENT_BADGE: Record<'unpaid' | 'part_paid' | 'settled', string> = {
    unpaid: 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-400',
    part_paid:
        'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
    settled:
        'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400',
};
const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';
const HEAD_ROW =
    'border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30';

/**
 * The bursar statement — a PURE CONSUMER of GET /api/v1/finance/students/{uuid}/invoices,
 * restyled to the Student-module design language (hero card + stat cards + section tables).
 * It NEVER nets or computes: the invoice shows its full amount, each credit note is its own
 * document, and the account balance / available credit come straight from the API. Every
 * money value is displayed via formatNaira; there is no client-side money arithmetic.
 */
export default function FinanceStatement({ student }: Props) {
    const getInitials = useInitials();
    const [statement, setStatement] = useState<Statement | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [payFor, setPayFor] = useState<Invoice | null>(null);
    // Account-scoped payment (ADR 0048): the header "Record payment" opens the modal with no
    // invoice — the money banks to the account and settles oldest-first at the next billing.
    const [accountPay, setAccountPay] = useState(false);
    const [creditFor, setCreditFor] = useState<Invoice | null>(null);
    const [voidFor, setVoidFor] = useState<Invoice | null>(null);
    const [newInvoiceOpen, setNewInvoiceOpen] = useState(false);
    // Which transaction table is shown — the three peer tables (invoices / credit notes /
    // payments) now share one tabbed card instead of stacking down the page.
    const [activeTab, setActiveTab] = useState<
        'invoices' | 'credit_notes' | 'payments'
    >('invoices');

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get<Statement>(
                forStudent.url(student.uuid),
            );
            setStatement(data);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, [student.uuid]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const account = statement?.account;

    // The set of invoice DISPLAY NUMBERS with a PENDING void request — the invoice is still active
    // but a void is awaiting a checker. Matched on display_number because the statement invoice
    // carries a uuid, not the numeric PK the void request references. Used to swap the "Request
    // void" action for an "awaiting approval" badge, so a maker cannot stack two open requests.
    const pendingVoidDisplayNumbers = new Set(
        (statement?.void_requests ?? [])
            .filter((v) => v.status === 'submitted' && v.invoice_display_number)
            .map((v) => v.invoice_display_number),
    );

    // Tab metadata (label + live count) for the transaction-table tabs.
    const txnTabs = statement
        ? ([
              {
                  key: 'invoices',
                  label: 'Invoices',
                  count: statement.invoices.length,
              },
              {
                  key: 'credit_notes',
                  label: 'Credit notes',
                  count: statement.credit_notes.length,
              },
              {
                  key: 'payments',
                  label: 'Payments',
                  count: statement.payments.length,
              },
          ] as const)
        : [];

    // Client-side datatables (search + pagination) over each already-loaded section.
    // Hooks run unconditionally; empty arrays until the statement loads.
    const invoicesTable = useClientTable(statement?.invoices ?? [], (i) => [
        i.display_number,
        i.academic_context,
        i.status,
    ]);
    const creditsTable = useClientTable(statement?.credit_notes ?? [], (c) => [
        c.display_number,
        c.kind,
        c.status,
        c.note,
        c.rejection_reason,
    ]);
    const paymentsTable = useClientTable(statement?.payments ?? [], (p) => [
        p.payer_name,
        String(p.reference),
        p.method,
    ]);

    return (
        <>
            <Head title={`Statement — ${student.name}`} />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <Avatar className="size-12 shrink-0 overflow-hidden rounded-xl">
                                    <AvatarFallback className="rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 text-sm font-bold text-indigo-600 dark:from-indigo-950/50 dark:to-violet-950/50 dark:text-indigo-400">
                                        {getInitials(student.name)}
                                    </AvatarFallback>
                                </Avatar>
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
                                            {student.name}
                                        </h1>
                                    </div>
                                    <p className="text-xs text-slate-500">
                                        Finance statement · invoices, payments &
                                        credit
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
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
                                <Can permission="finance.payment.record">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setAccountPay(true)}
                                        className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                    >
                                        <Wallet className="mr-1.5 h-4 w-4" />
                                        Record payment
                                    </Button>
                                </Can>
                                <Can permission="finance.invoice.generate">
                                    <Button
                                        size="sm"
                                        onClick={() => setNewInvoiceOpen(true)}
                                        className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New invoice
                                    </Button>
                                </Can>
                            </div>
                        </div>
                    </div>

                    {/* ── Account position stat cards ──────────────────────────── */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FinanceStatCard
                            icon={Scale}
                            tone={
                                account && account.balance.amount_minor > 0
                                    ? 'amber'
                                    : 'slate'
                            }
                            label="Account balance"
                            value={account ? formatNaira(account.balance) : '—'}
                            subText="Positive means the student owes this"
                            loading={loading && !statement}
                        />
                        <FinanceStatCard
                            icon={Wallet}
                            tone="emerald"
                            label="Available credit"
                            value={
                                account
                                    ? formatNaira(account.available_credit)
                                    : '—'
                            }
                            subText="Carries forward to the next invoice"
                            loading={loading && !statement}
                        />
                        <FinanceStatCard
                            icon={Landmark}
                            tone="indigo"
                            label="Total billed"
                            value={
                                statement
                                    ? formatNaira(statement.billed_total)
                                    : '—'
                            }
                            subText="Across all issued invoices"
                            loading={loading && !statement}
                        />
                    </div>

                    {loading && !statement && (
                        <div className="flex justify-center py-10">
                            <Spinner />
                        </div>
                    )}

                    {error && (
                        <div className={cn(SECTION_CARD, 'p-8')}>
                            <div className="flex flex-col items-center gap-3 text-center">
                                <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                    <AlertCircle className="h-6 w-6" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        Could not load the statement
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        Something went wrong fetching the data.
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
                    )}

                    {/* ── Transaction tabs (Invoices / Credit notes / Payments) ── */}
                    {statement && (
                        <div className="flex flex-wrap gap-1 rounded-xl border-none bg-white p-1 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                            {txnTabs.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => setActiveTab(t.key)}
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-lg px-4 py-2 text-xs font-semibold transition-colors',
                                        activeTab === t.key
                                            ? 'bg-indigo-600 text-white shadow-sm'
                                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800',
                                    )}
                                >
                                    {t.label}
                                    <span
                                        className={cn(
                                            'rounded-full px-1.5 py-0.5 text-[10px] font-bold',
                                            activeTab === t.key
                                                ? 'bg-white/20 text-white'
                                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                                        )}
                                    >
                                        {t.count}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}

                    {/* ── Invoices ─────────────────────────────────────────────── */}
                    {statement && activeTab === 'invoices' && (
                        <div className={SECTION_CARD}>
                            {statement.invoices.length > 0 && (
                                <TableToolbar
                                    value={invoicesTable.search}
                                    onChange={invoicesTable.setSearch}
                                    shown={invoicesTable.paged.length}
                                    total={invoicesTable.filtered.length}
                                    placeholder="Search invoices…"
                                />
                            )}
                            <div className="custom-scrollbar overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className={HEAD_ROW}>
                                            <th className={TH}>Invoice</th>
                                            <th className={TH}>Context</th>
                                            <th className={TH}>Status</th>
                                            <th
                                                className={cn(TH, 'text-right')}
                                            >
                                                Total
                                            </th>
                                            <th
                                                className={cn(TH, 'text-right')}
                                            >
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {statement.invoices.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="py-10 text-center text-xs text-slate-400"
                                                >
                                                    No invoices yet.
                                                </td>
                                            </tr>
                                        ) : invoicesTable.filtered.length ===
                                          0 ? (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="py-10 text-center text-xs text-slate-400"
                                                >
                                                    No invoices match your
                                                    search.
                                                </td>
                                            </tr>
                                        ) : (
                                            invoicesTable.paged.map(
                                                (invoice) => (
                                                    <tr
                                                        key={invoice.id}
                                                        className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                    >
                                                        <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                            {
                                                                invoice.display_number
                                                            }
                                                        </td>
                                                        <td className="px-4 py-2.5 text-slate-500">
                                                            {
                                                                invoice.academic_context
                                                            }
                                                        </td>
                                                        <td className="px-4 py-2.5">
                                                            {/* Two ORTHOGONAL axes: the document
                                                                state (issued/void) and, beneath it,
                                                                the derived settlement state. Never
                                                                collapsed into one badge. */}
                                                            <div className="flex flex-col items-start gap-1">
                                                                <span
                                                                    className={cn(
                                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                                                                        invoice.status ===
                                                                            'void'
                                                                            ? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                                                            : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                                                    )}
                                                                >
                                                                    {
                                                                        invoice.status
                                                                    }
                                                                </span>
                                                                {invoice.settlement_state && (
                                                                    <span
                                                                        className={cn(
                                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                                                            SETTLEMENT_BADGE[
                                                                                invoice
                                                                                    .settlement_state
                                                                            ],
                                                                        )}
                                                                    >
                                                                        {
                                                                            SETTLEMENT_LABEL[
                                                                                invoice
                                                                                    .settlement_state
                                                                            ]
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                            {formatNaira(
                                                                invoice.total,
                                                            )}
                                                            {invoice.settlement_state &&
                                                                invoice.settlement_state !==
                                                                    'settled' && (
                                                                    <div className="text-[10px] font-medium text-slate-400 tabular-nums">
                                                                        {formatNaira(
                                                                            invoice.outstanding,
                                                                        )}{' '}
                                                                        outstanding
                                                                    </div>
                                                                )}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right">
                                                            {/* Per-button treatment (server owns
                                                                the rule): HIDE what is meaningless
                                                                (record payment once settled),
                                                                keep AVAILABLE what is a real
                                                                operation (credit note on a paid
                                                                invoice), DISABLE-WITH-REASON what a
                                                                rule forbids (void once settled).
                                                                Authority is a SEPARATE axis from the
                                                                rule: a control shows only when the
                                                                rule permits it AND the user holds the
                                                                ability (<Can>), so the flag answers
                                                                "is this payable" and the gate answers
                                                                "may you". finance.payment.record
                                                                (ADR 0048 D1) is the ability behind
                                                                both payment buttons. */}
                                                            {invoice.status !==
                                                                'void' && (
                                                                <div className="flex flex-wrap justify-end gap-1.5">
                                                                    {invoice.can_record_payment && (
                                                                        <Can permission="finance.payment.record">
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                onClick={() =>
                                                                                    setPayFor(
                                                                                        invoice,
                                                                                    )
                                                                                }
                                                                                className="h-7 rounded-lg text-xs"
                                                                            >
                                                                                Record
                                                                                payment
                                                                            </Button>
                                                                        </Can>
                                                                    )}
                                                                    {invoice.can_submit_credit_note && (
                                                                        <Can permission="finance.credit-note.submit">
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                onClick={() =>
                                                                                    setCreditFor(
                                                                                        invoice,
                                                                                    )
                                                                                }
                                                                                className="h-7 rounded-lg text-xs"
                                                                            >
                                                                                Submit
                                                                                credit
                                                                                note
                                                                            </Button>
                                                                        </Can>
                                                                    )}
                                                                    {pendingVoidDisplayNumbers.has(
                                                                        invoice.display_number,
                                                                    ) ? (
                                                                        <span className="inline-flex h-7 items-center rounded-lg bg-amber-50 px-2 text-xs font-semibold text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                                                                            Void
                                                                            requested
                                                                        </span>
                                                                    ) : (
                                                                        <Can permission="finance.invoice.void-request.submit">
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                disabled={
                                                                                    !invoice.can_request_void
                                                                                }
                                                                                title={
                                                                                    invoice.can_request_void
                                                                                        ? undefined
                                                                                        : (invoice.void_blocked_reason ??
                                                                                          undefined)
                                                                                }
                                                                                onClick={() =>
                                                                                    invoice.can_request_void &&
                                                                                    setVoidFor(
                                                                                        invoice,
                                                                                    )
                                                                                }
                                                                                className="h-7 rounded-lg text-xs"
                                                                            >
                                                                                Request
                                                                                void
                                                                            </Button>
                                                                        </Can>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ),
                                            )
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            {invoicesTable.filtered.length > 0 && (
                                <div className={PAGINATION_FOOTER}>
                                    <Pagination
                                        meta={invoicesTable.meta}
                                        setPage={invoicesTable.setPage}
                                        setLimit={invoicesTable.setLimit}
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    {/* ── Credit notes ─────────────────────────────────────────── */}
                    {statement && activeTab === 'credit_notes' && (
                        <div className={SECTION_CARD}>
                            <TableToolbar
                                value={creditsTable.search}
                                onChange={creditsTable.setSearch}
                                shown={creditsTable.paged.length}
                                total={creditsTable.filtered.length}
                                placeholder="Search credit notes…"
                            />
                            <div className="custom-scrollbar overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className={HEAD_ROW}>
                                            <th className={TH}>Document</th>
                                            <th className={TH}>Kind</th>
                                            <th className={TH}>Status</th>
                                            <th className={TH}>Note</th>
                                            <th
                                                className={cn(TH, 'text-right')}
                                            >
                                                Amount
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {creditsTable.filtered.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="py-10 text-center text-xs text-slate-400"
                                                >
                                                    {statement.credit_notes
                                                        .length === 0
                                                        ? 'No credit notes yet.'
                                                        : 'No credit notes match your search.'}
                                                </td>
                                            </tr>
                                        ) : (
                                            creditsTable.paged.map((credit) => (
                                                <tr
                                                    key={credit.id}
                                                    className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                >
                                                    <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                        {credit.display_number}
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        <span className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-900/30 dark:text-violet-400">
                                                            {credit.kind ===
                                                            'write_off'
                                                                ? 'write-off'
                                                                : 'credit note'}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        <span
                                                            className={cn(
                                                                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                                                                credit.status ===
                                                                    'approved'
                                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                                                    : credit.status ===
                                                                        'rejected'
                                                                      ? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                                                      : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                                            )}
                                                        >
                                                            {credit.status ===
                                                            'submitted'
                                                                ? 'Pending'
                                                                : credit.status}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-2.5 text-slate-500">
                                                        {credit.status ===
                                                            'rejected' &&
                                                        credit.rejection_reason
                                                            ? credit.rejection_reason
                                                            : (credit.note ??
                                                              '—')}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'px-4 py-2.5 text-right font-semibold tabular-nums',
                                                            credit.status ===
                                                                'approved'
                                                                ? 'text-slate-800 dark:text-slate-100'
                                                                : 'text-slate-400 line-through',
                                                        )}
                                                        title={
                                                            credit.status !==
                                                            'approved'
                                                                ? 'Not in the balance — only approved credit notes affect the account'
                                                                : undefined
                                                        }
                                                    >
                                                        {formatNaira(
                                                            credit.amount,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            {creditsTable.filtered.length > 0 && (
                                <div className={PAGINATION_FOOTER}>
                                    <Pagination
                                        meta={creditsTable.meta}
                                        setPage={creditsTable.setPage}
                                        setLimit={creditsTable.setLimit}
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    {/* ── Payments ─────────────────────────────────────────────── */}
                    {statement && activeTab === 'payments' && (
                        <div className={SECTION_CARD}>
                            <TableToolbar
                                value={paymentsTable.search}
                                onChange={paymentsTable.setSearch}
                                shown={paymentsTable.paged.length}
                                total={paymentsTable.filtered.length}
                                placeholder="Search payments…"
                            />
                            <div className="custom-scrollbar overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className={HEAD_ROW}>
                                            <th className={TH}>Payer</th>
                                            <th className={TH}>Reference</th>
                                            <th className={TH}>Method</th>
                                            <th className={TH}>Date</th>
                                            <th
                                                className={cn(TH, 'text-right')}
                                            >
                                                Amount
                                            </th>
                                            <th
                                                className={cn(TH, 'text-right')}
                                            >
                                                Unallocated
                                            </th>
                                            <th
                                                className={cn(TH, 'text-right')}
                                            >
                                                Receipt
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {paymentsTable.filtered.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={7}
                                                    className="py-10 text-center text-xs text-slate-400"
                                                >
                                                    {statement.payments
                                                        .length === 0
                                                        ? 'No payments yet.'
                                                        : 'No payments match your search.'}
                                                </td>
                                            </tr>
                                        ) : (
                                            paymentsTable.paged.map(
                                                (payment) => (
                                                    <tr
                                                        key={payment.id}
                                                        className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                    >
                                                        <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                            {payment.payer_name}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-slate-500">
                                                            #{payment.reference}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-slate-500 capitalize">
                                                            {payment.method}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-slate-500">
                                                            {new Date(
                                                                payment.created_at,
                                                            ).toLocaleDateString()}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right font-semibold text-emerald-600 tabular-nums dark:text-emerald-400">
                                                            {formatNaira(
                                                                payment.amount,
                                                            )}
                                                        </td>
                                                        {/*
                                                            U10 — what is still sitting on the
                                                            account, and the way to direct it.
                                                            The FIGURE is always shown, including
                                                            zero: an operator asking "did this
                                                            payment settle anything" is asking
                                                            about the rows that did not, and a
                                                            column that appears only sometimes
                                                            cannot answer them.

                                                            The LINK is gated on the server's own
                                                            eligibility flag below — never on JS
                                                            comparing amounts — and on holding
                                                            finance.payment.allocate, because
                                                            offering a control the server will
                                                            refuse teaches the operator that this
                                                            screen lies. It differs from the
                                                            receipt link beside it on purpose: a
                                                            receipt is refused for a property of
                                                            the ROW and the refusal is worth
                                                            reading, whereas a fully-allocated
                                                            payment has nothing to direct at all
                                                            and the figure beside it already says
                                                            so.
                                                        */}
                                                        <td className="px-4 py-2.5 text-right">
                                                            <div className="flex items-center justify-end gap-2">
                                                                <span className="font-semibold text-slate-500 tabular-nums">
                                                                    {formatNaira(
                                                                        payment.unallocated,
                                                                    )}
                                                                </span>
                                                                {payment.can_allocate && (
                                                                    <Can permission="finance.payment.allocate">
                                                                        <Link
                                                                            href={`/finance/payments/${payment.id}/allocate`}
                                                                            className="font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                                                        >
                                                                            Allocate
                                                                        </Link>
                                                                    </Can>
                                                                )}
                                                            </div>
                                                        </td>
                                                        {/*
                                                            U11 — the receipt entry point. It is
                                                            NEVER hidden and never disabled, not
                                                            even for a payment the receipt route
                                                            will refuse: "never silently hide the
                                                            row" is the opening-balance spec's
                                                            wording, and an operator who cannot
                                                            find the link learns nothing. A row
                                                            the server will refuse says so HERE
                                                            as well, from the server's own
                                                            `receipt_refusal_reason`, so the rule
                                                            is readable without navigating — and
                                                            the page states it again in full. The
                                                            server-side branch in
                                                            PaymentReceiptController is the
                                                            control; this is the courtesy.
                                                        */}
                                                        <td className="px-4 py-2.5 text-right">
                                                            <div className="flex items-center justify-end gap-2">
                                                                {!payment.receiptable && (
                                                                    <span
                                                                        className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/20 dark:text-amber-400"
                                                                        title={
                                                                            payment.receipt_refusal_reason ??
                                                                            undefined
                                                                        }
                                                                    >
                                                                        Not
                                                                        issued
                                                                        here
                                                                    </span>
                                                                )}
                                                                <Link
                                                                    href={receiptUrl.url(
                                                                        payment.id,
                                                                    )}
                                                                    className="font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                                                >
                                                                    Receipt
                                                                </Link>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ),
                                            )
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            {paymentsTable.filtered.length > 0 && (
                                <div className={PAGINATION_FOOTER}>
                                    <Pagination
                                        meta={paymentsTable.meta}
                                        setPage={paymentsTable.setPage}
                                        setLimit={paymentsTable.setLimit}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <NewInvoiceModal
                isOpen={newInvoiceOpen}
                onClose={() => setNewInvoiceOpen(false)}
                student={student}
                onCreated={() => void load()}
            />
            <RecordPaymentModal
                isOpen={payFor !== null || accountPay}
                onClose={() => {
                    setPayFor(null);
                    setAccountPay(false);
                }}
                invoice={payFor}
                student={accountPay ? student : null}
                onRecorded={() => void load()}
            />
            <IssueCreditNoteModal
                isOpen={creditFor !== null}
                onClose={() => setCreditFor(null)}
                invoice={creditFor}
                onIssued={() => void load()}
            />
            <RequestVoidModal
                isOpen={voidFor !== null}
                onClose={() => setVoidFor(null)}
                invoice={voidFor}
                onRequested={() => void load()}
            />
        </>
    );
}

FinanceStatement.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
    ],
};
