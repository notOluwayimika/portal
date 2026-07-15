<?php

namespace App\Http\Requests;

use App\Enums\BehavioralGradeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PsychomotorSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('create_psychomotor_skills') ?? false)
            || ($this->user()?->can('edit_psychomotor_skills') ?? false);
    }

    public function rules(): array
    {
        $categoryRules = ['required', Rule::enum(BehavioralGradeEnum::class)];

        return [
            'student_curriculum_id' => ['required', 'string', 'exists:student_curricula,uuid'],
            'drawing_colouring' => $categoryRules,
            'cutting_pasting' => $categoryRules,
            'puzzles_building' => $categoryRules,
            'climbing_sliding' => $categoryRules,
            'comment' => ['nullable', 'string'],
        ];
    }
}
