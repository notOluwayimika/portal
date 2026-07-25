import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    ArrowLeft,
    FileText,
    Landmark,
    Plus,
    ReceiptText,
    RefreshCw,
    Scale,
    Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { forStudent } from '@/actions/App/Finance/Http/Controllers/InvoiceController';
import { Can } from '@/components/can';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { IssueCreditNoteModal } from '@/components/finance/issue-credit-note-modal';
import { NewInvoiceModal } from '@/components/finance/new-invoice-modal';
import { RecordPaymentModal } from '@/components/finance/record-payment-modal';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { formatNaira } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Invoice, Statement } from '@/types/finance';

type Props = {
    student: { uuid: string; name: string };
};

const SECTION_CARD =
    'overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card';
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
    const [creditFor, setCreditFor] = useState<Invoice | null>(null);
    const [newInvoiceOpen, setNewInvoiceOpen] = useState(false);

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
                                <Button
                                    size="sm"
                                    onClick={() => setNewInvoiceOpen(true)}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New invoice
                                </Button>
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

                    {/* ── Invoices ─────────────────────────────────────────────── */}
                    {statement && (
                        <div className={SECTION_CARD}>
                            <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                                <FileText className="h-4 w-4 text-slate-400" />
                                <h2 className="text-sm font-bold text-slate-700 dark:text-slate-200">
                                    Invoices
                                </h2>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    {statement.invoices.length}
                                </span>
                            </div>
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
                                        ) : (
                                            statement.invoices.map(
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
                                                            <span
                                                                className={cn(
                                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                                                                    invoice.status ===
                                                                        'void'
                                                                        ? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                                                        : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                                                )}
                                                            >
                                                                {invoice.status}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                            {formatNaira(
                                                                invoice.total,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right">
                                                            {invoice.status !==
                                                                'void' && (
                                                                <div className="flex justify-end gap-1.5">
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
                        </div>
                    )}

                    {/* ── Credit notes ─────────────────────────────────────────── */}
                    {statement && statement.credit_notes.length > 0 && (
                        <div className={SECTION_CARD}>
                            <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                                <ReceiptText className="h-4 w-4 text-slate-400" />
                                <h2 className="text-sm font-bold text-slate-700 dark:text-slate-200">
                                    Credit notes
                                </h2>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    {statement.credit_notes.length}
                                </span>
                            </div>
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
                                        {statement.credit_notes.map(
                                            (credit) => (
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
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* ── Payments ─────────────────────────────────────────────── */}
                    {statement && statement.payments.length > 0 && (
                        <div className={SECTION_CARD}>
                            <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                                <Wallet className="h-4 w-4 text-slate-400" />
                                <h2 className="text-sm font-bold text-slate-700 dark:text-slate-200">
                                    Payments
                                </h2>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    {statement.payments.length}
                                </span>
                            </div>
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
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {statement.payments.map((payment) => (
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
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
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
                isOpen={payFor !== null}
                onClose={() => setPayFor(null)}
                invoice={payFor}
                onRecorded={() => void load()}
            />
            <IssueCreditNoteModal
                isOpen={creditFor !== null}
                onClose={() => setCreditFor(null)}
                invoice={creditFor}
                onIssued={() => void load()}
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
