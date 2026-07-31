<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Propose a discount-policy change (S1 commit 3). The route gates on
 * finance.discount-policy.change.submit; this validates the wire shape loosely — the DB CHECKs
 * (target_shape, terms_shape, basis exclusivity) are the authoritative guard. `target` is the target
 * policy's uuid (amend/retire); the controller resolves it under SchoolScope.
 */
class SubmitDiscountPolicyChangeRequest extends FormRequest
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
            'kind' => ['required', 'in:create,amend,retire'],
            'target' => ['required_unless:kind,create', 'string'],
            'reason' => ['required', 'string', 'max:255'],

            'name' => ['required_unless:kind,retire', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'basis' => ['required_unless:kind,retire', 'in:amount,percent'],
            // amount XOR percent, refused here so a cross combo is a 422, not the DB terms_shape CHECK's
            // 3819 → 500 (backstop-reachability audit). The CHECK stays as the backstop.
            'value_minor' => ['required_if:basis,amount', 'prohibited_if:basis,percent', 'integer', 'min:1'],
            // regex mirrors Money's ISO-4217 invariant. WORSE than the 500 siblings: value_currency is NOT cast
            // through Money on DiscountPolicyChange/DiscountPolicy, so a bad shape here does not 500 — it PERSISTS
            // (append-only, undeletable) and fails far later at whatever applies the policy. Refuse it at the edge.
            // required_if/prohibited_if kept first, in order (prohibited_if keeps terms_shape unreachable, faa868e).
            'value_currency' => ['required_if:basis,amount', 'prohibited_if:basis,percent', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'percent' => ['required_if:basis,percent', 'prohibited_if:basis,amount', 'integer', 'between:1,100'],
            'requires_approval' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function terms(): array
    {
        return [
            'name' => $this->input('name'),
            'description' => $this->input('description'),
            'basis' => $this->input('basis'),
            'value_minor' => $this->has('value_minor') ? (int) $this->input('value_minor') : null,
            'value_currency' => $this->input('value_currency'),
            'percent' => $this->has('percent') ? (int) $this->input('percent') : null,
            'requires_approval' => $this->boolean('requires_approval'),
        ];
    }
}
