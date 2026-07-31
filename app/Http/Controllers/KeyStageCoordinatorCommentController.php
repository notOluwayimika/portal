<?php

namespace App\Http\Controllers;

use App\Concerns\FormatsClassLevelArmName;
use App\Concerns\ResolvesTermFilter;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Http\Resources\StudentResource;
use App\Models\ClassLevelArmTeacher;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;

/**
 * The Key Stage Coordinator's comment on a pupil's term.
 *
 * Primary calls this seat a Key Stage Coordinator; secondary calls the same job a
 * Head of School. This controller is deliberately a NARROW copy of
 * HeadOfSchoolCommentController rather than a shared base class: that one also
 * edits the form teacher's and boarding parent's comments on their behalf, which
 * this seat has no business doing. Copying the two methods it does need keeps the
 * extra authority out rather than inheriting it and remembering to block it.
 *
 * Scoping is identical and deliberately so — the coordinator is a Teacher with
 * `key_stage_coordinator` assignments in `class_level_arm_teacher`, and may only
 * see or write comments for the arms they hold.
 */
class KeyStageCoordinatorCommentController extends Controller
{
    use FormatsClassLevelArmName, ResolvesTermFilter;

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('manage_key_stage_coordinator_comments'), 403);

        $classLevelArmIds = $this->classLevelArmIds();

        if ($classLevelArmIds->isEmpty()) {
            return Response::success([]);
        }

        $term = $this->resolveTermFilter($request);

        if (! $term) {
            return Response::success([]);
        }

        $studentCurricula = StudentCurriculum::query()
            ->whereIn('status', $this->enrollmentStatusesFor($term))
            ->whereHas('curriculum', fn ($query) => $query
                ->where('term_id', $term->id)
                ->whereIn('class_level_arm_id', $classLevelArmIds))
            // Exclude withdrawn students in SQL. `students` is soft-deleted while
            // `student_curricula` rows outlive the withdrawal, so without this the
            // enrollment arrives with a null `->student` and the mapping below dies
            // on it. Fifth site in this family — see ClassResultsController.
            ->whereHas('student')
            ->with([
                'student',
                'curriculum.classLevelArm.classLevel',
                'curriculum.classLevelArm.arm',
                'curriculum.classLevelArm.stream',
            ])
            ->get();

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $studentCurricula->map(function (StudentCurriculum $studentCurriculum): array {
            $classLevelArm = $studentCurriculum->curriculum?->classLevelArm;

            return [
                'student_curriculum_id' => $studentCurriculum->uuid,
                'student' => new StudentResource($studentCurriculum->student),
                'class_name' => $classLevelArm ? $this->classLevelArmName($classLevelArm) : null,
                'comment' => $studentCurriculum->key_stage_coordinator_comment,
            ];
        });

        return Response::success($rows->values());
    }

    public function update(Request $request, StudentCurriculum $studentCurriculum)
    {
        abort_unless(auth()->user()->can('manage_key_stage_coordinator_comments'), 403);

        $data = $request->validate([
            'comment' => ['sometimes', 'nullable', 'string'],
        ]);

        // Assignment is the authorization, not merely the listing filter: without
        // this, any holder of the permission could comment on any enrollment in the
        // school by uuid.
        $classLevelArmIds = $this->classLevelArmIds();
        $classLevelArmId = $studentCurriculum->curriculum?->class_level_arm_id;

        abort_unless($classLevelArmId && $classLevelArmIds->contains($classLevelArmId), 403);

        if (array_key_exists('comment', $data)) {
            $studentCurriculum->update(['key_stage_coordinator_comment' => $data['comment']]);
        }

        return Response::success([
            'comment' => $studentCurriculum->key_stage_coordinator_comment,
        ]);
    }

    /**
     * The arms this coordinator holds, in the ACTIVE school.
     *
     * inActiveSchool() is load-bearing: `class_level_arm_teacher` carries no
     * school_id of its own, so a teacher visible in several schools would otherwise
     * bring their assignments across.
     *
     * @return Collection<int, int>
     */
    private function classLevelArmIds(): Collection
    {
        $teacher = Teacher::where('user_id', auth()->id())->first();

        if (! $teacher) {
            return new Collection;
        }

        return ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', TeacherAssignmentRoleEnum::KEY_STAGE_COORDINATOR->value)
            ->inActiveSchool()
            ->pluck('class_level_arm_id');
    }
}
