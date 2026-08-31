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

    // AND THE SNAPSHOT RELATIONS ARE UNSCOPED — asserted HERE, not in cohortIsolation, and the
    // location is the entire point. schoolId is derived from the eager-loaded student
    // (BillableEnrollmentAdapter::schoolId()), falling back to the curriculum and then to 0. If
    // currentEnrollments() eager-loads with self::SNAPSHOT_RELATIONS instead of
    // unscopedSnapshotRelations(), both relations are filtered to the AMBIENT School, resolve to
    // null under a foreign context, and every DTO silently carries schoolId 0 — which commit 2
    // stamps onto an invoice, producing an invoice attributed to no School at all.
    //
    // cohortIsolation cannot catch that substitution: it runs with NO ambient context, where
    // SchoolScope is a no-op and the scoped and unscoped eager loads are identical. This test is
    // the only place in the file where re-scoping the relations changes an observable value, so
    // the assertion belongs here or nowhere. Planted and watched red — see the branch report.
    expect($cohort[0]->schoolId)->toBe($a['school']->id)
        ->and($cohort[0]->studentId)->toBe($mine->id)
        // The name is snapshot-copied onto the invoice; a scoped relation degrades it to the
        // 'Student #<id>' fallback, so it fails on the same substitution and says why.
        ->and($cohort[0]->studentName)->not->toBe('Student #'.$mine->id);

    // Same substitution, same exposure, on the other method — it shares currentEnrollments().
    $unplaceableSchool = cohortSchool();
    $stranded = cohortStudent($unplaceableSchool, armId: null, termId: null);

    $unplaceable = ActiveSchool::runFor(
        $b['school']->id,
        fn () => cohortAdapter()->listUnplaceableForSchool($unplaceableSchool['school']->id)
    );

    expect(studentIdsOf($unplaceable))->toBe([$stranded->id])
        ->and($unplaceable[0]->schoolId)->toBe($unplaceableSchool['school']->id);
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

test('currentForStudent applies the SAME tie-break, and fails on its own when it is removed', function () {
    // The test above dies on its FIRST assertion — the cohort one — when the tie-break is planted
    // away, so it cannot show that currentForStudent is coupled to the same rule; a reader could
    // reasonably conclude only the cohort side is covered. This test asserts the single-invoice path
    // alone, so the plant produces TWO independent reds and the coupling is demonstrated rather than
    // asserted. Both now route through BillableEnrollmentAdapter::billableEpisodes().
    $ctx = cohortSchool();
    $student = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $later = ActiveSchool::runFor($ctx['school']->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'school_id' => $ctx['school']->id,
        'curriculum_id' => Curriculum::factory()->create([
            'school_id' => $ctx['school']->id,
            'class_level_arm_id' => $ctx['arm']->id,
            'term_id' => $ctx['term']->id,
        ])->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));

    $current = ActiveSchool::runFor(
        $ctx['school']->id,
        fn () => cohortAdapter()->currentForStudent($student->id)
    );

    expect($current)->not->toBeNull()
        ->and($current->enrollmentId)->toBe($later->id);
});

test('currentForStudents is currentForStudent asked once — same answer per student, unplaceable ABSENT', function () {
    // FOUR SHAPES IN ONE FIXTURE, because the map's contract has two halves and a fixture of
    // placeable students can only see one of them:
    //
    //   - a placeable student, who must be present;
    //   - a student holding TWO active episodes, so the MAX(id) tie-break has to survive the
    //     widening from `where` to `whereIn` — a batch that dropped it would return the earlier
    //     episode, or both rows and then silently keep whichever `keyBy` saw last;
    //   - a WITHDRAWN student and a student with NO episode at all, who must be ABSENT rather than
    //     present with a null value. A map that padded every requested id would pass a fixture made
    //     only of the first two, and the Action's `?? null` would then never fire.
    //
    // THE EXPECTATION IS DERIVED BY THE OTHER CODE PATH, one student at a time. Nothing here
    // restates the batch's own rule, so an implementation that agrees with itself is not enough.
    $ctx = cohortSchool();
    $second = cohortSecondLevel($ctx);

    $placeable = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $withdrawn = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id, StudentStatusEnum::WITHDRAWN);
    $twoEpisodes = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $later = ActiveSchool::runFor($ctx['school']->id, fn () => StudentCurriculum::create([
        'student_id' => $twoEpisodes->id,
        'school_id' => $ctx['school']->id,
        'curriculum_id' => Curriculum::factory()->create([
            'school_id' => $ctx['school']->id,
            'class_level_arm_id' => $second['arm']->id,
            'term_id' => $ctx['term']->id,
        ])->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));

    // NO `student_curricula` ROW AT ALL — distinct from the withdrawn one, and the shape a bursar's
    // selection actually produces when they tick a newly-admitted pupil.
    $noEpisode = ActiveSchool::runFor($ctx['school']->id, fn () => Student::factory()->create([
        'school_id' => $ctx['school']->id,
        'admission_number' => 'ADM-'.Str::random(8),
    ]));

    $adapter = cohortAdapter();
    $ids = [$placeable->id, $withdrawn->id, $twoEpisodes->id, $noEpisode->id];

    $map = ActiveSchool::runFor($ctx['school']->id, fn () => $adapter->currentForStudents($ids));

    foreach ($ids as $id) {
        $single = ActiveSchool::runFor($ctx['school']->id, fn () => $adapter->currentForStudent($id));

        if ($single === null) {
            expect($map)->not->toHaveKey($id);

            continue;
        }

        expect($map)->toHaveKey($id)
            ->and($map[$id]->enrollmentId)->toBe($single->enrollmentId)
            ->and($map[$id]->studentId)->toBe($single->studentId)
            ->and($map[$id]->enrollmentUuid)->toBe($single->enrollmentUuid);
    }

    // NOT VACUOUS IN EITHER DIRECTION. The loop above is satisfied by an empty map (every arm takes
    // the null branch) and by a padded one (nothing takes it), so the fixture's own split is pinned:
    // two present, two absent, and the two present are DIFFERENT episodes rather than one row
    // returned twice.
    expect(array_keys($map))->toHaveCount(2)
        ->and($map[$twoEpisodes->id]->enrollmentId)->toBe($later->id)
        ->and($map[$placeable->id]->enrollmentId)->not->toBe($map[$twoEpisodes->id]->enrollmentId);
});

test('currentForStudents is AMBIENT-scoped, and the mirror proves it is the scope doing it', function () {
    // The port splits its methods into AMBIENT and ARGUMENT and this one is deliberately ambient,
    // matching currentForStudent. A one-directional assertion ("School B's id is absent under School
    // A") passes for an implementation that returns nothing at all, so the mirror is the arm: under
    // School B the SAME two ids resolve the other way round.
    $a = cohortSchool();
    $b = cohortSchool();

    $mine = cohortStudent($a, $a['arm']->id, $a['term']->id);
    $theirs = cohortStudent($b, $b['arm']->id, $b['term']->id);

    $ids = [$mine->id, $theirs->id];

    $underA = ActiveSchool::runFor($a['school']->id, fn () => cohortAdapter()->currentForStudents($ids));
    $underB = ActiveSchool::runFor($b['school']->id, fn () => cohortAdapter()->currentForStudents($ids));

    expect($underA)->toHaveKey($mine->id)
        ->and($underA)->not->toHaveKey($theirs->id)
        ->and($underB)->toHaveKey($theirs->id)
        ->and($underB)->not->toHaveKey($mine->id);
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

test('the two methods cover the billable set WHEN EVERY OCCUPIED COORDINATE IS ITERATED — and not otherwise', function () {
    // NOT a partition, and this test used to claim it was one. listUnplaceableForSchool() is the
    // exact complement of ONE listForCohort() call, at the coordinates that call names — so the
    // cover holds only for a caller that iterates every occupied coordinate pair, which is what
    // this test does and what commit 2 must do. The second half proves the qualifier is real by
    // NOT iterating one, and showing a billable student then falls through both methods with
    // nothing reporting it. See docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md.
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

    expect($covered)->toBe($billable)                        // no gap, GIVEN both coordinates iterated
        ->and($covered)->toBe(array_values(array_unique($covered)));  // no overlap

    // Now skip the second level, as a caller with an incomplete coordinate list would. The student
    // enrolled there is billable, is in no cohort anyone asked for, and is NOT unplaceable — so
    // nothing here reports them, and a screen built on these two methods alone would announce
    // success having billed 3 of 4. That is the gap the ticket exists to close in commit 3.
    $partial = array_merge(
        studentIdsOf($adapter->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id)),
        studentIdsOf($adapter->listUnplaceableForSchool($ctx['school']->id)),
    );

    expect($partial)->not->toContain($inSecond[0]->id)
        ->and(count($partial))->toBe(count($billable) - 1);
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

/**
 * Queries issued by $read, and what it returned. Flushing before enabling keeps a prior test's log
 * out of the count.
 *
 * @return array{0: int, 1: int} [queryCount, resultCount]
 */
function cohortQueryCost(Closure $read): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = $read();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$queries, count($rows)];
}

test('the cohort read costs EIGHT queries, at any cohort size', function () {
    // BOTH halves matter and the first version asserted only the second. `$large === $small` passes
    // for any constant, including a constant 40 — it pins flatness and says nothing about the level.
    // The absolute number is asserted too, so a change that adds a whole extra round trip per call
    // (which commit 2 pays once per class level, in a loop) fails here instead of passing quietly.
    //
    // EIGHT = one root query + seven eager loads, exactly the seven paths SNAPSHOT_RELATIONS
    // declares: student, curriculum, classLevelArm, classLevel, arm, academicSession, term. If this
    // number legitimately changes, SNAPSHOT_RELATIONS changed; update both together.
    $ctx = cohortSchool();

    $count = function (int $students) use ($ctx) {
        for ($i = 0; $i < $students; $i++) {
            cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
        }

        return cohortQueryCost(fn () => cohortAdapter()
            ->listForCohort($ctx['school']->id, $ctx['term']->id, $ctx['level']->id));
    };

    [$small, $smallSize] = $count(3);
    [$large, $largeSize] = $count(27);

    expect($smallSize)->toBe(3)
        ->and($largeSize)->toBe(30)
        ->and($small)->toBe(8)
        ->and($large)->toBe(8);   // flat AND eight — eager-loaded, not N+1
});

test('the BATCH student read costs the same EIGHT, at any list size', function () {
    // The same two halves as the cohort arm above, for the same reason, on the read that replaced a
    // loop of currentForStudent() in StartManualInvoiceRun. It is the SAME eight because it is the
    // same builder — billableEpisodes() with `whereIn` in place of `where` — so if these two numbers
    // ever diverge, one of them grew a query the other did not and the two reads have started to
    // differ by more than their predicate.
    //
    // WHAT THIS REPLACED, measured on a copy of production data: 611 students through
    // currentForStudent() in a loop cost 4888 queries / 1647 ms, because the single-student call is
    // eight queries and not one. The same 611 through this method cost 8 / 82.7 ms.
    $ctx = cohortSchool();
    $ids = [];

    $count = function (int $students) use ($ctx, &$ids) {
        for ($i = 0; $i < $students; $i++) {
            $ids[] = cohortStudent($ctx, $ctx['arm']->id, $ctx['term']->id)->id;
        }

        // runFor is OUTSIDE the counted window: the ambient context is this method's isolation, not
        // its cost, and resolving it inside would put a School lookup in one measurement.
        return ActiveSchool::runFor(
            $ctx['school']->id,
            fn () => cohortQueryCost(fn () => cohortAdapter()->currentForStudents($ids)),
        );
    };

    [$small, $smallSize] = $count(3);
    [$large, $largeSize] = $count(27);

    expect($smallSize)->toBe(3)
        ->and($largeSize)->toBe(30)
        ->and($small)->toBe(8)
        ->and($large)->toBe(8);
});

test('the unplaceable read is flat in size, and never exceeds the cohort read\'s ceiling', function () {
    // It had no cost test at all, and it is not free: commit 3 calls it beside the run.
    //
    // I ASSERTED EIGHT HERE FIRST AND IT WAS WRONG — measured 4. The count is DATA-shaped, not just
    // code-shaped: Laravel skips an eager-load query entirely when every parent key for it is null,
    // and an unplaceable row is by definition one whose coordinate keys are null. All-null rows load
    // student + curriculum + the hasOneThrough probe and nothing else (4). A fixture that mixes in a
    // row with a real arm whose class_level_id is null pays 7. So a fixed number is a property of the
    // fixture, and asserting one would pin the fixture rather than the code.
    //
    // The two properties that ARE the code's: flat in the number of students, and never above the
    // structural ceiling of 1 root + count(SNAPSHOT_RELATIONS) eager loads = 8.
    $ctx = cohortSchool();

    $count = function (int $students) use ($ctx) {
        for ($i = 0; $i < $students; $i++) {
            cohortStudent($ctx, armId: null, termId: null);
        }

        return cohortQueryCost(fn () => cohortAdapter()->listUnplaceableForSchool($ctx['school']->id));
    };

    [$small, $smallSize] = $count(3);
    [$large, $largeSize] = $count(27);

    expect($smallSize)->toBe(3)
        ->and($largeSize)->toBe(30)
        ->and($large)->toBe($small)          // flat in size — the N+1 guard
        ->and($small)->toBe(4)               // this fixture's shape, measured
        ->and($large)->toBeLessThanOrEqual(8);

    // The mixed shape, so the ceiling is exercised by something above the floor rather than only by
    // the cheapest fixture. An arm that exists but names no class level is unplaceable AND loads the
    // arm chain, so it costs strictly more than the all-null rows beside it.
    $orphanArm = ActiveSchool::runFor($ctx['school']->id, fn () => ClassLevelArm::create([
        'school_id' => $ctx['school']->id,
        'class_level_id' => null,
        'arm_id' => Arm::create(['school_id' => $ctx['school']->id, 'label' => strtoupper(Str::random(3))])->id,
    ]));
    cohortStudent($ctx, $orphanArm->id, $ctx['term']->id);

    [$mixed, $mixedSize] = cohortQueryCost(
        fn () => cohortAdapter()->listUnplaceableForSchool($ctx['school']->id)
    );

    expect($mixedSize)->toBe(31)
        ->and($mixed)->toBeGreaterThan($large)
        ->and($mixed)->toBeLessThanOrEqual(8);
});
