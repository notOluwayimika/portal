<?php

namespace App\Http\Requests;

use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteStudentRequest extends FormRequest
{
    /**
     * ADR 0044: enrollment lifecycle, authorized by permission.
     *
     * Not a checker ability, so the super-admin bypass still applies — this is
     * the call that the authority probe recorded as a live super_admin lockout
     * (hasRole never consults the Gate); moving to can() resolves it.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('student_curriculum.promote') ?? false;
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
