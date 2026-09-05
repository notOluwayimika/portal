<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Models\Invoice;
use App\Finance\Services\GatewayFeeCalculator;
use App\Finance\Services\InvoiceReadModel;
use App\Finance\Services\InvoiceSettlement;
use App\Support\Money;

/**
 * WHAT A PARENT WILL BE CHARGED, BEFORE THEY AGREE TO ANYTHING.
 *
 * ── A FEE PREVIEW, NOT A QUOTE, AND THE DISTINCTION IS NOT PEDANTRY ────────────────────────────
 *
 * "Quote" is the step-5 spec's word (§1.4) for a DIFFERENT and deliberately parked design: asking
 * PAYSTACK what it will charge before the parent commits. That one is deferred because it puts a
 * network call and a failure mode in front of the confirmation screen.
 *
 * This is local arithmetic through {@see GatewayFeeCalculator}. **No provider
 * call, no network, and it is explicitly not the design line 278 defers.** A reviewer who sees
 * "quote" in this area will reasonably conclude the parked design shipped, so the word is avoided.
 *
 * ── IT WRITES NOTHING, AND THAT IS ITS ENTIRE JUSTIFICATION ────────────────────────────────────
 *
 * The alternative was initiate-first: create the transaction, show the parent the fee, let them
 * proceed or back out. Cheaper, and it leaves a `pending` row for every parent who sees the fee and
 * reconsiders — which is exactly D4's population in the discrepancy report that shipped this
 * morning. The pay screen would manufacture the report's noise on day one, at whatever rate parents
 * balk at a charge they had not expected.
 *
 * So no row exists until the parent agrees. If this action ever grows a log row keyed by invoice it
 * has quietly become the thing that was rejected.
 *
 * ── THE GROSS NEVER ROUND-TRIPS ────────────────────────────────────────────────────────────────
 *
 * The client DISPLAYS these figures and sends none of them back. Confirm posts `amount_minor` — the
 * bill the parent chose — and {@see InitiateGatewayPayment} recomputes the gross from it. A tampered
 * preview cannot become a charge because nothing reads one back. Preview is display-only, always.
 *
 * ── THE INVOICE MUST ARRIVE HYDRATED ───────────────────────────────────────────────────────────
 *
 * `InvoiceSettlement::for()` reads `allocated_minor` / `approved_credit_minor` off the model and
 * treats an ABSENT one as zero — so an invoice fetched by a plain `where('uuid')->first()` reports
 * its FULL TOTAL outstanding, silently. On this surface that is a parent told "₦80,000 settles this
 * bill in full" when most of it is heading to credit, and the overpayment branch never firing.
 *
 * Hence {@see InvoiceReadModel::withSettlement()} rather than the resolved model. The hazard is
 * documented at three other call sites and reached production once, on the generate route.
 */
final class PreviewGatewayFee
{
    public function __construct(
        private readonly InitiateGatewayPayment $initiate,
        private readonly InvoiceReadModel $invoices,
    ) {}

    /**
     * @param  Money  $bill  what the payer typed — not the outstanding, and not capped to it
     * @return array{gross: Money, applied: Money, excess: Money}
     *
     * @throws BusinessRuleException every refusal initiate would make, in the same order
     */
    public function handle(Invoice $invoice, Money $bill): array
    {
        // THE SAME GUARDS AND THE SAME ARITHMETIC AS THE PATH THAT WILL CHARGE THEM. Shared rather
        // than restated: two copies agree the day they are written, and the parity arm between this
        // and initiate would then be measuring a coincidence rather than a property.
        $gross = $this->initiate->payableGross($invoice, $bill);

        $outstanding = (new InvoiceSettlement)
            ->for($this->invoices->withSettlement($invoice))['outstanding'];

        // THE SPLIT IS COMPUTED HERE BECAUSE IT IS ARITHMETIC ON MONEY. `bin/ci-money-lint.php`
        // forbids it in the browser, and `outstanding` is a prop the client may be holding from
        // before another guardian paid. The branch condition belongs to the same authority that
        // decided the bill was payable.
        $appliedKobo = min($bill->toKobo(), $outstanding->toKobo());

        return [
            'gross' => $gross,
            'applied' => Money::fromKobo($appliedKobo, $bill->currency),
            'excess' => Money::fromKobo($bill->toKobo() - $appliedKobo, $bill->currency),
        ];
    }
}
