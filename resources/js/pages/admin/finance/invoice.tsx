import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, FileText, Printer } from 'lucide-react';
import { useState } from 'react';
import { Can } from '@/components/can';
import { IssueCreditNoteModal } from '@/components/finance/issue-credit-note-modal';
import { RecordPaymentModal } from '@/components/finance/record-payment-modal';
import { RequestVoidModal } from '@/components/finance/request-void-modal';
import { Button } from '@/components/ui/button';
import {
    INVOICE_KIND_BADGE,
    INVOICE_KIND_LABEL,
} from '@/lib/finance/invoice-kind';
import { formatNaira } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Invoice } from '@/types/finance';

/**
 * U7 — ONE INVOICE, the interactive screen. The printable document is its own page
 * (admin/finance/invoice-print), reached from the toolbar here.
 *
 * A PURE PROP CONSUMER. Everything arrives with the page from InvoiceDetailController; there is no
 * fetch, and the reason is the receipt's (design system §26): a client-fetched screen has four
 * states, this project's most-repeated defect is two of them collapsing into one confident false
 * statement, and a page whose data arrives in the same response has no loading, error or empty
 * state to collapse. After a successful action the modals call `router.reload()`, which re-runs the
 * controller — so what is on screen afterwards is the SERVER's account of the invoice, never a
 * client-side patch of the object that was there before.
 *
 * NO ARITHMETIC AND NO ELIGIBILITY DERIVED HERE. Every figure is rendered through formatNaira and
 * nothing is summed or compared; every control is decided by a server flag — `can_record_payment`,
 * `can_submit_credit_note`, `can_request_void` / `void_blocked_reason` on the invoice itself, and
 * `has_pending_void` in the props. The `<Can>` gates are the SEPARATE authority axis and are
 * convenience: the API route behind each button is the guard.
 *
 * DATES ARRIVE FORMATTED. bin/ci-money-lint.php's format ban is TOTAL inside this directory — every
 * number here is treated as money — so `toLocaleString` is a lint finding, and the controller
 * renders `voided_at` and the trail's timestamps in PHP.
 *
 * A VOIDED INVOICE OPENS HERE and states its void; it does not 404. Voidness is a named scope and
 * never a global one so that `{invoice:uuid}` binding does not miss a voided row, and the void
 * trail is what the person opening one has come to read.
 */

type VoidTrailEntry = {
    id: string;
    status: string;
    reason: string | null;
    submitted_by_name: string | null;
    submitted_at: string;
    decided_at: string | null;
    rejection_reason: string | null;
};

type Props = {
    invoice: Invoice;
    student: { uuid: string | null; name: string };
    voided_at: string | null;
    void_trail: VoidTrailEntry[];
    has_pending_void: boolean;
};

const SECTION_CARD =
    'overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card';
const LABEL = 'text-[10px] font-bold tracking-wide text-slate-400 uppercase';
const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';
const HEAD_ROW =
    'border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30';
const BADGE =
    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold';

// The settlement axis, presented exactly as the statement presents it — the same words and the
// same colours, because two finance screens naming one state differently is how an operator
// believes an invoice is in a state it is not.
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

// A line's own kind — charge / waiver / discount, and NOT the invoice's. The two words are
// unrelated and both are on this page; new-invoice-modal.tsx records the same trap.
const LINE_KIND_LABEL: Record<string, string> = {
    charge: 'Charge',
    waiver: 'Waiver',
    discount: 'Discount',
};

const VOID_STATUS_LABEL: Record<string, string> = {
    submitted: 'Awaiting approval',
    approved: 'Approved',
    rejected: 'Rejected',
};

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className={LABEL}>{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                {value}
            </p>
        </div>
    );
}

export default function InvoiceDetail({
    invoice,
    student,
    voided_at,
    void_trail,
    has_pending_void,
}: Props) {
    const [payOpen, setPayOpen] = useState(false);
    const [creditOpen, setCreditOpen] = useState(false);
    const [voidOpen, setVoidOpen] = useState(false);

    // THE SERVER RE-ANSWERS. Not a local mutation of `invoice` — every one of these actions changes
    // settlement, eligibility or the void trail, and all three are derived server-side.
    const refresh = () => router.reload();

    const isVoid = invoice.status === 'void';

    return (
        <>
            <Head
                title={`${INVOICE_KIND_LABEL[invoice.kind]} ${invoice.display_number}`}
            />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-4xl space-y-5">
                    {/* ── Toolbar ───────────────────────────────────────────── */}
                    <div className="flex items-center justify-between gap-3">
                        {student.uuid ? (
                            <Link
                                href={`/finance/students/${student.uuid}/statement`}
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg font-semibold"
                                >
                                    <ArrowLeft className="mr-1.5 h-4 w-4" />{' '}
                                    Statement
                                </Button>
                            </Link>
                        ) : (
                            <Link href="/finance">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg font-semibold"
                                >
                                    <ArrowLeft className="mr-1.5 h-4 w-4" />{' '}
                                    Finance
                                </Button>
                            </Link>
                        )}
                        {/*
                         * THE PRINTABLE VIEW. Offered on a voided invoice too, and that is a rule
                         * rather than an oversight: the document is what someone reconciling a
                         * reversed charge needs on paper, and the printed page states the void.
                         */}
                        <Link href={`/finance/invoices/${invoice.id}/print`}>
                            <Button
                                size="sm"
                                className="rounded-lg font-semibold"
                            >
                                <Printer className="mr-1.5 h-4 w-4" /> Print
                            </Button>
                        </Link>
                    </div>

                    {/* ── The invoice ───────────────────────────────────────── */}
                    <div className={SECTION_CARD}>
                        <div className="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <FileText className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div className="space-y-1.5">
                                    {/*
                                     * THREE BADGES, THREE AXES, NEVER COLLAPSED. Kind is what the
                                     * document IS (term bill / supplementary charge); status is
                                     * whether it is live (issued / void); settlement is what has
                                     * been paid against it. The statement renders the second and
                                     * third the same way; the first is what this branch adds and
                                     * what the ticket is about.
                                     */}
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span
                                            className={cn(
                                                BADGE,
                                                INVOICE_KIND_BADGE[
                                                    invoice.kind
                                                ],
                                            )}
                                        >
                                            {INVOICE_KIND_LABEL[invoice.kind]}
                                        </span>
                                        <span
                                            className={cn(
                                                BADGE,
                                                'capitalize',
                                                isVoid
                                                    ? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                                    : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                            )}
                                        >
                                            {invoice.status}
                                        </span>
                                        {invoice.settlement_state && (
                                            <span
                                                className={cn(
                                                    BADGE,
                                                    SETTLEMENT_BADGE[
                                                        invoice.settlement_state
                                                    ],
                                                )}
                                            >
                                                {
                                                    SETTLEMENT_LABEL[
                                                        invoice.settlement_state
                                                    ]
                                                }
                                            </span>
                                        )}
                                    </div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        {invoice.display_number}
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        {invoice.billed_to_name} ·{' '}
                                        {invoice.academic_context}
                                    </p>
                                </div>
                            </div>
                            <div className="sm:text-right">
                                <p className={LABEL}>Total</p>
                                <p className="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums dark:text-white">
                                    {formatNaira(invoice.total)}
                                </p>
                                {/*
                                 * OUTSTANDING IS SUPPRESSED ON A VOID, not zeroed. A voided
                                 * invoice has no meaningful settlement — its charge is reversed —
                                 * and InvoiceSettlement returns null for exactly that reason.
                                 * Rendering "₦0.00 outstanding" would state that it was paid.
                                 */}
                                {invoice.settlement_state && (
                                    <p className="mt-0.5 text-xs font-medium text-slate-400 tabular-nums">
                                        {formatNaira(invoice.outstanding)}{' '}
                                        outstanding
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* ── The void, stated ──────────────────────────────── */}
                        {isVoid && (
                            <div className="flex items-start gap-3 border-b border-slate-100 bg-slate-50/60 px-6 py-4 dark:border-slate-800 dark:bg-slate-900/30">
                                <Ban className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
                                <div className="space-y-0.5 text-xs">
                                    <p className="font-semibold text-slate-700 dark:text-slate-200">
                                        This invoice was voided
                                        {voided_at ? ` on ${voided_at}` : ''}.
                                        Its charge has been reversed in the
                                        ledger.
                                    </p>
                                    {invoice.cancel_reason && (
                                        <p className="text-slate-500">
                                            Reason: {invoice.cancel_reason}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* ── Lines ─────────────────────────────────────────── */}
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className={HEAD_ROW}>
                                        <th className={TH}>Description</th>
                                        <th className={TH}>Type</th>
                                        <th className={cn(TH, 'text-right')}>
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {(invoice.lines ?? []).length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="py-10 text-center text-xs text-slate-400"
                                            >
                                                This invoice has no lines.
                                            </td>
                                        </tr>
                                    ) : (
                                        (invoice.lines ?? []).map((line) => (
                                            <tr key={line.id}>
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {line.description}
                                                    {line.note && (
                                                        <span className="block text-[10px] font-medium text-slate-400">
                                                            {line.note}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {LINE_KIND_LABEL[
                                                        line.kind
                                                    ] ?? line.kind}
                                                </td>
                                                {/*
                                                 * THE SIGN IS THE ARITHMETIC AND IT IS THE
                                                 * SERVER'S. A reduction line arrives negative
                                                 * (InvoiceLineResource) and formatNaira renders it
                                                 * as it comes; nothing here negates, sums or
                                                 * compares — the total above is the snapshot the
                                                 * invoice was issued with.
                                                 */}
                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-900 tabular-nums dark:text-white">
                                                    {formatNaira(line.amount)}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* ── Actions ───────────────────────────────────────── */}
                        {!isVoid && (
                            <div className="flex flex-wrap items-center justify-end gap-1.5 border-t border-slate-100 px-6 py-4 dark:border-slate-800">
                                {/*
                                 * Per-button treatment, identical to the statement's because the
                                 * rule is the server's and one screen must not soften it: HIDE what
                                 * is meaningless (record payment once settled), keep AVAILABLE what
                                 * is a real operation (a credit note against a paid invoice),
                                 * DISABLE-WITH-REASON what a rule forbids (void once money has
                                 * settled). Authority is the separate <Can> axis.
                                 */}
                                {invoice.can_record_payment && (
                                    <Can permission="finance.payment.record">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setPayOpen(true)}
                                            className="h-8 rounded-lg text-xs"
                                        >
                                            Record payment
                                        </Button>
                                    </Can>
                                )}
                                {invoice.can_submit_credit_note && (
                                    <Can permission="finance.credit-note.submit">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setCreditOpen(true)}
                                            className="h-8 rounded-lg text-xs"
                                        >
                                            Submit credit note
                                        </Button>
                                    </Can>
                                )}
                                {has_pending_void ? (
                                    <span className="inline-flex h-8 items-center rounded-lg bg-amber-50 px-2.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                                        Void requested
                                    </span>
                                ) : (
                                    <Can permission="finance.invoice.void-request.submit">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={!invoice.can_request_void}
                                            title={
                                                invoice.can_request_void
                                                    ? undefined
                                                    : (invoice.void_blocked_reason ??
                                                      undefined)
                                            }
                                            onClick={() =>
                                                invoice.can_request_void &&
                                                setVoidOpen(true)
                                            }
                                            className="h-8 rounded-lg text-xs"
                                        >
                                            Request void
                                        </Button>
                                    </Can>
                                )}
                            </div>
                        )}
                    </div>

                    {/* ── The void trail ────────────────────────────────────── */}
                    {void_trail.length > 0 && (
                        <div className={SECTION_CARD}>
                            <div className="border-b border-slate-100 px-6 py-4 dark:border-slate-800">
                                <p className={LABEL}>Void requests</p>
                            </div>
                            <div className="divide-y divide-slate-100 dark:divide-slate-800">
                                {void_trail.map((entry) => (
                                    <div
                                        key={entry.id}
                                        className="grid grid-cols-1 gap-3 px-6 py-4 sm:grid-cols-4"
                                    >
                                        <Field
                                            label="Status"
                                            value={
                                                VOID_STATUS_LABEL[
                                                    entry.status
                                                ] ?? entry.status
                                            }
                                        />
                                        <Field
                                            label="Requested by"
                                            value={
                                                entry.submitted_by_name ?? '—'
                                            }
                                        />
                                        <Field
                                            label="Requested"
                                            value={entry.submitted_at}
                                        />
                                        <Field
                                            label="Decided"
                                            value={entry.decided_at ?? '—'}
                                        />
                                        <div className="sm:col-span-4">
                                            <p className={LABEL}>Reason</p>
                                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                                {entry.reason ?? '—'}
                                            </p>
                                            {entry.rejection_reason && (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Rejected:{' '}
                                                    {entry.rejection_reason}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/*
             * THE THREE MODALS, TAKING THE SAME `Invoice` OBJECT THE STATEMENT PASSES THEM — which
             * is what makes their titles name this invoice's KIND without a line of code here. They
             * are mounted only while open so each starts from a clean form.
             */}
            <RecordPaymentModal
                isOpen={payOpen}
                onClose={() => setPayOpen(false)}
                onRecorded={refresh}
                invoice={payOpen ? invoice : null}
            />
            <IssueCreditNoteModal
                isOpen={creditOpen}
                onClose={() => setCreditOpen(false)}
                onIssued={refresh}
                invoice={creditOpen ? invoice : null}
            />
            <RequestVoidModal
                isOpen={voidOpen}
                onClose={() => setVoidOpen(false)}
                onRequested={refresh}
                invoice={voidOpen ? invoice : null}
            />
        </>
    );
}

InvoiceDetail.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Invoice', href: '#' },
    ],
};
