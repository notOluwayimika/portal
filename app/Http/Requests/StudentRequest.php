<?php

namespace App\Http\Requests;

use App\Enums\StudentStatusEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        logger()->info('Validating StudentRequest', [$this->student?->id]);

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date'],
            'admission_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('students', 'admission_number')
                    ->ignore($this->student?->id, 'id')
            ],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
            'curriculum_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:curricula,id'],
            'promoted_to_id' => ['nullable', 'integer', 'exists:curricula,id'],
        ];
    }
}
