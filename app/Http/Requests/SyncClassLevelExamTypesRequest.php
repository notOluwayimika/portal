<?php

namespace App\Http\Requests;

use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Replace the set of exam types a class level runs.
 *
 * SET-VALUED BECAUSE THE DATA IS: Year 10 and Year 11 in school 1 each run BSS Grading AND WAEC
 * Grading, while Year 12 runs WAEC alone. Read together with `class_levels.default_exam_type_id`,
 * this answers two different questions — "is X allowed here" (membership) and "which one if the
 * pupil's current type is not" (the default) — which is why they are a table and a column rather
 * than one field.
 *
 * MIRRORS: `class_level_exam_types_unique` and the composite FK (exam_type_id, school_id).
 */
class SyncClassLevelExamTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exam_type_ids' => ['present', 'array'],
            'exam_type_ids.*' => ['uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $uuids = (array) $this->input('exam_type_ids', []);

            if ($uuids === []) {
                return;
            }

            // MIRRORS the unique: a repeated uuid would be a duplicate row, and telling the operator
            // beats a driver error.
            if (count($uuids) !== count(array_unique($uuids))) {
                $validator->errors()->add('exam_type_ids', 'The same exam type is listed more than once.');

                return;
            }

            // Resolved through the SCHOOL relation, not `exists:exam_types,uuid` — a bare exists rule
            // accepts another school's uuid, which the composite FK then refuses opaquely at write.
            $school = ActiveSchool::getOrFail();
            $found = $school->examTypes()->whereIn('uuid', $uuids)->count();

            if ($found !== count($uuids)) {
                $validator->errors()->add(
                    'exam_type_ids',
                    'One or more of those exam types do not belong to this school.'
                );
            }
        });
    }

    /**
     * The submitted uuids as school-scoped ids, in submission order.
     *
     * @return list<int>
     */
    public function resolvedIds(): array
    {
        $uuids = (array) $this->input('exam_type_ids', []);

        if ($uuids === []) {
            return [];
        }

        $school = ActiveSchool::getOrFail();

        return $school->examTypes()
            ->whereIn('uuid', $uuids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['exam_type_ids' => 'exam types'];
    }
}
