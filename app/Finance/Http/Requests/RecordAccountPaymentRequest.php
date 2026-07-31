<?php

namespace App\Finance\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a payment on the ACCOUNT (the route carries {student:uuid}, no invoice). Rules are
 * identical to {@see RecordPaymentRequest} today, but kept a SEPARATE class deliberately: payment
 * methods (cash / transfer / POS) are the next payment slice and will land on one door before the
 * other, so the two request contracts must be free to diverge.
 */
class RecordAccountPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            // regex mirrors Money's own ISO-4217 invariant — bad case/format is a 422 here, not the
            // constructor's InvalidArgumentException → 500 (backstop-reachability audit). Refuse, don't repair.
            // Rule::in([DEFAULT_CURRENCY]) — refuse at the edge what the single-currency system cannot
            // process; a well-formed "USD" would otherwise add straight into an NGN balance. The Action
            // (account currency) and SubledgerPoster backstop this. Reverses the f293358 regex steer.
            'currency' => ['sometimes', 'string', Rule::in([Money::DEFAULT_CURRENCY])],
            'payer_name' => ['required', 'string', 'max:255'],
        ];
    }
}
