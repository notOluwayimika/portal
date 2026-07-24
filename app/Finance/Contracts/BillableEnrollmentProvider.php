<?php

namespace App\Finance\Contracts;

/**
 * The ACL port (driven/secondary port) Finance owns to read an enrollment without
 * touching Academics' models or tables. The implementation lives OUTSIDE Finance
 * (App\Academics\BillableEnrollmentAdapter) and is bound in the composition root,
 * so the dependency arrow points Academics → Finance's contract, never the reverse
 * (Module Blueprint: "coupling flows one way; the reactor depends on the published
 * fact"). Here Finance is the consumer, so it publishes the interface and Academics
 * adapts to it.
 *
 * Returns null when no billable enrollment matches in the active School — the
 * SchoolScope on the underlying academic model already constrains visibility, so
 * cross-School lookups return null by construction.
 */
interface BillableEnrollmentProvider
{
    public function findByUuid(string $enrollmentUuid): ?BillableEnrollment;

    /**
     * The student's CURRENT billable episode — their active enrollment — or null when
     * they have none to bill. This is how a bursar bills a *student* without the
     * frontend ever handling an enrollment id: Finance asks its own port to resolve the
     * episode, and the Academics adapter owns "which enrollment is current" (the active
     * one). Isolation is the same as findByUuid: the episode's School is derived from the
     * student, and the cross-School guard in the Action rejects a mismatch.
     */
    public function currentForStudent(int $studentId): ?BillableEnrollment;
}
