<?php

namespace App\Http\Requests\StudentSubject;

use Illuminate\Foundation\Http\FormRequest;

class UnenrollStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('student_curriculum.unenroll');
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'The reason may not be longer than 500 characters.',
        ];
    }
}
