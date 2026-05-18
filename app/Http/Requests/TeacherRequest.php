<?php

namespace App\Http\Requests;

use App\Enums\GenderTypeEnum;
use App\Enums\TeacherStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class TeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        return [
            'school_id'     => [Rule::requiredIf(fn() => auth()->user()?->isSuperAdmin()), 'uuid', 'exists:schools,id'],
            'first_name'    => ['required', 'string', 'max:255'],
            'last_name'     => ['required', 'string', 'max:255'],
            'email'         => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->teacher?->user_id),
            ],
            'staff_number'  => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('teachers', 'staff_number')
                    ->ignore($this->teacher?->id, 'id'),
            ],
            'gender'        => ['nullable', 'string', Rule::in(GenderTypeEnum::values())],
            'date_of_birth' => ['nullable', 'date'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'address'       => ['nullable', 'string', 'max:500'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'hire_date'     => ['nullable', 'date'],
            'status'        => [$isUpdate ? 'sometimes' : 'nullable', 'string', Rule::enum(TeacherStatusEnum::class)],
            'photo'         => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422));
    }
}
