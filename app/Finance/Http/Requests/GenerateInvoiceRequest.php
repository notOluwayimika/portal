<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization is by route middleware (role:admin|super_admin) for the skeleton —
 * Finance Policies + maker-checker are Ph2/Ph3. Inline ->hasRole() is banned inside
 * app/Finance by the boundary lint, which is exactly why authz stays at the edge.
 *
 * amount_minor is integer minor units on the wire (ADR 0037) — never a decimal.
 */
class GenerateInvoiceRequest extends FormRequest
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
            'enrollment_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'description' => ['required', 'string', 'max:255'],
        ];
    }
}
