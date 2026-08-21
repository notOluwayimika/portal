<?php

namespace App\Http\Requests;

use App\Services\ProgressionGraph;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The three scalar progression columns on a class level.
 *
 * A FormRequest, NOT inline `$request->validate()` inside a try/catch. The neighbouring setup
 * controllers wrap their bodies in `catch (\Throwable)`, which swallows ValidationException and
 * returns a generic 500 — field errors cannot reach the user on those screens at all. These screens
 * exist to tell an operator WHICH RULE they broke before a rollover, so validation happens here,
 * before the controller body, and renders 422 with per-field messages.
 *
 * ── EVERY RULE MIRRORS A GUARD THAT ALREADY EXISTS FURTHER DOWN ───────────────────────────────────
 * The point is not to re-implement the database. It is that each of these is enforced somewhere the
 * operator cannot see — a composite FK, a trigger, or a job that declines silently at rollover — and
 * the difference between learning it now and learning it then is a stranded cohort.
 *
 *   next_class_level_id  → composite FK (next_class_level_id, school_id): must exist IN THIS SCHOOL
 *   next_class_level_id  → trigger class_levels_progression_guard_*: must not be the level itself
 *   next_class_level_id  → NOTHING IN THE DB: must not close a ring (see ProgressionGraph)
 *   arm_distribution_strategy → the same trigger's value guard: round_robin | explicit_only
 */
class UpdateClassLevelProgressionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level `permission:academic_setup.manage` already gates this; returning true here
        // keeps authorization in one place rather than splitting it across two mechanisms.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'next_class_level_id' => ['nullable', 'uuid'],
            'default_exam_type_id' => ['nullable', 'uuid'],
            // MIRRORS the trigger's value guard. `in:` rather than an enum rule because the column is
            // a plain string constrained by trigger, not a native enum.
            'arm_distribution_strategy' => ['required', 'string', 'in:round_robin,explicit_only'],
        ];
    }

    /**
     * The rules that need the school and the target level in hand.
     *
     * Resolution is deliberately through the SCHOOL relation rather than `exists:class_levels,uuid`:
     * a bare exists rule accepts another school's uuid, which the composite FK would then refuse at
     * write time as an opaque driver error. Same idiom the existing setup controllers use.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $school = ActiveSchool::getOrFail();
            $level = $this->route('classLevel');

            $nextUuid = $this->input('next_class_level_id');

            if ($nextUuid !== null) {
                $next = $school->classLevels()->where('uuid', $nextUuid)->first();

                if ($next === null) {
                    // MIRRORS the composite FK (next_class_level_id, school_id).
                    $validator->errors()->add(
                        'next_class_level_id',
                        'That class level does not belong to this school.'
                    );
                } elseif ((int) $next->id === (int) $level->id) {
                    // MIRRORS class_levels_progression_guard_bi/_bu.
                    $validator->errors()->add(
                        'next_class_level_id',
                        'A class level cannot progress into itself.'
                    );
                } else {
                    // MIRRORS NOTHING IN THE DATABASE — the trigger catches A -> A only; a multi-node
                    // ring is legal at every row. Asked of the graph AS IT WOULD BE with this pointer
                    // applied, so a ring is refused while the stored graph is still acyclic. Same walk
                    // the rollover gate runs, so the screen and the gate cannot disagree.
                    $cycle = ProgressionGraph::cycleIfPointed(
                        (int) $school->id,
                        (int) $level->id,
                        (int) $next->id,
                    );

                    if ($cycle !== null) {
                        $validator->errors()->add(
                            'next_class_level_id',
                            'This would create a progression loop: '.implode(' → ', $cycle)
                            .'. Pupils in a loop swap levels at rollover instead of advancing.'
                        );
                    }
                }
            }

            /*
             * NOT `return`-ing above, deliberately. The self and cycle checks legitimately need a
             * RESOLVED $next, so they are chained on it — but bailing out of the whole closure would
             * suppress this independent check, and a form with two bad fields would surface one, get
             * corrected, and then fail again on the other. The two fields are unrelated; their
             * validation should be too.
             */

            $examTypeUuid = $this->input('default_exam_type_id');

            if ($examTypeUuid !== null && $school->examTypes()->where('uuid', $examTypeUuid)->doesntExist()) {
                // MIRRORS the composite FK (default_exam_type_id, school_id).
                $validator->errors()->add(
                    'default_exam_type_id',
                    'That exam type does not belong to this school.'
                );
            }
        });
    }

    /**
     * Resolve the submitted uuids to ids, school-scoped. Called only after validation passes.
     *
     * @return array<string, int|string|null>
     */
    public function resolved(): array
    {
        $school = ActiveSchool::getOrFail();

        return [
            'next_class_level_id' => $this->input('next_class_level_id')
                ? $school->classLevels()->where('uuid', $this->input('next_class_level_id'))->value('id')
                : null,
            'default_exam_type_id' => $this->input('default_exam_type_id')
                ? $school->examTypes()->where('uuid', $this->input('default_exam_type_id'))->value('id')
                : null,
            'arm_distribution_strategy' => $this->input('arm_distribution_strategy'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'next_class_level_id' => 'next class level',
            'default_exam_type_id' => 'default exam type',
            'arm_distribution_strategy' => 'arm distribution strategy',
        ];
    }
}
