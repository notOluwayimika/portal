<?php

namespace App\Observers;

use App\Enums\StudentStatusEnum;
use App\Models\CurriculumSubject;
use App\Models\StudentCurriculum;
use App\Services\StudentSubjectService;
use Illuminate\Support\Facades\Log;

/**
 * Safety net only (see implementation_plan.md Decision 1).
 *
 * This observer is NOT the primary enrollment mechanism. It detects
 * StudentCurriculum rows created without compulsory subjects (e.g. via raw
 * SQL, seeders, or imports that bypass CurriculumEnrollmentService) and:
 *   1. Logs a warning to the academic-anomalies channel.
 *   2. Runs autoAttachCompulsorySubjects as a remediation fallback.
 *   3. Carries over optional subjects from the student's previous active
 *      enrollment, so promotions/migrations don't lose subject selections.
 */
class StudentCurriculumObserver
{
    public function created(StudentCurriculum $enrollment): void
    {
        $hasSubjects = $enrollment->studentSubjects()->exists();

        if (!$hasSubjects) {
            Log::channel('academic-anomalies')->warning(
                'StudentCurriculum created without compulsory subjects attached. '
                . 'Possible bypass of CurriculumEnrollmentService.',
                [
                    'enrollment_id' => $enrollment->id,
                    'student_id'    => $enrollment->student_id,
                    'curriculum_id' => $enrollment->curriculum_id,
                    'created_by'    => auth()->id(),
                ]
            );

            app(StudentSubjectService::class)->autoAttachCompulsorySubjects($enrollment);

            $this->carryOverOptionalSubjects($enrollment);
        }
    }

    /**
     * Re-add the optional subjects the student was taking on their previous
     * active enrollment, matched onto this curriculum's optional subjects by
     * subject_id, skipping anything already attached (e.g. compulsory subjects
     * just attached above).
     */
    private function carryOverOptionalSubjects(StudentCurriculum $enrollment): void
    {
        $performedBy = auth()->user();

        if (!$performedBy) {
            return;
        }

        $previous = StudentCurriculum::where('student_id', $enrollment->student_id)
            ->where('id', '!=', $enrollment->id)
            ->where('status', StudentStatusEnum::ACTIVE)
            ->latest('id')
            ->first();

        if (!$previous) {
            return;
        }

        $optionalSubjectsBySubjectId = CurriculumSubject::where('curriculum_id', $enrollment->curriculum_id)
            ->where('is_compulsory', false)
            ->active()
            ->get()
            ->keyBy('subject_id');

        $attachedSubjectIds = $enrollment->studentSubjects()->pluck('curriculum_subject_id');

        $subjectService = app(StudentSubjectService::class);

        foreach ($previous->activeSubjects as $oldStudentSubject) {
            $oldCurriculumSubject = $oldStudentSubject->curriculumSubject;

            if (!$oldCurriculumSubject) {
                continue;
            }

            $newCurriculumSubject = $optionalSubjectsBySubjectId->get($oldCurriculumSubject->subject_id);

            if (!$newCurriculumSubject || $attachedSubjectIds->contains($newCurriculumSubject->id)) {
                continue;
            }

            $subjectService->addOptionalSubject($enrollment, $newCurriculumSubject, $performedBy);
        }
    }
}
