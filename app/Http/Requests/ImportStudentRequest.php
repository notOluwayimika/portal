<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'curriculum_id'               => ['required', 'integer', 'exists:curricula,id'],
            'students'                    => ['required', 'array', 'min:1'],
            'students.*'                  => ['array'],
            'students.*.first_name'       => ['required', 'string', 'max:255'],
            'students.*.last_name'        => ['required', 'string', 'max:255'],
            'students.*.middle_name'      => ['nullable', 'string', 'max:255'],
            'students.*.gender'           => ['nullable', 'string', 'max:50'],
            'students.*.date_of_birth'    => ['nullable'],
            'students.*.admission_number' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'curriculum_id.required' => 'A class must be selected before importing.',
            'curriculum_id.exists'   => 'The selected class does not exist.',
            'students.required'      => 'No student rows were provided.',
            'students.min'           => 'At least one student row is required.',
        ];
    }
}
