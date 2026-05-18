<?php

namespace App\Http\Requests\StudentSubject;

use App\Models\StudentCurriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOptionalSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('student_subject.add_optional');
    }

    public function rules(): array
    {
        /** @var StudentCurriculum $enrollment */
        $enrollment = $this->route('studentCurriculum');

        return [
            'curriculum_subject_id' => [
                'required',
                'integer',
                Rule::exists('curriculum_subjects', 'id')->where('curriculum_id', $enrollment->curriculum_id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'curriculum_subject_id.required' => 'Please select a subject to add.',
            'curriculum_subject_id.exists'   => 'The selected subject is not available in this curriculum.',
        ];
    }
}
