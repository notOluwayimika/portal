<?php

namespace App\Http\Controllers;

use App\Concerns\FormatsClassLevelArmName;
use App\Concerns\ResolvesTermFilter;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Enums\TermStatusEnum;
use App\Http\Requests\BehavioralAssessmentRequest;
use App\Http\Resources\BehavioralAssessmentResource;
use App\Http\Resources\StudentResource;
use App\Models\BehavioralAssessment;
use App\Models\ClassLevelArmTeacher;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class BehavioralAssessmentController extends Controller
{
    use FormatsClassLevelArmName, ResolvesTermFilter;

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('view_behavioral_assessments'), 403);

        $term = $this->resolveTermFilter($request);

        $rows = $this->visibleStudentCurricula($term)->map(function (StudentCurriculum $studentCurriculum) {
            $classLevelArm = $studentCurriculum->curriculum ? $studentCurriculum->curriculum->classLevelArm : null;
            $assessment = $studentCurriculum->behavioralAssessments->first();

            return [
                'student_curriculum_id' => $studentCurriculum->uuid,
                'student' => new StudentResource($studentCurriculum->student),
                'class_name' => $classLevelArm ? $this->classLevelArmName($classLevelArm) : null,
                'assessment' => $assessment ? new BehavioralAssessmentResource($assessment) : null,
            ];
        });

        return Response::success($rows->values());
    }

    public function store(BehavioralAssessmentRequest $request)
    {
        $data = $request->validated();

        $studentCurriculum = StudentCurriculum::where('uuid', $data['student_curriculum_id'])->firstOrFail();

        // The assessment is recorded against the term the enrollment belongs
        // to (not the active term), so backdated enrollments produce
        // correctly-dated past-term assessments with the same code path.
        $term = $studentCurriculum->curriculum?->term;

        abort_unless($term, 422, 'There is no term to record an assessment for.');
        abort_if($term->status === TermStatusEnum::UPCOMING, 422, 'Cannot record assessments for an upcoming term.');

        $visibleIds = $this->visibleStudentCurricula($term)->pluck('id');

        abort_unless($visibleIds->contains($studentCurriculum->id), 403);

        $assessment = BehavioralAssessment::updateOrCreate(
            [
                'student_curriculum_id' => $studentCurriculum->id,
                'assessment_term_id' => $term->id,
            ],
            [
                'punctuality' => $data['punctuality'],
                'mental_alertness' => $data['mental_alertness'],
                'respect' => $data['respect'],
                'neatness' => $data['neatness'],
                'politeness' => $data['politeness'],
                'honesty' => $data['honesty'],
                'relationship_with_peers' => $data['relationship_with_peers'],
                'teamwork' => $data['teamwork'],
                'perseverance' => $data['perseverance'],
                'comment' => $data['comment'] ?? null,
                'assessed_by' => auth()->id(),
            ]
        );

        return Response::success(new BehavioralAssessmentResource($assessment));
    }

    private function visibleStudentCurricula(?Term $term): Collection
    {
        $teacher = Teacher::where('user_id', auth()->id())->first();

        if (!$teacher) {
            return new Collection();
        }

        $assignments = ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', TeacherAssignmentRoleEnum::BOARDING_PARENT->value)
            ->get(['class_level_arm_id', 'gender']);

        if ($assignments->isEmpty()) {
            return new Collection();
        }

        if (!$term) {
            return new Collection();
        }

        return StudentCurriculum::query()
            ->whereIn('status', $this->enrollmentStatusesFor($term))
            ->whereHas('curriculum', fn($query) => $query->where('term_id', $term->id))
            ->where(function ($query) use ($assignments) {
                foreach ($assignments as $assignment) {
                    $query->orWhere(function ($groupQuery) use ($assignment) {
                        $groupQuery
                            ->whereHas('curriculum', fn($q) => $q->where('class_level_arm_id', $assignment->class_level_arm_id))
                            ->whereHas('student', fn($q) => $q->where('gender', $assignment->gender?->value));
                    });
                }
            })
            ->with([
                'student',
                'curriculum.classLevelArm.classLevel',
                'curriculum.classLevelArm.arm',
                'curriculum.classLevelArm.stream',
                'behavioralAssessments' => fn($query) => $query->where('assessment_term_id', $term->id),
            ])
            ->get();
    }
}
