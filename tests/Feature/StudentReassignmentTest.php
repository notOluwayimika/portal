<?php

use App\Academics\BillableEnrollmentAdapter;
use App\Enums\StudentMembershipStatus;
use App\Enums\StudentStatusEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\Permission;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\CohortSiblings;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture — one Year 8 cohort with arms B and S (the reassignment this screen
// exists for), plus a Year 9 class as the same-school NON-sibling and a whole
// second school as the isolation subject.
// ---------------------------------------------------------------------------

function sr_admin(School $school): User
{
    $user = al_makeUser($school->id);

    $permission = Permission::where('name', 'academic_setup.manage')->where('guard_name', 'web')->first()
        ?? Permission::create(['name' => 'academic_setup.manage', 'guard_name' => 'web']);

    setPermissionsTeamId($school->id);
    $user->givePermissionTo($permission);

    return $user;
}

function sr_arm(School $school, ClassLevel $level, string $label): ClassLevelArm
{
    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => $label])->id,
    ]);
}

function sr_curriculum(School $school, ClassLevelArm $arm, ExamType $examType, Term $term): Curriculum
{
    $curriculum = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    // A compulsory subject so the service's additive auto-attach actually runs, rather than the
    // move being proved against a curriculum that requires nothing.
    CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => Subject::create(['school_id' => $school->id, 'name' => 'Subj '.Str::random(5)])->id,
        'is_compulsory' => true,
    ]);

    return $curriculum;
}

function sr_school(string $name): array
{
    $school = al_makeSchool();
    $admin = sr_admin($school);

    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'slug' => 'as-'.Str::random(8),
    ]);
    $term = Term::create([
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'name' => 'First Term',
        'slug' => 'tm-'.Str::random(8),
        'order' => 1,
        // Both NOT NULL without defaults; the dates are irrelevant to reassignment but the row will
        // not insert without them.
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
    ]);
    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal',
        'slug' => 'et-'.Str::random(8),
    ]);

    $y8 = ClassLevel::forceCreate(['school_id' => $school->id, 'name' => 'Year 8', 'order' => 8]);
    $y9 = ClassLevel::forceCreate(['school_id' => $school->id, 'name' => 'Year 9', 'order' => 9]);

    $c8B = sr_curriculum($school, sr_arm($school, $y8, 'B'), $examType, $term);
    $c8S = sr_curriculum($school, sr_arm($school, $y8, 'S'), $examType, $term);
    // SAME school, same term, same exam type — and a different YEAR GROUP. This is the row that
    // isolates the sibling rule: no school guard can refuse it.
    $c9B = sr_curriculum($school, sr_arm($school, $y9, 'B'), $examType, $term);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Pupil',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    $episode = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $c8B->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);

    return compact('school', 'admin', 'session', 'term', 'examType', 'y8', 'y9', 'c8B', 'c8S', 'c9B', 'student', 'episode');
}

function sr_world(): array
{
    return sr_school('primary');
}

function sr_post(array $w, string $destinationUuid, ?StudentCurriculum $episode = null)
{
    $episode ??= $w['episode'];

    return test()->actingAs($w['admin'])
        ->postJson("/api/student-curricula/{$episode->uuid}/reassign", [
            'destination_curriculum_id' => $destinationUuid,
        ]);
}

function sr_options(array $w, ?StudentCurriculum $episode = null)
{
    $episode ??= $w['episode'];

    return test()->actingAs($w['admin'])
        ->getJson("/api/student-curricula/{$episode->uuid}/reassignment-options");
}

// ---------------------------------------------------------------------------
// 1. The billing transition — the highest-value assertion in this file
// ---------------------------------------------------------------------------

/**
 * BILLABILITY MUST FLIP AT ALL FOUR POINTS, and the fixture is built so that none of the four is
 * true by accident.
 *
 * The naive version of this test creates the destination episode DURING the move, which makes
 * "destination not billable before" trivially true — it did not exist. So the pupil here has been in
 * 8S before: that episode exists throughout, soft-ended and TRANSFERRED, and is revived by the move.
 * Every one of the four points is therefore a real decision the adapter makes about a row that is
 * present on both sides of the reassignment, and the only thing that changed is its status.
 *
 * This is what pins BillableEnrollmentAdapter::billableEpisodes()'s ACTIVE allowlist. An adapter
 * that billed every episode, or none, passes an endpoint-only test and fails this one.
 */
it('moves billability from the vacated episode to the destination, and only on status', function () {
    $w = sr_world();

    // The pupil's earlier stint in 8S: ended, TRANSFERRED, still on the books.
    $priorInS = StudentCurriculum::create([
        'student_id' => $w['student']->id,
        'curriculum_id' => $w['c8S']->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);
    $priorInS->update([
        'status' => StudentStatusEnum::TRANSFERRED,
        'ended_at' => now(),
    ]);

    // ── THE ORDERING IS WHAT MAKES THIS FIXTURE DISCRIMINATE, SO IT IS PINNED ─────────────────────
    // BillableEnrollmentAdapter admits one episode per pupil via `MAX(id) ... WHERE status = ACTIVE`.
    // The ENDED destination row must hold the HIGHER id, so that a status-blind MAX(id) would select
    // IT — which is precisely what makes "destination not billable before" a real decision about the
    // status filter rather than a coincidence of row order.
    //
    // Without this assertion the fixture proves the ordering but never states it: renumber the seed,
    // or change a factory so insertion order flips, and the live episode becomes the higher id. A
    // status-blind MAX would then pick the LIVE row, "not billable before" would pass for the wrong
    // reason, and this test would quietly stop testing what it was built for — with nothing going red
    // to say so. Measured 2026-08-22: ended = 2, live = 1.
    expect($priorInS->id)->toBeGreaterThan($w['episode']->id);

    $billableUuids = function () use ($w): array {
        return ActiveSchool::runFor($w['school']->id, fn () => array_map(
            fn ($enrollment) => $enrollment->enrollmentUuid,
            app(BillableEnrollmentAdapter::class)->listForCohort(
                (int) $w['school']->id,
                (int) $w['term']->id,
                (int) $w['y8']->id,
            )
        ));
    };

    $before = $billableUuids();

    // POINTS 1 and 3 — the live 8B episode is billed; the ended 8S episode is not.
    expect($before)->toContain($w['episode']->uuid)
        ->and($before)->not->toContain($priorInS->uuid);

    sr_post($w, $w['c8S']->uuid)->assertOk();

    $after = $billableUuids();

    // POINTS 2 and 4 — and they are the SAME two rows, read the same way, after one request.
    expect($after)->not->toContain($w['episode']->uuid)
        ->and($after)->toContain($priorInS->uuid);

    // The revive is what made point 4 true — same row, not a new one.
    expect(StudentCurriculum::where('student_id', $w['student']->id)
        ->where('curriculum_id', $w['c8S']->id)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Eligibility — each guard isolated where it acts ALONE
// ---------------------------------------------------------------------------

it('accepts a true sibling arm in the same year group and term', function () {
    $w = sr_world();

    sr_post($w, $w['c8S']->uuid)
        ->assertOk()
        ->assertJsonPath('episode.curriculum', 'Year 8 S');

    expect($w['episode']->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);
});

/**
 * THE SIBLING RULE, ISOLATED. Year 9 B is in the SAME school, term and exam type — so no school
 * guard, no scope and no foreign key can refuse it. If this 422 ever comes from somewhere other than
 * the sibling rule, deleting the sibling rule will show it: the mutation check below is the whole
 * point of using a same-school row here rather than reusing the foreign-school one.
 */
it('refuses a same-school class in a different year group', function () {
    $w = sr_world();

    sr_post($w, $w['c9B']->uuid)
        ->assertStatus(422)
        ->assertJsonValidationErrors('destination_curriculum_id');

    expect($w['episode']->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
});

it('refuses a curriculum belonging to another school, without naming it', function () {
    $w = sr_world();
    $other = sr_school('other');

    $response = sr_post($w, $other['c8S']->uuid)->assertStatus(422);

    expect($response->json('errors.destination_curriculum_id.0'))
        ->toContain('not found in this school');

    expect($w['episode']->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
});

/**
 * THE ISOLATION GUARD THAT ACTUALLY REFUSES, ISOLATED.
 *
 * The uuid RESOLUTION in ReassignStudentRequest is the only cross-school guard on this path that can
 * be tested alone, and the message is what proves which guard fired: with the SchoolScope removed
 * from that lookup the foreign curriculum resolves, falls through to the sibling rule, and the
 * response says "not an alternative arm" instead of "not found in this school". So asserting the
 * SENTENCE — not merely the 422 — is what makes this a test of isolation rather than of any refusal
 * at all. A bare `assertStatus(422)` here would stay green with the scope gone.
 */
it('cannot resolve another school’s curriculum by uuid at all', function () {
    $w = sr_world();
    $other = sr_school('other');

    $resolved = ActiveSchool::runFor(
        $w['school']->id,
        fn () => Curriculum::query()->where('uuid', $other['c8S']->uuid)->first()
    );

    expect($resolved)->toBeNull();

    // And the row is really there to be found — otherwise this passes for the wrong reason.
    expect(Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('uuid', $other['c8S']->uuid)->exists())->toBeTrue();
});

/**
 * The sibling query does not leak across schools either — BUT SAY WHY HONESTLY.
 *
 * This is NOT carried by the school clause in CohortSiblings. Each school has its own class_levels
 * row, so the class-level match alone already excludes every foreign curriculum: removing the school
 * clause, the global scope, or both leaves this test green (confirmed by mutation, and recorded in
 * CohortSiblings' own docblock). What this test pins is the OUTCOME — that a reassignment can never
 * be offered a foreign class — not the mechanism that delivers it.
 */
it('never returns another school’s curricula from the sibling query', function () {
    $w = sr_world();
    $other = sr_school('other');

    $siblings = ActiveSchool::runFor(
        $w['school']->id,
        fn () => CohortSiblings::for($w['episode'])->pluck('id')->all()
    );

    expect($siblings)->toContain($w['c8S']->id)
        ->and($siblings)->not->toContain($other['c8S']->id);

    // And the other school's cohort really is identically shaped — otherwise the assertion above
    // would pass for the wrong reason (nothing there to find).
    $mirrored = ActiveSchool::runFor(
        $other['school']->id,
        fn () => CohortSiblings::for($other['episode'])->pluck('id')->all()
    );

    expect($mirrored)->toContain($other['c8S']->id);
});

it('refuses the class the pupil is already in, with its own message', function () {
    $w = sr_world();

    $response = sr_post($w, $w['c8B']->uuid)->assertStatus(422);

    // A DISTINCT message, not the sibling one — the pupil's own class is excluded from the sibling
    // set, so a shared message would tell them it is "not in the same year group", which is false.
    expect($response->json('errors.destination_curriculum_id.0'))
        ->toContain('already in');
});

it('refuses a closed class, which the picker never offers', function () {
    $w = sr_world();
    $w['c8S']->update(['status' => 'closed']);

    sr_post($w, $w['c8S']->uuid)->assertStatus(422);

    expect(sr_options($w)->json('destinations'))->toBe([]);
});

it('offers exactly the sibling arms, and never the pupil’s own class', function () {
    $w = sr_world();

    $labels = array_column(sr_options($w)->assertOk()->json('destinations'), 'label');

    expect($labels)->toBe(['Year 8 S']);
});

/**
 * A TERM-LESS COHORT STILL HAS SIBLINGS.
 *
 * `curricula.term_id` and `exam_type_id` are both nullable, and `WHERE term_id = NULL` is never true
 * in SQL — so the obvious `where('term_id', $value)` returns NOTHING here and the operator is told
 * their pupil has nowhere to go. It fails in the safe direction, which is exactly why it would have
 * survived: no bad move is possible, the screen is just silently empty.
 *
 * The shape is real, not contrived — CurriculumReassignmentServiceTest's own fixture builds its
 * curricula with `term_id => null`.
 */
it('offers siblings for a curriculum with no term or exam type', function () {
    $w = sr_world();

    Curriculum::whereIn('id', [$w['c8B']->id, $w['c8S']->id])
        ->update(['term_id' => null, 'exam_type_id' => null]);

    $labels = array_column(sr_options($w)->assertOk()->json('destinations'), 'label');

    expect($labels)->toBe(['Year 8 S']);

    sr_post($w, $w['c8S']->uuid)->assertOk();
});

// ---------------------------------------------------------------------------
// 3. Back a level — a promotion UNDONE, through the controller
// ---------------------------------------------------------------------------

/**
 * The over-promoted pupil sent back into the very episode they were promoted OUT of.
 *
 * A plain repoint would set that row's promoted_to_id to its own id, and NOTHING would reject it —
 * the composite FK is satisfied and the trigger only guards `promoted` rows, which the revive has
 * already made `active`. The service reads it as the promotion being undone and clears the link. The
 * service test proves that directly; this proves the controller reaches it, since the sibling rule
 * sits in between and a careless guard would have refused the move as "not a sibling".
 */
it('clears the promotion link when the pupil is sent back into their referring episode', function () {
    $w = sr_world();

    // The referrer: the pupil's 8S episode, promoted INTO their current 8B one.
    $referrer = StudentCurriculum::create([
        'student_id' => $w['student']->id,
        'curriculum_id' => $w['c8S']->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]);
    $referrer->update([
        'status' => StudentStatusEnum::PROMOTED,
        'promoted_to_id' => $w['episode']->id,
    ]);

    expect($referrer->fresh()->promoted_to_id)->toBe($w['episode']->id);

    sr_post($w, $w['c8S']->uuid)->assertOk();

    $after = $referrer->fresh();

    expect($after->promoted_to_id)->toBeNull()
        ->and($after->promoted_to_id)->not->toBe($after->id);
});

// ---------------------------------------------------------------------------
// 4. Re-submitting the same move
// ---------------------------------------------------------------------------

/**
 * A double-submit — the operator clicks twice, or refreshes and retries.
 *
 * The second request names an episode that the FIRST one ended, so it is refused as a sentence
 * rather than silently no-opped into a success toast. What must never happen is a second
 * `student_curricula` row for the same (student, curriculum): that pair is UNIQUE, and reaching it
 * would surface as a driver error rather than a field message.
 */
it('refuses a repeated reassignment of an episode that has already been vacated', function () {
    $w = sr_world();

    sr_post($w, $w['c8S']->uuid)->assertOk();

    sr_post($w, $w['c8S']->uuid)
        ->assertStatus(422)
        ->assertJsonValidationErrors('destination_curriculum_id');

    expect(StudentCurriculum::where('student_id', $w['student']->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// 5. The word
// ---------------------------------------------------------------------------

/**
 * ONE VERB, AND THE COLLISION STAYS RESOLVED AT THE DISPLAY LAYER.
 *
 * `transferred` is stored on the episode and spoken as "Reassigned". `students.status` keeps its own
 * TRANSFERRED, meaning a pupil who actually left the school. This pins both halves: a future
 * "unification" that renames either enum, or adds a membership label of its own, fails here.
 */
it('speaks of the vacated episode as Reassigned while membership keeps Transferred', function () {
    $w = sr_world();

    $response = sr_post($w, $w['c8S']->uuid)->assertOk();

    expect($response->json('audit_line'))->toBe('Reassigned from Year 8 B to Year 8 S');

    $vacated = sr_options($w, $w['episode']->fresh())->assertOk();

    expect($vacated->json('episode.status'))->toBe('transferred')
        ->and($vacated->json('episode.status_label'))->toBe('Reassigned');

    // The stored value is untouched; only the word moved.
    expect(StudentStatusEnum::TRANSFERRED->value)->toBe('transferred')
        ->and(StudentStatusEnum::TRANSFERRED->displayLabel())->toBe('Reassigned');

    // Membership is a DIFFERENT enum on a DIFFERENT column and is deliberately not renamed — it has
    // no display label of its own, so nothing here can change what a withdrawn-to-another-school
    // pupil reads as.
    expect(StudentMembershipStatus::TRANSFERRED->value)->toBe('transferred')
        ->and(method_exists(StudentMembershipStatus::class, 'displayLabel'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('refuses an operator without academic_setup.manage', function () {
    $w = sr_world();
    $plain = al_makeUser($w['school']->id);

    test()->actingAs($plain)
        ->postJson("/api/student-curricula/{$w['episode']->uuid}/reassign", [
            'destination_curriculum_id' => $w['c8S']->uuid,
        ])
        ->assertForbidden();

    expect($w['episode']->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
});

it('cannot reach another school’s episode through the route binding', function () {
    $w = sr_world();
    $other = sr_school('other');

    test()->actingAs($w['admin'])
        ->postJson("/api/student-curricula/{$other['episode']->uuid}/reassign", [
            'destination_curriculum_id' => $w['c8S']->uuid,
        ])
        ->assertNotFound();

    // Read UNSCOPED — the point is that the other school's row is untouched, and the scope that
    // produced the 404 above would otherwise hide it from this assertion too, making the check pass
    // by finding nothing. `value('status')` returns the CAST enum, not the raw string.
    expect(
        StudentCurriculum::withoutGlobalScope(SchoolScope::class)
            ->whereKey($other['episode']->id)
            ->value('status')
    )->toBe(StudentStatusEnum::ACTIVE);
});
