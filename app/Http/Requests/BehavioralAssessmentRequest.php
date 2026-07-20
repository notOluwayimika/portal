<?php

namespace App\Http\Requests;

use App\Enums\BehavioralGradeEnum;
use App\Support\ActiveSchool;
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
            // School-SCOPED existence (slice ii). Laravel's presence verifier queries
            // the DB directly and does NOT apply Eloquent global scopes, so adding
            // SchoolScope to StudentCurriculum does not reach this rule — a foreign
            // School's uuid would still validate and only fail later (or not at all).
            'student_curriculum_id' => [
                'required',
                'string',
                Rule::exists('student_curricula', 'uuid')->where('school_id', ActiveSchool::id()),
            ],
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
