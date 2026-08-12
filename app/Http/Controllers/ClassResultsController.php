<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClassLevelArmResource;
use App\Http\Resources\GradeBoundaryResource;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\GradeBoundary;
use App\Models\School;
use App\Services\ResultSignatureService;
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

    /**
     * Widen the process memory cap to at least $bytes, never narrow it.
     *
     * Returns early on an unlimited ('-1') ambient limit: capping an
     * intentionally-unlimited process is exactly the narrowing this avoids.
     */
    private static function ensureMemoryLimitAtLeast(int $bytes): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '-1' || $current === '') {
            return;
        }

        $value = (int) $current;
        $currentBytes = match (strtolower(substr($current, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        if ($currentBytes < $bytes) {
            ini_set('memory_limit', (string) $bytes);
        }
    }

    private function renderResults(Builder $armsQuery, string $approvalEndpoint, string $scopeName)
    {
        // A result sheet hydrates a whole class at once, so this page wants more
        // headroom than a stock 128M php.ini gives it.
        //
        // RAISE ONLY. A bare ini_set('memory_limit', '256M') also LOWERS the cap
        // whenever the ambient limit is already higher — the opposite of the
        // intent — and ini_set is process-wide, not request-scoped. Under the test
        // suite (phpunit.xml sets 1G) the first request through here capped every
        // subsequent test at 256M, and the run died on an allocation fatal in an
        // unrelated test. Same hazard with a php-fpm pool configured above 256M.
        self::ensureMemoryLimitAtLeast(256 * 1024 * 1024);

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
            // `.terms` IS LOAD-BEARING, not a spare leaf. TermResource derives
            // `is_last_term` by comparing the term's order against the session's
            // highest, and it reads that ONLY from already-loaded relations —
            // without `academicSession.terms` present it does not query, it
            // silently returns false (TermResource::toArray). The result card
            // gates "Promoted To Year X" on that flag, so the class sheet
            // omitted the promotion line for every pupil in the final term while
            // the single-student page — whose routes do load this leaf — printed
            // it from the same component. Same bug shape as any derived field
            // that degrades quietly instead of failing.
            'curricula.term.academicSession.terms',
            'curricula.academicSession',
            'curricula.gradingScheme.items',
            'curricula.markingScheme.components',
            'curricula.curriculumSubjects.subject',
            'curricula.curriculumSubjects.markingComponents',
            // The CATEGORICAL card's "Subject Teacher" column reads this straight
            // off the payload — `curriculum_subject.teachers[0].teacher.full_name`
            // — and CurriculumSubjectResource emits `teachers` under
            // whenLoaded('teacherAssignments'), so without this the key was simply
            // ABSENT and every row rendered the `|| '—'` fallback no matter how
            // many teachers were assigned.
            //
            // The numeric card never showed the bug because it does not use the
            // payload at all: SubjectRow fetches GET /curriculum-subjects/{uuid}/teachers
            // per subject and loads the relation itself. Two mechanisms for one
            // column is what let this rot unnoticed on the only page that omits
            // the eager load.
            //
            // Loaded ONCE per curriculum subject, not per student: the re-point
            // loop below hands every enrollment the same shared instance, so this
            // costs two queries for the whole sheet rather than two per pupil.
            'curricula.curriculumSubjects.teacherAssignments.teacher',
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
            // A WITHDRAWN student is still 'active' HERE. `students` is soft-deleted
            // and School-scoped, but `student_curricula` rows outlive the withdrawal,
            // so the enrollment arrives with `->student` resolving to null while its
            // own status still says active. This page then serialized `student: null`
            // and the print view died on `sc.student.photo` — one student leaving
            // broke the result sheet for the whole class.
            //
            // whereHas() runs through the Student model, so SoftDeletes and
            // SchoolScope both apply and the orphan rows never hydrate. FOURTH site
            // in this family: CurriculumSubjectController::handleStudentResults,
            // FormTeacherCommentController::index and
            // HeadOfSchoolCommentController::index carry the same guard for the same
            // reason. If you are adding a fifth, the smell is that enrollment reads
            // need this by default.
            'curricula.studentCurricula' => function ($query) {
                $query->where('status', 'active')->whereHas('student');
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
        //
        // The same walk collects every enrollment for the signature lookup below —
        // the alternative, a `flatMap->curricula->flatMap->studentCurricula` chain,
        // walks the identical tree a second time and loses its element type on the
        // way (the query builder's get() is Collection<int, Model>).
        $studentCurricula = [];

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
                    $studentCurricula[] = $studentCurriculum;
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

        // The approval signature, which this page printed for nobody.
        //
        // The single-student routes (students/{student}/results/*) have always passed
        // `resultSignatures`, and CurriculumCardFinal renders its signature row only
        // when the prop is present — so the class sheets, which never sent it, simply
        // showed no "Approved by …" row at all. Same component, same template, one
        // missing prop. Keyed by enrollment uuid, which is what StudentCurriculum
        // exposes as `id` on the wire; `$studentCurricula` was gathered by the
        // re-point walk above.
        return Inertia::render('student/results/list', [
            'classLevelArms' => ClassLevelArmResource::collection($classLevelArms),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries),
            'approvalEndpoint' => $approvalEndpoint,
            'approvalScopeName' => $scopeName,
            'resultSignatures' => app(ResultSignatureService::class)->forCurricula(
                $studentCurricula,
                School::findOrFail(ActiveSchool::id()),
            ),
        ]);
    }
}
