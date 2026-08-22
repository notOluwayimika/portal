import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Landmark,
    Lock,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
// Wayfinder-generated from the controller, so both paths live in exactly one place —
// routes/endpoints/finance.php — and a rename cannot leave this screen fetching a dead URL. Same
// reason the statement imports its receipt link rather than spelling the path.
import {
    proposal as proposalUrl,
    store as submitUrl,
} from '@/actions/App/Finance/Http/Controllers/PaymentAllocationController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    differenceMinor,
    formatNaira,
    minorToNairaInput,
    nairaToMinor,
    sumMinor,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AllocationCandidate, AllocationProposal } from '@/types/finance';

type Props = {
    paymentUuid: string;
    studentUuid: string;
    studentName: string;
};

const CARD =
    'overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card';
const TH =
    'px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase';
const HEAD_ROW =
    'border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30';

/**
 * U10 — WHERE THIS PAYMENT'S REMAINDER SETTLES.
 *
 * A PURE CONSUMER of GET /api/v1/finance/payments/{uuid}/allocation-proposal and POST
 * …/allocations. It computes no money except the two sanctioned integer ops in lib/format
 * (sumMinor for the running total, differenceMinor for the headroom); every figure it displays comes
 * from the server, and every refusal it shows is the server's own words.
 *
 * THE THREE THINGS THIS SCREEN EXISTS TO MAKE VISIBLE, none of which is decoration:
 *
 *   1. THE BANK-ACCOUNT MISMATCH. The MVP cut brief is explicit that money received into account A
 *      settling lines destined for account B is an ordinary occurrence in term one, and that this
 *      screen has to SHOW it rather than allocate across it silently. It is rendered three-valued,
 *      because the destination is derived through the invoice line's nullable `fee_item_id` and is
 *      frequently not readable at all — `finance_invoice_lines` has no `bank_account_id` of its own,
 *      deliberately (2026_08_10_120000). "Not recorded" gets its own badge and its own wording; it is
 *      NOT rendered as agreement, which would be the same silence one level further in.
 *
 *   2. THAT SUBMIT IS FINAL. `finance_payment_allocations` carries `_no_update` and `_no_delete`
 *      (2026_07_19_110000). Editing happens HERE, on the proposal; after submit a correction is a
 *      compensating write, not an edit. An operator who has spent two minutes typing into a table has
 *      every reason to assume otherwise, so the sentence sits at the point of submit, in plain words,
 *      rather than in a tooltip or a doc.
 *
 *   3. THAT A DEPARTURE FROM THE PROPOSAL IS RECORDED. The changed rows carry a marker and the reason
 *      the operator gives, forever. The reason field appears the moment a figure differs and the
 *      submit is blocked without it — the server refuses the same thing under the account-row lock,
 *      and the pairing trigger is the floor under that.
 *
 * THE SERVER IS THE AUTHORITY ON EVERY REFUSAL HERE. The client-side checks below decide what to
 * DISPLAY and when to disable a button; they are never what makes an illegal allocation impossible.
 * A submit that gets past all of them meets the Action's guards under the lock, and the two allocation
 * triggers under those.
 */
export default function AllocatePaymentScreen({
    paymentUuid,
    studentUuid,
    studentName,
}: Props) {
    const [proposal, setProposal] = useState<AllocationProposal | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    // Naira strings keyed by invoice uuid — the shape an <input> holds, converted at the boundary.
    const [amounts, setAmounts] = useState<Record<string, string>>({});
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setLoadError(false);
        setErrors({});
        setMessage(null);

        try {
            const { data } = await axios.get<AllocationProposal>(
                proposalUrl.url(paymentUuid),
            );
            setProposal(data);
            // Prefilled with the engine's proposal — the operator's starting point, not a commitment.
            setAmounts(
                Object.fromEntries(
                    data.invoices.map((invoice) => [
                        invoice.id,
                        minorToNairaInput(invoice.proposed),
                    ]),
                ),
            );
            setReason('');
        } catch {
            setLoadError(true);
        } finally {
            setLoading(false);
        }
    }, [paymentUuid]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    // Every row's typed amount in minor units, or null when the text is not a valid amount. null is
    // carried rather than coerced to 0: "2,50" is a typo, and silently reading it as nothing is how a
    // bursar submits a split they did not intend.
    const parsed = useMemo(() => {
        const out: Record<string, number | null> = {};

        for (const invoice of proposal?.invoices ?? []) {
            const raw = (amounts[invoice.id] ?? '').trim();
            out[invoice.id] = raw === '' ? 0 : nairaToMinor(raw);
        }

        return out;
    }, [amounts, proposal]);

    const malformed = (proposal?.invoices ?? []).filter(
        (invoice) => parsed[invoice.id] === null,
    );

    const allocatedTotal = sumMinor(
        (proposal?.invoices ?? []).map((invoice) => parsed[invoice.id] ?? 0),
    );

    // May go NEGATIVE, and is shown negative on purpose — an operator over-allocating needs to see it
    // while they are in it. The submit is disabled and the server refuses it regardless.
    const headroom = proposal
        ? differenceMinor(
              proposal.payment.unallocated.amount_minor,
              allocatedTotal,
          )
        : 0;

    // A row DEPARTS from the proposal when its figure differs — including a proposed row left empty,
    // which is a decision to allocate nothing to it. The server computes exactly this comparison
    // again under the lock and is the authority; this drives the marker and the reason field.
    const departed = (proposal?.invoices ?? []).filter(
        (invoice) =>
            parsed[invoice.id] !== null &&
            parsed[invoice.id] !== invoice.proposed.amount_minor,
    );

    const needsReason = departed.length > 0;
    const reasonMissing = needsReason && reason.trim() === '';

    const blocked =
        !proposal ||
        submitting ||
        malformed.length > 0 ||
        reasonMissing ||
        headroom < 0 ||
        allocatedTotal === 0;

    const submit = async () => {
        if (!proposal || blocked) {
            return;
        }

        setSubmitting(true);
        setErrors({});
        setMessage(null);

        try {
            await axios.post(submitUrl.url(paymentUuid), {
                fingerprint: proposal.fingerprint,
                // EVERY offered invoice is posted, including the ones set to zero: a zero is how
                // an operator says "not this one", and the server needs to see it to compare the
                // submission against the proposal it made.
                allocations: proposal.invoices.map((invoice) => ({
                    invoice_id: invoice.id,
                    amount_minor: parsed[invoice.id] ?? 0,
                })),
                override_reason: needsReason ? reason.trim() : null,
            });

            router.visit(`/finance/students/${studentUuid}/statement`);
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                // Laravel validation and the Action's own refusals arrive in the same `errors` shape,
                // which is the whole reason AllocationRefused carries a field: a row's message lands
                // on that row instead of in a banner above a table of eight editable amounts.
                const raw = (err.response.data?.errors ?? {}) as Record<
                    string,
                    string[]
                >;
                setErrors(
                    Object.fromEntries(
                        Object.entries(raw).map(([key, list]) => [
                            key,
                            list[0] ?? '',
                        ]),
                    ),
                );
                setMessage(err.response.data?.message ?? null);
            } else {
                setMessage(
                    'The allocation could not be submitted. Nothing was written; try again.',
                );
            }
        } finally {
            setSubmitting(false);
        }
    };

    const rowError = (index: number) =>
        errors[`allocations.${index}.amount_minor`] ??
        errors[`allocations.${index}.invoice_id`];

    return (
        <>
            <Head title={`Allocate payment — ${studentName}`} />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-6xl space-y-5">
                    <div className="flex items-center gap-2">
                        <Link
                            href={`/finance/students/${studentUuid}/statement`}
                            className="text-slate-400 transition-colors hover:text-indigo-600"
                            title="Back to the statement"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                            Allocate payment
                        </h1>
                        <span className="text-xs text-slate-500">
                            {studentName}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => void load()}
                            disabled={loading || submitting}
                            className="ml-auto rounded-lg"
                        >
                            <RefreshCw
                                className={cn(
                                    'mr-1.5 h-4 w-4',
                                    loading && 'animate-spin',
                                )}
                            />
                            Reload
                        </Button>
                    </div>

                    {loading && !proposal && (
                        <div className="flex justify-center py-10">
                            <Spinner />
                        </div>
                    )}

                    {loadError && (
                        <div className={cn(CARD, 'p-8 text-center')}>
                            <AlertCircle className="mx-auto h-6 w-6 text-red-500" />
                            <p className="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Could not load the proposal
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => void load()}
                                className="mt-3 rounded-lg"
                            >
                                Retry
                            </Button>
                        </div>
                    )}

                    {proposal && (
                        <>
                            {/* ── The payment ─────────────────────────────── */}
                            <div className={cn(CARD, 'p-5')}>
                                <div className="grid gap-4 text-xs sm:grid-cols-3 lg:grid-cols-6">
                                    <Fact
                                        label="Amount"
                                        value={formatNaira(
                                            proposal.payment.amount,
                                        )}
                                    />
                                    <Fact
                                        label="Received"
                                        value={proposal.payment.received_at}
                                        note={
                                            proposal.payment
                                                .received_at_reason ?? undefined
                                        }
                                    />
                                    <Fact
                                        label="Method"
                                        value={proposal.payment.method}
                                    />
                                    <Fact
                                        label="Reference"
                                        value={`#${proposal.payment.reference}`}
                                    />
                                    <Fact
                                        label="Landed in"
                                        value={
                                            proposal.payment.bank_account
                                                ?.label ?? 'Not recorded'
                                        }
                                        note={
                                            proposal.payment.bank_account
                                                ?.bank_name ??
                                            'This payment names no bank account — it was brought across from the previous system.'
                                        }
                                    />
                                    <Fact
                                        label="Unallocated"
                                        value={formatNaira(
                                            proposal.payment.unallocated,
                                        )}
                                        strong
                                    />
                                </div>
                            </div>

                            {/* ── The proposal ────────────────────────────── */}
                            <div className={CARD}>
                                <div className="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                        Open invoices
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-slate-500">
                                        The amounts below are what the system
                                        would do on its own — oldest invoice
                                        first, never more than an invoice still
                                        owes. Change any of them and say why.
                                    </p>
                                </div>

                                {proposal.invoices.length === 0 ? (
                                    <p className="px-5 py-10 text-center text-xs text-slate-500">
                                        This student has no open invoice for
                                        this payment to settle. The money stays
                                        on the account as credit and the next
                                        invoice raised will draw it forward
                                        automatically.
                                    </p>
                                ) : (
                                    <div className="custom-scrollbar overflow-x-auto">
                                        <table className="w-full text-xs">
                                            <thead>
                                                <tr className={HEAD_ROW}>
                                                    <th className={TH}>
                                                        Invoice
                                                    </th>
                                                    <th className={TH}>Kind</th>
                                                    <th className={TH}>
                                                        Destined for
                                                    </th>
                                                    <th
                                                        className={cn(
                                                            TH,
                                                            'text-right',
                                                        )}
                                                    >
                                                        Outstanding
                                                    </th>
                                                    <th
                                                        className={cn(
                                                            TH,
                                                            'text-right',
                                                        )}
                                                    >
                                                        Proposed
                                                    </th>
                                                    <th
                                                        className={cn(
                                                            TH,
                                                            'text-right',
                                                        )}
                                                    >
                                                        Allocate (₦)
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                                {proposal.invoices.map(
                                                    (invoice, index) => (
                                                        <Row
                                                            key={invoice.id}
                                                            invoice={invoice}
                                                            index={index}
                                                            value={
                                                                amounts[
                                                                    invoice.id
                                                                ] ?? ''
                                                            }
                                                            onChange={(next) =>
                                                                setAmounts(
                                                                    (prev) => ({
                                                                        ...prev,
                                                                        [invoice.id]:
                                                                            next,
                                                                    }),
                                                                )
                                                            }
                                                            malformed={
                                                                parsed[
                                                                    invoice.id
                                                                ] === null
                                                            }
                                                            departed={
                                                                parsed[
                                                                    invoice.id
                                                                ] !== null &&
                                                                parsed[
                                                                    invoice.id
                                                                ] !==
                                                                    invoice
                                                                        .proposed
                                                                        .amount_minor
                                                            }
                                                            error={rowError(
                                                                index,
                                                            )}
                                                        />
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                                    <p className="text-[11px] text-slate-500">
                                        Allocating{' '}
                                        <span className="font-bold text-slate-700 tabular-nums dark:text-slate-200">
                                            {formatNaira({
                                                amount_minor: allocatedTotal,
                                                currency:
                                                    proposal.payment.amount
                                                        .currency,
                                            })}
                                        </span>{' '}
                                        of{' '}
                                        {formatNaira(
                                            proposal.payment.unallocated,
                                        )}
                                    </p>
                                    <p
                                        className={cn(
                                            'text-[11px] font-bold tabular-nums',
                                            headroom < 0
                                                ? 'text-rose-600'
                                                : 'text-slate-500',
                                        )}
                                    >
                                        {headroom < 0
                                            ? 'Over by '
                                            : 'Still unallocated: '}
                                        {formatNaira({
                                            amount_minor:
                                                headroom < 0
                                                    ? -headroom
                                                    : headroom,
                                            currency:
                                                proposal.payment.amount
                                                    .currency,
                                        })}
                                    </p>
                                </div>
                            </div>

                            {/* What the proposal could not place. */}
                            {proposal.unproposed_remainder.amount_minor > 0 && (
                                <Notice tone="slate">
                                    {formatNaira(proposal.unproposed_remainder)}{' '}
                                    of this payment has no open invoice to
                                    settle. It stays on the account as credit
                                    and the next invoice raised for this student
                                    will draw it forward.
                                </Notice>
                            )}

                            {/* ── The override reason ─────────────────────── */}
                            {needsReason && (
                                <div className={cn(CARD, 'p-5')}>
                                    <label
                                        htmlFor="override_reason"
                                        className="flex items-center gap-1.5 text-xs font-bold text-amber-700 dark:text-amber-400"
                                    >
                                        <AlertTriangle className="h-3.5 w-3.5" />
                                        You changed{' '}
                                        {departed.length === 1
                                            ? 'one row'
                                            : `${departed.length} rows`}
                                        . Why?
                                    </label>
                                    <p className="mt-1 text-[11px] text-slate-500">
                                        This is written onto the changed
                                        allocation rows and marked as an
                                        override. It cannot be edited
                                        afterwards.
                                    </p>
                                    <input
                                        id="override_reason"
                                        type="text"
                                        maxLength={255}
                                        value={reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        placeholder="e.g. Parent asked for the trip fee to be cleared before the term bill"
                                        className="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-900"
                                    />
                                    {errors.override_reason && (
                                        <p className="mt-1.5 text-[11px] font-semibold text-rose-600">
                                            {errors.override_reason}
                                        </p>
                                    )}
                                </div>
                            )}

                            {errors.fingerprint && (
                                <Notice tone="rose">
                                    {errors.fingerprint}
                                </Notice>
                            )}
                            {errors.allocations && (
                                <Notice tone="rose">
                                    {errors.allocations}
                                </Notice>
                            )}
                            {message &&
                                !errors.allocations &&
                                !errors.fingerprint && (
                                    <Notice tone="rose">{message}</Notice>
                                )}

                            {/* ── THE POINT OF SUBMIT ─────────────────────── */}
                            <div className={cn(CARD, 'p-5')}>
                                {/*
                                    THE SENTENCE THAT HAS TO BE HERE, in plain words, at the moment
                                    it matters. finance_payment_allocations carries _no_update and
                                    _no_delete: the operator has been editing a TABLE, which is the
                                    single strongest cue that this can be edited again, and it
                                    cannot. Saying so in a tooltip, or only in the report, would
                                    leave the assumption standing.
                                */}
                                <p className="flex items-start gap-2 text-[11px] text-slate-600 dark:text-slate-300">
                                    <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                                    <span>
                                        <span className="font-bold">
                                            Once submitted, these allocations
                                            cannot be edited or removed.
                                        </span>{' '}
                                        The allocation record is permanent.
                                        Correcting a mistake afterwards means
                                        raising a further document — a credit
                                        note or a new invoice — not changing
                                        these rows. Check the split before you
                                        submit.
                                    </span>
                                </p>

                                <div className="mt-4 flex items-center justify-end gap-2">
                                    <Link
                                        href={`/finance/students/${studentUuid}/statement`}
                                        className="rounded-lg px-4 py-2 text-xs font-semibold text-slate-500 hover:text-slate-700"
                                    >
                                        Cancel
                                    </Link>
                                    <Button
                                        size="sm"
                                        onClick={() => void submit()}
                                        disabled={blocked}
                                        className="rounded-lg bg-indigo-600 px-4 font-semibold text-white hover:bg-indigo-700"
                                    >
                                        {submitting
                                            ? 'Submitting…'
                                            : 'Submit allocation'}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

function Fact({
    label,
    value,
    note,
    strong,
}: {
    label: string;
    value: string;
    note?: string;
    strong?: boolean;
}) {
    return (
        <div>
            <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                {label}
            </p>
            <p
                className={cn(
                    'mt-0.5 capitalize',
                    strong
                        ? 'text-sm font-extrabold text-indigo-600 tabular-nums dark:text-indigo-400'
                        : 'font-semibold text-slate-700 dark:text-slate-200',
                )}
            >
                {value}
            </p>
            {note && (
                <p className="mt-0.5 text-[10px] text-slate-400">{note}</p>
            )}
        </div>
    );
}

function Notice({
    tone,
    children,
}: {
    tone: 'slate' | 'rose';
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl px-4 py-3 text-[11px] font-medium',
                tone === 'rose'
                    ? 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-300'
                    : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
            )}
        >
            {children}
        </div>
    );
}

/**
 * WHERE THIS INVOICE'S CHARGES WERE DESTINED, rendered three-valued.
 *
 * `differs` is the cut brief's case and it NAMES the account, because "there is a mismatch" without
 * saying which one is a warning nobody can act on. `unrecorded` is its own state with its own words —
 * the invoice's lines are free text with no fee item behind them, so there is nothing to compare —
 * and it is deliberately NOT drawn as agreement. And a `matches` that only covers part of the invoice
 * says so, rather than showing a bare tick over lines it could not read.
 */
function Destination({ invoice }: { invoice: AllocationCandidate }) {
    const {
        state,
        accounts,
        charge_lines_without_destination: unread,
    } = invoice.destination;

    if (state === 'differs') {
        return (
            <div className="flex items-start gap-1.5">
                <Landmark className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" />
                <div>
                    <p className="font-semibold text-amber-700 dark:text-amber-400">
                        {accounts.map((a) => a.label).join(', ')}
                    </p>
                    <p className="text-[10px] text-amber-600/80">
                        Not the account this money landed in.
                        {unread > 0 &&
                            ` ${unread} more line(s) name no account.`}
                    </p>
                </div>
            </div>
        );
    }

    if (state === 'matches') {
        return (
            <div>
                <p className="font-semibold text-emerald-600 dark:text-emerald-400">
                    Same account
                </p>
                {unread > 0 && (
                    <p className="text-[10px] text-slate-400">
                        {unread} line(s) name no account, so this covers only
                        part of the invoice.
                    </p>
                )}
            </div>
        );
    }

    return (
        <div>
            <p className="font-semibold text-slate-500">Not recorded</p>
            <p className="text-[10px] text-slate-400">
                These lines name no bank account, so there is nothing to compare
                against.
            </p>
        </div>
    );
}

function Row({
    invoice,
    index,
    value,
    onChange,
    malformed,
    departed,
    error,
}: {
    invoice: AllocationCandidate;
    index: number;
    value: string;
    onChange: (next: string) => void;
    malformed: boolean;
    departed: boolean;
    error?: string;
}) {
    return (
        <tr
            className={cn(
                'transition-colors',
                departed && 'bg-amber-50/40 dark:bg-amber-900/10',
            )}
        >
            <td className="px-4 py-2.5">
                <p className="font-semibold text-slate-700 dark:text-slate-200">
                    {invoice.display_number}
                </p>
                <p className="text-[10px] text-slate-400">
                    {invoice.academic_context}
                </p>
            </td>
            <td className="px-4 py-2.5">
                <span
                    className={cn(
                        'rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                        invoice.kind === 'scheduled'
                            ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-400'
                            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                    )}
                >
                    {invoice.kind === 'scheduled' ? 'Term bill' : 'Extra'}
                </span>
            </td>
            <td className="px-4 py-2.5">
                <Destination invoice={invoice} />
            </td>
            <td className="px-4 py-2.5 text-right font-semibold text-slate-700 tabular-nums dark:text-slate-200">
                {formatNaira(invoice.outstanding)}
            </td>
            <td className="px-4 py-2.5 text-right text-slate-500 tabular-nums">
                {formatNaira(invoice.proposed)}
            </td>
            <td className="px-4 py-2.5 text-right">
                {invoice.allocatable ? (
                    <>
                        <input
                            type="text"
                            inputMode="decimal"
                            aria-label={`Allocate to ${invoice.display_number}`}
                            value={value}
                            onChange={(e) => onChange(e.target.value)}
                            className={cn(
                                'w-28 rounded-lg border px-2 py-1 text-right text-xs tabular-nums dark:bg-slate-900',
                                malformed || error
                                    ? 'border-rose-400'
                                    : 'border-slate-200 dark:border-slate-700',
                            )}
                        />
                        {departed && !malformed && !error && (
                            <p className="mt-0.5 text-[10px] font-semibold text-amber-600">
                                Changed
                            </p>
                        )}
                        {malformed && (
                            <p className="mt-0.5 text-[10px] font-semibold text-rose-600">
                                Enter an amount like 2500 or 2500.75
                            </p>
                        )}
                        {error && (
                            <p className="mt-0.5 text-[10px] font-semibold text-rose-600">
                                {error}
                            </p>
                        )}
                    </>
                ) : (
                    // NOT HIDDEN. The row stays, the reason is the server's own sentence, and the
                    // input is simply absent — an invoice missing from the list would tell an
                    // operator their student has fewer open bills than they do.
                    <p className="text-[10px] font-semibold text-slate-400">
                        {invoice.blocked_reason}
                    </p>
                )}
                <span className="sr-only">{`row ${index}`}</span>
            </td>
        </tr>
    );
}
