<?php

namespace App\Finance\Http\Requests;

use App\Finance\Enums\CreditNoteKind;
use App\Support\Money;
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
            // Rule::in([DEFAULT_CURRENCY]) — the same pin RecordPaymentRequest and
            // RecordAccountPaymentRequest already carry, and this rule is now identical to theirs.
            // It used to be shape-only ('size:3' + ^[A-Z]{3}$), which refuses 'ngn' and 'usdd' and
            // ACCEPTS a well-formed 'USD'. That gap became reachable when the summary moved to
            // Money::format(), which throws on a non-NGN amount: the credit note commits, and the
            // approval notification is built AFTER the commit (see SubmitCreditNote — "AFTER the
            // commit, never inside it"), so the throw lands on an already-written row. A 500 with a
            // committed credit note and no notification is strictly worse than the 422 here.
            //
            // Refuse, don't uppercase: 'ngn'/'usd' are not typos to repair silently. And refusing a
            // well-formed second currency is truth rather than a hardcode — a second currency is a
            // schema-and-ledger project, not a validation rule. The Action's invoice-currency match
            // and Money's own constructor still backstop this.
            'currency' => ['sometimes', 'string', Rule::in([Money::DEFAULT_CURRENCY])],
            // Defaults to credit_note in the controller when absent; a write-off is the
            // same mechanism under a distinct, reportable label.
            'kind' => ['sometimes', Rule::enum(CreditNoteKind::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
