<?php

namespace App\Academics;

use App\Enums\StudentStatusEnum;
use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Models\Student;
use App\Models\StudentCurriculum;

/**
 * The Academics-side adapter that fulfils Finance's ACL port. It is the ONE place
 * an enrollment is translated into the Finance-facing {@see BillableEnrollment}
 * value; Finance domain code never imports StudentCurriculum (arch rule 3 forbids
 * it — that failure is what proves the boundary holds).
 *
 * This namespace (App\Academics) is the seam for the not-yet-extracted Academics
 * module: today it holds only this outbound adapter. When Academics is formally
 * extracted (Module Blueprint), its models/services move under here and this class
 * becomes an ordinary Contracts/ adapter published to Finance.
 *
 * All label fields are built defensively (CurriculumFactory is minimal, FKs
 * nullable) and captured as SNAPSHOTS — the caller copies them onto the invoice at
 * billing time; they are never re-joined afterwards.
 */
final class BillableEnrollmentAdapter implements BillableEnrollmentProvider
{
    /** Relations needed to build a BillableEnrollment's snapshot labels. */
    private const SNAPSHOT_RELATIONS = ['student', 'curriculum.classLevelArm.classLevel', 'curriculum.classLevelArm.arm', 'curriculum.academicSession', 'curriculum.term'];

    public function findByUuid(string $enrollmentUuid): ?BillableEnrollment
    {
        // NOTE (corrected slice 2): StudentCurriculum is deliberately UNSCOPED —
        // `student_curricula` has no school_id column and the model does not use
        // BelongsToSchool (v10 §14). An earlier comment here claimed a SchoolScope
        // gave "isolation for free"; that scope does not exist, so this lookup is
        // NOT School-constrained. Isolation for the billing path is therefore
        // asserted downstream, from the STUDENT's school (below) — see the
        // cross-School regression test in tests/Feature/Finance.
        $enrollment = StudentCurriculum::query()
            ->where('uuid', $enrollmentUuid)
            ->with(self::SNAPSHOT_RELATIONS)
            ->first();

        return $enrollment === null ? null : $this->toBillableEnrollment($enrollment);
    }

    /**
     * The student's CURRENT billable episode — the ACTIVE enrollment. "Current" is an
     * Academics concept (Student::currentCurriculum is exactly this: hasOne where status
     * ACTIVE), and it lives HERE, not in Finance, so the frontend never juggles an
     * enrollment id. Returns null when the student has no active enrollment to bill.
     */
    public function currentForStudent(int $studentId): ?BillableEnrollment
    {
        $enrollment = StudentCurriculum::query()
            ->where('student_id', $studentId)
            ->where('status', StudentStatusEnum::ACTIVE)
            ->with(self::SNAPSHOT_RELATIONS)
            ->latest('id')
            ->first();

        return $enrollment === null ? null : $this->toBillableEnrollment($enrollment);
    }

    /**
     * Batch student directory for a Finance list. LIVE display (name + admission #) for a
     * page of ids — not a snapshot: the accounts index shows the student as they are now.
     * The Student model is School-scoped (BelongsToSchool), so ids outside the active
     * School never resolve and simply drop out of the map. Soft-deleted students are
     * excluded by the model's default scope — their account may still carry a balance in
     * the KPIs, but they get no display row link (the statement route can't bind a trashed
     * student anyway); the caller falls back to a non-linked "Student #id" label.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{uuid: string, name: string, admission_number: ?string}>
     */
    public function displayFor(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return Student::query()
            ->whereIn('id', $studentIds)
            ->get(['id', 'uuid', 'first_name', 'last_name', 'admission_number'])
            ->keyBy('id')
            ->map(fn (Student $student) => [
                'uuid' => (string) $student->getAttribute('uuid'),
                'name' => $this->displayName($student),
                'admission_number' => $student->getAttribute('admission_number'),
            ])
            ->all();
    }

    /**
     * Which student_ids match a free-text term over name / admission number, School-scoped.
     * CONCAT covers a full-name query ("Ada Lovelace") that neither column matches alone;
     * LIKE wildcards in the term are escaped so a user's `%` searches literally. This is the
     * Academics half of the accounts-index search — Finance filters its accounts to these ids.
     *
     * @return list<int>
     */
    public function matchingStudentIds(string $term): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return Student::query()
            ->where(function ($query) use ($like) {
                $query->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('admission_number', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) like ?", [$like]);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function displayName(Student $student): string
    {
        $name = trim(($student->getAttribute('first_name') ?? '').' '.($student->getAttribute('last_name') ?? ''));

        return $name !== '' ? $name : 'Student #'.$student->getAttribute('id');
    }

    /**
     * Translate one academic enrollment into the Finance-facing snapshot value. The ONE
     * place StudentCurriculum becomes a BillableEnrollment. getAttribute()/getKey()
     * (returning mixed) keep this clean without annotating the academic models.
     */
    private function toBillableEnrollment(StudentCurriculum $enrollment): BillableEnrollment
    {
        return new BillableEnrollment(
            enrollmentId: (int) $enrollment->getKey(),
            enrollmentUuid: (string) $enrollment->getAttribute('uuid'),
            studentId: (int) $enrollment->getAttribute('student_id'),
            // The enrollment row carries NO school_id (see above), so the episode's School
            // is the STUDENT's School (the account holder); the curriculum is the fallback
            // when the student is unreadable. This keeps an invoice's School a function of
            // the episode being billed, not of who is logged in.
            schoolId: $this->schoolId($enrollment),
            studentName: $this->studentName($enrollment),
            academicContext: $this->academicContext($enrollment),
        );
    }

    /**
     * The School that owns this billable episode, derived from the durable
     * academic identities rather than from ambient request context.
     */
    private function schoolId(StudentCurriculum $enrollment): int
    {
        $student = $enrollment->getAttribute('student');
        if (is_object($student) && $student->getAttribute('school_id') !== null) {
            return (int) $student->getAttribute('school_id');
        }

        $curriculum = $enrollment->getAttribute('curriculum');
        if (is_object($curriculum) && $curriculum->getAttribute('school_id') !== null) {
            return (int) $curriculum->getAttribute('school_id');
        }

        return 0;
    }

    private function studentName(StudentCurriculum $enrollment): string
    {
        $student = $enrollment->getAttribute('student');
        $name = is_object($student)
            ? trim(($student->first_name ?? '').' '.($student->last_name ?? ''))
            : '';

        return $name !== '' ? $name : 'Student #'.$enrollment->getAttribute('student_id');
    }

    private function academicContext(StudentCurriculum $enrollment): string
    {
        $curriculum = $enrollment->getAttribute('curriculum');
        $parts = array_filter([
            $curriculum?->classLevelArm?->classLevel?->name,
            $curriculum?->classLevelArm?->arm?->name,
            $curriculum?->academicSession?->name,
            $curriculum?->term?->name,
        ], fn ($p) => is_string($p) && $p !== '');

        return $parts !== [] ? implode(' · ', $parts) : 'Enrollment '.$enrollment->getAttribute('uuid');
    }
}
