<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * U11 — the payment receipt. ONE payment, rendered as a printable web page.
 *
 * ── WHY A PAGE ROUTE AND NOT A JSON ENDPOINT ──
 *
 * The receipt's data is resolved HERE and handed to the page as props; there is no
 * GET /v1/finance/payments/{uuid} and this commit does not coin one. Four reasons, in the order
 * they mattered:
 *
 *  1. A document is true whole or it is absent. A client-fetched screen has four states and the
 *     recorded defect of this project is that two of them collapse into one and the screen then
 *     makes a confident false statement — five instances, three of them inside the fix for the
 *     previous one (design system §26). A page whose data arrives in the same response as the page
 *     has no loading state, no error state and no empty state to collapse: either the receipt
 *     rendered or the navigation failed.
 *  2. The money rule points the same way. Every figure on the receipt must be computed by the
 *     server (bin/ci-money-lint.php's total ban covers resources/js/pages/admin/finance/). The
 *     totals are computed below, in PHP, and travel as {amount_minor, currency}; a JSON endpoint
 *     would compute them in exactly the same place and add a second surface to keep in step.
 *  3. The refusal has one place to live. A page route lets the refusal BE the response — one
 *     status, one reason, rendered by the same component. An endpoint would refuse in JSON and
 *     still need a page to render that refusal, i.e. two statements of one rule.
 *  4. There is no second consumer. An endpoint with one caller that is a page is the primitive
 *     built ahead of its consumer; routes/web.php:237-241 (the statement page) is the in-repo shape
 *     for resolving a finance screen's own props server-side, and this follows it.
 *
 * ── THE PERMISSION IS `finance.access`, READ OFF THE SIBLINGS ──
 *
 * The route carries no ability of its own, so it takes the group's `finance.access`. That is what
 * the sibling READ surfaces carry and it was checked rather than chosen: the statement page
 * (routes/web.php:237-241) declares no extra middleware inside the same
 * `['auth','tenant','permission:finance.access']` group, and its feed
 * GET /v1/finance/students/{student:uuid}/invoices (routes/endpoints/finance.php:73) declares none
 * either inside the api group's `finance.access`. `finance.payment.record` is a different and
 * narrower capability — it is the authority to take money in, held by the seats that record it. A
 * receipt is a READ of a payment already recorded, and every seat that can read the statement the
 * payment appears on can read the receipt for it.
 *
 * ── THE MIGRATED REFUSAL (opening-balance spec §4) ──
 *
 * `origin = 'migrated'` means WCBS collected that money before the cutover. Printing this system's
 * receipt for it would be this system claiming an act it did not perform, so the route refuses:
 * 403, with the reason rendered on the page. It refuses SERVER-SIDE and independently of anything
 * the client did — PaymentResource's `receiptable` flag lets the statement row say why in place,
 * but that flag is a courtesy and this branch is the control. The entry point is never hidden:
 * the statement links every payment row here, including the ones that will be refused, because an
 * operator who cannot find the button learns nothing and one who reads the reason learns the rule.
 */
class PaymentReceiptController extends Controller
{
    /**
     * The return type is Symfony's, not Illuminate's: Inertia\Response::toResponse answers a
     * JsonResponse to an Inertia XHR visit and a view Response to a full page load (Response.php:206
     * / :211), and only the Symfony base type covers both. Inertia's own client renders a page
     * response carrying a >= 400 status rather than showing an error dialog (@inertiajs/core
     * dist/index.js:2271-2284 — httpException fires, then setPage), so the 403 below is a rendered
     * refusal on both paths, not a modal and not a blank screen.
     */
    public function __invoke(Request $request, Payment $payment): Response
    {
        // SchoolScope has already bounded the route-model binding: a payment belonging to another
        // School does not resolve at all, and produces the same 404 as a uuid that never existed.
        // Nothing below re-checks it, and nothing below should — a second, hand-written school_id
        // predicate here would read as the boundary and hide the fact that the model carries it.
        $school = ActiveSchool::getOrFail();

        $props = [
            'school' => [
                'name' => $school->name,
                'address' => $school->address,
                'phone' => $school->phone,
                'email' => $school->email,
            ],
            'reference' => (int) $payment->reference,
        ];

        if (! $payment->isReceiptable()) {
            return Inertia::render('admin/finance/receipt', $props + [
                'receipt' => null,
                'refusal' => $payment->receiptRefusalReason(),
            ])->toResponse($request)->setStatusCode(403);
        }

        return Inertia::render('admin/finance/receipt', $props + [
            'receipt' => $this->document($payment),
            'refusal' => null,
        ])->toResponse($request);
    }

    /**
     * The receipt document itself. EVERY figure on it is computed here — the page performs no
     * arithmetic and no comparison on money, which is why `fully_applied` and `held_on_account`
     * travel as booleans rather than as amounts the page would have to compare.
     *
     * WHAT THE MONEY WAS APPLIED TO IS DERIVED, NOT DECLARED. There is no stored "kind" on a
     * payment distinguishing the invoice-allocated door (POST …/invoices/{uuid}/payments) from the
     * account-level one (POST …/students/{uuid}/payments), and there must not be one, because the
     * distinction does not survive: an account-level payment gains allocations LATER when a new
     * invoice draws its unallocated remainder forward (PaymentAllocation::RULE_CREDIT_APPLIED_
     * FORWARD_OLDEST_FIRST). So the receipt states the position as it stands — the invoices this
     * money has reached, and whether any of it is still sitting on the account — which is true for
     * a payment through either door and stays true after a later draw-down.
     *
     * @return array<string, mixed>
     */
    private function document(Payment $payment): array
    {
        $payment->loadMissing(['student', 'bankAccount']);

        $allocations = $payment->allocations()->with('invoice')->orderBy('id')->get();

        $currency = $payment->amount->currency;

        // Integer minor units throughout (ADR 0037/0039) — never a float, and never in the browser.
        $allocatedKobo = array_sum($allocations->map(fn (PaymentAllocation $a) => $a->amount->toKobo())->all());
        $allocated = Money::fromKobo((int) $allocatedKobo, $currency);
        $unallocated = $payment->amount->minus($allocated);

        return [
            // BOTH DATES ARE FORMATTED HERE, not in the page. bin/ci-money-lint.php's format ban
            // is TOTAL inside resources/js/pages/admin/finance/ — every number there is treated as
            // money — so `new Date(…).toLocaleString()` on this page is a lint finding, and it was
            // one before this line existed. Formatting server-side is the right answer rather than
            // the way round it: the receipt is a document, and the document's own rendering of a
            // date belongs with the rest of its values.
            'received_at' => $payment->received_at->format('j F Y'),
            'recorded_at' => $payment->created_at->format('j F Y, H:i'),
            'payer_name' => $payment->payer_name,
            'method' => $payment->method,
            'amount' => $payment->amount,
            'student_name' => $payment->student?->full_name,
            // WHERE THE MONEY LANDED. `method` is a column DEFAULT ('manual',
            // 2026_07_19_100002_create_fee_payments_tables.php:36) that no writer ever overrides, so
            // it says almost nothing on its own; the account the bursar reconciles against is the
            // part of "how was this paid" a parent can actually check. Always present here — the
            // origin-keyed CHECK makes bank_account_id NOT NULL for every portal payment, and a
            // receipt is only ever issued for a portal payment.
            'bank_account' => $payment->bankAccount === null ? null : [
                'label' => $payment->bankAccount->label,
                'bank_name' => $payment->bankAccount->bank_name,
            ],
            'allocations' => $allocations->map(fn (PaymentAllocation $a) => [
                'invoice_number' => $a->invoice?->displayNumber(),
                'academic_context' => $a->invoice?->academic_context,
                'amount' => $a->amount,
                // Which of the two allocation rules put this money here. A receipt that showed an
                // invoice with no such note would let a carry-forward draw-down read as money paid
                // against that invoice on the day the receipt was issued, which is a false
                // statement about when it was applied.
                'applied_on_receipt' => $a->allocation_rule === PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE,
            ])->values()->all(),
            'allocated_total' => $allocated,
            'unallocated_amount' => $unallocated,
            // The page renders on these two; it compares no amounts itself.
            'fully_applied' => $unallocated->isZero() && $allocations->isNotEmpty(),
            'held_on_account' => ! $unallocated->isZero(),
            'nothing_applied' => $allocations->isEmpty(),
        ];
    }
}
