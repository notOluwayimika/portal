<?php

namespace App\Http\Requests;

use App\Models\MarkingComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'curriculum_subject_id' => ['required', 'string', 'exists:curriculum_subjects,uuid'],
            'student_id' => ['required', 'string', 'exists:students,uuid'],
            'marking_component_id' => ['required', 'string', 'exists:marking_components,uuid'],
            'score' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $mc = MarkingComponent::find($this->input('marking_component_id'));
            if (!$mc) {
                return;
            }

            // weight is a fraction (e.g. 0.300). Max raw score for the component
            // is interpreted as weight * 100 (a 30%-weighted CA is scored /30).
            $max = round(((float) $mc->weight) * 100, 2);

            if ((float) $this->input('score') > $max) {
                $v->errors()->add('score', "Score cannot exceed {$max} for this component.");
            }
        });
    }
}
