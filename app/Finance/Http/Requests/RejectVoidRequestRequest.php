<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ph3b checker side — REJECT a pending void request. The permission is gated by route middleware
 * (permission:finance.invoice.void-request.reject); the record-level maker ≠ checker rule is the
 * VoidRequestPolicy (invoked in the controller). A reason is REQUIRED — audit needs the "why",
 * and the domain layer (VoidRequest::transitionTo) refuses a reasonless rejection as a backstop.
 */
class RejectVoidRequestRequest extends FormRequest
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
