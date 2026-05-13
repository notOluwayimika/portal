<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterStudentCurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('head_of_school'));
    }

    public function rules(): array
    {
        return [
            'curriculum_id' => ['required', 'uuid', 'exists:curricula,uuid'],
        ];
    }
}
