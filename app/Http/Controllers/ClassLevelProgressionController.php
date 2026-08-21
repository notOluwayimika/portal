<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassLevelParticipationRequest;
use App\Http\Requests\SyncClassLevelExamTypesRequest;
use App\Http\Requests\UpdateClassLevelProgressionRequest;
use App\Http\Resources\ClassLevelProgressionResource;
use App\Models\ClassLevel;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The progression configuration for one class level: where its pupils go, which term slots it runs,
 * and which exam types it offers.
 *
 * A SEPARATE CONTROLLER, not more methods on ClassLevelArmController — that class already carries
 * four entities across 300+ lines.
 *
 * ── NO try/catch AROUND VALIDATION, DELIBERATELY ──────────────────────────────────────────────────
 * The neighbouring setup controllers wrap each action in `catch (\Throwable)`, which catches
 * ValidationException and turns a field error into a generic 500. Every rule these screens mirror
 * would be invisible under that. Validation happens in FormRequests, BEFORE these methods run, so a
 * failure renders 422 with per-field messages and never reaches a catch block. This is the one place
 * the plan does not extend its nearest neighbour, and the reason is that telling an operator which
 * rule they broke is the entire job of these screens.
 *
 * Every submitted uuid is resolved through the SCHOOL relation (see the FormRequests), which is what
 * keeps a cross-school id out. Route-model binding plus SchoolScope means a foreign class level 404s
 * before any of this runs.
 */
class ClassLevelProgressionController extends Controller
{
    public function show(ClassLevel $classLevel): JsonResponse
    {
        return response()->json([
            'progression' => new ClassLevelProgressionResource($this->hydrate($classLevel)),
            // Advisories come back on EVERY read, not only from the edit that happens to compute
            // them. A level SITS in the unresolvable-exam-type state; an operator who configured
            // everything else, navigated away and came back to finish would otherwise never see it.
            'warnings' => $this->warningsFor($classLevel),
        ]);
    }

    public function update(UpdateClassLevelProgressionRequest $request, ClassLevel $classLevel): JsonResponse
    {
        $classLevel->update($request->resolved());
        $fresh = $classLevel->fresh();

        return response()->json([
            'message' => 'Progression updated.',
            'progression' => new ClassLevelProgressionResource($this->hydrate($fresh)),
            // Clearing the default exam type can CREATE the unresolvable state, so this read has to
            // report it too.
            'warnings' => $this->warningsFor($fresh),
        ]);
    }

    public function storeParticipation(
        StoreClassLevelParticipationRequest $request,
        ClassLevel $classLevel
    ): JsonResponse {
        ClassLevelTermParticipation::create([
            'school_id' => $classLevel->school_id,
            'class_level_id' => $classLevel->id,
            'term_order' => $request->integer('term_order'),
            // v1 creates participation NON-CCM. A CCM slot is an explicit decision and the toggle is
            // not in the UI yet; the PATCH endpoint below exists for when it is.
            'is_ccm' => $request->boolean('is_ccm'),
        ]);

        return response()->json([
            'message' => 'Term slot added.',
            'progression' => new ClassLevelProgressionResource($this->hydrate($classLevel->fresh())),
            'warnings' => $this->warningsFor($classLevel->fresh()),
        ], 201);
    }

    /**
     * Toggle a slot's CCM flag. Reachable by API, not surfaced in the v1 UI — see the note above.
     */
    public function updateParticipation(
        ClassLevel $classLevel,
        ClassLevelTermParticipation $participation
    ): JsonResponse {
        abort_unless((int) $participation->class_level_id === (int) $classLevel->id, 404);

        $participation->update(['is_ccm' => ! $participation->is_ccm]);

        return response()->json([
            'message' => 'Term slot updated.',
            'progression' => new ClassLevelProgressionResource($this->hydrate($classLevel->fresh())),
            'warnings' => $this->warningsFor($classLevel->fresh()),
        ]);
    }

    /**
     * Removing a slot is the act of saying the level does not run it — presence IS participation.
     */
    public function destroyParticipation(
        ClassLevel $classLevel,
        ClassLevelTermParticipation $participation
    ): JsonResponse {
        // Nested-route integrity: the slot must belong to the level in the URL. Both are already
        // School-scoped, so this closes the remaining same-school mismatch.
        abort_unless((int) $participation->class_level_id === (int) $classLevel->id, 404);

        $participation->delete();

        return response()->json([
            'message' => 'Term slot removed.',
            'progression' => new ClassLevelProgressionResource($this->hydrate($classLevel->fresh())),
            'warnings' => $this->warningsFor($classLevel->fresh()),
        ]);
    }

    /**
     * Replace the exam-type set. A sync rather than add/remove endpoints because the screen edits it
     * as a set — and because the membership question ("is X allowed here") is answered by the whole
     * set, not by any one row.
     */
    public function syncExamTypes(
        SyncClassLevelExamTypesRequest $request,
        ClassLevel $classLevel
    ): JsonResponse {
        $ids = $request->resolvedIds();

        DB::transaction(function () use ($classLevel, $ids) {
            ClassLevelExamType::where('class_level_id', $classLevel->id)
                ->whereNotIn('exam_type_id', $ids ?: [0])
                ->delete();

            foreach ($ids as $examTypeId) {
                ClassLevelExamType::firstOrCreate([
                    'school_id' => $classLevel->school_id,
                    'class_level_id' => $classLevel->id,
                    'exam_type_id' => $examTypeId,
                ]);
            }
        });

        return response()->json([
            'message' => 'Exam types updated.',
            'progression' => new ClassLevelProgressionResource($this->hydrate($classLevel->fresh())),
            'warnings' => $this->warningsFor($classLevel->fresh()),
        ]);
    }

    /**
     * Advisory only — these are states the configuration is ALLOWED to be in but which make a job
     * decline silently at rollover. The screen shows them; nothing blocks on them, because each is a
     * legitimate intermediate state while an operator is still configuring.
     *
     * @return list<string>
     */
    private function warningsFor(ClassLevel $classLevel): array
    {
        $warnings = [];

        $hasExamTypes = ClassLevelExamType::where('class_level_id', $classLevel->id)->exists();

        if (! $hasExamTypes && $classLevel->default_exam_type_id === null) {
            // MoveToNextYearJob::resolveExamType hard-stops here rather than guessing a certificate,
            // leaving every pupil of this level unplaced with only a log line to show for it.
            $warnings[] = 'This level runs no exam types and has no default, so end-of-year cannot '
                .'decide which exam type its pupils move into — they will be left unplaced.';
        }

        return $warnings;
    }

    private function hydrate(ClassLevel $classLevel): ClassLevel
    {
        return $classLevel->load([
            'nextClassLevel',
            'defaultExamType',
            'termParticipation',
            'examTypes.examType',
        ]);
    }
}
