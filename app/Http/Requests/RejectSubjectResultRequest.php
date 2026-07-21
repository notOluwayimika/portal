<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectSubjectResultRequest extends FormRequest
{
    /**
     * ADR 0044: the checker side of the result workflow.
     *
     * This is a CHECKER ability, so super_admin does not pass here via the
     * Gate::before bypass (ADR 0040 / ApprovalAbility). Approval authority is
     * granted, never inherited from platform authority. The record-level half
     * of the rule (maker != checker) needs the status row, which a FormRequest
     * never loads — CurriculumSubjectController::authorizeDecision owns it.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('result.reject') ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
