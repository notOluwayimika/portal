<?php

namespace App\Finance\Http\Requests;

use App\Finance\Models\Invoice;
use App\Finance\Services\GuardianPaymentAuthorisation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who may start a payment, and what shape the request must have.
 *
 * NO NEW ABILITY. The route sits behind `parent_portal.access` like its siblings, and WHICH invoice
 * this particular user may pay is a relationship question rather than a permission one —
 * `GuardianPaymentAuthorisation::mayPay()` answers it by asking whether the invoice's student is
 * their ward. A permission cannot express "this parent, that child".
 *
 * THE AMOUNT IS IN MINOR UNITS AND IS AN INTEGER. Never a float, never naira-major: this project's
 * money rule exists because a float amount is a rounding error waiting to be argued about, and the
 * one place it would be unarguable is the number a parent is charged.
 *
 * The RANGE is not validated here beyond positivity. The minimum comes from configuration and the
 * refusal is a BusinessRuleException from the action, so an unconfigured minimum fails loudly
 * rather than being silently absent from a `min:` rule.
 */
final class InitiateGatewayPaymentRequest extends FormRequest
{
    public function authorize(GuardianPaymentAuthorisation $authorisation): bool
    {
        $invoice = $this->resolveInvoice();

        return $invoice !== null && $authorisation->mayPay($this->user(), $invoice);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * The invoice this request names, resolved WITHIN the caller's school scope.
     *
     * Addressed by uuid, as every parent-facing finance route is — a sequential id in a URL a parent
     * holds is an invitation to walk it. `SchoolScope` is active on this query, so a uuid belonging
     * to another school resolves to null and the request is refused as unauthorised rather than as
     * not-found: which of the two it is would itself leak whether the uuid exists.
     *
     * PREFIXED `resolveInvoice`, not `invoice`. FormRequest extends Illuminate\Http\Request, whose
     * method surface is large and called internally by the framework; a generic private name on a
     * framework subclass has corrupted a call and exited PHP with no output before.
     */
    public function resolveInvoice(): ?Invoice
    {
        return Invoice::query()->where('uuid', (string) $this->route('invoice'))->first();
    }
}
