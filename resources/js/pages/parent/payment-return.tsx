import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, HelpCircle } from 'lucide-react';
import { formatNaira } from '@/lib/format';
import type { Money } from '@/types/parent-finance';

/**
 * WHAT THE PAYER IS TOLD WHEN PAYSTACK SENDS THEM BACK.
 *
 * FOUR STATES, NOT NINE. The server collapses `GatewaySettlementOutcome`'s nine cases onto four
 * sentences — see `GatewayReturnController::state()`. This component renders what it is given and
 * decides nothing.
 *
 * THE PENDING COPY IS THE ONE THAT MATTERS. A parent told their payment failed pays again, and the
 * second payment is real money the school then has to return. Every state where the payer may have
 * been charged but the row is not yet settled says the same thing: nothing is lost, do nothing.
 *
 * NO RETRY BUTTON ON `pending`, DELIBERATELY. The recovery is Paystack's own webhook retry, which
 * needs nothing from the parent — and a button labelled "try again" on a screen that has just said
 * "you have paid" is an invitation to pay twice.
 */

type Props = {
    state: 'settled' | 'recorded' | 'pending' | 'failed' | 'unknown';
    amount: Money | null;
};

export default function PaymentReturn({ state, amount }: Props) {
    const view = {
        settled: {
            icon: CheckCircle2,
            tone: 'text-emerald-700',
            box: 'bg-emerald-50',
            title: 'Payment received',
            body: amount
                ? `${formatNaira(amount)} has been applied to your child's account. Thank you.`
                : "Your payment has been applied to your child's account. Thank you.",
        },
        recorded: {
            icon: CheckCircle2,
            tone: 'text-emerald-700',
            box: 'bg-emerald-50',
            // NOT AN ERROR AND NOT A DUPLICATE. The webhook usually beats the browser back; the
            // parent should see the same reassurance either way rather than a message implying
            // something unusual happened.
            title: 'Already recorded',
            body: amount
                ? `${formatNaira(amount)} was already recorded against your child's account. Nothing further is needed.`
                : "This payment was already recorded against your child's account. Nothing further is needed.",
        },
        pending: {
            icon: Clock,
            tone: 'text-amber-700',
            box: 'bg-amber-50',
            title: 'We are confirming your payment',
            // "YOU DO NOT NEED TO PAY AGAIN" IS THE LOAD-BEARING SENTENCE.
            body: 'Your payment has gone through at Paystack. It can take a few minutes to appear against the invoice. You do not need to pay again, and you do not need to do anything else.',
        },
        failed: {
            icon: AlertTriangle,
            tone: 'text-red-800',
            box: 'bg-red-50',
            title: 'This payment did not go through',
            // ONLY WHEN THE PROVIDER ITSELF SAYS SO. Every ambiguous state is `pending`.
            body: 'Paystack did not complete this payment, and nothing has been charged. You can try again from the fees page.',
        },
        unknown: {
            icon: HelpCircle,
            tone: 'text-gray-700',
            box: 'bg-gray-50',
            title: 'We could not find this payment',
            body: 'We could not match this return to a payment on your account. If money has left your account, contact the school office — do not pay again.',
        },
    }[state];

    const Icon = view.icon;

    return (
        <div className="mx-auto w-full max-w-xl px-4 py-10">
            <div className={`rounded-xl ${view.box} p-6`}>
                <div className="flex items-start gap-3">
                    <Icon
                        className={`mt-0.5 h-6 w-6 shrink-0 ${view.tone}`}
                        aria-hidden="true"
                    />
                    <div>
                        <h1 className={`text-lg font-semibold ${view.tone}`}>
                            {view.title}
                        </h1>
                        <p className="mt-2 text-sm text-gray-800">
                            {view.body}
                        </p>
                    </div>
                </div>
            </div>

            <Link
                href="/parent/finance"
                className="mt-6 inline-block rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700"
            >
                Back to fees
            </Link>
        </div>
    );
}
