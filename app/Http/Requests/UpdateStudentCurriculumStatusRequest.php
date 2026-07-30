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
                // 'promoted' is deliberately NOT settable here (S1 promotion-link closure). It is a state a
                // student ARRIVES AT via promote() — which writes the promotion link — not one an admin
                // asserts; a manual 'promoted' would manufacture exactly the status='promoted' + NULL link row
                // the student_curricula_promoted_requires_link CHECK forbids. Moving OFF promoted (to
                // active/repeated/withdrawn) is still allowed and still clears the link (controller :98/:118).
                Rule::in(['active', 'repeated', 'withdrawn']),
            ],
        ];
    }
}
