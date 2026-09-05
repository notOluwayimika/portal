import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, FileText, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PayInvoice } from '@/components/parent/pay-invoice';
import { formatNaira, formatNairaMagnitude } from '@/lib/format';
import {
    hasAvailableCredit,
    isInCredit,
    wardsView,
} from '@/lib/parent-finance-view';
import type {
    FinanceWard,
    FinanceWardInvoice,
    FinanceWardsResponse,
} from '@/types/parent-finance';

/**
 * WHAT EACH WARD OWES — the parent portal's finance screen, and the READ half only.
 *
 * THERE IS NO PAY BUTTON, AND ITS ABSENCE IS DELIBERATE. Nothing it could call exists yet: there is
 * no gateway client, no initiation route, and the settlement-account column a gateway payment is
 * obliged to name has not landed. A button wired to nothing is worse than no button — it invites a
 * parent to try, and it makes a screen look finished to everyone reviewing it. It arrives with the
 * initiation endpoint, in the same change.
 *
 * THE DATA COMES FROM `GET /api/parent/finance/wards` AND NOWHERE ELSE. The endpoint takes no
 * identifier: the wards are derived server-side from the authenticated user, so there is no uuid on
 * the request to tamper with and therefore no ownership check this screen can forget. Nothing here
 * may add a `?student=` — the correct shape for one ward is a filter over this response.
 *
 * MONEY IS NEVER COMPUTED HERE. Every figure on the screen is one the server already derived, and
 * each is rendered through `formatNaira` — the single sanctioned formatter. No addition, no
 * `.toLocaleString()`, no totals across wards: `bin/ci-money-lint.php` bans all three, because the
 * whole money architecture is integer minor units precisely so that JavaScript floats never touch a
 * naira figure.
 *
 * @see resources/js/types/parent-finance.ts for why `parent/dashboard.tsx` is a picture and not a
 *      contract, and why none of its props or numbers appear here.
 */

/** The three states this screen can be in. Modelled explicitly so none of them renders as another. */
type LoadState =
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'ready'; wards: FinanceWard[] };

function InvoiceRow({
    invoice,
    studentName,
    schoolName,
}: {
    invoice: FinanceWardInvoice;
    studentName: string;
    schoolName: string;
}) {
    return (
        <li className="border-b border-gray-100 py-3 last:border-b-0">
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <p className="truncate font-medium text-gray-900">
                        {invoice.display_number}
                    </p>
                    <p className="truncate text-sm text-gray-500">
                        {invoice.academic_context}
                        {/* `kind` distinguishes the term bill from a one-off; an episode can carry both
                        at once, so the number alone no longer says which document this is. */}
                        {invoice.kind === 'supplementary' && (
                            <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700">
                                Supplementary
                            </span>
                        )}
                    </p>
                </div>

                <div className="flex shrink-0 items-baseline gap-4 sm:justify-end">
                    <div className="text-right">
                        <p className="text-xs text-gray-500">Billed</p>
                        <p className="text-sm text-gray-700">
                            {formatNaira(invoice.total)}
                        </p>
                    </div>
                    <div className="text-right">
                        <p className="text-xs text-gray-500">Outstanding</p>
                        <p className="font-semibold text-gray-900">
                            {formatNaira(invoice.outstanding)}
                        </p>
                    </div>
                </div>
            </div>

            {/*
                THE PAY AFFORDANCE, AND ITS CONDITION IS "THIS BILL IS IN THE LIST".
                `GuardianFinanceController::wards` withholds unreleased bills on both keys, so every
                invoice reaching here is already released. A second, client-side release check would
                be a duplicate of a server-side control that could drift into offering a button the
                server refuses.
            */}
            <PayInvoice
                invoice={invoice}
                studentName={studentName}
                schoolName={schoolName}
            />
        </li>
    );
}

function WardCard({
    ward,
    schoolName,
}: {
    ward: FinanceWard;
    schoolName: string;
}) {
    const { student, invoices, account } = ward;
    const inCredit = isInCredit(account.balance);

    return (
        <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <header className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold text-gray-900">
                    {student.name}
                </h2>

                {/* THE ACCOUNT POSITION, which is not derivable from the invoice list. */}
                <div className="text-right">
                    <p className="text-xs text-gray-500">
                        {inCredit ? 'In credit' : 'Account balance'}
                    </p>
                    <p
                        className={
                            inCredit
                                ? 'font-semibold text-emerald-700'
                                : 'font-semibold text-gray-900'
                        }
                    >
                        {/*
                            THE LABEL ABOVE ALREADY SAYS WHICH DIRECTION THIS IS, so the number
                            must not say it again. `balance` is signed and the sign is its meaning,
                            which rendered as "In credit -NGN 2,000.00" — a parent cannot tell
                            whether the minus means the school owes them or that they owe less.
                            Magnitude under a directional label; the signed form stays correct
                            everywhere the direction is not otherwise stated.
                        */}
                        {inCredit
                            ? formatNairaMagnitude(account.balance)
                            : formatNaira(account.balance)}
                    </p>
                </div>
            </header>

            {/*
                AVAILABLE CREDIT IS SHOWN WHENEVER THERE IS ANY, not only when the invoice list is
                empty. A parent can hold credit AND owe on a newer invoice at the same time, and the
                credit is invisible everywhere else on this screen — there is no outstanding invoice
                for it to sit on.
            */}
            {hasAvailableCredit(account.available_credit) && (
                <p className="mb-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    <Wallet className="h-4 w-4 shrink-0" aria-hidden="true" />
                    {/*
                        DEV 1'S APPROVED WORDING, and this REPLACES a sentence that described a
                        state. "Already paid and not yet applied to a bill" tells a parent what the
                        number is and nothing about what happens to it — which is the shape that
                        makes a parent conclude the system is broken and telephone the school. This
                        says what will happen and who to ask.
                    */}
                    <span>
                        {formatNaira(account.available_credit)} credit on this
                        student's account. This will be applied automatically to
                        the next invoice issued. To apply it to an outstanding
                        invoice, contact the bursar.
                    </span>
                </p>
            )}

            {invoices.length === 0 ? (
                /*
                    AN EMPTY INVOICE LIST IS A REAL STATE AND MUST SAY SO. The endpoint returns every
                    ward, carrying an empty array when nothing is owed — dropping the ward would make
                    "paid up" and "not your child" the same screen. This is the branch that keeps
                    those two apart.
                */
                <p className="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-3 text-sm text-gray-600">
                    <CheckCircle2
                        className="h-4 w-4 shrink-0 text-emerald-600"
                        aria-hidden="true"
                    />
                    Nothing outstanding for {student.name} right now.
                </p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {invoices.map((invoice) => (
                        <InvoiceRow
                            key={invoice.id}
                            invoice={invoice}
                            studentName={student.name}
                            schoolName={schoolName}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function ParentFinance() {
    const [state, setState] = useState<LoadState>({ status: 'loading' });

    /*
        THE ACTIVE SCHOOL'S NAME, from the shared props HandleInertiaRequests already provides on
        every page. It is on the confirmation because the SCHOOL is the field that distinguishes
        "the wrong child" from "the wrong portal" for a two-campus family — and it costs no change
        to the wards endpoint.
    */
    const { auth } = usePage().props;
    const schoolName = auth.school?.name ?? '';

    useEffect(() => {
        let cancelled = false;

        axios
            .get<FinanceWardsResponse>('/api/parent/finance/wards')
            .then((response) => {
                if (cancelled) {
                    return;
                }

                setState({ status: 'ready', wards: response.data.data ?? [] });
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }

                // The failure is SHOWN, not swallowed into an empty list. "We could not load this"
                // and "you owe nothing" are opposite facts and must never render alike on a screen
                // about money.
                setState({
                    status: 'error',
                    message:
                        'We could not load your fee information just now. Please try again shortly.',
                });
            });

        return () => {
            cancelled = true;
        };
    }, []);

    return (
        <div className="mx-auto w-full max-w-3xl px-4 py-6">
            <header className="mb-6">
                <h1 className="text-2xl font-semibold text-gray-900">Fees</h1>
                <p className="mt-1 text-sm text-gray-600">
                    What each of your children currently owes.
                </p>
            </header>

            {state.status === 'loading' && (
                <p className="text-sm text-gray-500">
                    Loading your fee information…
                </p>
            )}

            {state.status === 'error' && (
                <p
                    role="alert"
                    className="flex items-center gap-2 rounded-lg bg-red-50 px-3 py-3 text-sm text-red-800"
                >
                    <AlertTriangle
                        className="h-4 w-4 shrink-0"
                        aria-hidden="true"
                    />
                    {state.message}
                </p>
            )}

            {state.status === 'ready' &&
                wardsView(state.wards).kind === 'no-wards' && (
                    /*
                    NO WARDS IN THIS SCHOOL is a legitimate state, not an error: a guardian may hold
                    wards in a school they have not switched to. The endpoint answers with an empty
                    list rather than a 403, and this says so plainly instead of implying something is
                    broken.
                */
                    <p className="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-3 text-sm text-gray-600">
                        <FileText
                            className="h-4 w-4 shrink-0"
                            aria-hidden="true"
                        />
                        No children are linked to your account in this school.
                    </p>
                )}

            {state.status === 'ready' &&
                wardsView(state.wards).kind === 'wards' && (
                    <div className="space-y-4">
                        {state.wards.map((ward) => (
                            <WardCard
                                key={ward.student.id}
                                ward={ward}
                                schoolName={schoolName}
                            />
                        ))}
                    </div>
                )}
        </div>
    );
}
