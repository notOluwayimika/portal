<?php

namespace App\Http\Requests;

use App\Enums\TeacherAssignmentRoleEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_teacher_assignments') ?? false;
    }

    public function rules(): array
    {
        $isBoardingParent = $this->input('role') === TeacherAssignmentRoleEnum::BOARDING_PARENT->value;

        return [
            'teacher_id' => ['required', 'string', 'exists:teachers,uuid'],
            'role' => ['required', Rule::enum(TeacherAssignmentRoleEnum::class)],
            'gender' => [$isBoardingParent ? 'required' : 'prohibited', Rule::in(['male', 'female'])],
            'class_level_arm_ids' => ['required', 'array', 'min:1'],
            'class_level_arm_ids.*' => ['string', 'exists:class_level_arms,uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('role') !== TeacherAssignmentRoleEnum::FORM_TEACHER->value) {
                return;
            }

            $ids = $this->input('class_level_arm_ids', []);

            if (is_array($ids) && count($ids) !== 1) {
                $validator->errors()->add('class_level_arm_ids', 'A form teacher can only be assigned to exactly one class arm.');
            }
        });
    }
}
