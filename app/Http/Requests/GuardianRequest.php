<?php

namespace App\Http\Requests;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class GuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        return [
            'first_name'        => ['required', 'string', 'max:255'],
            'middle_name'       => ['nullable', 'string', 'max:255'],
            'last_name'         => ['required', 'string', 'max:255'],
            'gender'            => ['nullable', 'string', Rule::in(GenderTypeEnum::values())],
            'phone'             => ['required', 'string', 'max:50'],
            'whatsapp_number'   => ['nullable', 'string', 'max:50'],
            'city'              => ['nullable', 'string', 'max:255'],
            'state'             => ['nullable', 'string', 'max:255'],
            'country'           => ['nullable', 'string', 'max:255'],
            'postal_code'       => ['nullable', 'string', 'max:50'],
            'occupation'        => ['nullable', 'string', 'max:255'],
            'employer_name'     => ['nullable', 'string', 'max:255'],
            'marital_status'    => ['nullable', 'string', Rule::in(MaritalStatusEnum::values())],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'id_type'           => ['nullable', 'string', Rule::in(GuardianIdTypeEnum::values())],
            'id_number'         => ['nullable', 'string', 'max:255'],
            'id_expiry_date'    => ['nullable', 'date'],
            'status'            => [$isUpdate ? 'sometimes' : 'nullable', 'string', Rule::enum(GuardianStatusEnum::class)],
            'can_login'         => ['nullable', 'boolean'],
            'email'             => [
                Rule::requiredIf(fn() => filter_var($this->input('can_login'), FILTER_VALIDATE_BOOLEAN)),
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->guardian?->user_id),
            ],
            'relationship'      => ['nullable', 'string', Rule::in(GuardianRelationshipEnum::values())],
            'photo'             => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
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
