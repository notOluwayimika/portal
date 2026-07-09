<?php

namespace App\Http\Requests;

use App\Enums\BehavioralGradeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BehavioralAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('create_behavioral_assessments') ?? false)
            || ($this->user()?->can('edit_behavioral_assessments') ?? false);
    }

    public function rules(): array
    {
        $pillarRules = ['required', Rule::enum(BehavioralGradeEnum::class)];

        return [
            'student_curriculum_id' => ['required', 'string', 'exists:student_curricula,uuid'],
            'punctuality' => $pillarRules,
            'mental_alertness' => $pillarRules,
            'respect' => $pillarRules,
            'neatness' => $pillarRules,
            'politeness' => $pillarRules,
            'honesty' => $pillarRules,
            'relationship_with_peers' => $pillarRules,
            'teamwork' => $pillarRules,
            'perseverance' => $pillarRules,
            'comment' => ['nullable', 'string'],
        ];
    }
}
