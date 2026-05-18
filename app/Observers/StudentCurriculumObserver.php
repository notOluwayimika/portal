<?php

namespace App\Observers;

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
        }
    }
}
