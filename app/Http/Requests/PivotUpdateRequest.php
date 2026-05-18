<?php

namespace App\Http\Requests;

use App\Enums\GuardianRelationshipEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PivotUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('guardian.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'relationship' => ['sometimes', 'string', Rule::in(GuardianRelationshipEnum::values())],
            'is_primary'   => ['sometimes', 'boolean'],
            'can_login'    => ['sometimes', 'boolean'],
        ];
    }
}
