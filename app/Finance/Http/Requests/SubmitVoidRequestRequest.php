<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ph3b maker side — SUBMIT a request to void an invoice. The permission is gated by route
 * middleware (permission:finance.invoice.void-request.submit). A reason is REQUIRED: a void
 * reverses a whole charge, so audit needs the "why" — the domain layer (SubmitVoidRequest)
 * refuses a reasonless submit as a second line of defence.
 */
class SubmitVoidRequestRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
