<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterStudentCurriculumRequest extends FormRequest
{
    /**
     * ADR 0044: enrollment lifecycle, authorized by permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('student_curriculum.register') ?? false;
    }

    public function rules(): array
    {
        return [
            'curriculum_id' => ['required', 'uuid', 'exists:curricula,uuid'],
        ];
    }
}
