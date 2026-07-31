<?php

namespace App\Finance\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
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
            // Rule::in([DEFAULT_CURRENCY]) — the system processes exactly one currency; refuse at the edge
            // what it cannot process, rather than let a well-formed "USD" (valid ^[A-Z]{3}$) corrupt the
            // balance downstream. Reverses the f293358 regex steer deliberately: a second currency is a
            // schema-and-ledger project, not a validation rule, so refusing the unprocessable is truth, not
            // a hardcode. The Action (invoice currency) and SubledgerPoster (account currency) still backstop.
            'currency' => ['sometimes', 'string', Rule::in([Money::DEFAULT_CURRENCY])],
            'payer_name' => ['required', 'string', 'max:255'],
        ];
    }
}
