<?php

namespace App\Academics;

use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
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
            ->with(['student', 'curriculum.classLevelArm.classLevel', 'curriculum.classLevelArm.arm', 'curriculum.academicSession', 'curriculum.term'])
            ->first();

        if ($enrollment === null) {
            return null;
        }

        // getAttribute()/getKey() (returning mixed) keep this adapter clean without
        // annotating the academic models — the translation boundary owns the casts.
        return new BillableEnrollment(
            enrollmentId: (int) $enrollment->getKey(),
            enrollmentUuid: (string) $enrollment->getAttribute('uuid'),
            studentId: (int) $enrollment->getAttribute('student_id'),
            // The enrollment row carries NO school_id (see above), so reading one
            // here silently produced 0 — and the invoice then got its School from
            // whatever ActiveSchool context happened to be set, via
            // BelongsToSchool::creating. That made an invoice's School a function
            // of WHO WAS LOGGED IN rather than of the episode being billed, and it
            // made any school_id-keyed Finance query against this value dead code.
            // The episode's School is the STUDENT's School (the account holder);
            // the curriculum is the fallback when the student is unreadable.
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
