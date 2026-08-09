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
            // REQUIRED, with no default at any layer. A payment's business date is the operator's
            // to state: a receipt handed over on Friday and keyed on Monday belongs to Friday, and
            // finance_payments is append-only so nobody can correct it afterwards. Defaulting it to
            // today at the edge would make "the operator did not say" indistinguishable from "the
            // operator said today", forever. The UI pre-fills today; the API refuses silence.
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            // Required only when the date is not today — U9's spec. required_unless compares the
            // SUBMITTED value, so a back-dated receipt cannot arrive without an explanation and a
            // same-day one is not made to invent one.
            'received_at_reason' => ['nullable', 'required_unless:received_at,'.now()->toDateString(), 'string', 'max:255'],
        ];
    }
}
