<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A score is posted as the PERCENTAGE the teacher typed, 0–100.
 *
 * The column stores a WEIGHTED value (percentage × the component's weight), and the conversion is
 * done by the controller — never by the client. It used to be done only in score-entry-page.tsx,
 * which meant the meaning of a stored score depended on which JS bundle the browser was running: a
 * cached bundle applying one half of the conversion wrote 100 where 10.0 was meant, or read 10.0
 * where 100 was meant. That is the "I typed 100 and it shows 10.0" bug.
 *
 * `score` is therefore PROHIBITED here. A stale bundle still posting the old weighted field is
 * refused with a 422 telling the teacher to refresh, rather than silently storing a number an
 * order of magnitude out. Failing loudly is the entire point — this rule IS the fix.
 */
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

            // 0–100, always. The per-component ceiling that used to live here (weight × 100) was
            // the weighted max; it is now applied by the controller when it converts, so the
            // number the teacher sees and the number this rule checks are finally the same one.
            'score_percent' => ['required', 'numeric', 'min:0', 'max:100'],

            'score' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score.prohibited' => 'This page is out of date. Please refresh and enter the score again.',
            'score_percent.max' => 'Score must be between 0 and 100.',
            'score_percent.min' => 'Score must be between 0 and 100.',
        ];
    }
}
