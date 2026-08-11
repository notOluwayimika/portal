<?php

namespace App\Finance\Http\Requests;

use App\Finance\Http\Requests\Concerns\HasFeeScheduleItemRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a DRAFT fee schedule (U1 commit 1). The route gates on `finance.fee-schedule.manage`; this
 * validates the shape of what `EditFeeScheduleDraft::handle()` actually consumes — a `label` and a
 * set of `items`, and nothing else.
 *
 * WHY THIS CLASS EXISTS. `PUT /v1/finance/fee-schedules/{feeSchedule:uuid}/draft` was validated by
 * {@see FeeScheduleRequest}, which requires `term_id` and `class_level_id`. The Action never receives
 * either: `EditFeeScheduleDraft::handle(FeeSchedule, string $label, array $items)`. So the endpoint
 * demanded two fields, refused the request without them, and then discarded them — a page editing a
 * draft would have had to send a term and a class level it is not changing and that nothing reads,
 * and a page that omitted them got a 422 naming fields its operator cannot see. This is option (1),
 * "a dedicated EditFeeScheduleDraftRequest", of the ticket #234's cold review left open for U1 to
 * decide (`docs/handoff/tickets/edit-draft-request-reuse-decide-at-u1.md`, DELETED by the commit
 * that adds this file — the decision it was holding is now this class).
 *
 * IT DOES NOT CARRY `term_id`/`class_level_id`, AND MUST NOT ACQUIRE THEM. Moving a draft to a
 * different term or class level is a different act with a different uniqueness collision
 * (`finance_fee_schedules_pending_unique`); letting an edit do it silently is the same defect
 * `FeeScheduleController::supersede` deliberately avoids. `FeeScheduleRequest` keeps both fields for
 * `store` and `supersede`, which DO read them.
 *
 * The `items.*` rules — including the School-scoped, not-deactivated `bank_account_id` existence
 * rule — are SHARED with `FeeScheduleRequest` through {@see HasFeeScheduleItemRules} rather than
 * copied, so the isolation rule cannot drift between the create path and the edit path.
 *
 * @property array<int, array<string, mixed>> $items
 */
class EditFeeScheduleDraftRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            ...$this->feeItemRules(),
        ];
    }
}
