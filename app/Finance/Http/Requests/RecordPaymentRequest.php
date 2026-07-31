<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'payer_name' => ['required', 'string', 'max:255'],
        ];
    }
}
