<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectSubjectResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('head_of_school'));
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
