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
        // The SchoolScope on StudentCurriculum constrains this to the active School,
        // so a cross-School uuid resolves to null — isolation for free.
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
            schoolId: (int) $enrollment->getAttribute('school_id'),
            studentName: $this->studentName($enrollment),
            academicContext: $this->academicContext($enrollment),
        );
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
