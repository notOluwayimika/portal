<?php

namespace App\Finance\Http\Requests;

use App\Finance\Enums\CreditNoteKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization is by route middleware (permission:finance.credit-note.issue) — issuing
 * forgives money, so it is gated on a DISTINCT permission beyond finance.access. Inline
 * ->can()/->hasRole() is banned inside app/Finance by the boundary lint, which is why
 * authz stays at the edge. Maker-checker (an approver) is Ph3, deliberately absent here.
 *
 * The wire carries amount_minor (integer, ADR 0037) — never a decimal.
 */
class IssueCreditNoteRequest extends FormRequest
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
            // Defaults to credit_note in the controller when absent; a write-off is the
            // same mechanism under a distinct, reportable label.
            'kind' => ['sometimes', Rule::enum(CreditNoteKind::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
