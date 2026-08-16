import { Head } from '@inertiajs/react';
import { ArrowLeft, Ban, Printer, Receipt as ReceiptIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { handleBack } from '@/helpers';
import { formatNaira } from '@/lib/format';
import type { Money } from '@/types/finance';

/**
 * U11 — the printable payment receipt. ONE payment, one page.
 *
 * A PURE PROP CONSUMER: everything on this page arrives with the page from
 * PaymentReceiptController. There is no fetch here, and that is the point rather than an
 * accident — design system §26's most-repeated defect is a client-fetched screen whose four
 * states collapse into two and which then makes a confident false statement (five instances).
 * A document that arrives with its own page has no loading, error or empty state to collapse:
 * either this rendered from real props or the navigation itself failed and this component was
 * never mounted. The one branch below is not a state, it is a RULE — the migrated-payment
 * refusal, decided by the server, sent as `refusal`.
 *
 * NO ARITHMETIC, NOT EVEN A COMPARISON. Every figure is rendered through formatNaira and
 * nothing is summed, subtracted or compared here: the server sends `allocated_total`,
 * `unallocated_amount`, and the three booleans (`fully_applied`, `held_on_account`,
 * `nothing_applied`) that decide which sentence this page states. This file sits inside
 * resources/js/pages/admin/finance/, where bin/ci-money-lint.php's format ban is TOTAL.
 *
 * WHAT PRINTING REMOVES, stated explicitly because a printable page that prints the app
 * chrome is not printable — see PRINT_STYLES below.
 */

type Allocation = {
    invoice_number: string | null;
    academic_context: string | null;
    amount: Money;
    applied_on_receipt: boolean;
};

type Props = {
    school: {
        name: string;
        address: string | null;
        phone: string | null;
        email: string | null;
    };
    reference: number;
    receipt: {
        received_at: string;
        recorded_at: string;
        payer_name: string;
        method: string;
        amount: Money;
        student_name: string | null;
        bank_account: { label: string; bank_name: string } | null;
        allocations: Allocation[];
        allocated_total: Money;
        unallocated_amount: Money;
        fully_applied: boolean;
        held_on_account: boolean;
        nothing_applied: boolean;
    } | null;
    refusal: string | null;
};

/**
 * WHAT DISAPPEARS WHEN THIS PAGE IS PRINTED, and what must survive.
 *
 * GONE — every part of the application rather than of the document:
 *   • the sidebar and its rail/trigger ([data-slot="sidebar"] and friends), and the inset's
 *     margin, rounding and shadow, which exist to float the content area inside the shell;
 *   • the breadcrumb header (AppSidebarHeader already carries `print:hidden`, kept here as
 *     well so this page does not depend on a class in a file it does not own);
 *   • both toast layers — sonner's [data-sonner-toaster] and react-toastify's .Toastify —
 *     which are mounted at body level on every page by AppLayout;
 *   • the impersonation banner;
 *   • this page's own toolbar: Back, and the Print button itself.
 *
 * SURVIVES — the document and nothing else: the school block, the receipt number, both dates,
 * the payer, the student, the method and bank account, the amount, and the whole "what this
 * paid for" section including the allocation table and the account sentence. The refusal, when
 * this is a refused receipt, survives too: printing a blank sheet would be worse than printing
 * the reason.
 *
 * COLOUR IS FORCED BACK TO LIGHT inside the document. Browsers drop backgrounds when printing
 * but keep text colour, so a page printed from DARK MODE would otherwise put `dark:text-white`
 * on white paper — an invisible receipt, and precisely the class of defect §26 records (it
 * renders, it returns 200, and it cannot be used). The `!important` rules below are the fix and
 * they are scoped to `.receipt-document`.
 */
const PRINT_STYLES = `
@media print {
    @page {
        size: A4;
        margin: 14mm;
    }
    html, body {
        background: #fff !important;
    }
    [data-slot="sidebar"],
    [data-slot="sidebar-rail"],
    [data-slot="sidebar-trigger"],
    [data-sonner-toaster],
    .Toastify,
    .receipt-screen-only {
        display: none !important;
    }
    [data-slot="sidebar-inset"] {
        margin: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        overflow: visible !important;
    }
    .receipt-canvas {
        background: #fff !important;
        min-height: 0 !important;
        padding: 0 !important;
    }
    .receipt-document {
        max-width: none !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    .receipt-document,
    .receipt-document * {
        color: #0f172a !important;
        background: transparent !important;
        border-color: #cbd5e1 !important;
    }
    .receipt-document .receipt-muted,
    .receipt-document .receipt-muted * {
        color: #475569 !important;
    }
    .receipt-document tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}
`;

const LABEL =
    'receipt-muted text-[10px] font-bold tracking-wide text-slate-400 uppercase';
const VALUE = 'text-sm font-semibold text-slate-900 dark:text-white';
const TH =
    'receipt-muted px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className={LABEL}>{label}</p>
            <p className={`${VALUE} mt-1`}>{value}</p>
        </div>
    );
}

export default function PaymentReceipt({
    school,
    reference,
    receipt,
    refusal,
}: Props) {
    return (
        <>
            <Head title={`Receipt #${reference}`} />
            <style>{PRINT_STYLES}</style>

            <div className="receipt-canvas min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-3xl space-y-5">
                    {/* Toolbar — application, not document. Removed by PRINT_STYLES. */}
                    <div className="receipt-screen-only flex items-center justify-between gap-3">
                        <Button
                            size="sm"
                            variant="outline"
                            className="rounded-lg font-semibold"
                            onClick={handleBack}
                        >
                            <ArrowLeft className="mr-1.5 h-4 w-4" /> Back
                        </Button>
                        {receipt && (
                            <Button
                                size="sm"
                                className="rounded-lg font-semibold"
                                onClick={() => window.print()}
                            >
                                <Printer className="mr-1.5 h-4 w-4" /> Print
                            </Button>
                        )}
                    </div>

                    {refusal !== null ? (
                        /*
                         * THE REFUSAL. Rendered from the server's `refusal` string — this file holds
                         * no copy of the rule and cannot drift from it. The row is never hidden on
                         * the statement, so an operator reaches this page and reads why.
                         */
                        <div className="receipt-document overflow-hidden rounded-2xl border border-white bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                            <div className="flex items-start gap-4 px-6 py-6">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-amber-50 ring-1 ring-black/5 dark:bg-amber-950/40">
                                    <Ban className="h-6 w-6 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div className="space-y-2">
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        No receipt for payment #{reference}
                                    </h1>
                                    <p className="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                        {refusal}
                                    </p>
                                    <p className="receipt-muted text-xs text-slate-500">
                                        {school.name}
                                    </p>
                                </div>
                            </div>
                        </div>
                    ) : receipt === null ? null : (
                        <div className="receipt-document overflow-hidden rounded-2xl border border-white bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                            {/* ── The school, and what this document is ─────────────── */}
                            <div className="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
                                <div className="flex items-center gap-4">
                                    <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                        <ReceiptIcon className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <div>
                                        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            {school.name}
                                        </h1>
                                        {school.address && (
                                            <p className="receipt-muted text-xs text-slate-500">
                                                {school.address}
                                            </p>
                                        )}
                                        {(school.phone || school.email) && (
                                            <p className="receipt-muted text-xs text-slate-500">
                                                {[school.phone, school.email]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="sm:text-right">
                                    <p className={LABEL}>Payment receipt</p>
                                    <p className="mt-1 text-lg font-extrabold text-slate-900 tabular-nums dark:text-white">
                                        #{reference}
                                    </p>
                                </div>
                            </div>

                            {/* ── Who, what, how ────────────────────────────────────── */}
                            <div className="grid grid-cols-1 gap-5 px-6 py-5 sm:grid-cols-2">
                                <Field
                                    label="Received from"
                                    value={receipt.payer_name}
                                />
                                <Field
                                    label="On behalf of"
                                    value={receipt.student_name ?? '—'}
                                />
                                <Field
                                    label="Date received"
                                    value={receipt.received_at}
                                />
                                <Field
                                    label="Recorded"
                                    value={receipt.recorded_at}
                                />
                                <Field label="Method" value={receipt.method} />
                                <Field
                                    label="Paid into"
                                    value={
                                        receipt.bank_account
                                            ? `${receipt.bank_account.label} · ${receipt.bank_account.bank_name}`
                                            : '—'
                                    }
                                />
                            </div>

                            {/* ── The amount ────────────────────────────────────────── */}
                            <div className="flex items-baseline justify-between gap-4 border-y border-slate-100 bg-slate-50/50 px-6 py-5 dark:border-slate-800 dark:bg-slate-900/30">
                                <p className={LABEL}>Amount received</p>
                                <p className="text-2xl font-extrabold text-emerald-600 tabular-nums dark:text-emerald-400">
                                    {formatNaira(receipt.amount)}
                                </p>
                            </div>

                            {/* ── What the money was applied to ─────────────────────── */}
                            <div className="px-6 py-5">
                                <p className={LABEL}>What this paid for</p>

                                {receipt.nothing_applied ? (
                                    <p className="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                        This payment was received onto{' '}
                                        {receipt.student_name ?? 'the student'}
                                        ’s account and has not been applied to
                                        any invoice. The full amount of{' '}
                                        {formatNaira(
                                            receipt.unallocated_amount,
                                        )}{' '}
                                        is held as credit on the account and
                                        will be applied to the next invoice
                                        raised.
                                    </p>
                                ) : (
                                    <>
                                        <div className="custom-scrollbar mt-3 overflow-x-auto">
                                            <table className="w-full text-xs">
                                                <thead>
                                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                                        <th className={TH}>
                                                            Invoice
                                                        </th>
                                                        <th className={TH}>
                                                            Period
                                                        </th>
                                                        <th
                                                            className={`${TH} text-right`}
                                                        >
                                                            Applied
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                                    {receipt.allocations.map(
                                                        (a, i) => (
                                                            <tr key={i}>
                                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                                    {a.invoice_number ??
                                                                        '—'}
                                                                    {!a.applied_on_receipt && (
                                                                        <span className="receipt-muted ml-2 text-[10px] font-medium text-slate-500">
                                                                            (credit
                                                                            applied
                                                                            to a
                                                                            later
                                                                            invoice)
                                                                        </span>
                                                                    )}
                                                                </td>
                                                                <td className="receipt-muted px-4 py-2.5 text-slate-500">
                                                                    {a.academic_context ??
                                                                        '—'}
                                                                </td>
                                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-900 tabular-nums dark:text-white">
                                                                    {formatNaira(
                                                                        a.amount,
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                                <tfoot>
                                                    <tr className="border-t border-slate-100 dark:border-slate-800">
                                                        <td
                                                            className="receipt-muted px-4 py-2.5 text-[10px] font-bold tracking-wide text-slate-400 uppercase"
                                                            colSpan={2}
                                                        >
                                                            Applied to invoices
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right font-extrabold text-slate-900 tabular-nums dark:text-white">
                                                            {formatNaira(
                                                                receipt.allocated_total,
                                                            )}
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>

                                        {receipt.held_on_account && (
                                            <p className="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                                The remaining{' '}
                                                {formatNaira(
                                                    receipt.unallocated_amount,
                                                )}{' '}
                                                is held as credit on{' '}
                                                {receipt.student_name ??
                                                    'the student'}
                                                ’s account and will be applied
                                                to the next invoice raised.
                                            </p>
                                        )}

                                        {receipt.fully_applied && (
                                            <p className="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                                The full amount has been applied
                                                to the invoices above. Nothing
                                                is held on the account from this
                                                payment.
                                            </p>
                                        )}
                                    </>
                                )}
                            </div>

                            <div className="receipt-muted border-t border-slate-100 px-6 py-4 text-[11px] text-slate-500 dark:border-slate-800">
                                Issued by {school.name}. This receipt records
                                one payment and is valid without a signature.
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

PaymentReceipt.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Receipt', href: '#' },
    ],
};
