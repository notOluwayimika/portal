<?php

/**
 * Architectural contract (see implementation_plan.md):
 *
 * - All subject attachment logic lives here. No controller or observer may write
 *   student_subjects rows directly.
 * - When a subject is re-added after being dropped, the EXISTING row is RESTORED —
 *   a new row is NEVER created. This preserves academic history, GPA, and audit continuity.
 * - Compulsory subjects are immutable: they cannot be added manually or dropped.
 * - Every write is wrapped in a transaction.
 * - Activity log entries are automatic via the LogsActivity trait on StudentSubject.
 */

namespace App\Services;

use App\Enums\StudentSubjectStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CurriculumSubject;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentSubjectService
{
    /**
     * Auto-attach all active compulsory subjects for a newly created enrollment.
     * Idempotent: skips any combination that already exists (active or dropped).
     */
    public function autoAttachCompulsorySubjects(StudentCurriculum $enrollment): Collection
    {
        $compulsory = $enrollment->curriculum
            ->curriculumSubjects()
            ->active()
            ->where('is_compulsory', true)
            ->get();

        $existingIds = $enrollment->studentSubjects()
            ->pluck('curriculum_subject_id')
            ->toArray();

        return DB::transaction(function () use ($enrollment, $compulsory, $existingIds) {
            $attached = collect();

            foreach ($compulsory as $cs) {
                if (in_array($cs->id, $existingIds)) {
                    continue;
                }

                $attached->push(StudentSubject::create([
                    'student_curriculum_id' => $enrollment->id,
                    'curriculum_subject_id' => $cs->id,
                    'status'                => StudentSubjectStatus::Active,
                ]));
            }

            return $attached;
        });
    }

    /**
     * Add a single optional subject to a student's enrollment.
     * If the subject was previously dropped, it is RESTORED (no new row created).
     */
    public function addOptionalSubject(
        StudentCurriculum $enrollment,
        CurriculumSubject $curriculumSubject,
        User $performedBy
    ): StudentSubject {
        if ($curriculumSubject->curriculum_id !== $enrollment->curriculum_id) {
            throw new BusinessRuleException('This subject does not belong to the enrollment\'s curriculum.');
        }

        if ($curriculumSubject->is_compulsory) {
            throw new BusinessRuleException('Compulsory subjects are auto-assigned and cannot be added manually.');
        }

        if ($curriculumSubject->isArchived()) {
            throw new BusinessRuleException('This subject has been archived in the curriculum and cannot be added to new students.');
        }

        $existing = StudentSubject::where('student_curriculum_id', $enrollment->id)
            ->where('curriculum_subject_id', $curriculumSubject->id)
            ->first();

        if ($existing) {
            if ($existing->status === StudentSubjectStatus::Active) {
                throw new BusinessRuleException('Subject is already active for this student.');
            }

            // Restore the dropped row instead of creating a new one (Decision 2).
            return $this->restoreDroppedSubject($existing, $performedBy);
        }

        return DB::transaction(fn () => StudentSubject::create([
            'student_curriculum_id' => $enrollment->id,
            'curriculum_subject_id' => $curriculumSubject->id,
            'status'                => StudentSubjectStatus::Active,
        ]));
    }

    /**
     * Drop an optional subject from a student's enrollment.
     */
    public function dropOptionalSubject(
        StudentSubject $studentSubject,
        User $performedBy,
        ?string $reason = null
    ): StudentSubject {
        if (!$studentSubject->canBeDropped()) {
            $message = $studentSubject->isCompulsory()
                ? 'Compulsory subjects cannot be dropped.'
                : 'Subject is not currently active.';

            throw new BusinessRuleException($message);
        }

        return DB::transaction(function () use ($studentSubject, $performedBy, $reason) {
            $studentSubject->update([
                'status'              => StudentSubjectStatus::Dropped,
                'dropped_by_user_id'  => $performedBy->id,
                'dropped_at'          => now(),
                'drop_reason'         => $reason,
            ]);

            return $studentSubject->fresh();
        });
    }

    /**
     * Restore a previously dropped optional subject.
     * Preserves all drop metadata (dropped_at, dropped_by, drop_reason) as historical record.
     */
    public function restoreDroppedSubject(
        StudentSubject $studentSubject,
        User $performedBy
    ): StudentSubject {
        if (!$studentSubject->canBeRestored()) {
            throw new BusinessRuleException('Subject is not currently dropped.');
        }

        $studentSubject->loadMissing('curriculumSubject');

        if ($studentSubject->curriculumSubject->isArchived()) {
            throw new BusinessRuleException('Cannot restore: the subject has been archived in the curriculum.');
        }

        return DB::transaction(function () use ($studentSubject, $performedBy) {
            $studentSubject->update([
                'status'               => StudentSubjectStatus::Active,
                'restored_by_user_id'  => $performedBy->id,
                'restored_at'          => now(),
                // Drop metadata preserved intentionally — see implementation_plan.md Decision 2.
            ]);

            return $studentSubject->fresh();
        });
    }

    /**
     * Add multiple optional subjects in a single all-or-nothing transaction.
     */
    public function bulkAddOptionalSubjects(
        StudentCurriculum $enrollment,
        array $curriculumSubjectIds,
        User $performedBy
    ): Collection {
        return DB::transaction(function () use ($enrollment, $curriculumSubjectIds, $performedBy) {
            $results = collect();

            foreach ($curriculumSubjectIds as $id) {
                $cs = CurriculumSubject::findOrFail($id);
                $results->push($this->addOptionalSubject($enrollment, $cs, $performedBy));
            }

            return $results;
        });
    }
}
