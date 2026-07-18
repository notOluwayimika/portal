<?php

/**
 * Architectural contract (see implementation_plan.md):
 *
 * - All enrollment logic lives here. Controllers MUST NOT create StudentCurriculum directly.
 * - autoAttachCompulsorySubjects is delegated to StudentSubjectService.
 * - Every write is wrapped in a transaction; failure rolls back the entire enrollment.
 * - Unenrollment sets ended_at; it NEVER deletes student_subjects rows.
 */

namespace App\Services;

use App\Enums\StudentStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CurriculumEnrollmentService
{
    public function __construct(
        private StudentSubjectService $subjectService
    ) {}

    public function enroll(
        Student $student,
        Curriculum $curriculum,
        User $_performedBy,
        array $options = []
    ): StudentCurriculum {
        if ($curriculum->school_id !== $student->school_id) {
            throw new BusinessRuleException('The curriculum does not belong to this school.');
        }

        $alreadyEnrolled = StudentCurriculum::where('student_id', $student->id)
            ->where('curriculum_id', $curriculum->id)
            ->whereNull('ended_at')
            ->exists();

        if ($alreadyEnrolled) {
            throw new BusinessRuleException('The student already has an active enrollment in this curriculum.');
        }

        return DB::transaction(function () use ($student, $curriculum, $options) {
            $enrollment = StudentCurriculum::create([
                'student_id' => $student->id,
                'curriculum_id' => $curriculum->id,
                'status' => $options['status'] ?? StudentStatusEnum::ACTIVE,
                'promoted_to_id' => $options['promoted_to_id'] ?? null,
            ]);

            $this->subjectService->autoAttachCompulsorySubjects($enrollment);

            return $enrollment->load('studentSubjects.curriculumSubject.subject');
        });
    }

    public function unenroll(
        StudentCurriculum $enrollment,
        User $performedBy,
        ?string $reason = null
    ): StudentCurriculum {
        return $this->softEnd($enrollment, $performedBy, StudentStatusEnum::WITHDRAWN, $reason);
    }

    /**
     * The ONE soft-end operation every enrollment-termination path routes
     * through (Option-B vocabulary: an episode ends with a terminal status —
     * completed/withdrawn/repeated/promoted/transferred — and is NEVER deleted;
     * the row is the durable referent §9 invoice cancellation requires, and the
     * old delete cascaded behavioral/psychomotor assessments away and threw on
     * FK RESTRICT for enrollments with subjects).
     *
     * Sets the terminal status AND ended_at/ended_by together (previously
     * unenroll set only ended_at, leaving status=active — so ended enrollments
     * still read as "current"). This is the minimal convergence; the full
     * Option-B endEpisode/enroll convergence and the active_key uniqueness flip
     * come later and extend this, not replace it.
     */
    public function softEnd(
        StudentCurriculum $enrollment,
        User $performedBy,
        StudentStatusEnum $terminalStatus,
        ?string $reason = null
    ): StudentCurriculum {
        if ($terminalStatus === StudentStatusEnum::ACTIVE) {
            throw new \InvalidArgumentException('softEnd() requires a terminal status; active is not one.');
        }

        if ($enrollment->isEnded()) {
            throw new BusinessRuleException('This enrollment has already ended.');
        }

        return DB::transaction(function () use ($enrollment, $performedBy, $terminalStatus, $reason) {
            $enrollment->update([
                'status' => $terminalStatus,
                'ended_at' => now(),
                'ended_by_user_id' => $performedBy->id,
                'end_reason' => $reason,
            ]);

            return $enrollment->fresh();
        });
    }
}
