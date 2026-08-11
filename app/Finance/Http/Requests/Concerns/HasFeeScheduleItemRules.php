<?php

namespace App\Finance\Http\Requests\Concerns;

use App\Finance\Models\BankAccount;
use App\Support\ActiveSchool;
use Illuminate\Validation\Rule;

/**
 * The `items.*` half of a fee-schedule body, in ONE place.
 *
 * U1 split `PUT …/draft` off `FeeScheduleRequest` into `EditFeeScheduleDraftRequest`, because the
 * edit route validated `term_id`/`class_level_id` and then discarded them. The split is the decision
 * #234's cold review
 * left open for U1 (ticket `edit-draft-request-reuse-decide-at-u1.md`, deleted by that same commit);
 * this trait is the cost that ticket named alongside its option (1) — "the cost is keeping the
 * item-rule reuse genuinely shared rather than copied — a second copy of that rule is exactly what
 * the domain commit avoided".
 *
 * What is single-sourced here is not a convenience. `items.*.bank_account_id` is an ISOLATION rule:
 * a second copy of it can be weakened, or simply left behind, on one of the two request classes, and
 * the result is a route through which another School's bank account is an acceptable destination for
 * this School's money. One definition, two callers, no drift.
 */
trait HasFeeScheduleItemRules
{
    /**
     * @return array<string, mixed>
     */
    protected function feeItemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            // The configured DESTINATION for this charge — active accounts in this School only, the
            // same rule the payment routes use. finance_fee_items.bank_account_id is NOT NULL with
            // no default, so this is the layer that turns a missing destination into a 422 an
            // operator can act on rather than a database error.
            'items.*.bank_account_id' => [
                'required',
                Rule::exists(BankAccount::class, 'uuid')
                    ->where('school_id', ActiveSchool::id())
                    ->whereNull('deactivated_at'),
            ],
            'items.*.amount_minor' => ['required', 'integer', 'min:1'],
            // regex mirrors Money's ISO-4217 invariant — a bad case/format is a 422 here, not CreateFeeSchedule's
            // Money::fromKobo → InvalidArgumentException → 500 inside the transaction (f293358 finish).
            'items.*.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'items.*.is_mandatory' => ['sometimes', 'boolean'],
            'items.*.is_discountable' => ['sometimes', 'boolean'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemSpecs(): array
    {
        return array_values((array) $this->input('items', []));
    }
}
