<?php

namespace App\Finance\Http\Requests;

use App\Finance\Enums\CreditNoteKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ph3 maker side — SUBMIT a credit note for approval. Authorization is by route middleware
 * (permission:finance.credit-note.submit) — proposing a credit is the maker gate, distinct
 * from finance.access. No record-level rule here: the note does not exist yet, and maker ≠
 * checker only bites at the DECISION (the CreditNotePolicy + DB CHECK). ->hasRole() is
 * banned inside app/Finance by the boundary lint, which is why authz stays at the edge.
 *
 * The wire carries amount_minor (integer, ADR 0037) — never a decimal.
 */
class SubmitCreditNoteRequest extends FormRequest
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
