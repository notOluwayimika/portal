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
            'currency' => ['sometimes', 'string', 'size:3'],
            'payer_name' => ['required', 'string', 'max:255'],
        ];
    }
}
