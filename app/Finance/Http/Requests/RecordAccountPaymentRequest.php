<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'currency' => ['sometimes', 'string', 'size:3'],
            'payer_name' => ['required', 'string', 'max:255'],
        ];
    }
}
