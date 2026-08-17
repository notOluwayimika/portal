<?php

/*
 * U6 commit 1 — the ACL port's two cohort reads: listForCohort() and listUnplaceableForSchool().
 *
 * The load-bearing claims these tests exist to break, in the order they matter:
 *
 *   1. ISOLATION IS NOT AUTOMATIC HERE, and the risk is old. `student_curricula` only gained a
 *      school_id in slice (i) and only gained a SchoolScope in slice (ii); the comment in
 *      BillableEnrollmentAdapter::findByUuid has now been wrong about that in BOTH directions.
 *      Worse, both new methods take a School as an ARGUMENT and deliberately STRIP the ambient
 *      scope — so the argument is the only thing standing between School A's bulk run and School
 *      B's students. `cohortIsolation` and `unplaceableIsolation` below are written to be PLANTED
 *      against: delete the school constraint in currentEnrollments() and they must go red. A test
 *      that has never failed is a claim, not a proof.
 *
 *   2. THE TWO DEFINITIONS OF "BILLABLE" MUST NOT DRIFT. currentForStudent() answers for one
 *      student; listForCohort() answers for a whole class level. If they ever disagree, a student
 *      is billable down the single-invoice path and invisible to the bulk one — or billed twice by
 *      it. So the agreement is asserted directly, both ways, including the awkward shape (a student
 *      holding TWO active episodes) where a naive "status = active" cohort would return both rows.
 *
 *   3. NOBODY IS SILENTLY OMITTED. An enrollment with a null term or a null class level matches no
 *      cohort query anyone runs, which is exactly why bulk generation would bill 47 of 50 and report
 *      success (docs/ui-ux-design-system.md §26). The complement is asserted as a partition: every
 *      billable enrollment lands in a cohort or in the unplaceable list, and none lands in both.
 *
 * No ambient School context is established anywhere in this file (no ActiveSchool::runFor around the
 * calls under test) — that is the point. If these reads only isolate because a scope happened to be
 * active, they fail here.
 */

use App\Academics\BillableEnrollmentAdapter;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Finance\Contracts\BillableEnrollment;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A School with a session, a term, a class level and an arm — the pricing coordinates an enrollment
 * keys off. Built inside runFor() because the WRITE side legitimately needs a context (Curriculum is
 * School-scoped); every READ under test runs outside one.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm}
 */
function cohortSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $arm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return ['school' => $school, 'term' => $term, 'level' => $level, 'arm' => $arm];
    });
}

/** A second class level + arm in the same School, for "the cohort next door". */
function cohortSecondLevel(array $ctx): array
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $level = ClassLevel::create(['school_id' => $ctx['school']->id, 'name' => 'JSS 2', 'order' => 2]);
        $arm = ClassLevelArm::create([
            'school_id' => $ctx['school']->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $ctx['school']->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return ['level' => $level, 'arm' => $arm];
    });
}

/**
 * A student in $ctx's School with an enrollment at the given coordinates.
 *
 * $armId / $termId are passed explicitly (and may be null) so the null-coordinate shapes the
 * unplaceable list exists for can be built without a second helper. $curriculum = false builds the
 * enrollment with NO curriculum at all — the fourth unplaceable shape.
 *
 * THE NO-CURRICULUM ROW IS INSERTED RAW, and that is not the test taking a shortcut.
 * `student_curricula.curriculum_id` is nullable in the schema, so the shape is reachable; but
 * StudentCurriculum::create() fires StudentCurriculumObserver::created(), whose remediation path
 * calls StudentSubjectService::autoAttachCompulsorySubjects() and fatals on a null curriculum
 * (StudentSubjectService.php:35). The observer's own docblock names raw SQL / seeders / imports as
 * exactly how these rows arise in production — so a raw insert IS the production path for this
 * shape, and using the model would test a row production never produces. (The observer fataling on
 * a legal row is a real defect; it is not this commit's, and it is recorded in the branch report.)
 */
function cohortStudent(
    array $ctx,
    ?int $armId = null,
    ?int $termId = null,
    string|StudentStatusEnum $status = StudentStatusEnum::ACTIVE,
    bool $curriculum = true,
): Student {
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $armId, $termId, $status, $curriculum) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => 'ADM-'.Str::random(8),
        ]);

        if (! $curriculum) {
            DB::table('student_curricula')->insert([
                'uuid' => (string) Str::uuid(),
                'student_id' => $student->id,
                'school_id' => $ctx['school']->id,
                'curriculum_id' => null,
                'status' => $status instanceof StudentStatusEnum ? $status->value : $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $student;
        }

        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $armId,
                'term_id' => $termId,
            ])->id,
            // PROMOTED is written in a second step: the CHECK constraint
            // student_curricula_promoted_requires_link forbids status='promoted' without a
            // promoted_to_id, and the link must point at a real episode.
            'status' => $status === StudentStatusEnum::PROMOTED ? StudentStatusEnum::ACTIVE : $status,
        ]);

        if ($status === StudentStatusEnum::PROMOTED) {
            $target = StudentCurriculum::create([
                'student_id' => $student->id,
                'school_id' => $ctx['school']->id,
                'curriculum_id' => Curriculum::factory()->create([
                    'school_id' => $ctx['school']->id,
                    'class_level_arm_id' => $armId,
                    'term_id' => $termId,
                ])->id,
                'status' => StudentStatusEnum::WITHDRAWN,
            ]);

            $enrollment->update(['status' => StudentStatusEnum::PROMOTED, 'promoted_to_id' => $target->id]);
        }

        return $student;
    });
}

/** @return list<int> the student ids in a cohort/unplaceable result, for readable assertions */
function studentIdsOf(array $enrollments): array
{
    return array_map(fn (BillableEnrollment $e) => $e->studentId, $enrollments);
}

function cohortAdapter(): BillableEnrollmentAdapter
{
    return new BillableEnrollmentAdapter;
}

/* ── 1 · Isolation — the planted pair ──────────────────────────────────────────────────────── */

test('cohortIsolation: a School B student at School A\'s exact coordinates is not in School A\'s cohort', function () {
    $a = cohortSchool();
    $b = cohortSchool();

    $mine = cohortStudent($a, $a['arm']->id, $a['term']->id);

    // School B's student, on a School B curriculum pointing at School A's OWN term_id and arm id.
    // This is deliberate and it is the whole test: the first version of this case gave School B its
    // own term and level, and the planted run stayed GREEN — the coordinate filter was doing the
    // excluding and the school constraint was never exercised. Nothing in the schema prevents the
    // collision either: curricula_term_id_foreign and fk_curricula_class_level_arm_id are both
    // SINGLE-column FKs (information_schema, 2026-08-17), so a curriculum may reference another
    // School's term and arm and the engine will not object. school_id is therefore the ONLY thing
    // separating these two students, which is exactly what this test needs to be able to say.
    $theirs = cohortStudent($b, $a['arm']->id, $a['term']->id);

    $cohort = cohortAdapter()->listForCohort($a['school']->id, $a['term']->id, $a['level']->id);

    expect(studentIdsOf($cohort))->toBe([$mine->id])
        ->and(studentIdsOf($cohort))->not->toContain($theirs->id);

    // And the School of every DTO returned is the School asked for — not 0, not the other one.
    // schoolId is derived from the eager-loaded student, so this also catches the scope-stripping
    // on the snapshot relations silently failing and falling back.
    foreach ($cohort as $enrollment) {
        expect($enrollment->schoolId)->toBe($a['school']->id);
    }
});

test('unplaceableIsolation: School B\'s unplaceable enrollments are not in School A\'s list', function () {
    $a = cohortSchool();
    $b = cohortSchool();

    $mine = cohortStudent($a, armId: null, termId: null);
    $theirs = cohortStudent($b, armId: null, termId: null);

    $unplaceable = cohortAdapter()->listUnplaceableForSchool($a['school']->id);

    expect(studentIdsOf($unplaceable))->toBe([$mine->id])
        ->and(studentIdsOf($unplaceable))->not->toContain($theirs->id);
});

test('isolation holds when the ambient School context is the WRONG one', function () {
    // The ambient SchoolScope is stripped deliberately, so a disagreeing context must neither leak
    // School B's rows nor silently empty School A's cohort. Both failure modes are asserted: the
    // empty one is the "billed 0 of 50, reported success" shape.
    $a = cohortSchool();
    $b = cohortSchool();

    $mine = cohortStudent($a, $a['arm']->id, $a['term']->id);
    cohortStudent($b, $a['arm']->id, $a['term']->id);   // colliding coordinates, per cohortIsolation

    $cohort = ActiveSchool::runFor(
        $b['school']->id,
        fn () => cohortAdapter()->listForCohort($a['school']->id, $a['term']->id, $a['level']->id)
    );

    expect(studentIdsOf($cohort))->toBe([$mine->id]);
});

/* ── 2 · "Billable" is one definition, not two ─────────────────────────────────────────────── */

test('the cohort filter is exactly currentForStudent\'s definition', function () {
    $ctx = cohortSchool();

    $active = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $withdrawn = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id, StudentStatusEnum::WITHDRAWN);
    $promoted = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id, StudentStatusEnum::PROMOTED);
    $repeated = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id, StudentStatusEnum::REPEATED);

    $adapter = cohortAdapter();
    $cohort = $adapter->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id);

    expect(studentIdsOf($cohort))->toBe([$active->id]);

    // Both directions, per student, against the OTHER code path — this is the assertion that
    // actually pins the two definitions together rather than restating one of them.
    foreach ([$active, $withdrawn, $promoted, $repeated] as $student) {
        $current = ActiveSchool::runFor($ctx['school']->id, fn () => $adapter->currentForStudent($student->id));
        $inCohort = in_array($student->id, studentIdsOf($cohort), true);

        expect($inCohort)->toBe($current !== null);
    }
});

test('a student holding TWO active episodes is billed once, for the one currentForStudent returns', function () {
    $ctx = cohortSchool();
    $second = cohortSecondLevel($ctx);

    $student = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // A second ACTIVE episode, later by id, at the class level NEXT DOOR. A cohort built as a plain
    // `status = active` filter would put this student in BOTH levels' cohorts and bill them twice;
    // currentForStudent() would meanwhile report only the later one.
    $later = ActiveSchool::runFor($ctx['school']->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'school_id' => $ctx['school']->id,
        'curriculum_id' => Curriculum::factory()->create([
            'school_id' => $ctx['school']->id,
            'class_level_arm_id' => $second['arm']->id,
            'term_id' => $ctx['term']->id,
        ])->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));

    $adapter = cohortAdapter();
    $first = $adapter->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id);
    $next = $adapter->listForCohort($ctx['school']->id, $ctx['term']->id, $second['level']->id);

    expect(studentIdsOf($first))->toBe([])
        ->and(studentIdsOf($next))->toBe([$student->id])
        ->and($next[0]->enrollmentId)->toBe($later->id);

    $current = ActiveSchool::runFor($ctx['school']->id, fn () => $adapter->currentForStudent($student->id));
    expect($current->enrollmentId)->toBe($next[0]->enrollmentId);
});

test('the cohort is the class LEVEL, not the arm, and not the neighbouring term', function () {
    $ctx = cohortSchool();

    // A second arm under the SAME level: JSS1A and JSS1B are priced identically, so both belong.
    $secondArm = ActiveSchool::runFor($ctx['school']->id, fn () => ClassLevelArm::create([
        'school_id' => $ctx['school']->id,
        'class_level_id' => $ctx['level']->id,
        'arm_id' => Arm::create(['school_id' => $ctx['school']->id, 'label' => strtoupper(Str::random(3))])->id,
    ]));

    $armA = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $armB = cohortStudent($ctx, $secondArm->id, $ctx['term']->id);

    // Same level, DIFFERENT term — a different pricing coordinate, so out.
    $otherTerm = ActiveSchool::runFor($ctx['school']->id, fn () => Term::create([
        'academic_session_id' => $ctx['term']->academic_session_id, 'school_id' => $ctx['school']->id,
        'name' => 'Second Term', 'slug' => 'term-'.Str::random(8), 'order' => 2,
        'start_date' => now()->addMonths(3), 'end_date' => now()->addMonths(5),
        'status' => TermStatusEnum::ACTIVE->value,
    ]));
    $wrongTerm = cohortStudent($ctx, $ctx['arm']->id, $otherTerm->id);

    // Different level entirely.
    $second = cohortSecondLevel($ctx);
    $wrongLevel = cohortStudent($ctx, $second['arm']->id, $ctx['term']->id);

    $cohort = studentIdsOf(cohortAdapter()->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id));

    expect($cohort)->toContain($armA->id)
        ->and($cohort)->toContain($armB->id)
        ->and($cohort)->not->toContain($wrongTerm->id)
        ->and($cohort)->not->toContain($wrongLevel->id)
        ->and($cohort)->toHaveCount(2);
});

/* ── 3 · Nobody is silently omitted ────────────────────────────────────────────────────────── */

test('every shape that cannot reach a fee schedule is reported unplaceable', function () {
    $ctx = cohortSchool();

    $nullTerm = cohortStudent($ctx, $ctx['arm']->id, null);
    $nullArm = cohortStudent($ctx, null, $ctx['term']->id);
    $bothNull = cohortStudent($ctx, null, null);
    $noCurriculum = cohortStudent($ctx, null, null, curriculum: false);
    $placeable = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    // Not billable at all, so not this list's business either — it belongs to no cohort AND to no
    // "could not be placed" count, and a screen must not report a withdrawn student as a failure.
    $withdrawn = cohortStudent($ctx, null, null, StudentStatusEnum::WITHDRAWN);

    // The arm with a NULL class_level_id — the fourth hop, and the one an orWhere chain forgets.
    $orphanArm = ActiveSchool::runFor($ctx['school']->id, fn () => ClassLevelArm::create([
        'school_id' => $ctx['school']->id,
        'class_level_id' => null,
        'arm_id' => Arm::create(['school_id' => $ctx['school']->id, 'label' => strtoupper(Str::random(3))])->id,
    ]));
    $nullLevel = cohortStudent($ctx, $orphanArm->id, $ctx['term']->id);

    $unplaceable = studentIdsOf(cohortAdapter()->listUnplaceableForSchool($ctx['school']->id));

    expect($unplaceable)->toContain($nullTerm->id)
        ->and($unplaceable)->toContain($nullArm->id)
        ->and($unplaceable)->toContain($bothNull->id)
        ->and($unplaceable)->toContain($noCurriculum->id)
        ->and($unplaceable)->toContain($nullLevel->id)
        ->and($unplaceable)->not->toContain($placeable->id)
        ->and($unplaceable)->not->toContain($withdrawn->id)
        ->and($unplaceable)->toHaveCount(5);
});

test('cohorts and the unplaceable list PARTITION the billable set — no gap, no overlap', function () {
    $ctx = cohortSchool();
    $second = cohortSecondLevel($ctx);

    $inFirst = [cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id), cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id)];
    $inSecond = [cohortStudent($ctx, $second['arm']->id, $ctx['term']->id)];
    $stranded = [cohortStudent($ctx, null, $ctx['term']->id), cohortStudent($ctx, $ctx['arm']->id, null)];
    cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id, StudentStatusEnum::WITHDRAWN);

    $adapter = cohortAdapter();
    $covered = array_merge(
        studentIdsOf($adapter->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id)),
        studentIdsOf($adapter->listForCohort($ctx['school']->id, $ctx['term']->id, $second['level']->id)),
        studentIdsOf($adapter->listUnplaceableForSchool($ctx['school']->id)),
    );

    $billable = array_map(fn (Student $s) => $s->id, array_merge($inFirst, $inSecond, $stranded));

    sort($covered);
    sort($billable);

    expect($covered)->toBe($billable)                        // no gap
        ->and($covered)->toBe(array_values(array_unique($covered)));  // no overlap
});

test('the unplaceable DTO names its own reason through termId / classLevelId', function () {
    // The port promises commit 3 can read the reason off the DTO without a new field. Assert that
    // the promise holds rather than leaving the screen to discover it.
    $ctx = cohortSchool();
    cohortStudent($ctx, $ctx['arm']->id, null);
    cohortStudent($ctx, null, $ctx['term']->id);

    $unplaceable = cohortAdapter()->listUnplaceableForSchool($ctx['school']->id);

    expect($unplaceable)->toHaveCount(2);
    foreach ($unplaceable as $enrollment) {
        expect($enrollment->termId === null || $enrollment->classLevelId === null)->toBeTrue();
    }
});

/* ── 4 · Cost — commit 2 loops this ────────────────────────────────────────────────────────── */

test('the cohort read costs a constant number of queries, not one per student', function () {
    $ctx = cohortSchool();

    $count = function (int $students) use ($ctx) {
        for ($i = 0; $i < $students; $i++) {
            cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cohort = cohortAdapter()->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, count($cohort)];
    };

    [$small, $smallSize] = $count(3);
    [$large, $largeSize] = $count(27);

    expect($smallSize)->toBe(3)
        ->and($largeSize)->toBe(30)
        ->and($large)->toBe($small);   // flat in cohort size — eager-loaded, not N+1
});
