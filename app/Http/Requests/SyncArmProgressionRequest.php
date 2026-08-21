<?php

namespace App\Http\Requests;

use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Scopes\SchoolScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Replace a class level's arm progression map: which arm of THIS level feeds which arm of the level
 * its pupils move into.
 *
 * ── THE RULE NO FOREIGN KEY ENFORCES, AND WHY IT MATTERS MOST HERE ────────────────────────────────
 * `class_level_arm_progressions` carries composite FKs that guarantee both arms belong to the same
 * SCHOOL — and nothing more. Nothing ties the mapped target to the source level's
 * `next_class_level_id`. So an operator can map 7A into a Year 9 arm and every constraint in the
 * database accepts the write.
 *
 * What happens then is the reason this validation exists: MoveToNextYearJob::resolveArm REFUSES a
 * mapped target whose class level is not the progression target — it does not fall through to label
 * matching, because falling through would quietly place the pupil somewhere else and hide the
 * misconfiguration. It logs and leaves the pupil UNRESOLVED. So a bad map is not a loud failure at
 * rollover; it is a pupil who silently does not move, discovered when someone notices they are in
 * the wrong class.
 *
 * The write succeeds, the rollover declines, and nobody is told in between. This request is the only
 * place that gap can be closed, which is why the rule is stated here rather than left to the job.
 *
 * ── A LEVEL WITH NO NEXT CANNOT HAVE A MAP AT ALL ─────────────────────────────────────────────────
 * If `next_class_level_id` is NULL the level is terminal — nobody progresses out of it — so there is
 * no target level for a mapping to point INTO. The whole sync is refused rather than each row, since
 * the problem is the level's configuration and not any individual pair.
 */
class SyncArmProgressionRequest extends FormRequest
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
            'mappings' => ['present', 'array'],
            'mappings.*.source_arm_id' => ['required', 'uuid'],
            // Nullable so the UI can send "no mapping for this arm" as an explicit clear rather than
            // by omission — an omitted source and a source mapped to nothing are different edits.
            'mappings.*.target_arm_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ClassLevel|null $level */
            $level = $this->route('classLevel');

            if ($level === null) {
                return;
            }

            $mappings = (array) $this->input('mappings', []);

            if ($mappings === []) {
                return;
            }

            if ($level->next_class_level_id === null) {
                $validator->errors()->add(
                    'mappings',
                    'This class level is terminal — nobody progresses out of it, so there is nowhere '
                    .'for an arm mapping to point. Set where its pupils move to first.'
                );

                return;
            }

            $sourceArmIds = $this->armIdsFor((int) $level->id);
            $targetArmIds = $this->armIdsFor((int) $level->next_class_level_id);

            $seenSources = [];

            foreach ($mappings as $index => $mapping) {
                $sourceUuid = $mapping['source_arm_id'] ?? null;
                $targetUuid = $mapping['target_arm_id'] ?? null;

                $sourceId = $sourceArmIds[$sourceUuid] ?? null;

                if ($sourceId === null) {
                    // Composite FK guarantees same-school; this adds "and belongs to THIS level".
                    $validator->errors()->add(
                        "mappings.{$index}.source_arm_id",
                        'That arm does not belong to this class level.'
                    );

                    continue;
                }

                // MIRRORS class_level_arm_progressions_source_unique — one target per source, so the
                // map can never answer the same question twice.
                if (isset($seenSources[$sourceId])) {
                    $validator->errors()->add(
                        "mappings.{$index}.source_arm_id",
                        'This arm is mapped more than once.'
                    );

                    continue;
                }

                $seenSources[$sourceId] = true;

                if ($targetUuid === null) {
                    continue; // an explicit "no mapping" — legitimate, and removes any existing row
                }

                // ── THE LOAD-BEARING RULE ──────────────────────────────────────────────────────────
                // No FK expresses this. Without it the write succeeds and the pupil silently fails to
                // move at rollover.
                if (! isset($targetArmIds[$targetUuid])) {
                    $validator->errors()->add(
                        "mappings.{$index}.target_arm_id",
                        'That arm is not in the class level these pupils move into, so the rollover '
                        .'would refuse it and leave them unplaced. Pick an arm of the target level.'
                    );
                }
            }
        });
    }

    /**
     * Arms of one class level, keyed by uuid — school-scoped, so a uuid from another school simply
     * is not in the map and fails the lookup above rather than reaching a composite FK.
     *
     * @return array<string, int>
     */
    private function armIdsFor(int $classLevelId): array
    {
        return ClassLevelArm::where('class_level_id', $classLevelId)
            ->pluck('id', 'uuid')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The submitted map as source id => target id|null, resolved school-scoped. Only valid after
     * validation passes.
     *
     * @return array<int, int|null>
     */
    public function resolvedMappings(): array
    {
        /** @var ClassLevel $level */
        $level = $this->route('classLevel');

        $sourceArmIds = $this->armIdsFor((int) $level->id);
        $targetArmIds = $level->next_class_level_id === null
            ? []
            : $this->armIdsFor((int) $level->next_class_level_id);

        $resolved = [];

        foreach ((array) $this->input('mappings', []) as $mapping) {
            $sourceId = $sourceArmIds[$mapping['source_arm_id'] ?? ''] ?? null;

            if ($sourceId === null) {
                continue;
            }

            $targetUuid = $mapping['target_arm_id'] ?? null;
            $resolved[$sourceId] = $targetUuid === null ? null : ($targetArmIds[$targetUuid] ?? null);
        }

        return $resolved;
    }

    /**
     * Every arm of this level, whether mapped or not — the sync must be able to REMOVE a row, and it
     * can only do that for arms it is authoritative over.
     *
     * @return list<int>
     */
    public function allSourceArmIds(): array
    {
        /** @var ClassLevel $level */
        $level = $this->route('classLevel');

        return ClassLevelArm::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $level->school_id)
            ->where('class_level_id', $level->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
