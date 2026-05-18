<?php

namespace App\Http\Requests;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a guardian-detail update.
 *
 * Sensitive fields (email, phone) are gated by the `guardian.update_credentials`
 * permission when the underlying user has an active login.
 */
class GuardianUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('guardian.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $guardian = $this->route('guardian');
        if (!$guardian) {
            return;
        }

        // If the actor lacks credential permission AND the guardian has an active
        // login user, strip email/phone from the payload so users can't sneak it past.
        $hasCredPerm = $this->user()?->can('guardian.update_credentials') ?? false;
        $user        = $guardian->user;
        $loginActive = $user && !$user->isDisabled();

        if (!$hasCredPerm && $loginActive) {
            $this->request->remove('email');
            // Phone is a guardian-table column; only block it for login-active accounts.
            $this->request->remove('phone');
        }
    }

    public function rules(): array
    {
        $guardian = $this->route('guardian');
        $userId   = $guardian?->user_id;

        return [
            'first_name'        => ['sometimes', 'string', 'max:255'],
            'middle_name'       => ['nullable', 'string', 'max:255'],
            'last_name'         => ['sometimes', 'string', 'max:255'],
            'gender'            => ['nullable', 'string', Rule::in(GenderTypeEnum::values())],
            'phone'             => ['sometimes', 'string', 'max:50'],
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
            'status'            => ['sometimes', 'string', Rule::enum(GuardianStatusEnum::class)],
            'email'             => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }
}
