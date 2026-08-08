<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject an opening-balance batch — a reason is required (the route gates on
 * finance.opening-balance.reject; the record-level maker ≠ checker rule is OpeningBalanceBatchPolicy).
 *
 * `max:255` is the storage column's own width: `rejection_reason` is `$table->string(…)` in
 * 2026_08_09_100000_opening_balance_approval_gate.php:83, i.e. varchar(255), the same as the
 * discount-policy-change sibling this mirrors. Checked rather than copied — a rule wider than the
 * column aborts the write at 1406 after the checker has typed the reason.
 */
class RejectOpeningBalanceBatchRequest extends FormRequest
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
