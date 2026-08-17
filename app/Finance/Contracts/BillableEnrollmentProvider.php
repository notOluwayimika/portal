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

    /**
     * The COHORT: every billable enrollment in $schoolId sitting at the pricing coordinates
     * ($termId, $classLevelId) — the input to U6's bulk invoice generation, which raises one
     * invoice per element. The consumer is the bulk-generation job (U6 commit 2); nothing calls
     * it before that.
     *
     * "BILLABLE" IS NOT A SECOND DEFINITION. It is exactly {@see currentForStudent()}'s, and the
     * adapter shares one private base query so the two cannot drift: a student appears in
     * listForCohort($school, $t, $c) IF AND ONLY IF currentForStudent($student) returns non-null
     * with termId === $t and classLevelId === $c. That equivalence is the point — two definitions
     * would mean a student billable through the single-invoice path and invisible to the bulk one
     * (or, worse, billed twice by it).
     *
     * AT MOST ONE ROW PER STUDENT, for the same reason. currentForStudent() resolves a student
     * with several ACTIVE enrollments to the latest by id; the cohort applies the same tie-break
     * BEFORE filtering on coordinates, so a student with two active episodes is billed once, for
     * the episode the rest of Finance calls current.
     *
     * ISOLATION IS EXPLICIT, NOT AMBIENT — $schoolId decides, and nothing else does. See the
     * adapter's currentEnrollments() for the mechanism and for why the ambient SchoolScope is
     * deliberately stripped rather than layered on top.
     *
     * @return list<BillableEnrollment>
     */
    public function listForCohort(int $schoolId, int $termId, int $classLevelId): array;

    /**
     * The billable enrollments in $schoolId that CANNOT be placed in any cohort: their termId or
     * their classLevelId is null, so no fee schedule can be keyed to them ({@see
     * \App\Finance\Services\FeeScheduleLookup::activeFor()} needs both) and no call to
     * {@see listForCohort()} can ever return them.
     *
     * WHY IT EXISTS BEFORE ITS SCREEN. Without it a bulk run bills 47 of 50 students and reports
     * success — the omission is invisible precisely because the omitted rows match no query anyone
     * runs. That is the silent-partial-result defect class in docs/ui-ux-design-system.md §26. The
     * named consumer is U6 commit 3, which surfaces the count as "N students could not be placed"
     * beside the generation result; it is built here, with that consumer named, so it cannot be
     * quietly dropped between commits.
     *
     * NO REASON FIELD. The reason is already readable off the returned DTO — termId === null,
     * classLevelId === null, or both — so {@see BillableEnrollment} gains nothing. Adding a field
     * no consumer needs would be the adapter inventing vocabulary.
     *
     * School-wide, not term-scoped: an enrollment with a null term cannot be attributed to a term,
     * so there is no term to scope the question by.
     *
     * @return list<BillableEnrollment>
     */
    public function listUnplaceableForSchool(int $schoolId): array;

    /**
     * The active School's admission-number roster — every student id paired with the admission
     * number as stored. The join key for an off-platform import, and the ONLY thing that can
     * answer §6's pre-flight ("how many NULL, how many duplicate-after-trim") and §7's other
     * side ("who is in the portal but absent from the file").
     *
     * WHY NOT THE TWO METHODS ABOVE, which is the obvious question. {@see displayFor()} runs the
     * wrong way — ids in, display out — and an import has admission numbers, not ids.
     * {@see matchingStudentIds()} is a `LIKE %term%` search box: it would resolve "A1" onto
     * "A100" and silently import one student's arrears against another. Neither is a join, so
     * neither is safe as one, and a fuzzy join key is the single worst failure available to a
     * money import. Hence an exact roster the caller matches against itself.
     *
     * LIVE, School-scoped by the Academics model's SchoolScope, and soft-deleted students are
     * excluded by its default scope (the same boundary displayFor() has). Withdrawn and
     * graduated students ARE included: §7 imports their arrears and payments — their balance
     * stays chaseable — and only excludes them from being invoiced, which is V2's concern.
     *
     * @return list<array{student_id: int, admission_number: ?string}>
     */
    public function admissionNumberIndex(): array;
}
