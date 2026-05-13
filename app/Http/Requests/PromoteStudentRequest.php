<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromoteStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('head_of_school'));
    }

    public function rules(): array
    {
        return [
            'from_student_curriculum_id' => [
                'required',
                'uuid',
                'exists:student_curricula,uuid',
            ],
            'to_curriculum_id' => [
                'required',
                'uuid',
                'exists:curricula,uuid',
            ],
        ];
    }
}
