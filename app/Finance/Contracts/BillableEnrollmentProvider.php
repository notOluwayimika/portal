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

    /**
     * Batch student directory for a Finance LIST (the accounts index). Finance holds
     * student_ids on its account/ledger rows but owns no name or admission number — those
     * are Academics facts and must never be re-joined from a Finance query (arch rule 3).
     * So the read side asks its port to resolve display for a page of ids in one shot.
     *
     * LIVE, not snapshot: unlike {@see BillableEnrollment}'s billing-time snapshots, an
     * index shows the student as they are NOW, so a rename surfaces immediately. Isolation
     * is automatic — the Academics model is School-scoped, so ids outside the active School
     * simply do not resolve (they fall out of the map, never leak a name).
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{uuid: string, name: string, admission_number: ?string}> keyed by student id
     */
    public function displayFor(array $studentIds): array;

    /**
     * Resolve which student_ids match a free-text search over name / admission number —
     * the accounts-index search box. Search-by-name inherently crosses into Academics
     * (Finance stores no name to match against), so it goes through the port too: the
     * adapter returns the matching ids and the Finance query filters its accounts to them.
     * School-scoped by construction, so only the active School's students can match.
     *
     * @return list<int>
     */
    public function matchingStudentIds(string $term): array;
}
