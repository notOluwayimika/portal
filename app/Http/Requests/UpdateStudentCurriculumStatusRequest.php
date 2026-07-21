<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentCurriculumStatusRequest extends FormRequest
{
    /**
     * ADR 0044: enrollment lifecycle, authorized by permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('student_curriculum.update_status') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in(['active', 'promoted', 'repeated', 'withdrawn']),
            ],
        ];
    }
}
