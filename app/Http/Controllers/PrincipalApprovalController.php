<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatusEnum;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\StudentCurriculum;
use App\Notifications\Contracts\Notifier;
use App\Notifications\Types\ResultReady;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PrincipalApprovalController extends Controller
{
    public function __construct(private readonly Notifier $notifier) {}

    public function classLevel(Request $request, ClassLevel $classLevel)
    {
        abort_unless($classLevel->school_id === ActiveSchool::id(), 404);

        return $this->updateApproval(
            $request,
            fn (Builder $query) => $query->whereHas(
                'curriculum.classLevelArm',
                fn (Builder $armQuery) => $armQuery->where('class_level_id', $classLevel->id),
            ),
        );
    }

    public function classLevelArm(Request $request, ClassLevelArm $classLevelArm)
    {
        abort_unless($classLevelArm->classLevel?->school_id === ActiveSchool::id(), 404);

        return $this->updateApproval(
            $request,
            fn (Builder $query) => $query->whereHas(
                'curriculum',
                fn (Builder $curriculumQuery) => $curriculumQuery->where('class_level_arm_id', $classLevelArm->id),
            ),
        );
    }

    private function updateApproval(Request $request, callable $scope)
    {
        $data = $request->validate(['approved' => ['required', 'boolean']]);

        $query = StudentCurriculum::query()
            ->where('status', StudentStatusEnum::ACTIVE)
            ->whereHas('curriculum', fn (Builder $query) => $query->where('status', 'active'));

        $scope($query);

        // Captured BEFORE the update, because the notification is for enrolments
        // that were NOT already approved. Re-approving an already-approved class
        // (the toggle is idempotent and gets clicked twice) must not re-notify
        // every guardian in it.
        $newlyApproved = $data['approved']
            ? (clone $query)->where('principal_approval', false)->get()
            : collect();

        $updated = $query->update(['principal_approval' => $data['approved']]);

        $this->notifyGuardians($newlyApproved, $request);

        return response()->json([
            'approved' => $data['approved'],
            'updated' => $updated,
        ]);
    }

    /**
     * Tell each pupil's guardians their result is available.
     *
     * ONE NOTIFICATION PER ENROLMENT, not per guardian. A parent with three
     * children in the class gets three feed rows, each deep-linking to that
     * child's result — which is what a parent actually wants to click. Collapsing
     * them into one message is a v2 concern (bundling) and belongs on the OUTBOUND
     * side; doing it here, via a dedup key containing the guardian id, would
     * collide on the second and third child and silently lose them.
     *
     * The dedup key is per ENROLMENT, so this loop is safe to re-run: a repeated
     * approval finds the existing row instead of creating a second one.
     *
     * @param  \Illuminate\Support\Collection<int, StudentCurriculum>  $enrolments
     */
    private function notifyGuardians($enrolments, Request $request): void
    {
        $schoolId = ActiveSchool::getOrFail()->id;
        $actorId = (int) $request->user()->id;

        foreach ($enrolments as $enrolment) {
            $this->notifier->send(new ResultReady($enrolment, $schoolId, $actorId));
        }
    }
}
