<?php

namespace App\Http\Requests;

use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('head_of_school'));
    }

    public function rules(): array
    {
        return [
            'from_student_curriculum_id' => [
                'required',
                'uuid',
                // School-SCOPED existence (slice ii) — the presence verifier bypasses
                // Eloquent global scopes, so SchoolScope does not reach this rule.
                Rule::exists('student_curricula', 'uuid')->where('school_id', ActiveSchool::id()),
            ],
            'to_curriculum_id' => [
                'required',
                'uuid',
                'exists:curricula,uuid',
            ],
        ];
    }
}
