<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesImportRows;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportStudentRequest extends FormRequest
{
    use NormalizesImportRows;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->stringifyNumericRowCells('students');
    }

    public function rules(): array
    {
        return [
            // School-SCOPED existence (slice (i)) — an unscoped exists would let
            // another School's curriculum through, and the new composite FK on
            // student_curricula (curriculum_id, school_id) would then surface as a
            // raw QueryException mid-import instead of a clean validation error.
            'curriculum_id' => [
                'required',
                'integer',
                Rule::exists('curricula', 'id')->where('school_id', ActiveSchool::id()),
            ],
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['array'],
            'students.*.first_name' => ['required', 'string', 'max:255'],
            'students.*.last_name' => ['required', 'string', 'max:255'],
            'students.*.middle_name' => ['nullable', 'string', 'max:255'],
            'students.*.gender' => ['nullable', 'string', 'max:50'],
            'students.*.date_of_birth' => ['nullable'],
            'students.*.admission_number' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'curriculum_id.required' => 'A class must be selected before importing.',
            'curriculum_id.exists' => 'The selected class does not exist.',
            'students.required' => 'No student rows were provided.',
            'students.min' => 'At least one student row is required.',
        ];
    }
}
