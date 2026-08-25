<?php

use App\Enums\StudentStatusEnum;
use App\Jobs\MoveToNextYearJob;
use App\Models\Arm;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Services\Rollover\RolloverPlanner;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * THE PREVIEW MUST NAME THE PLACE THE COMMIT ACTUALLY PUTS THE PUPIL.
 *
 * The last-term defect is the reason this file exists: the planner selected on
 * (school, term, status) alone, promised "12 classes, 340 pupils would move", twelve jobs no-opped,
 * and the batch reported 12/12 succeeded. Plan and commit agreed while both were wrong, so
 * count-honesty could not see it. The remedy was one resolution called by both, and these are the
 * tests that hold that remedy in place.
 */

/**
 * Year 7 -> Year 8, with the target level configured but its CURRICULUM ABSENT.
 *
 * The absence is the point. Read-only and write mode share arm and exam-type resolution, so wherever
 * the destination already exists the two are running identical code and parity there is very nearly
 * tautological. The ONLY place they diverge is the create path. A fixture of existing destinations
 * would prove parity everywhere except the one spot that can break.
 */
function rpp_world(int $pupils = 3): array
{
    $w = rc_world();

    $sourceTerm = rc_term($w['source'], 1);
    $targetTerm = rc_term($w['target'], 1);

    [$y7, $arm7] = rc_level($w['school'], 'Year 7', 7, [1]);
    [$y8, $arm8] = rc_level($w['school'], 'Year 8', 8, [1], ['default_exam_type_id' => $w['examType']->id]);
    $y7->update(['next_class_level_id' => $y8->id]);

    $curriculum = rc_curriculum($w['school'], $arm7, $sourceTerm, $w['examType']);

    // GUARDED, because `range(1, 0)` is DESCENDING in PHP and returns [1, 0] — two elements. An
    // unguarded range() here silently planted two pupils in every world that asked for none, and the
    // tests that build their own roster then measured mine as well as theirs.
    $episodes = collect($pupils < 1 ? [] : range(1, $pupils))->map(fn (int $i) => StudentCurriculum::create([
        'student_id' => Student::create([
            'school_id' => $w['school']->id,
            'first_name' => 'Pupil'.$i,
            'last_name' => Str::random(6),
            'gender' => 'female',
            'admission_number' => 'ADM-'.Str::random(8),
        ])->id,
        'curriculum_id' => $curriculum->id,
        'status' => StudentStatusEnum::ACTIVE,
    ]));

    rollover_grant($w['admin'], $w['school']);

    return $w + compact('sourceTerm', 'targetTerm', 'y7', 'y8', 'arm7', 'arm8', 'curriculum', 'episodes');
}

function rpp_plan(array $w)
{
    return ActiveSchool::runFor(
        $w['school']->id,
        fn () => app(RolloverPlanner::class)->planEndOfYear($w['source'], $w['target']),
    );
}

/** Where a pupil ACTUALLY sits now, read from the database as "Year 8 B". */
function rpp_landing(int $studentId): ?string
{
    $episode = StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $studentId)
        ->where('status', StudentStatusEnum::ACTIVE->value)
        ->orderByDesc('id')
        ->first();

    $arm = $episode?->curriculum?->classLevelArm;

    return $arm === null ? null : trim($arm->classLevel?->name.' '.$arm->arm?->label);
}

// ---------------------------------------------------------------------------
// PARITY — the load-bearing one, and it runs THROUGH the create path
// ---------------------------------------------------------------------------

it('lands every pupil in the class the preview named, including where the destination did not exist', function () {
    $w = rpp_world();

    $plan = rpp_plan($w);

    // The destination is absent, so this is the create path — the one place the modes differ.
    expect($plan->placement->advancers)->toHaveCount(1)
        ->and($plan->placement->advancers->first()->destinationIsUnconfigured())->toBeTrue()
        ->and($plan->placement->unconfiguredCount())->toBe(1);

    // WHAT THE PREVIEW PROMISED, per pupil, before anything is written.
    $promised = [];
    foreach ($plan->placement->advancers as $group) {
        foreach ($group->pupils as $pupil) {
            $promised[$pupil['id']] = $group->destinationLabel;
        }
    }

    expect($promised)->toHaveCount(3);

    ActiveSchool::runFor($w['school']->id, fn () => (new MoveToNextYearJob(
        $w['curriculum'], $w['target'], (int) $w['admin']->id, (int) $w['school']->id,
    ))->handle());

    // WHERE THEY ACTUALLY WENT, read from the database rather than from the resolver — asserting
    // through the same code that produced the promise would only prove it agrees with itself.
    foreach ($promised as $studentId => $label) {
        expect(rpp_landing((int) $studentId))->toBe($label);
    }
});

/**
 * PARITY WHERE DISTRIBUTION ACTUALLY DECIDES THE ARM.
 *
 * The parity test above runs through the create path but its target level has ONE arm, so every
 * placement rule agrees trivially — a preview that chose arms by a completely different rule would
 * still land everyone in the same single arm. Caught by mutation: making the preview path pick
 * $arms[0] while the write path kept the modulo left that test GREEN.
 *
 * This one gives the target two arms and a source label that matches neither, so the modulo is the
 * only thing deciding, and preview and commit must agree pupil by pupil.
 */
it('agrees with the commit about WHICH ARM when distribution is what decides it', function () {
    $w = rpp_world(0);

    $w['arm7']->update([
        'arm_id' => Arm::firstOrCreate(['school_id' => $w['school']->id, 'label' => 'Z'])->id,
    ]);

    ClassLevelArm::forceCreate([
        'school_id' => $w['school']->id,
        'class_level_id' => $w['y8']->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $w['school']->id, 'label' => 'S'])->id,
    ]);

    foreach (range(1, 4) as $i) {
        StudentCurriculum::create([
            'student_id' => Student::create([
                'school_id' => $w['school']->id,
                'first_name' => 'Dist'.$i,
                'last_name' => Str::random(6),
                'gender' => 'female',
                'admission_number' => 'ADM-'.Str::random(8),
            ])->id,
            'curriculum_id' => $w['curriculum']->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);
    }

    $promised = [];
    foreach (rpp_plan($w)->placement->advancers as $group) {
        foreach ($group->pupils as $pupil) {
            $promised[$pupil['id']] = $group->destinationLabel;
        }
    }

    // THE FIXTURE'S PREMISE: distribution really did split them. If every pupil landed in one arm
    // the comparison below would hold for a preview that ignored the pupil entirely.
    expect($promised)->toHaveCount(4)
        ->and(array_unique(array_values($promised)))->toHaveCount(2);

    ActiveSchool::runFor($w['school']->id, fn () => (new MoveToNextYearJob(
        $w['curriculum'], $w['target'], (int) $w['admin']->id, (int) $w['school']->id,
    ))->handle());

    foreach ($promised as $studentId => $label) {
        expect(rpp_landing((int) $studentId))->toBe($label);
    }
});

// ---------------------------------------------------------------------------
// THE PREVIEW WRITES NOTHING — the whole safety claim of read-only mode
// ---------------------------------------------------------------------------

it('creates no curriculum and no episode when previewing', function () {
    $w = rpp_world();

    $before = [
        'curricula' => Curriculum::withoutGlobalScopes()->count(),
        'episodes' => StudentCurriculum::withoutGlobalScopes()->count(),
    ];

    rpp_plan($w);
    // TWICE. A preview that wrote on the first call would report the destination as configured on
    // the second, so the flag this screen exists to raise would vanish after one look.
    $second = rpp_plan($w);

    expect(Curriculum::withoutGlobalScopes()->count())->toBe($before['curricula'])
        ->and(StudentCurriculum::withoutGlobalScopes()->count())->toBe($before['episodes'])
        ->and($second->placement->unconfiguredCount())->toBe(1);
});

// ---------------------------------------------------------------------------
// PLACEMENT IS A PURE FUNCTION — what makes an EXACT preview possible at all
// ---------------------------------------------------------------------------

it('places a pupil the same way every time it is asked', function () {
    $w = rpp_world();

    $first = rpp_plan($w)->placement->advancers->first();
    $second = rpp_plan($w)->placement->advancers->first();

    expect(array_column($second->pupils, 'id'))->toBe(array_column($first->pupils, 'id'))
        ->and($second->destinationKey)->toBe($first->destinationKey);
});

it('distributes by student id over the arms in id order, so two pupils an armCount apart share an arm', function () {
    $w = rpp_world(0);

    // ── THE SOURCE ARM MUST NOT LABEL-MATCH, OR THIS TESTS THE WRONG RULE ────────────────────────
    // Arm resolution is: explicit map, then STREAM-AWARE LABEL MATCH, then distribution. rc_level
    // labels every arm 'B', so a source 'B' matches the target's 'B' and returns before the modulo
    // is ever evaluated. The first version of this test did exactly that — it exercised label match,
    // called itself a distribution test, and agreed with the expected arm only because arms[0] IS
    // the label-matched one. It stayed green under an orderByDesc mutation, which is how it was
    // caught.
    $w['arm7']->update([
        'arm_id' => Arm::firstOrCreate(['school_id' => $w['school']->id, 'label' => 'Z'])->id,
    ]);

    // A SECOND arm on the target level, so the modulo has something to choose between.
    ClassLevelArm::forceCreate([
        'school_id' => $w['school']->id,
        'class_level_id' => $w['y8']->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $w['school']->id, 'label' => 'S'])->id,
    ]);

    $armCount = 2;
    $ids = [];

    // THREE pupils, and the assertion uses the FIRST and THIRD. Created back to back their ids
    // differ by one, so a two-pupil fixture would be ONE apart, not armCount apart — the modulo
    // would send them to different arms and the test would assert the opposite of what it means.
    for ($i = 0; $i < 3; $i++) {
        $student = Student::create([
            'school_id' => $w['school']->id,
            'first_name' => 'Mod'.$i,
            'last_name' => Str::random(6),
            'gender' => 'male',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $w['curriculum']->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);
        $ids[] = (int) $student->id;
    }

    // All three are kept: consecutive ids cover BOTH residues, and a single residue would leave the
    // arm ordering unpinned — index 0 alone is the same arm whichever way the list is sorted only
    // when you never look at index 1.
    $allIds = $ids;
    $ids = [$ids[0], $ids[2]];

    // The fixture's own premise: the two ids really are armCount apart. Without this the assertion
    // below could pass because both happened to land in the same arm for an unrelated reason.
    expect($ids[1] - $ids[0])->toBe($armCount)
        ->and(ClassLevelArm::withoutGlobalScopes()->where('class_level_id', $w['y8']->id)->count())->toBe($armCount)
        // AND the source arm genuinely does not label-match anything in the target level, which is
        // what forces resolution past the label rule and into distribution.
        ->and(ClassLevelArm::withoutGlobalScopes()->with('arm')
            ->where('class_level_id', $w['y8']->id)->get()
            ->pluck('arm.label')->all())->not->toContain('Z');

    $groups = rpp_plan($w)->placement->advancers;

    $destinationOf = [];
    foreach ($groups as $group) {
        foreach ($group->pupils as $pupil) {
            $destinationOf[$pupil['id']] = $group->destinationLabel;
        }
    }

    // (a) SAME MODULO, SAME ARM.
    expect($destinationOf[$ids[0]])->toBe($destinationOf[$ids[1]]);

    // (b) AND THE ORDER IS ASCENDING BY ID, which (a) alone does NOT prove: two pupils an armCount
    // apart co-locate under ANY arm ordering, so a test stopping at (a) is green against a resolver
    // that iterates the arms backwards. Caught by mutation — flipping orderBy('id') to
    // orderByDesc('id') left (a) green and only reddened MoveToNextYearJobTest, which meant this
    // test was proving the adjacent property rather than the one its name claims.
    //
    // The expectation is built from a query with an EXPLICIT ascending order, so it is independent
    // of the resolver's own ordering rather than a restatement of it.
    $armsAscending = ClassLevelArm::withoutGlobalScopes()
        ->with('arm')
        ->where('class_level_id', $w['y8']->id)
        ->orderBy('id')
        ->get()
        ->values();

    $seen = [];

    foreach ($allIds as $studentId) {
        $expected = $armsAscending[$studentId % $armCount];
        expect($destinationOf[$studentId])->toBe('Year 8 '.$expected->arm->label);
        $seen[$studentId % $armCount] = true;
    }

    // BOTH residues were actually exercised. Without this the loop could assert only index 0 and the
    // ordering would stay unpinned while the test read as though it covered it.
    expect(array_keys($seen))->toHaveCount($armCount);
});

// ---------------------------------------------------------------------------
// THE READINESS FLAG IS HONEST — asserted for a REPEATER, not only an advancer
// ---------------------------------------------------------------------------

it('flags a repeaters own-level destination as unconfigured, and stops once it exists', function () {
    $w = rpp_world(0);

    // Year 7 participates in the target session's slot 1 too, so a held repeater has somewhere to go.
    $student = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Held',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $w['curriculum']->id,
        'status' => StudentStatusEnum::REPEATED,
    ]);

    $plan = rpp_plan($w);

    // A repeater is HELD, never advanced — and the marker is on their group too. Without it a
    // registrar sees advancer destinations flagged and repeater ones silently unflagged, which
    // under-warns for exactly the pupils least likely to be looked at twice.
    expect($plan->placement->repeaters)->toHaveCount(1)
        ->and($plan->placement->advancers)->toHaveCount(0)
        ->and($plan->placement->repeaters->first()->destinationIsUnconfigured())->toBeTrue()
        ->and($plan->placement->repeaters->first()->destinationLabel)->toBe('Year 7 B')
        ->and($plan->placement->unconfiguredKeys())->toHaveCount(1);

    // Now CREATE the repeater's destination and re-preview. The flag must clear and name the id —
    // the same assertion in the other direction, so a flag stuck permanently on cannot pass.
    $target = rc_curriculum($w['school'], $w['arm7'], $w['targetTerm'], $w['examType']);

    $after = rpp_plan($w);

    expect($after->placement->repeaters->first()->destinationIsUnconfigured())->toBeFalse()
        ->and($after->placement->repeaters->first()->destinationCurriculumId)->toBe((int) $target->id)
        ->and($after->placement->unconfiguredCount())->toBe(0);
});

// ---------------------------------------------------------------------------
// THE SKIP ORDER IS THE JOB'S
// ---------------------------------------------------------------------------

it('omits withdrawn and already-promoted pupils, as the job does', function () {
    $w = rpp_world(0);

    foreach ([StudentStatusEnum::WITHDRAWN, StudentStatusEnum::ACTIVE] as $status) {
        $student = Student::create([
            'school_id' => $w['school']->id,
            'first_name' => 'S'.$status->value,
            'last_name' => Str::random(6),
            'gender' => 'male',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $w['curriculum']->id,
            'status' => $status,
        ]);
    }

    // One withdrawn, one active: only the active pupil is previewed as moving.
    expect(rpp_plan($w)->placement->advancers->first()->pupilCount())->toBe(1);

    // Now promote the active one and re-preview. An already-promoted pupil is skipped wherever they
    // now sit — this is what stops a re-run's preview double-counting a cohort that has gone.
    ActiveSchool::runFor($w['school']->id, fn () => (new MoveToNextYearJob(
        $w['curriculum'], $w['target'], (int) $w['admin']->id, (int) $w['school']->id,
    ))->handle());

    expect(rpp_plan($w)->placement->advancers)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// THE TOCTOU PRE-CHECK — the acknowledgment is BINDING, not decorative
// ---------------------------------------------------------------------------

/**
 * THREE source levels, so the acknowledged set can be changed in both directions at once.
 *
 * Year 7 -> Year 8 and Year 9 -> Year 10 have NO destination curriculum (unconfigured at preview).
 * Year 11 -> Year 12 HAS one (configured at preview), which is the destination the swap case later
 * deletes. Without a third level there is nothing that can BECOME unconfigured, and the swap — the
 * one case a count cannot see — could not be constructed at all.
 */
function rpp_toctou_world(): array
{
    $w = rc_world();

    $sourceTerm = rc_term($w['source'], 1);
    $targetTerm = rc_term($w['target'], 1);

    $levels = [];
    foreach ([[7, 8], [9, 10], [11, 12]] as [$from, $to]) {
        [$src, $srcArm] = rc_level($w['school'], "Year {$from}", $from, [1]);
        [$dst, $dstArm] = rc_level($w['school'], "Year {$to}", $to, [1], ['default_exam_type_id' => $w['examType']->id]);
        $src->update(['next_class_level_id' => $dst->id]);

        $curriculum = rc_curriculum($w['school'], $srcArm, $sourceTerm, $w['examType']);

        StudentCurriculum::create([
            'student_id' => Student::create([
                'school_id' => $w['school']->id,
                'first_name' => "Y{$from}",
                'last_name' => Str::random(6),
                'gender' => 'male',
                'admission_number' => 'ADM-'.Str::random(8),
            ])->id,
            'curriculum_id' => $curriculum->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);

        $levels[$from] = ['src' => $src, 'dst' => $dst, 'dstArm' => $dstArm, 'curriculum' => $curriculum];
    }

    // Year 12's destination EXISTS at preview time — the one that can later be removed.
    $levels[11]['dstCurriculum'] = rc_curriculum($w['school'], $levels[11]['dstArm'], $targetTerm, $w['examType']);

    rollover_grant($w['admin'], $w['school']);

    return $w + compact('sourceTerm', 'targetTerm', 'levels');
}

function rpp_commit(array $w, array $acknowledged)
{
    return test()->actingAs($w['admin'])->postJson('/api/rollover/end-of-year', [
        'source_session_id' => $w['source']->uuid,
        'target_session_id' => $w['target']->uuid,
        'acknowledged_unconfigured' => $acknowledged,
    ]);
}

it('commits when the acknowledged set matches', function () {
    Bus::fake();
    $w = rpp_toctou_world();

    $acknowledged = rpp_plan($w)->placement->unconfiguredKeys();
    expect($acknowledged)->toHaveCount(2);

    rpp_commit($w, $acknowledged)->assertOk();
    Bus::assertBatchCount(1);
});

it('commits when a destination was configured since the preview — a removal is less risk, not more', function () {
    Bus::fake();
    $w = rpp_toctou_world();

    $acknowledged = rpp_plan($w)->placement->unconfiguredKeys();

    // Year 8's destination now exists, so the fresh set is a strict SUBSET of what was acknowledged.
    rc_curriculum($w['school'], $w['levels'][7]['dstArm'], $w['targetTerm'], $w['examType']);

    expect(rpp_plan($w)->placement->unconfiguredKeys())->toHaveCount(1);

    // Proceeds. Refusing here would refuse an operator for FIXING the very thing they were warned
    // about, which teaches people to stop fixing it.
    rpp_commit($w, $acknowledged)->assertOk();
    Bus::assertBatchCount(1);
});

it('refuses when a destination became unconfigured after the preview, and dispatches nothing', function () {
    Bus::fake();
    $w = rpp_toctou_world();

    $acknowledged = rpp_plan($w)->placement->unconfiguredKeys();
    expect($acknowledged)->toHaveCount(2);

    // Year 12's curriculum is removed AFTER the operator looked. They never accepted this one.
    Curriculum::withoutGlobalScopes()->whereKey($w['levels'][11]['dstCurriculum']->id)->delete();

    expect(rpp_plan($w)->placement->unconfiguredKeys())->toHaveCount(3);

    $response = rpp_commit($w, $acknowledged)->assertStatus(422);

    expect($response->json('unacknowledged_destinations'))->toHaveCount(1);
    Bus::assertNothingBatched();
});

/**
 * THE SWAP — the only arm that tells a subset check apart from a count check.
 *
 * One destination is configured and another is deleted between preview and confirm, so the COUNT IS
 * UNCHANGED at two. An implementation comparing numbers passes every other arm in this file and
 * still lets a destination the operator never saw take pupils subject-less.
 */
it('refuses a SWAP, where one destination was configured and another deleted and the count never moved', function () {
    Bus::fake();
    $w = rpp_toctou_world();

    $acknowledged = rpp_plan($w)->placement->unconfiguredKeys();
    expect($acknowledged)->toHaveCount(2);

    rc_curriculum($w['school'], $w['levels'][7]['dstArm'], $w['targetTerm'], $w['examType']);
    Curriculum::withoutGlobalScopes()->whereKey($w['levels'][11]['dstCurriculum']->id)->delete();

    $fresh = rpp_plan($w)->placement->unconfiguredKeys();

    // THE PREMISE, ASSERTED: the count really did not move. Without this the arm could pass under a
    // count check for the boring reason that the numbers happened to differ.
    expect($fresh)->toHaveCount(count($acknowledged))
        ->and($fresh)->not->toBe($acknowledged);

    $response = rpp_commit($w, $acknowledged)->assertStatus(422);

    expect($response->json('unacknowledged_destinations'))->toHaveCount(1);
    Bus::assertNothingBatched();
});

it('treats a missing acknowledgment as the empty set — safe while nothing is unconfigured, refused once something is', function () {
    Bus::fake();
    $w = rpp_toctou_world();

    // An older client sending no acknowledgment at all is refused, because something IS unconfigured.
    test()->actingAs($w['admin'])->postJson('/api/rollover/end-of-year', [
        'source_session_id' => $w['source']->uuid,
        'target_session_id' => $w['target']->uuid,
    ])->assertStatus(422);

    Bus::assertNothingBatched();
});
