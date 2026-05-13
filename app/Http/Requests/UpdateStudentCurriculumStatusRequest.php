<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentCurriculumStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('head_of_school'));
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in(['active', 'promoted', 'repeated', 'withdrawn']),
            ],
        ];
    }
}
