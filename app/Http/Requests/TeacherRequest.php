<?php

namespace App\Http\Requests;

use App\Enums\GenderTypeEnum;
use App\Enums\TeacherStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'first_name'    => ['required', 'string', 'max:255'],
            'last_name'     => ['required', 'string', 'max:255'],
            'staff_number'  => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('teachers', 'staff_number')
                    ->where('school_id', $this->user()->school_id)
                    ->ignore($this->teacher?->id),
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
}
