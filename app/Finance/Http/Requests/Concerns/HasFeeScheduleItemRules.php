<?php

namespace App\Finance\Http\Requests\Concerns;

use App\Finance\Models\BankAccount;
use App\Support\ActiveSchool;
use App\Support\Money;
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
            // TWO DIFFERENT FAILURES, ONE LINE.
            //
            // The regex mirrors Money's ISO-4217 invariant — a bad case/format is a 422 here, not
            // CreateFeeSchedule's Money::fromKobo → InvalidArgumentException → 500 inside the
            // transaction (f293358 finish). That half stops a MALFORMED currency.
            //
            // `required` — U1 commit 2 — stops an ABSENT one, which is the worse failure because it
            // is silent. An edit replaces a schedule's items WHOLESALE, so an omitted currency is not
            // "unchanged": the Actions read `$item['currency'] ?? Money::DEFAULT_CURRENCY` and the
            // line comes back with the same minor units under a different denomination. A USD item
            // edited through a body that forgot this field became an NGN item, and the schedule then
            // reported a total that is not an amount of anything. `sometimes` made that a valid
            // request; `required` makes it unrepresentable at the edge.
            //
            // REQUIRED AT THE HTTP EDGE, DEFAULTED AT THE ACTION — and BOTH are live, so neither is
            // dead code to be tidied away. This rule governs only bodies that pass through a
            // FormRequest; {@see \App\Finance\Actions\CreateFeeSchedule} and
            // {@see \App\Finance\Actions\EditFeeScheduleDraft} are also called in-process (the suite
            // alone does so ~100 times), and those callers never see a validation rule, so the `??`
            // in each Action is what keeps a direct `handle()` call working.
            //
            // Rule::in([DEFAULT_CURRENCY]) — the THIRD surface to take this pin, after the payment
            // requests and (in the F1 commit) the credit-note and invoice-line rules. This one is
            // CONSISTENCY, not a bypass being closed: a fee item's currency does not flow into an
            // invoice line's Money — `fee_item_id` is provenance, and GenerateInvoiceRequest's
            // lineSpecs() reads the amount and the currency from the wire, never from the cited
            // item — so a USD fee item could not reach Money::format() through an invoice. It could
            // still reach a fee-schedule read path, and a shape-only rule on the last of four
            // otherwise-identical surfaces is the kind of asymmetry that reads as intentional to
            // the next person and is not.
            'items.*.currency' => ['required', 'string', Rule::in([Money::DEFAULT_CURRENCY])],
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
