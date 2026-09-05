import { formatNaira } from '@/lib/format';
import { splitInvoiceLines } from '@/lib/invoice-breakdown';
import type { FinanceWardInvoice } from '@/types/parent-finance';

/**
 * WHAT A BILL IS MADE OF, ON THE PAYER'S OWN SCREEN.
 *
 * Ruled 5 September 2026: parents see their invoice composition, discounts included. Before it, a
 * parent was asked for a term's fees against a document number and a term label — the school's own
 * staff could read the breakdown and the person paying could not.
 *
 * ── IT RECOMPUTES NOTHING, AND THAT IS THE WHOLE DESIGN ──
 *
 * The server sends signed line amounts and a total; this renders both and derives neither. No
 * `.reduce()`, no addition — `bin/ci-money-lint.php` forbids money arithmetic in the finance UI, and
 * the reason is stronger than the rule: a client that re-added the lines could disagree with the
 * figure the parent is actually charged, and the screen would then be confidently wrong about money.
 * `total = SUM(lines)` is true by construction inside GenerateInvoice's transaction, and asserted on
 * the wire by `ParentPortalFinanceReadTest`. Here it is displayed, not checked.
 *
 * ── GROUPED BY SIGN, LABELLED BY KIND ──
 *
 * Charges above, reductions beneath, exactly as the staff screen orders them. The split is on the
 * SIGN of the amount, because the sign is what carries the arithmetic — a reduction is a negative
 * line, and `InvoiceLine::kind` says what it MEANS rather than what it does. `kind` is used only for
 * the badge, so a mislabelled row still lands on the correct side of the bill.
 *
 * ── AND IT IS CLOSED BY DEFAULT ──
 *
 * `<details>` rather than an always-open list. Most parents open this screen to pay, not to audit,
 * and three lines above the amount they owe is noise on that path — but the one parent asking "what
 * is this for?" is the person the ruling is about, and for them it is one click and no navigation.
 * Native disclosure, so it is keyboard-reachable and readable by a screen reader without any state
 * of our own.
 */
export function InvoiceBreakdown({ invoice }: { invoice: FinanceWardInvoice }) {
    // A bill whose lines were not loaded renders nothing rather than an empty breakdown that reads
    // as "this bill is made of nothing". `lines` is eager-loaded by InvoiceReadModel; this is the
    // guard for the day some other caller forgets, which is how the absent-reads-as-empty defect
    // this feature already hit once would come back.
    if (!invoice.lines || invoice.lines.length === 0) {
        return null;
    }

    const { charges, reductions } = splitInvoiceLines(invoice.lines);

    return (
        <details className="group mt-2">
            <summary className="cursor-pointer list-none text-sm text-gray-500 underline-offset-2 hover:text-gray-700 hover:underline">
                <span className="group-open:hidden">What is this for?</span>
                <span className="hidden group-open:inline">
                    Hide the breakdown
                </span>
            </summary>

            <dl className="mt-2 space-y-1 rounded-md bg-gray-50 px-3 py-2 text-sm">
                {charges.map((line, index) => (
                    <div
                        key={`charge-${index}`}
                        className="flex items-baseline justify-between gap-4"
                    >
                        <dt className="min-w-0 truncate text-gray-700">
                            {line.description}
                        </dt>
                        <dd className="shrink-0 text-gray-700 tabular-nums">
                            {formatNaira(line.amount)}
                        </dd>
                    </div>
                ))}

                {reductions.map((line, index) => (
                    <div
                        key={`reduction-${index}`}
                        className="flex items-baseline justify-between gap-4"
                    >
                        <dt className="min-w-0 truncate text-emerald-700">
                            {line.description}
                            {line.kind === 'waiver' && (
                                <span className="ml-2 rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700">
                                    Waiver
                                </span>
                            )}
                        </dt>
                        <dd className="shrink-0 text-emerald-700 tabular-nums">
                            {formatNaira(line.amount)}
                        </dd>
                    </div>
                ))}

                {/* The server's total, restated where a reader's eye ends up — never re-added here. */}
                <div className="flex items-baseline justify-between gap-4 border-t border-gray-200 pt-1 font-medium text-gray-900">
                    <dt>Total</dt>
                    <dd className="tabular-nums">
                        {formatNaira(invoice.total)}
                    </dd>
                </div>
            </dl>
        </details>
    );
}
