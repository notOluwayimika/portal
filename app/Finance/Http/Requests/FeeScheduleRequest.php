<?php

namespace App\Finance\Http\Requests;

use App\Finance\Http\Requests\Concerns\HasFeeScheduleItemRules;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Author a fee schedule (S1 commit 2). The route gates on `finance.fee-schedule.manage`; this validates
 * the shape. `term_id`/`class_level_id` are soft references (the schedule carries live FKs to them, but
 * the wire only names ids); `status` is NEVER a wire field — publication is the Action's job, and in
 * commit 4 the approval Action's alone.
 *
 * SCOPE, as of U1 commit 1: `store` and `supersede` ONLY. The draft-edit route, which validated both
 * ids here and then discarded them, now has its own {@see EditFeeScheduleDraftRequest}. The `items.*`
 * rules moved to {@see HasFeeScheduleItemRules} so both requests read one definition — see that trait
 * for why a second copy of the `bank_account_id` rule is the thing worth preventing.
 *
 * @property array<int, array<string, mixed>> $items
 */
class FeeScheduleRequest extends FormRequest
{
    use HasFeeScheduleItemRules;

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
            // SCOPED TO THE ACTIVE SCHOOL, and the bare `exists:terms,id` they replace was a real hole
            // on `store` and `supersede`, which READ these two. Nothing at the database catches it:
            // `finance_fee_schedules` carries three SINGLE-column foreign keys — term_id → terms.id,
            // class_level_id → class_levels.id, school_id → schools.id (read from
            // information_schema, not from the migration) — and no composite (school_id, term_id)
            // pair, so another School's term id is a perfectly valid reference. The
            // (school_id, term, class level) uniqueness key does not help either: it prevents a second
            // OPEN schedule for a slot, it does not ask whether the slot belongs to you.
            //
            // The result would be a schedule sitting in your School, priced by you, keyed to a term
            // and a class level that are somebody else's — and `SchoolScope` would happily show it to
            // you, because the schedule's own school_id is correct.
            //
            // Written as an explicit `where` rather than through the scoped model because
            // Rule::exists queries the TABLE and no global scope applies to it. Same shape and same
            // reason as `items.*.bank_account_id` in {@see HasFeeScheduleItemRules} — it used to be
            // BELOW this line and U1 moved it into the shared trait — and as the fee_item_id rule on
            // GenerateInvoiceRequest — leaving one of the three unscoped after scoping the others
            // would be incoherent rather than merely incomplete.
            'term_id' => ['required', 'integer', Rule::exists('terms', 'id')->where('school_id', ActiveSchool::id())],
            'class_level_id' => ['required', 'integer', Rule::exists('class_levels', 'id')->where('school_id', ActiveSchool::id())],
            'label' => ['required', 'string', 'max:255'],
            ...$this->feeItemRules(),
        ];
    }
}
