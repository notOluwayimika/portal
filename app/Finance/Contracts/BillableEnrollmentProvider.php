<?php

namespace App\Finance\Contracts;

use App\Academics\BillableEnrollmentAdapter;

/**
 * The ACL port (driven/secondary port) Finance owns to read an enrollment without
 * touching Academics' models or tables. The implementation lives OUTSIDE Finance
 * (App\Academics\BillableEnrollmentAdapter) and is bound in the composition root,
 * so the dependency arrow points Academics → Finance's contract, never the reverse
 * (Module Blueprint: "coupling flows one way; the reactor depends on the published
 * fact"). Here Finance is the consumer, so it publishes the interface and Academics
 * adapts to it.
 *
 * ISOLATION IS NOT UNIFORM ACROSS THIS INTERFACE, and stating that it was is how a brief
 * came to be written on a false premise. This docblock previously said, flatly, that "the
 * SchoolScope on the underlying academic model already constrains visibility, so cross-School
 * lookups return null by construction". Two different things are wrong with that as a blanket
 * claim, so here is the split, per method:
 *
 *   AMBIENT — findByUuid(), currentForStudent(), displayFor(), matchingStudentIds(),
 *   admissionNumberIndex(). These take no School and read the ACTIVE one: a School-scoped
 *   Academics model filters them, so a cross-School lookup resolves to null / drops out of the
 *   map. Note what that is and is not: a filter turns a wrong-School read into "not found", never
 *   into a refusal, which is why the billing path also carries an explicit cross-School guard in
 *   the Action rather than trusting the scope to object.
 *
 *   ARGUMENT — listForCohort(), listUnplaceableForSchool(). These take a School as a PARAMETER
 *   and deliberately STRIP the ambient scope, so the argument is the only thing that decides what
 *   is read. A second ambient opinion would silently empty the result whenever the two disagreed,
 *   and an empty cohort is an error nowhere.
 *
 * A caller mixing the two — resolving a cohort for School A and then calling currentForStudent()
 * on one of its members under School B's ambient context — will get an answer from the first and
 * null from the second. See listForCohort() for where that matters.
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
     * "BILLABLE" IS NOT A SECOND DEFINITION. Both this method and {@see currentForStudent()} route
     * through ONE private base query in the adapter (BillableEnrollmentAdapter::billableEpisodes()),
     * so the definition is shared in code and not merely asserted here — an earlier version of this
     * paragraph made the claim while the two were in fact separate expressions of the same rule, and
     * deleting the tie-break from one left the other green.
     *
     * AT MOST ONE ROW PER STUDENT, from that same base. A student holding several ACTIVE enrollments
     * resolves to the latest by id, and the cohort applies that tie-break BEFORE filtering on
     * coordinates — so such a student is billed once, for the episode the rest of Finance calls
     * current, rather than once per coordinate pair they happen to sit at.
     *
     * THE EQUIVALENCE IS CONDITIONAL, and the condition is the ambient School. Membership here is
     * decided by $schoolId with the ambient scope stripped; currentForStudent() is decided by the
     * ambient scope with no argument at all. So the biconditional
     *
     *     student ∈ listForCohort($s, $t, $c)  ⟺  currentForStudent($student) is non-null
     *                                             with termId === $t and classLevelId === $c
     *
     * holds WHEN the ambient School is $s or is absent — which covers every caller that exists: a
     * SchoolAware job under ActiveSchool::runFor($s), and a request already in School $s. Under a
     * FOREIGN ambient context the two disagree by design: this method still answers for $s, while
     * currentForStudent() returns null. Do not read the biconditional as unconditional; if commit 2
     * ever calls both across a context switch, that is the seam it will fail at.
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
     * HOW MANY billable enrollments $schoolId has, at all — the denominator the bulk run reconciles
     * against (U6 commit 3, closing
     * docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md).
     *
     * WHY A COUNT AND NOT A LIST. The ticket offered two shapes and this is the cheaper one: the
     * requirement it has to meet is that the run can state "N billable students were neither billed
     * nor flagged", and a number states it. An enumeration (`listBillableNotIn(school, pairs)`) would
     * let a screen NAME those students, which nothing asks for yet — and building the richer
     * primitive ahead of a consumer produces an abstraction shaped by imagination. The run subtracts:
     *
     *     unaccounted = countBillableForSchool(s)
     *                 − |listForCohort(s, t, c)|        (billed + already billed + failed)
     *                 − |listUnplaceableForSchool(s)|
     *
     * IT REUSES {@see BillableEnrollmentAdapter::billableEpisodes()}, as the ticket
     * requires. A count derived from a fourth expression of "billable" would reconcile a run against
     * a population defined differently from the one it billed, and the identity above would then be
     * a subtraction of two unrelated numbers that happened to be close.
     *
     * IT IS DELIBERATELY WIDER THAN THE TWO LIST METHODS, AND THAT IS THE WHOLE POINT. Those two go
     * through `currentEnrollments()`, which adds an EXISTS-through-`students` clause; this method
     * does not. The difference is exactly the episodes with a NULL `student_id` — schema-legal, since
     * the column is nullable and MySQL MATCH SIMPLE skips a composite FK check when a component is
     * NULL — which fail that EXISTS and appear in NEITHER list. Inheriting the same blindness in the
     * denominator would make the reconciliation balance to zero over a population that had already
     * dropped them, which is the silent-omission defect (docs/ui-ux-design-system.md §26) reproduced
     * one level up, in the very figure written to detect it. So: those episodes are counted here,
     * they match no row the run writes, and they surface as `unaccounted_count`.
     *
     * A CONSEQUENCE WORTH STATING PLAINLY: this is a count of billable EPISODES, and it is exactly a
     * count of billable STUDENTS only where every episode has a student. `billableEpisodes()` takes
     * MAX(id) GROUP BY student_id, and SQL groups all NULLs into ONE group — so any number of
     * student-less episodes contribute 1 between them, not one each. The reconciliation therefore
     * detects that such episodes exist; it does not count how many.
     *
     * ISOLATION IS THE ARGUMENT, with the ambient scope stripped — the same decision, for the same
     * reason, as the two list methods above (see the adapter's `currentEnrollments()`).
     */
    public function countBillableForSchool(int $schoolId): int;

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
