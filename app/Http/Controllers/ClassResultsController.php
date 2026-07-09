<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClassLevelArmResource;
use App\Http\Resources\GradeBoundaryResource;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\GradeBoundary;
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
        return $this->renderResults(
            ClassLevelArm::query()->where('class_level_id', $classLevel->id)
        );
    }

    public function classLevelArm(ClassLevelArm $classLevelArm)
    {
        return $this->renderResults(
            ClassLevelArm::query()->where('id', $classLevelArm->id)
        );
    }

    private function renderResults(Builder $armsQuery)
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
            'curricula.term',
            'curricula.curriculumSubjects.subject',
            'curricula.curriculumSubjects.studentResults' => function ($query) {
                $query->select([
                    'id',
                    'curriculum_subject_id',
                    'student_id',
                    'total_score',
                    'grade',
                ]);
            },
            'curricula.curriculumSubjects.studentResults.student:id,uuid,first_name,middle_name,last_name',
            'curricula.curriculumSubjects.resultStatus',
            'curricula.studentCurricula.student',
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

                foreach ($curriculum->studentCurricula as $studentCurriculum) {
                    $studentCurriculum->setRelation('curriculum', $curriculumForStudents);

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
        ]);
    }
}
