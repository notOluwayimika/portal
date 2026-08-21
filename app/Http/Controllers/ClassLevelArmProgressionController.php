<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncArmProgressionRequest;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmProgression;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The explicit arm progression map for one class level: which of its arms feeds which arm of the
 * level its pupils move into.
 *
 * Consulted FIRST by MoveToNextYearJob::resolveArm, before stream-aware label matching and before
 * distribution — it is the operator's override, and the only way to express 11H -> 12P when no label
 * matches.
 *
 * ── STALE ROWS ARE THE REASON THIS CONTROLLER REPORTS RATHER THAN JUST LISTS ──────────────────────
 * A mapping is valid only relative to the source level's `next_class_level_id`. Change that pointer
 * and EVERY row in this map silently becomes wrong: the arms it names still exist, both are still in
 * the same school, and every foreign key is still satisfied — but they now point into a level these
 * pupils no longer move to.
 *
 * Nothing in the database notices. MoveToNextYearJob does: it refuses a mapped target outside the
 * progression level and leaves that pupil UNRESOLVED, logged and otherwise silent. So the failure
 * mode is a cohort that quietly does not move, discovered when someone notices they are in the wrong
 * class months later.
 *
 * Which is why {@see index()} computes staleness on every read instead of trusting the rows, and why
 * {@see destroyAll()} exists as the one-action recovery. The map cannot be "repaired" row by row
 * after the target level changes — nothing about the old targets is salvageable — so the honest
 * offer is to clear it and start again against the new level.
 */
class ClassLevelArmProgressionController extends Controller
{
    public function index(ClassLevel $classLevel): JsonResponse
    {
        return response()->json($this->payloadFor($classLevel));
    }

    public function sync(SyncArmProgressionRequest $request, ClassLevel $classLevel): JsonResponse
    {
        $mappings = $request->resolvedMappings();
        $ownedSourceIds = $request->allSourceArmIds();

        DB::transaction(function () use ($classLevel, $mappings, $ownedSourceIds) {
            foreach ($mappings as $sourceId => $targetId) {
                // Only ever touching rows whose SOURCE is an arm of this level — the level this
                // request is authoritative over. A map row for another level's arm is not this
                // endpoint's to rewrite, even within the same school.
                if (! in_array($sourceId, $ownedSourceIds, true)) {
                    continue;
                }

                if ($targetId === null) {
                    ClassLevelArmProgression::where('source_class_level_arm_id', $sourceId)->delete();

                    continue;
                }

                ClassLevelArmProgression::updateOrCreate(
                    ['source_class_level_arm_id' => $sourceId],
                    [
                        'school_id' => $classLevel->school_id,
                        'target_class_level_arm_id' => $targetId,
                    ]
                );
            }
        });

        return response()->json(array_merge(
            ['message' => 'Arm mapping updated.'],
            $this->payloadFor($classLevel->fresh())
        ));
    }

    /**
     * Clear every mapping whose source is an arm of this level.
     *
     * The recovery for a changed `next_class_level_id`: once the target level moves, no existing row
     * points anywhere useful and there is nothing to salvage row by row.
     */
    public function destroyAll(ClassLevel $classLevel): JsonResponse
    {
        $sourceIds = ClassLevelArm::where('class_level_id', $classLevel->id)->pluck('id');

        ClassLevelArmProgression::whereIn('source_class_level_arm_id', $sourceIds)->delete();

        return response()->json(array_merge(
            ['message' => 'Arm mapping cleared.'],
            $this->payloadFor($classLevel->fresh())
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(ClassLevel $classLevel): array
    {
        $sourceArms = $this->armsOf((int) $classLevel->id);
        $targetArms = $classLevel->next_class_level_id === null
            ? collect()
            : $this->armsOf((int) $classLevel->next_class_level_id);

        $rows = ClassLevelArmProgression::with('targetClassLevelArm.arm', 'targetClassLevelArm.stream')
            ->whereIn('source_class_level_arm_id', $sourceArms->pluck('id'))
            ->get()
            ->keyBy('source_class_level_arm_id');

        // The progression target's CLASS LEVEL id — deliberately not the list of its arm ids. Those
        // are different id spaces, and comparing a class_level_id against arm ids reads every valid
        // mapping as stale (caught by the invalidation test, which is the point of asserting the
        // clean state before the change rather than only the dirty one after it).
        $targetLevelId = $classLevel->next_class_level_id === null
            ? null
            : (int) $classLevel->next_class_level_id;

        $mappings = $sourceArms->map(function (ClassLevelArm $arm) use ($rows, $targetLevelId) {
            $row = $rows->get($arm->id);
            $target = $row?->targetClassLevelArm;

            // STALE: the row exists and every FK is satisfied, but the target is not in the level
            // these pupils now move into — so the rollover will refuse it and leave them unplaced.
            // This is exactly MoveToNextYearJob::resolveArm's own test, so the screen and the job
            // agree on what "valid mapping" means.
            $isStale = $target !== null && (int) $target->class_level_id !== $targetLevelId;

            return [
                'source_arm' => $this->describe($arm),
                'target_arm' => $target === null ? null : $this->describe($target),
                'is_stale' => $isStale,
            ];
        })->values();

        $staleCount = $mappings->where('is_stale', true)->count();

        return [
            'is_terminal' => $classLevel->next_class_level_id === null,
            'target_level' => $classLevel->next_class_level_id === null ? null : [
                'id' => $classLevel->nextClassLevel?->uuid,
                'name' => $classLevel->nextClassLevel?->name,
            ],
            'mappings' => $mappings,
            // The arms a target may be chosen from. Constraining the PICKER is the primary defence;
            // the request rule is the backstop for anything that bypasses it.
            'target_arms' => $targetArms->map(fn (ClassLevelArm $arm) => $this->describe($arm))->values(),
            'warnings' => $this->warningsFor($classLevel, $staleCount, $sourceArms, $targetArms),
        ];
    }

    /**
     * @return Collection<int, ClassLevelArm>
     */
    private function armsOf(int $classLevelId): Collection
    {
        return ClassLevelArm::with('arm', 'stream')
            ->where('class_level_id', $classLevelId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Label AND stream, because `class_level_arms` is UNIQUE on (class_level_id, arm_id, stream_id):
     * arm "B" can legitimately appear twice in one level, differing only by stream. Every stream_id
     * is NULL across both schools today, so a label alone happens to be unambiguous — and would stop
     * being so the first time a school configures streams, silently offering the operator two
     * identical-looking options.
     *
     * @return array<string, mixed>
     */
    private function describe(ClassLevelArm $arm): array
    {
        return [
            'id' => $arm->uuid,
            'label' => $arm->arm?->label,
            'stream' => $arm->stream?->name,
        ];
    }

    /**
     * @param  Collection<int, ClassLevelArm>  $sourceArms
     * @param  Collection<int, ClassLevelArm>  $targetArms
     * @return list<string>
     */
    private function warningsFor(
        ClassLevel $classLevel,
        int $staleCount,
        Collection $sourceArms,
        Collection $targetArms
    ): array {
        $warnings = [];

        if ($staleCount > 0) {
            $warnings[] = "{$staleCount} mapping(s) point outside "
                .($classLevel->nextClassLevel->name ?? 'the level these pupils move into')
                .' — most likely because that level was changed after the map was made. The rollover '
                .'refuses these and leaves those pupils unplaced. Clear the map and remake it.';
        }

        if ($classLevel->next_class_level_id !== null && $targetArms->isEmpty()) {
            $warnings[] = 'The level these pupils move into has no arms, so there is nothing to map '
                .'them into.';
        }

        // Only meaningful under explicit_only: round_robin distributes whatever the map does not
        // cover, so an unmapped arm there is normal rather than a gap.
        if ($classLevel->arm_distribution_strategy === 'explicit_only') {
            $unmapped = $sourceArms->count() - ClassLevelArmProgression::whereIn(
                'source_class_level_arm_id',
                $sourceArms->pluck('id')
            )->count();

            if ($unmapped > 0) {
                $warnings[] = "This level places pupils by explicit mapping only, and {$unmapped} of "
                    .'its arms are unmapped. Pupils in those arms will be left unplaced at rollover.';
            }
        }

        return $warnings;
    }
}
