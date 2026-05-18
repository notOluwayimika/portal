<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teachers'                    => ['required', 'array', 'min:1'],
            'teachers.*'                  => ['array'],
            'teachers.*.first_name'       => ['required', 'string', 'max:255'],
            'teachers.*.last_name'        => ['required', 'string', 'max:255'],
            'teachers.*.email'            => ['nullable', 'string', 'max:255'],
            'teachers.*.staff_number'     => ['nullable', 'string', 'max:255'],
            'teachers.*.gender'           => ['nullable', 'string', 'max:50'],
            'teachers.*.date_of_birth'    => ['nullable'],
            'teachers.*.address'          => ['nullable', 'string', 'max:500'],
            'teachers.*.qualification'    => ['nullable', 'string', 'max:255'],
            'teachers.*.hire_date'        => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'teachers.required' => 'No teacher rows were provided.',
            'teachers.min'      => 'At least one teacher row is required.',
        ];
    }
}
