<?php

namespace App\Academics;

use App\Enums\StudentStatusEnum;
use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use Illuminate\Database\Eloquent\Builder;

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
        // NOTE (re-corrected, U6 commit 1). This comment has now been wrong in BOTH
        // directions, so state what is true TODAY and cite it. Slice 2 said a
        // SchoolScope gave "isolation for free" and it did not; the correction said
        // `student_curricula` has no school_id and the model carries no scope, and
        // that has ALSO stopped being true — slice (i) added the column NOT NULL with
        // the composite FK student_curricula_student_school_foreign (student_id,
        // school_id) -> students (id, school_id)
        // (2026_07_19_130000_add_school_id_to_student_curricula.php:57,:80,:85), and
        // slice (ii) added a bare SchoolScope in StudentCurriculum::booted().
        //
        // So THIS lookup IS School-constrained today, by the ambient scope. It is left
        // as-is because the downstream cross-School guard in the Action is the
        // assertion that actually refuses (a scope that filters turns a wrong-School
        // lookup into "not found", never into a refusal). The two cohort reads below
        // take a School as an ARGUMENT and therefore cannot rely on ambient context at
        // all — see currentEnrollments().
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
     * The cohort at ($termId, $classLevelId) in $schoolId. See the port for the contract; what
     * follows is how the two coordinates are reached, because neither is a column on the episode.
     *
     * TERM is one hop (`curricula.term_id`) and CLASS LEVEL is two
     * (`curricula.class_level_arm_id -> class_level_arms.class_level_id`) — the same hops
     * {@see termId()} and {@see classLevelId()} take to build the DTO, so the filter and the
     * reported value cannot disagree. The level, not the arm: JSS1A and JSS1B are priced
     * identically.
     *
     * The coordinate filter is applied AFTER the current-episode tie-break inside
     * {@see currentEnrollments()}, never alongside it. Alongside, a student whose current episode
     * sits at (t2, c2) but who still has an older ACTIVE episode at (t1, c1) would be returned by
     * the (t1, c1) cohort while currentForStudent() reported (t2, c2) — the two definitions
     * disagreeing about one student, which is the whole failure this method is written to avoid.
     *
     * @return list<BillableEnrollment>
     */
    public function listForCohort(int $schoolId, int $termId, int $classLevelId): array
    {
        return $this->currentEnrollments($schoolId)
            ->whereHas('curriculum', function ($query) use ($termId, $classLevelId) {
                $query->withoutGlobalScope(SchoolScope::class)
                    ->where('term_id', $termId)
                    ->whereHas('classLevelArm', fn ($arm) => $arm
                        ->withoutGlobalScope(SchoolScope::class)
                        ->where('class_level_id', $classLevelId));
            })
            ->get()
            ->map(fn (StudentCurriculum $enrollment) => $this->toBillableEnrollment($enrollment))
            ->all();
    }

    /**
     * The billable enrollments in $schoolId that no cohort can contain. See the port for why this
     * exists and who consumes it.
     *
     * ONE NEGATION, NOT A LIST OF CASES. `whereDoesntHave('curriculum', <placeable>)` is NOT EXISTS
     * over a belongsTo, so it is true when the curriculum is missing entirely, when `term_id` is
     * null, when `class_level_arm_id` is null, and when the arm's `class_level_id` is null. Spelling
     * those four out as an orWhere chain would be four chances to forget one; the negation of the
     * placeable condition is exact by construction, and it is the exact complement of
     * {@see listForCohort()}'s filter — so every billable enrollment is in some cohort or in here,
     * and none is in both.
     *
     * @return list<BillableEnrollment>
     */
    public function listUnplaceableForSchool(int $schoolId): array
    {
        return $this->currentEnrollments($schoolId)
            ->whereDoesntHave('curriculum', function ($query) {
                $query->withoutGlobalScope(SchoolScope::class)
                    ->whereNotNull('term_id')
                    ->whereHas('classLevelArm', fn ($arm) => $arm
                        ->withoutGlobalScope(SchoolScope::class)
                        ->whereNotNull('class_level_id'));
            })
            ->get()
            ->map(fn (StudentCurriculum $enrollment) => $this->toBillableEnrollment($enrollment))
            ->all();
    }

    /**
     * THE ONE DEFINITION OF "BILLABLE", shared by both cohort reads. It is
     * {@see currentForStudent()}'s definition applied to a whole School instead of to one student:
     *
     *   status = ACTIVE                       — the filter currentForStudent() applies
     *   latest id per student                 — the tie-break currentForStudent()'s latest('id') applies
     *
     * Both halves are needed. The status filter alone would return every active episode a student
     * has; currentForStudent() returns one. Deriving the cohort from anything else — an `ended_at`
     * test, a term-currency test — would be a SECOND definition, and the day the two disagree a
     * student is billable down one code path and invisible down the other.
     *
     * WITHDRAWN / soft-ended enrollments are therefore EXCLUDED, and by the status column alone:
     * withdraw sets status to WITHDRAWN. `ended_at` is NOT consulted here, because
     * currentForStudent() does not consult it either — see the report for the one shape where the
     * two columns can disagree.
     *
     * SOFT-DELETED STUDENTS are INCLUDED, again because currentForStudent() includes them: it
     * queries `student_curricula` and never touches `students`, so a trashed student's active
     * episode still resolves. The whereExists below is written to match (it ignores `deleted_at`)
     * rather than quietly introducing a third rule. Whether bulk generation SHOULD bill a
     * soft-deleted student is a real question and it belongs to commit 2, where the invoice is
     * actually raised — not to a read that is contractually a mirror of an existing one.
     *
     * ISOLATION. $schoolId is the ONLY thing that decides which School is read, and it is enforced
     * twice over:
     *
     *   1. `student_curricula.school_id = $schoolId`. NOT NULL since slice (i), and the composite FK
     *      student_curricula_student_school_foreign (student_id, school_id) -> students (id,
     *      school_id) makes it structurally equal to the student's School — the engine rejects a
     *      divergent row, so this is not a hopeful column.
     *   2. an EXISTS through `students` on the same pair. Redundant TODAY, on purpose: it is the
     *      clause that still holds if the FK is ever dropped, and it is the "through the student"
     *      constraint the isolation brief asked for by name.
     *
     * AND THE AMBIENT SchoolScope IS STRIPPED. That is a deliberate removal, not an oversight. A
     * method whose School is an ARGUMENT must not also carry a second, ambient opinion about which
     * School it is reading: when the two disagree the intersection is EMPTY, and an empty cohort is
     * not an error anywhere — bulk generation would raise zero invoices and report success. Silent
     * partial (here, total) results are the defect class this whole pair of methods exists to close
     * (docs/ui-ux-design-system.md §26), so the argument is made authoritative and the tests prove
     * it holds on its own. Every nested closure strips the scope for the same reason: a scoped
     * relation subquery reintroduces the empty-intersection mode one level down.
     *
     * @return Builder<StudentCurriculum>
     */
    private function currentEnrollments(int $schoolId): Builder
    {
        return StudentCurriculum::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('student_curricula.school_id', $schoolId)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('students')
                ->whereColumn('students.id', 'student_curricula.student_id')
                ->where('students.school_id', $schoolId))
            ->where('student_curricula.status', StudentStatusEnum::ACTIVE)
            // The tie-break, as a set: the latest ACTIVE episode of each student in this School.
            // MAX(id) mirrors latest('id'); grouping by student_id is what makes it one row per
            // student. Constrained to the same School so the subquery cannot pick a foreign row
            // and then have the outer clauses exclude it, leaving the student out of the cohort
            // entirely — a silent omission, again.
            ->whereIn('student_curricula.id', fn ($query) => $query
                ->selectRaw('MAX(id)')
                ->from('student_curricula')
                ->where('school_id', $schoolId)
                ->where('status', StudentStatusEnum::ACTIVE->value)
                ->groupBy('student_id'))
            ->with($this->unscopedSnapshotRelations())
            ->orderBy('student_curricula.student_id');
    }

    /**
     * SNAPSHOT_RELATIONS with the ambient SchoolScope stripped at every level, for the cohort reads.
     *
     * Not cosmetic. {@see schoolId()} derives the DTO's School from the eager-loaded student, then
     * the curriculum, then falls back to 0. Under an ambient context that disagrees with $schoolId
     * both relations resolve to null and every DTO in the cohort would carry schoolId 0 — which
     * commit 2 would stamp onto an invoice. Stripping the scope keeps these reads a pure function of
     * their argument, labels included.
     *
     * @return array<string, \Closure>
     */
    private function unscopedSnapshotRelations(): array
    {
        $unscoped = fn ($query) => $query->withoutGlobalScope(SchoolScope::class);

        return array_fill_keys(self::SNAPSHOT_RELATIONS, $unscoped);
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

    /**
     * The School's admission-number roster, for an exact join key (see the port's docblock for why
     * neither displayFor() nor matchingStudentIds() can serve as one). Ordered by id so a caller's
     * duplicate-detection and its "absent from the file" report are stable between runs.
     *
     * @return list<array{student_id: int, admission_number: ?string}>
     */
    public function admissionNumberIndex(): array
    {
        return Student::query()
            ->orderBy('id')
            ->get(['id', 'admission_number'])
            ->map(fn (Student $student) => [
                'student_id' => (int) $student->getAttribute('id'),
                'admission_number' => $student->getAttribute('admission_number'),
            ])
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
            termId: $this->termId($enrollment),
            classLevelId: $this->classLevelId($enrollment),
        );
    }

    /**
     * The episode's term. ONE hop — `curricula.term_id` is a direct FK to `terms`.
     *
     * The spec (§5) prescribed a three-hop resolution through a `curricula.term` ordinal (1|2|3)
     * joined to `terms` on the unique `(academic_session_id, order)`, and warned that the id types
     * on `curricula.academic_session_id` vs `terms.academic_session_id` might disagree across the
     * hybrid uuid→integer conversion. NEITHER COLUMN EXISTS: `2026_05_06_085734_update_terms_and_
     * curricula_tables.php:114` dropped `curricula.term` AND `curricula.academic_session_id`
     * together and replaced them with this `term_id` FK. Confirmed against information_schema on
     * 2026-08-06. The ordinal join, and the id-type question with it, is dead code that was never
     * written. (The stale index name `curricula_school_id_academic_session_id_status_index`
     * survives the drop and covers only (school_id, status) — it is a name that lies, not a column.)
     */
    private function termId(StudentCurriculum $enrollment): ?int
    {
        $curriculum = $enrollment->getAttribute('curriculum');
        $termId = is_object($curriculum) ? $curriculum->getAttribute('term_id') : null;

        return $termId === null ? null : (int) $termId;
    }

    /**
     * The episode's class level — `curricula.class_level_arm_id → class_level_arms.class_level_id`,
     * the one hop of §5's chain that survived. A fee schedule is keyed to the class LEVEL, not the
     * arm: JSS1A and JSS1B are priced identically. Null when either link is absent (both columns
     * are nullable), which the caller must report as "not comparable" rather than guess at.
     */
    private function classLevelId(StudentCurriculum $enrollment): ?int
    {
        $curriculum = $enrollment->getAttribute('curriculum');
        $classLevelId = is_object($curriculum) ? $curriculum->classLevelArm?->class_level_id : null;

        return $classLevelId === null ? null : (int) $classLevelId;
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
