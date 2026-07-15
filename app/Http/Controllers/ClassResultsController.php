<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClassLevelArmResource;
use App\Http\Resources\GradeBoundaryResource;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\GradeBoundary;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;

/**
 * Bulk result sheets (student/results/list) for a whole class level or a
 * single class-level arm. Both routes render the same page; the arm route
 * simply narrows the collection to one arm.
 */
class ClassResultsController extends Controller
{
    public function classLevel(ClassLevel $classLevel)
    {
        abort_unless($classLevel->school_id === ActiveSchool::id(), 404);

        return $this->renderResults(
            ClassLevelArm::query()->where('class_level_id', $classLevel->id),
            "/api/class-levels/{$classLevel->uuid}/principal-approval",
            $classLevel->name,
        );
    }

    public function classLevelArm(ClassLevelArm $classLevelArm)
    {
        $classLevelArm->loadMissing(['classLevel', 'arm', 'stream']);
        abort_unless($classLevelArm->classLevel?->school_id === ActiveSchool::id(), 404);

        $scopeName = collect([
            $classLevelArm->classLevel?->name,
            $classLevelArm->arm?->label,
            $classLevelArm->stream?->name,
        ])->filter()->implode(' ');

        return $this->renderResults(
            ClassLevelArm::query()->where('id', $classLevelArm->id),
            "/api/class-level-arms/{$classLevelArm->uuid}/principal-approval",
            $scopeName ?: 'selected class arm',
        );
    }

    private function renderResults(Builder $armsQuery, string $approvalEndpoint, string $scopeName)
    {
        ini_set('memory_limit', '256M');

        $classLevelArms = $armsQuery->with([
            'classLevel',
            'arm',
            'stream',
            'curricula' => function ($query) {
                $query->where('status', 'active');
            },
            'curricula.examType.gradeBoundaries',
            'curricula.classLevelArm.classLevel',
            'curricula.classLevelArm.arm',
            'curricula.classLevelArm.stream',
            'curricula.term.academicSession',
            'curricula.academicSession',
            'curricula.gradingScheme.items',
            'curricula.markingScheme.components',
            'curricula.curriculumSubjects.subject',
            'curricula.curriculumSubjects.markingComponents',
            'curricula.curriculumSubjects.studentResults' => function ($query) {
                $query->select([
                    'id',
                    'uuid',
                    'curriculum_subject_id',
                    'student_id',
                    'grading_scheme_item_id',
                    'total_score',
                    'grade',
                    'status',
                ]);
            },
            'curricula.curriculumSubjects.studentResults.gradingSchemeItem',
            'curricula.curriculumSubjects.resultStatus',
            'curricula.studentCurricula' => function ($query) {
                $query->where('status', 'active');
            },
            'curricula.studentCurricula.student.photoFile',
            'curricula.studentCurricula.student.sportHouse',
            'curricula.studentCurricula.student.scholarship',
            'curricula.studentCurricula.studentSubjects' => function ($query) {
                $query->where('status', 'active');
            },
        ])->get();

        // A curriculum's subjects, exam type and grade boundaries are shared
        // by every student enrolled in it. Without this, eager-loading them
        // through studentCurricula/studentSubjects re-hydrates the same
        // curriculum/exam-type/grade-boundary/subject/student-results data
        // once per student instead of once per curriculum, which for
        // studentResults (every student's scores for a subject) multiplies
        // into an N-per-student-times-N-students blow-up. Re-point each
        // child's relation at the single instance already loaded on its
        // parent curriculum instead of letting Eloquent hydrate it again.
        foreach ($classLevelArms as $arm) {
            foreach ($arm->curricula as $curriculum) {
                $curriculumSubjectsById = $curriculum->curriculumSubjects->keyBy('id');

                // Give students a copy of the curriculum with the reverse
                // (curriculum -> studentCurricula) relation stripped, so the
                // resource layer doesn't recurse back through it forever
                // while still reusing the already-loaded examType/term/
                // gradeBoundaries instances instead of re-hydrating them.
                $curriculumForStudents = clone $curriculum;
                $curriculumForStudents->unsetRelation('studentCurricula');

                foreach ($curriculum->curriculumSubjects as $curriculumSubject) {
                    // CurriculumSubjectResource includes a compact curriculum
                    // object. Reuse the stripped instance so it neither runs
                    // one curriculum query per subject nor recurses through
                    // all enrollments again.
                    $curriculumSubject->setRelation('curriculum', $curriculumForStudents);
                }

                foreach ($curriculum->studentCurricula as $studentCurriculum) {
                    $studentCurriculum->setRelation('curriculum', $curriculumForStudents);
                    // StudentResource normally resolves currentCurriculum on
                    // demand. This enrollment is already the active one, so
                    // reuse it and avoid two extra lookups per student (the
                    // status lookup and the student_class accessor lookup).
                    $studentCurriculum->student?->setRelation('currentCurriculum', $studentCurriculum);

                    foreach ($studentCurriculum->studentSubjects as $studentSubject) {
                        $curriculumSubject = $curriculumSubjectsById->get($studentSubject->curriculum_subject_id);

                        if ($curriculumSubject) {
                            $studentSubject->setRelation('curriculumSubject', $curriculumSubject);
                        }
                    }
                }
            }
        }

        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();

        return Inertia::render('student/results/list', [
            'classLevelArms' => ClassLevelArmResource::collection($classLevelArms),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries),
            'approvalEndpoint' => $approvalEndpoint,
            'approvalScopeName' => $scopeName,
        ]);
    }
}
