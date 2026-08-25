<?php

use App\Academics\BillableEnrollmentAdapter;
use App\Enums\StudentStatusEnum;
use App\Http\Requests\BulkReassignStudentsRequest;
use App\Models\Activity;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Services\CurriculumReassignmentService;
use App\Services\StudentSubjectService;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture — reuses M3's sr_* world (one Year 8 cohort with arms B and S, a
// Year 9 class as the same-school non-sibling, and a second school for
// isolation), and adds the pupils a BATCH needs.
// ---------------------------------------------------------------------------

/**
 * Place N additional pupils into a curriculum and return their episodes.
 *
 * @return array<int, StudentCurriculum>
 */
function bsr_pupils(array $w, Curriculum $curriculum, int $count): array
{
    $episodes = [];

    for ($i = 0; $i < $count; $i++) {
        $student = Student::create([
            'school_id' => $w['school']->id,
            'first_name' => 'Batch'.$i,
            'last_name' => Str::random(6),
            'gender' => 'female',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);

        $episodes[] = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);
    }

    return $episodes;
}

/**
 * @param  array<int, StudentCurriculum>  $episodes
 */
function bsr_post(array $w, array $episodes, string $destinationUuid, array $overrides = [])
{
    return test()->actingAs($w['admin'])
        ->postJson('/api/students/bulk-reassign', array_merge([
            'episode_ids' => array_map(fn (StudentCurriculum $e) => $e->uuid, $episodes),
            'destination_curriculum_id' => $destinationUuid,
        ], $overrides));
}

// ---------------------------------------------------------------------------
// 1. THE COHORT LOCK — the most important test in this file
// ---------------------------------------------------------------------------

/**
 * CROSS-EXAM-TYPE IS NOW ALLOWED, AND THIS ARM IS WHAT PINS IT OUT OF THE KEY.
 *
 * This test previously asserted the OPPOSITE — two pupils sharing a class label and differing on
 * exam type were REFUSED — and it was a good test of a rule that has since been decided against.
 * Exam type left the eligibility key deliberately: promotion places pupils provisionally
 * (MoveToNextYearJob carries the source exam type, or the level default), so Year 10 B/WAEC ->
 * Year 10 S/BSS is the ordinary correction, and a rule forbidding it left the operator holding a
 * misplaced pupil on the screen built to fix misplacements.
 *
 * INVERTED RATHER THAN DELETED, because this is now the mutation guard: re-add `exam_type_id` to
 * CohortSiblings and this goes red. That is what keeps the removal deliberate instead of drifting
 * back in as a "restored" match.
 *
 * The pair still shares the SAME class_level_arm row, so nothing but the curriculum distinguishes
 * them — which also exercises the second half of the change, the exclusion moving from arm to
 * curriculum. Under the old arm-exclusion this destination was unreachable.
 */
it('allows a batch whose pupils differ only by exam type', function () {
    $w = sr_world();

    $otherExamType = ExamType::create([
        'school_id' => $w['school']->id,
        'name' => 'External',
        'slug' => 'et-'.Str::random(8),
    ]);

    $sameArmOtherExam = sr_curriculum(
        $w['school'],
        ClassLevelArm::find($w['c8B']->class_level_arm_id),
        $otherExamType,
        $w['term'],
    );

    [$inB] = bsr_pupils($w, $w['c8B'], 1);
    [$inOtherExam] = bsr_pupils($w, $sameArmOtherExam, 1);

    // Same arm, different curriculum — the shape the old rule refused twice over.
    expect($inB->curriculum->class_level_arm_id)
        ->toBe($inOtherExam->curriculum->class_level_arm_id)
        ->and($inB->curriculum_id)->not->toBe($inOtherExam->curriculum_id);

    // ── THE DESTINATION MUST CROSS EXAM TYPE, NOT JUST THE SOURCES ───────────────────────────────
    // Mutation-checked, and the first version of this arm FAILED that check: it sent both pupils to
    // c8S, which shares the reference episode's exam type. Bulk validates the destination against
    // the reference, so re-adding `exam_type_id` to CohortSiblings left this green — it was proving
    // mixed-source acceptance and nothing whatever about the exam-type key.
    //
    // Sending them INTO the other-exam curriculum is what discriminates: under the old key that
    // destination is not a sibling of c8B (different exam) and not reachable under the old
    // arm-exclusion either (same arm), so both halves of the change are pinned here.
    [$alsoInB] = bsr_pupils($w, $w['c8B'], 1);

    bsr_post($w, [$inB, $alsoInB], $sameArmOtherExam->uuid)
        ->assertOk()
        ->assertJsonPath('moved', 2);

    foreach ([$inB, $alsoInB] as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);
    }

    // And the pupil already sitting in the destination was left alone.
    expect($inOtherExam->fresh()->status)->toBe(StudentStatusEnum::ACTIVE)
        ->and($inOtherExam->fresh()->ended_at)->toBeNull();
});

/**
 * THE LEVEL AXIS, ISOLATED. Year 9 shares the school, term and is_ccm, so only the class-level
 * clause can refuse it — and it must, because a batch spanning levels has no single legal
 * destination.
 */
it('refuses a selection spanning two class levels', function () {
    $w = sr_world();

    [$inY8] = bsr_pupils($w, $w['c8B'], 1);
    [$inY9] = bsr_pupils($w, $w['c9B'], 1);

    bsr_post($w, [$inY8, $inY9], $w['c8S']->uuid)
        ->assertStatus(422)
        ->assertJsonPath('errors.episode_ids.0', fn (string $m) => str_contains($m, 'spans 2 cohorts'));

    expect($inY8->fresh()->status)->toBe(StudentStatusEnum::ACTIVE)
        ->and($inY9->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
});

/**
 * THE TERM AXIS, ISOLATED. Same level, same arm, same is_ccm — a second term is the only
 * difference, and term is what pins the academic session.
 */
it('refuses a selection spanning two terms', function () {
    $w = sr_world();

    $secondTerm = Term::create([
        'school_id' => $w['school']->id,
        'academic_session_id' => $w['session']->id,
        'name' => 'Second Term',
        'slug' => 'tm-'.Str::random(8),
        'order' => 2,
        'start_date' => now()->addMonths(2),
        'end_date' => now()->addMonths(4),
    ]);

    $otherTerm = sr_curriculum(
        $w['school'],
        ClassLevelArm::find($w['c8B']->class_level_arm_id),
        $w['examType'],
        $secondTerm,
    );

    [$inFirst] = bsr_pupils($w, $w['c8B'], 1);
    [$inSecond] = bsr_pupils($w, $otherTerm, 1);

    bsr_post($w, [$inFirst, $inSecond], $w['c8S']->uuid)
        ->assertStatus(422)
        ->assertJsonPath('errors.episode_ids.0', fn (string $m) => str_contains($m, 'spans 2 cohorts'));

    expect($inFirst->fresh()->status)->toBe(StudentStatusEnum::ACTIVE)
        ->and($inSecond->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
});

/**
 * THE is_ccm AXIS, ISOLATED — and it is doing more work than it used to.
 *
 * With exam type out of the key, is_ccm is the ONLY clause standing between this screen and a CCM
 * crossing, which belongs to MoveFromCcmJob rather than to a manual move. Everything else about
 * these two curricula matches.
 */
it('refuses a selection spanning CCM and non-CCM', function () {
    $w = sr_world();

    // Created AS CCM, not created-then-flipped: `curricula` is UNIQUE on
    // (school, term, class_level_arm, exam_type, is_ccm), so a non-CCM twin of c8B collides on
    // insert before the flip can happen. is_ccm is part of that key, which is why building it
    // directly works and is also a small confirmation that the axis is real in the schema.
    $ccm = Curriculum::create([
        'school_id' => $w['school']->id,
        'term_id' => $w['term']->id,
        'class_level_arm_id' => $w['c8B']->class_level_arm_id,
        'exam_type_id' => $w['examType']->id,
        'status' => 'active',
        'is_ccm' => true,
        'min_subjects' => 1,
    ]);

    [$inNormal] = bsr_pupils($w, $w['c8B'], 1);
    [$inCcm] = bsr_pupils($w, $ccm, 1);

    bsr_post($w, [$inNormal, $inCcm], $w['c8S']->uuid)
        ->assertStatus(422)
        ->assertJsonPath('errors.episode_ids.0', fn (string $m) => str_contains($m, 'spans 2 cohorts'));

    expect($inNormal->fresh()->status)->toBe(StudentStatusEnum::ACTIVE)
        ->and($inCcm->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
});

/**
 * MIXED SOURCES, ONE DESTINATION — the batch shape the old lock forbade outright.
 *
 * Pupils drawn from two different source arms move together into one destination, and the
 * destination is one of the sources — so half the batch is a real move and half is a no-op the
 * service absorbs idempotently. That combination is what the "already in that class" refusal used
 * to reject wholesale.
 */
it('moves a mixed-arm selection into one destination, no-opping those already there', function () {
    $w = sr_world();

    $fromB = bsr_pupils($w, $w['c8B'], 2);
    $alreadyInS = bsr_pupils($w, $w['c8S'], 2);

    bsr_post($w, [...$fromB, ...$alreadyInS], $w['c8S']->uuid)
        ->assertOk()
        ->assertJsonPath('moved', 4);

    // The movers vacated their 8B episodes.
    foreach ($fromB as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);
    }

    // The no-ops were untouched — still live in 8S, never ended and revived.
    foreach ($alreadyInS as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE)
            ->and($episode->fresh()->ended_at)->toBeNull();
    }

    // Every pupil ends up in 8S exactly once.
    expect(StudentCurriculum::where('curriculum_id', $w['c8S']->id)->whereNull('ended_at')->count())
        ->toBe(4);
});

it('accepts a selection that genuinely shares one curriculum', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 3);

    bsr_post($w, $episodes, $w['c8S']->uuid)
        ->assertOk()
        ->assertJsonPath('moved', 3);

    foreach ($episodes as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::TRANSFERRED);
    }
});

// ---------------------------------------------------------------------------
// 2. ALL OR NOTHING
// ---------------------------------------------------------------------------

/**
 * ONE BAD EPISODE ANYWHERE IN THE BATCH AND NOBODY MOVES — asserted as the BEFORE STATE RESTORED,
 * not merely as an error response.
 *
 * A test that checks only the status code passes against an implementation that moved nineteen
 * pupils and then failed on the twentieth, which is precisely the outcome the transaction exists to
 * prevent. So every pupil's status, ended_at and curriculum_id are read back.
 */
it('moves nobody when one episode in the batch is not movable', function () {
    $w = sr_world();

    $good = bsr_pupils($w, $w['c8B'], 4);

    // The bad one: already ended, which is what a pupil moved out from under the operator looks
    // like, and what a double-submit looks like.
    [$stale] = bsr_pupils($w, $w['c8B'], 1);
    $stale->update(['status' => StudentStatusEnum::TRANSFERRED, 'ended_at' => now()]);

    $before = collect($good)->mapWithKeys(fn (StudentCurriculum $e) => [
        $e->uuid => [
            'status' => $e->status,
            'ended_at' => $e->ended_at,
            'curriculum_id' => $e->curriculum_id,
        ],
    ]);

    bsr_post($w, [...$good, $stale], $w['c8S']->uuid)->assertStatus(422);

    foreach ($good as $episode) {
        $fresh = $episode->fresh();
        $was = $before[$episode->uuid];

        expect($fresh->status)->toBe($was['status'])
            ->and($fresh->ended_at)->toEqual($was['ended_at'])
            ->and($fresh->curriculum_id)->toBe($was['curriculum_id']);
    }

    // And no destination episodes were conjured for the pupils that did not move.
    expect(StudentCurriculum::where('curriculum_id', $w['c8S']->id)->count())->toBe(0);
});

/**
 * THE TRANSACTION ITSELF, PINNED — and this arm exists because the one above does NOT pin it.
 *
 * Mutation-checked while writing: removing `DB::transaction` from the controller left every other
 * arm in this file GREEN, including "moves nobody when one episode is not movable". That arm passes
 * because the FormRequest rejects the stale episode BEFORE any write opens, so the batch never
 * starts and the transaction is never exercised. It proves validation ordering, which is worth
 * proving, and it says nothing whatever about rollback.
 *
 * The gap matters because the transaction guards a different failure entirely: not a predictable
 * one the request can foresee, but an EXCEPTIONAL one partway through — a driver error, a trigger,
 * a constraint — on pupil 3 of 5, after two have already moved. That is the half-applied cohort the
 * all-or-nothing decision exists to make impossible, and until this arm it was unprotected by any
 * test.
 *
 * So the service is swapped for one that throws on the third call. Everything else is real.
 */
it('rolls the whole batch back when the move throws partway through', function () {
    $w = sr_world();

    // Faked BEFORE the request so the assertions below observe this batch and nothing else.
    Bus::fake();
    Queue::fake();

    $episodes = bsr_pupils($w, $w['c8B'], 5);

    app()->instance(
        CurriculumReassignmentService::class,
        new class(app(StudentSubjectService::class)) extends CurriculumReassignmentService
        {
            public int $calls = 0;

            public function reassign(
                StudentCurriculum $current,
                Curriculum $newCurriculum,
                User $performedBy,
                ?string $reason = null,
            ): StudentCurriculum {
                $this->calls++;

                // On the third pupil, after two have genuinely moved inside the open transaction.
                if ($this->calls === 3) {
                    throw new RuntimeException('simulated mid-batch failure');
                }

                return parent::reassign($current, $newCurriculum, $performedBy, $reason);
            }
        },
    );

    bsr_post($w, $episodes, $w['c8S']->uuid)
        ->assertStatus(500)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Nothing was moved'));

    // THE TWO THAT HAD ALREADY MOVED ARE BACK. Without the transaction, pupils 1 and 2 are sitting
    // in 8S with their 8B episodes ended, and nothing in the response says so.
    foreach ($episodes as $episode) {
        $fresh = $episode->fresh();

        expect($fresh->status)->toBe(StudentStatusEnum::ACTIVE)
            ->and($fresh->ended_at)->toBeNull()
            ->and($fresh->curriculum_id)->toBe($w['c8B']->id);
    }

    // ── THE SIDE EFFECTS ROLL BACK TOO, NOT ONLY THE PLACEMENT ──────────────────────────────────
    // Placement and its side effects are written by different code inside the same boundary, so
    // "nobody moved" and "nothing else survived" are separate claims. A write that sits OUTSIDE the
    // transaction — an audit row, a revived destination episode, a dispatched job — would leave the
    // placement correct and the record of it wrong, and this is the only arm positioned to see it.
    //
    // Audit rows are counted BY BATCH ID rather than by description: a description match would also
    // pass if the rows survived under a different batch, which is the failure this is looking for.
    expect(StudentCurriculum::where('curriculum_id', $w['c8S']->id)->count())->toBe(0)
        ->and(Activity::where('description', 'LIKE', 'Reassigned from%')->count())->toBe(0)
        ->and(
            Activity::query()
                ->whereNotNull('properties->batch_id')
                ->count()
        )->toBe(0);

    // Nothing was queued. `reassign` dispatches nothing today, so this is a tripwire rather than a
    // regression test: the moment a job is added to that path, this arm decides whether it is
    // inside the transaction boundary or leaks past a rollback.
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

/**
 * THE STALE MESSAGE NAMES THE PUPIL. "One of your 24 selections is stale" is not actionable on a
 * screen showing 24 identical-looking rows, which is the entire reason this endpoint takes episode
 * uuids rather than student uuids.
 */
it('names the stale pupil rather than reporting a count', function () {
    $w = sr_world();

    $good = bsr_pupils($w, $w['c8B'], 2);
    [$stale] = bsr_pupils($w, $w['c8B'], 1);
    $stale->update(['status' => StudentStatusEnum::TRANSFERRED, 'ended_at' => now()]);

    $expected = $stale->student->admission_number;

    bsr_post($w, [...$good, $stale], $w['c8S']->uuid)
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.episode_ids.0',
            fn (string $m) => str_contains($m, $expected),
        );
});

// ---------------------------------------------------------------------------
// 3. BATCH BILLING — the four-point transition, generalised and asserted for
//    EVERY pupil, not sampled
// ---------------------------------------------------------------------------

/**
 * M3's four-point billing transition, applied to a batch.
 *
 * Every pupil has a PRIOR, ENDED episode in 8S that exists throughout, so "destination not billable
 * before" is a real decision the adapter makes about a present row rather than a statement about a
 * row that did not exist yet. The batch then revives all of them at once.
 *
 * ASSERTED FOR ALL N, NOT SAMPLED. Since all move or none do, a sampled assertion would pass against
 * an implementation that moved the first pupil and silently skipped the rest — and "all or nothing"
 * is exactly the property that makes the full assertion cheap to state and the sampled one
 * misleading.
 */
it('moves billability for every pupil in the batch, and rolls billing back with the batch', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 3);

    // Each pupil's earlier stint in 8S: ended, TRANSFERRED, still on the books, and holding the
    // HIGHER id — so a status-blind MAX(id) would select it and "not billable before" would be true
    // for the wrong reason. Same fixture discipline M3 pins.
    $priors = [];
    foreach ($episodes as $episode) {
        $prior = StudentCurriculum::create([
            'student_id' => $episode->student_id,
            'curriculum_id' => $w['c8S']->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);
        $prior->update(['status' => StudentStatusEnum::TRANSFERRED, 'ended_at' => now()]);

        expect($prior->id)->toBeGreaterThan($episode->id);

        $priors[] = $prior;
    }

    $billableUuids = fn (): array => ActiveSchool::runFor($w['school']->id, fn () => array_map(
        fn ($enrollment) => $enrollment->enrollmentUuid,
        app(BillableEnrollmentAdapter::class)->listForCohort(
            (int) $w['school']->id,
            (int) $w['term']->id,
            (int) $w['y8']->id,
        )
    ));

    $before = $billableUuids();

    foreach ($episodes as $i => $episode) {
        expect($before)->toContain($episode->uuid)
            ->and($before)->not->toContain($priors[$i]->uuid);
    }

    bsr_post($w, $episodes, $w['c8S']->uuid)->assertOk();

    $after = $billableUuids();

    // ALL N, both directions.
    foreach ($episodes as $i => $episode) {
        expect($after)->not->toContain($episode->uuid)
            ->and($after)->toContain($priors[$i]->uuid);
    }

    // The revive is what made the second half true — same rows, not new ones.
    expect(StudentCurriculum::where('curriculum_id', $w['c8S']->id)->count())
        ->toBe(count($episodes));
});

/**
 * A ROLLBACK LEAVES BILLING UNTOUCHED TOO, not only placement.
 *
 * Placement and billability are read from the same rows but by different code, so "nobody moved"
 * and "nobody's billing changed" are two claims. This asserts the second one directly rather than
 * inferring it from the first.
 */
it('leaves billability untouched when the batch is refused', function () {
    $w = sr_world();

    $good = bsr_pupils($w, $w['c8B'], 3);
    [$stale] = bsr_pupils($w, $w['c8B'], 1);
    $stale->update(['status' => StudentStatusEnum::TRANSFERRED, 'ended_at' => now()]);

    $billableUuids = fn (): array => ActiveSchool::runFor($w['school']->id, fn () => array_map(
        fn ($enrollment) => $enrollment->enrollmentUuid,
        app(BillableEnrollmentAdapter::class)->listForCohort(
            (int) $w['school']->id,
            (int) $w['term']->id,
            (int) $w['y8']->id,
        )
    ));

    $before = $billableUuids();

    bsr_post($w, [...$good, $stale], $w['c8S']->uuid)->assertStatus(422);

    sort($before);
    $after = $billableUuids();
    sort($after);

    expect($after)->toBe($before);
});

// ---------------------------------------------------------------------------
// 4. ISOLATION, ELIGIBILITY, PERMISSION
// ---------------------------------------------------------------------------

/**
 * A FOREIGN EPISODE IN THE BATCH IS REFUSED, and the refusal is the uuid resolution — the guard that
 * is isolatable — not the sibling rule shadowing it.
 */
it('refuses a batch containing an episode from another school', function () {
    $w = sr_world();
    $other = sr_school('second');

    $mine = bsr_pupils($w, $w['c8B'], 2);
    [$theirs] = bsr_pupils($other, $other['c8B'], 1);

    bsr_post($w, [...$mine, $theirs], $w['c8S']->uuid)
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.episode_ids.0',
            fn (string $m) => str_contains($m, 'could not be found'),
        );

    foreach ($mine as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
    }

    // The other school's pupil is untouched, checked by ID rather than by label.
    expect($theirs->fresh()->status)->toBe(StudentStatusEnum::ACTIVE)
        ->and($theirs->fresh()->curriculum_id)->toBe($other['c8B']->id);
});

/**
 * THE SIBLING RULE, ISOLATED. Year 9 B is in the SAME school, term and exam type, so no school
 * guard, scope or foreign key can refuse it — only the sibling rule can.
 */
it('refuses a destination in a different year group', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 2);

    bsr_post($w, $episodes, $w['c9B']->uuid)
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.destination_curriculum_id.0',
            fn (string $m) => str_contains($m, 'not in this cohort'),
        );

    foreach ($episodes as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
    }
});

it('refuses a destination the cohort is already in', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 2);

    bsr_post($w, $episodes, $w['c8B']->uuid)
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.destination_curriculum_id.0',
            fn (string $m) => str_contains($m, 'already in that class'),
        );
});

/**
 * THE CAP IS PINNED AT ITS VALUE, NOT AT ITS OWN DEFINITION.
 *
 * Mutation-checked: the first version of this arm built its payload from the constant —
 * `while (count($ids) <= MAX_BATCH)` — so it submitted "one more than whatever the cap is" and
 * stayed GREEN with MAX_BATCH raised to 100000. It proved that SOME cap is enforced and was
 * structurally incapable of noticing the cap being loosened or removed, which is the whole risk:
 * at a measured class maximum of 24, nothing in normal use would ever reach it either.
 *
 * So the number is asserted directly, and the payload is a literal 61. Raising the constant now
 * reds on the pin; removing the rule reds on the request.
 */
it('caps the batch at sixty', function () {
    expect(BulkReassignStudentsRequest::MAX_BATCH)->toBe(60);
});

it('refuses a batch of sixty-one', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 2);

    // Padded with syntactically valid uuids: the cap must be refused by COUNT, before any
    // resolution, so it cannot be mistaken for a "not found" refusal.
    $ids = array_map(fn (StudentCurriculum $e) => $e->uuid, $episodes);
    while (count($ids) < 61) {
        $ids[] = (string) Str::uuid();
    }

    expect($ids)->toHaveCount(61);

    test()->actingAs($w['admin'])
        ->postJson('/api/students/bulk-reassign', [
            'episode_ids' => $ids,
            'destination_curriculum_id' => $w['c8S']->uuid,
        ])
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.episode_ids.0',
            fn (string $m) => str_contains($m, 'at once'),
        );
});

/** Sixty is accepted — the boundary is pinned from both sides, so an off-by-one reds. */
it('accepts a batch of exactly sixty', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 60);

    bsr_post($w, $episodes, $w['c8S']->uuid)
        ->assertOk()
        ->assertJsonPath('moved', 60);
});

it('refuses an operator without academic_setup.manage', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 2);
    $outsider = al_makeUser($w['school']->id);

    test()->actingAs($outsider)
        ->postJson('/api/students/bulk-reassign', [
            'episode_ids' => array_map(fn (StudentCurriculum $e) => $e->uuid, $episodes),
            'destination_curriculum_id' => $w['c8S']->uuid,
        ])
        ->assertForbidden();

    foreach ($episodes as $episode) {
        expect($episode->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);
    }
});

// ---------------------------------------------------------------------------
// 5. AUDIT
// ---------------------------------------------------------------------------

/**
 * N ROWS, ONE BATCH ID.
 *
 * One row per pupil so an individual's history reads correctly on their own page; the shared uuid is
 * what makes the N rows recognisable afterwards as one operator action rather than N coincidences
 * that happened to share a timestamp.
 */
it('writes one audit row per pupil carrying a shared batch id', function () {
    $w = sr_world();

    $episodes = bsr_pupils($w, $w['c8B'], 3);

    $response = bsr_post($w, $episodes, $w['c8S']->uuid)->assertOk();

    $batchId = $response->json('batch_id');
    expect($batchId)->not->toBeNull();

    $rows = Activity::query()
        ->whereIn('subject_id', array_map(fn (StudentCurriculum $e) => $e->id, $episodes))
        ->where('subject_type', StudentCurriculum::class)
        ->where('description', 'LIKE', 'Reassigned from%')
        ->get();

    expect($rows)->toHaveCount(3);

    // Every row carries the SAME batch id — the property that makes them one action.
    expect($rows->pluck('properties.batch_id')->unique()->values()->all())
        ->toBe([$batchId]);

    // Written against the VACATED episode, which is the row an operator opens when asking why a
    // pupil left a class.
    expect($rows->pluck('subject_id')->sort()->values()->all())
        ->toBe(collect($episodes)->pluck('id')->sort()->values()->all());
});
