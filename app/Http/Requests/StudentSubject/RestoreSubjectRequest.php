<?php

namespace App\Http\Requests\StudentSubject;

use Illuminate\Foundation\Http\FormRequest;

class RestoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('student_subject.restore');
    }

    public function rules(): array
    {
        return [];
    }
}
