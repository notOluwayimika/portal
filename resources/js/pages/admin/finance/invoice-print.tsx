import { Head } from '@inertiajs/react';
import { ArrowLeft, FileText, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { handleBack } from '@/helpers';
import { INVOICE_KIND_LABEL } from '@/lib/finance/invoice-kind';
import { formatNaira } from '@/lib/format';
import type { Invoice } from '@/types/finance';

/**
 * U7 — THE PRINTABLE INVOICE. One invoice, one page, no controls on the paper.
 *
 * "PDF download" in the MVP cut means a printable page and not a server-side PDF library: the
 * receipt (U11) settled that, and this follows it in shape and in reasoning — a PURE PROP CONSUMER
 * with no fetch, no loading state and no arithmetic, whose figures are all computed in PHP and
 * rendered through formatNaira.
 *
 * ── WHAT IT DOES NOT REFUSE, AND WHY THAT IS A FINDING RATHER THAN A GAP ──
 *
 * The receipt refuses `origin = 'migrated'`: WCBS collected that money, and printing this system's
 * receipt for it would be this system claiming an act it did not perform. THERE IS NO INVOICE-SIDE
 * EQUIVALENT to refuse. `finance_invoices` carries no origin or provenance column, and the
 * opening-balance import — the only writer of `origin = 'migrated'` anywhere — raises NO INVOICE by
 * rule (PostOpeningBalanceBatch step 3 / R6). Every row in that table was issued by this system, so
 * a migrated branch here would match zero rows now and forever. The controller's docblock carries
 * the full argument; this note exists so nobody adds the branch believing it was forgotten.
 *
 * WHAT AN INVOICE DOES HAVE IS A VOID, and this page MARKS it rather than refusing to print it. The
 * reader who needs an invoice on paper after it was voided is the one reconciling why the charge is
 * gone; refusing them the document helps nobody. Printing it silently as a live demand for payment
 * is the failure to avoid, and the banner below is what avoids it.
 */

type Props = {
    school: {
        name: string;
        address: string | null;
        phone: string | null;
        email: string | null;
    };
    invoice: Invoice;
    student: { uuid: string | null; name: string };
    issued_at: string;
    voided_at: string | null;
};

/**
 * WHAT DISAPPEARS WHEN THIS PAGE IS PRINTED — the same set as the receipt's, for the same reasons,
 * and read from that file rather than re-derived: the sidebar and its rail/trigger, the inset's
 * margin and shadow, the breadcrumb header (`[data-slot="sidebar-inset"] > header`, scoped to the
 * inset's DIRECT child so a `<header>` inside the document is not caught), the three body-level
 * overlay layers this page does not control (sonner, react-toastify, sweetalert2), and this page's
 * own Back/Print toolbar.
 *
 * ONE DEPENDENCY IS LEFT STANDING AND IS NAMED: the impersonation banner is removed by
 * `print:hidden` on impersonation-banner.tsx, not by anything here. That is correct for every
 * printable page in the application, so duplicating a selector for it in this file would be the
 * wrong fix. If that class is ever removed, this page prints the banner.
 *
 * COLOUR IS FORCED BACK TO LIGHT inside `.invoice-document`. Browsers drop backgrounds when
 * printing but keep text colour, so a page printed from DARK MODE would put `dark:text-white` on
 * white paper — an invisible invoice, which renders, returns 200, and cannot be used.
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
    [data-slot="sidebar-inset"] > header,
    [data-sonner-toaster],
    .Toastify,
    .swal2-container,
    .invoice-screen-only {
        display: none !important;
    }
    [data-slot="sidebar-inset"] {
        margin: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        overflow: visible !important;
    }
    .invoice-canvas {
        background: #fff !important;
        min-height: 0 !important;
        padding: 0 !important;
    }
    .invoice-document {
        max-width: none !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    .invoice-document,
    .invoice-document * {
        color: #0f172a !important;
        background: transparent !important;
        border-color: #cbd5e1 !important;
    }
    .invoice-document .invoice-muted,
    .invoice-document .invoice-muted * {
        color: #475569 !important;
    }
    .invoice-document tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}
`;

const LABEL =
    'invoice-muted text-[10px] font-bold tracking-wide text-slate-400 uppercase';
const VALUE = 'text-sm font-semibold text-slate-900 dark:text-white';
const TH =
    'invoice-muted px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';

const LINE_KIND_LABEL: Record<string, string> = {
    charge: 'Charge',
    waiver: 'Waiver',
    discount: 'Discount',
};

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className={LABEL}>{label}</p>
            <p className={`${VALUE} mt-1`}>{value}</p>
        </div>
    );
}

export default function InvoicePrint({
    school,
    invoice,
    student,
    issued_at,
    voided_at,
}: Props) {
    const isVoid = invoice.status === 'void';

    return (
        <>
            <Head
                title={`${INVOICE_KIND_LABEL[invoice.kind]} ${invoice.display_number}`}
            />
            <style>{PRINT_STYLES}</style>

            <div className="invoice-canvas min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-3xl space-y-5">
                    {/* Toolbar — application, not document. Removed by PRINT_STYLES. */}
                    <div className="invoice-screen-only flex items-center justify-between gap-3">
                        <Button
                            size="sm"
                            variant="outline"
                            className="rounded-lg font-semibold"
                            onClick={handleBack}
                        >
                            <ArrowLeft className="mr-1.5 h-4 w-4" /> Back
                        </Button>
                        <Button
                            size="sm"
                            className="rounded-lg font-semibold"
                            onClick={() => window.print()}
                        >
                            <Printer className="mr-1.5 h-4 w-4" /> Print
                        </Button>
                    </div>

                    <div className="invoice-document overflow-hidden rounded-2xl border border-white bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        {/* ── The school, and what this document is ─────────── */}
                        <div className="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <FileText className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        {school.name}
                                    </h1>
                                    {school.address && (
                                        <p className="invoice-muted text-xs text-slate-500">
                                            {school.address}
                                        </p>
                                    )}
                                    {(school.phone || school.email) && (
                                        <p className="invoice-muted text-xs text-slate-500">
                                            {[school.phone, school.email]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="sm:text-right">
                                {/*
                                 * THE KIND IS THE DOCUMENT'S OWN NAME ON THE PAPER, not a badge
                                 * beside it. A printed sheet has no hover, no tooltip and no second
                                 * screen to check against, so the one line naming what this is has
                                 * to say which of the two it is.
                                 */}
                                <p className={LABEL}>
                                    {INVOICE_KIND_LABEL[invoice.kind]}
                                </p>
                                <p className="mt-1 text-lg font-extrabold text-slate-900 tabular-nums dark:text-white">
                                    {invoice.display_number}
                                </p>
                            </div>
                        </div>

                        {/* ── The void, stated on the paper ─────────────────── */}
                        {isVoid && (
                            <div className="border-b border-slate-100 bg-slate-50/60 px-6 py-4 dark:border-slate-800 dark:bg-slate-900/30">
                                <p className="text-sm font-extrabold tracking-wide text-slate-800 uppercase dark:text-slate-100">
                                    Voided — not payable
                                </p>
                                <p className="invoice-muted mt-1 text-xs text-slate-600">
                                    This invoice was voided
                                    {voided_at ? ` on ${voided_at}` : ''} and
                                    its charge reversed in the ledger. It is
                                    reproduced here as a record and is not a
                                    demand for payment.
                                    {invoice.cancel_reason
                                        ? ` Reason: ${invoice.cancel_reason}`
                                        : ''}
                                </p>
                            </div>
                        )}

                        {/* ── Who and when ──────────────────────────────────── */}
                        <div className="grid grid-cols-1 gap-5 px-6 py-5 sm:grid-cols-2">
                            <Field label="Billed to" value={student.name} />
                            <Field label="Issued" value={issued_at} />
                            <Field
                                label="Period"
                                value={invoice.academic_context}
                            />
                            <Field
                                label="Document"
                                value={INVOICE_KIND_LABEL[invoice.kind]}
                            />
                        </div>

                        {/* ── Lines ─────────────────────────────────────────── */}
                        <div className="custom-scrollbar overflow-x-auto border-t border-slate-100 dark:border-slate-800">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className={TH}>Description</th>
                                        <th className={TH}>Type</th>
                                        <th className={`${TH} text-right`}>
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {(invoice.lines ?? []).map((line) => (
                                        <tr key={line.id}>
                                            <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                {line.description}
                                                {line.note && (
                                                    <span className="invoice-muted block text-[10px] font-medium text-slate-500">
                                                        {line.note}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="invoice-muted px-4 py-2.5 text-slate-500">
                                                {LINE_KIND_LABEL[line.kind] ??
                                                    line.kind}
                                            </td>
                                            <td className="px-4 py-2.5 text-right font-semibold text-slate-900 tabular-nums dark:text-white">
                                                {formatNaira(line.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-100 dark:border-slate-800">
                                        <td
                                            className="invoice-muted px-4 py-2.5 text-[10px] font-bold tracking-wide text-slate-400 uppercase"
                                            colSpan={2}
                                        >
                                            Total
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-extrabold text-slate-900 tabular-nums dark:text-white">
                                            {formatNaira(invoice.total)}
                                        </td>
                                    </tr>
                                    {/*
                                     * OUTSTANDING PRINTS ONLY WHERE IT MEANS SOMETHING. A voided
                                     * invoice has no settlement state at all (InvoiceSettlement
                                     * returns null), so this row is absent rather than showing a
                                     * zero that would read as "paid in full".
                                     */}
                                    {invoice.settlement_state && (
                                        <tr>
                                            <td
                                                className="invoice-muted px-4 py-2.5 text-[10px] font-bold tracking-wide text-slate-400 uppercase"
                                                colSpan={2}
                                            >
                                                Outstanding
                                            </td>
                                            <td className="px-4 py-2.5 text-right font-extrabold text-slate-900 tabular-nums dark:text-white">
                                                {formatNaira(
                                                    invoice.outstanding,
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </tfoot>
                            </table>
                        </div>

                        <div className="invoice-muted border-t border-slate-100 px-6 py-4 text-[11px] text-slate-500 dark:border-slate-800">
                            Issued by {school.name}. Amounts are stated as at
                            the moment this page was produced; the account
                            statement is the authority on what is owed today.
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

InvoicePrint.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Invoice', href: '#' },
    ],
};
