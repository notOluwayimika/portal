<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Reject a discount-policy change — a reason is required (route gates on change.reject). */
class RejectDiscountPolicyChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }
}
