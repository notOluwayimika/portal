<?php

use App\Jobs\MoveFromTermJob;
use App\Jobs\MoveToNextYearJob;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\User;
use App\Services\Rollover\RolloverBatchName;
use App\Services\Rollover\RolloverDispatcher;
use App\Services\Rollover\RolloverPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A runnable end-of-year world: one level, one final slot, one active non-CCM curriculum, and an
 * acyclic graph. Everything the gates would refuse is deliberately absent, so an arm that turns red
 * has turned red for the reason it names.
 *
 * @return array<string, mixed>
 */
function rs_runnable_world(): array
{
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['target'], 1);

    [$level, $arm] = rc_level($w['school'], 'Year 7', 7, [1]);
    $curriculum = rc_curriculum($w['school'], $arm, $t1, $w['examType']);

    rollover_grant($w['admin'], $w['school']);

    return $w + ['term' => $t1, 'level' => $level, 'arm' => $arm, 'curriculum' => $curriculum];
}

function rs_preview(array $w)
{
    return test()->actingAs($w['admin'])->postJson('/api/rollover/end-of-year/preview', [
        'source_session_id' => $w['source']->uuid,
        'target_session_id' => $w['target']->uuid,
    ]);
}

function rs_commit(array $w)
{
    return test()->actingAs($w['admin'])->postJson('/api/rollover/end-of-year', [
        'source_session_id' => $w['source']->uuid,
        'target_session_id' => $w['target']->uuid,
    ]);
}

// ---------------------------------------------------------------------------
// 1. NOTHING IS DISPATCHED BY EITHER GATE
// ---------------------------------------------------------------------------

/**
 * The cycle gate refuses the COMMIT and queues nothing.
 *
 * `assertNothingBatched` is asserting a structural fact rather than an implementation detail: the
 * planner has no path to a queue at all (pinned in tests/Arch/RolloverSeamTest), so a preview
 * physically cannot dispatch, and the commit's refusal is the only thing standing between a blocked
 * plan and a batch.
 */
it('dispatches nothing when the progression graph has a cycle', function () {
    Bus::fake();

    $w = rollover_cyclic_world();
    rollover_grant($w['admin'], $w['school']);

    rs_commit($w)
        ->assertStatus(422)
        ->assertJsonPath('plan.blocked_by', fn (array $b) => in_array('progression-cycle', $b, true))
        ->assertJsonPath('plan.is_runnable', false);

    Bus::assertNothingBatched();
});

it('dispatches nothing when a final slot is still CCM', function () {
    Bus::fake();

    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['target'], 1);
    [, $arm] = rc_level($w['school'], 'Year 7', 7, [1]);
    rc_curriculum($w['school'], $arm, $t1, $w['examType'], isCcm: true);
    rollover_grant($w['admin'], $w['school']);

    rs_commit($w)
        ->assertStatus(422)
        ->assertJsonPath('plan.blocked_by', fn (array $b) => in_array('ccm-active', $b, true));

    Bus::assertNothingBatched();
});

/** A PREVIEW never queues, whatever it finds — including a perfectly runnable plan. */
it('dispatches nothing from a preview of a runnable plan', function () {
    Bus::fake();

    $w = rs_runnable_world();

    rs_preview($w)->assertOk()->assertJsonPath('is_runnable', true);

    Bus::assertNothingBatched();
});

// ---------------------------------------------------------------------------
// 2. PREVIEW / COMMIT PARITY
// ---------------------------------------------------------------------------

/**
 * The same selection, one dispatching and one not.
 *
 * If these ever diverge the screen is lying: an operator approves what the preview showed and
 * something else runs. Compared by CURRICULUM IDS and pupil count rather than by a whole-body
 * equality, because the commit body legitimately carries a batch id the preview cannot have.
 */
it('commits exactly the selection the preview showed', function () {
    Bus::fake();

    $w = rs_runnable_world();

    $preview = rs_preview($w)->assertOk();
    $previewIds = collect($preview->json('curricula'))->pluck('id')->sort()->values()->all();

    $commit = rs_commit($w)->assertOk();
    $commitIds = collect($commit->json('plan.curricula'))->pluck('id')->sort()->values()->all();

    expect($commitIds)->toBe($previewIds)
        ->and($commit->json('plan.pupil_count'))->toBe($preview->json('pupil_count'))
        ->and($commit->json('plan.batch_name'))->toBe($preview->json('batch_name'));

    Bus::assertBatchCount(1);
});

// ---------------------------------------------------------------------------
// 3. THE STALE PREVIEW — state MUTATED BETWEEN the two calls
// ---------------------------------------------------------------------------

/**
 * A CYCLE INTRODUCED AFTER THE PREVIEW BLOCKS THE COMMIT.
 *
 * This is the arm the whole plan/dispatch split exists for, and it is written to mutate state
 * BETWEEN the two requests rather than merely to assert that a re-plan happens. Asserting "the
 * planner was called twice" would pass against an implementation that re-planned and then dispatched
 * the previewed plan anyway; only changing the world between the calls proves the SECOND plan is the
 * one that decides.
 *
 * The failure this prevents: preview is clean, an operator reads it, someone edits the progression
 * config, the operator confirms — and a whole year group migrates through a ring where every job
 * succeeds individually while nobody advances.
 */
it('blocks the commit when a cycle appears between preview and confirm', function () {
    Bus::fake();

    $w = rs_runnable_world();

    // Preview is clean and runnable — this is what the operator reads.
    rs_preview($w)->assertOk()
        ->assertJsonPath('is_runnable', true)
        ->assertJsonPath('progression_cycle', null)
        ->assertJsonPath('progression_is_acyclic', true);

    // ── THE WORLD CHANGES between the operator reading and confirming ────────────────────────────
    $second = ClassLevel::forceCreate([
        'school_id' => $w['school']->id, 'name' => 'Year 8', 'order' => 8,
    ]);
    $w['level']->update(['next_class_level_id' => $second->id]);
    $second->update(['next_class_level_id' => $w['level']->id]);

    // The commit re-plans and refuses, though the preview said go.
    rs_commit($w)
        ->assertStatus(422)
        ->assertJsonPath('plan.blocked_by', fn (array $b) => in_array('progression-cycle', $b, true))
        ->assertJsonPath('plan.progression_check_ran', true)
        ->assertJsonPath('plan.progression_is_acyclic', false);

    Bus::assertNothingBatched();
});

/** The same shape on the other gate: a CCM curriculum appearing after the preview also blocks. */
it('blocks the commit when a CCM curriculum appears between preview and confirm', function () {
    Bus::fake();

    $w = rs_runnable_world();

    rs_preview($w)->assertOk()->assertJsonPath('is_runnable', true);

    [, $ccmArm] = rc_level($w['school'], 'Year 9', 9, [1]);
    rc_curriculum($w['school'], $ccmArm, $w['term'], $w['examType'], isCcm: true);

    rs_commit($w)
        ->assertStatus(422)
        ->assertJsonPath('plan.blocked_by', fn (array $b) => in_array('ccm-active', $b, true));

    Bus::assertNothingBatched();
});

// ---------------------------------------------------------------------------
// 4. AUTHORIZATION — the runtime arm, which is the ONLY thing pinning the gate
// ---------------------------------------------------------------------------

/**
 * `ci-authz-lint` scans for COMMENTED-OUT authorization checks against a frozen baseline. It does
 * not verify that a route carries a permission, so this arm is the only thing that pins the gate.
 *
 * The seat holds `academic_setup.manage` and NOT `academics.rollover` — the exact conflation the
 * separate permission exists to prevent. An actor built from an `admin` role would hold both and
 * pass for the wrong reason.
 */
it('refuses an operator holding academic_setup.manage but not academics.rollover', function () {
    Bus::fake();

    $w = rs_runnable_world();
    // Built inline rather than borrowing a role: the actor must hold academic_setup.manage and
    // NOT academics.rollover. Granting an `admin` seat would hand over both and the 403 would never
    // fire — the arm would pass while proving nothing.
    $outsider = al_makeUser($w['school']->id);
    $config = Permission::where('name', 'academic_setup.manage')
        ->where('guard_name', 'web')->first()
        ?? Permission::create(['name' => 'academic_setup.manage', 'guard_name' => 'web']);

    $outsider->grantSchoolAccess($w['school'], 'registrar');
    setPermissionsTeamId($w['school']->id);
    $outsider->givePermissionTo($config);
    $outsider->flushSchoolAccessCache();

    expect($outsider->fresh()->can('academics.rollover'))->toBeFalse();

    test()->actingAs($outsider)
        ->postJson('/api/rollover/end-of-year', [
            'source_session_id' => $w['source']->uuid,
            'target_session_id' => $w['target']->uuid,
        ])
        ->assertForbidden();

    Bus::assertNothingBatched();
});

// ---------------------------------------------------------------------------
// 5. ISOLATION, AND THE THREE FIELD REFUSALS
// ---------------------------------------------------------------------------

it('cannot reach another schools session by uuid', function () {
    Bus::fake();

    $w = rs_runnable_world();
    $other = rc_world();

    test()->actingAs($w['admin'])
        ->postJson('/api/rollover/end-of-year/preview', [
            'source_session_id' => $other['source']->uuid,
            'target_session_id' => $other['target']->uuid,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_session_id', 'target_session_id']);

    Bus::assertNothingBatched();
});

it('refuses a target session that is the source', function () {
    $w = rs_runnable_world();

    test()->actingAs($w['admin'])
        ->postJson('/api/rollover/end-of-year/preview', [
            'source_session_id' => $w['source']->uuid,
            'target_session_id' => $w['source']->uuid,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['target_session_id']);
});

// ---------------------------------------------------------------------------
// 6. QUEUED, NOT DONE
// ---------------------------------------------------------------------------

/**
 * The response must say the rollover is NOT finished. A registrar who reads "done" and switches the
 * current session while workers are still draining is the failure this wording exists to prevent,
 * so the words are asserted rather than left to a reviewer's eye.
 */
it('reports the batch as queued and warns against switching session', function () {
    Bus::fake();

    $w = rs_runnable_world();

    $response = rs_commit($w)->assertOk();

    expect($response->json('message'))->toContain('Queued')
        ->and($response->json('message'))->toContain('not finished')
        ->and($response->json('message'))->toContain('do not change the current session')
        ->and($response->json('queued_jobs'))->toBe(1)
        ->and($response->json('batch_name'))->toContain('rollover:end-of-year:school:');
});

/** The progress view reports the counts it reads, and calls a draining batch draining. */
it('reports batch progress as queued/done/failed rather than a boolean', function () {
    $w = rs_runnable_world();

    DB::table('job_batches')->insert([
        'id' => 'batch-under-test',
        'name' => RolloverBatchName::forSession((int) $w['school']->id, (int) $w['source']->id),
        'total_jobs' => 5, 'pending_jobs' => 2, 'failed_jobs' => 1,
        'failed_job_ids' => '[]', 'options' => '', 'created_at' => now()->timestamp,
    ]);

    $response = test()->actingAs($w['admin'])->getJson('/api/rollover/batches')->assertOk();

    expect($response->json('data.0.total_jobs'))->toBe(5)
        ->and($response->json('data.0.pending_jobs'))->toBe(2)
        ->and($response->json('data.0.done_jobs'))->toBe(3)
        ->and($response->json('data.0.failed_jobs'))->toBe(1)
        ->and($response->json('data.0.is_draining'))->toBeTrue();
});

/** Another school's batches are not visible — the reason the school id is in the name pattern. */
it('never lists another schools rollover batches', function () {
    $w = rs_runnable_world();
    $other = rc_world();

    DB::table('job_batches')->insert([
        'id' => 'foreign-batch',
        'name' => RolloverBatchName::forSession((int) $other['school']->id, 1),
        'total_jobs' => 3, 'pending_jobs' => 3, 'failed_jobs' => 0,
        'failed_job_ids' => '[]', 'options' => '', 'created_at' => now()->timestamp,
    ]);

    $response = test()->actingAs($w['admin'])->getJson('/api/rollover/batches')->assertOk();

    // By id, never by count alone.
    expect(collect($response->json('data'))->pluck('id')->all())->not->toContain('foreign-batch');
});

// ---------------------------------------------------------------------------
// 7. THE DISPATCHER'S OWN REFUSALS — isolated, because the controller shadows them
// ---------------------------------------------------------------------------

/**
 * THE DISPATCHER'S OWN REFUSALS, ISOLATED — because the controller shadows them.
 *
 * Mutation-checked: removing `isRunnable()` from the dispatcher left every surface arm GREEN, since
 * RolloverController::refuse() returns a 422 first and the dispatcher is never reached with a
 * blocked plan. That is the belt-and-braces working as intended, and it is exactly why the guard
 * needs an arm where it acts ALONE — otherwise it is a guard nobody has ever seen fire, which is
 * indistinguishable from one that does not.
 *
 * It exists for the third caller: a future command, job or controller that plans and dispatches
 * without asking. Queuing a whole-school migration past a gate because a caller forgot is the single
 * most expensive bug available in this milestone.
 */
it('refuses to dispatch a blocked plan even when the caller does not check', function () {
    Bus::fake();

    $blocked = rollover_plan(
        progressionCheckRan: true,
        progressionCycle: ['Year 7', 'Year 8', 'Year 7'],
        blockedBy: ['progression-cycle'],
    );

    expect(fn () => app(RolloverDispatcher::class)->dispatchEndOfYear(
        $blocked, new AcademicSession, new User
    ))->toThrow(LogicException::class);

    Bus::assertNothingBatched();
});

it('refuses to dispatch an empty plan', function () {
    Bus::fake();

    // Runnable, but nothing to migrate — an empty batch would report a rollover that did not happen.
    $empty = rollover_plan(progressionCheckRan: true, progressionCycle: null);

    expect(fn () => app(RolloverDispatcher::class)->dispatchEndOfYear(
        $empty, new AcademicSession, new User
    ))->toThrow(LogicException::class);

    Bus::assertNothingBatched();
});

/** A term plan sent down the year path is a caller bug, and must not silently queue the wrong jobs. */
it('refuses to dispatch a plan through the wrong kind of path', function () {
    Bus::fake();

    $termPlan = new RolloverPlan(
        kind: RolloverBatchName::KIND_END_OF_TERM,
        schoolId: 1,
        batchName: RolloverBatchName::forTerm(1, 1),
        curricula: collect([new Curriculum]),
        pupilCount: 1,
        progressionCheckRan: false,
        progressionCycle: null,
        ccmBlockers: collect(),
        warnings: [],
        blockedBy: [],
    );

    expect(fn () => app(RolloverDispatcher::class)->dispatchEndOfYear(
        $termPlan, new AcademicSession, new User
    ))->toThrow(LogicException::class);

    Bus::assertNothingBatched();
});

// ---------------------------------------------------------------------------
// 8. THE BATCH CAN ACTUALLY BE CREATED — the one thing Bus::fake() cannot tell you
// ---------------------------------------------------------------------------

/**
 * BOTH ROLLOVER JOBS MUST BE BATCHABLE, ASSERTED AGAINST THE REAL Bus.
 *
 * ── WHY EVERY OTHER ARM IN THIS FILE IS BLIND TO THIS ────────────────────────────────────────────
 * `Bus::fake()` replaces the dispatcher, and `BusFake::batch()` returns a `PendingBatchFake` which
 * never calls `ensureJobIsBatchable()`. Only the real `PendingBatch` does. So a job missing the
 * `Batchable` trait passes `assertBatchCount`, `assertNothingBatched` and every faked commit arm —
 * and throws the moment a human presses the button.
 *
 * That is exactly what happened: neither MoveToNextYearJob nor MoveFromTermJob carried the trait
 * since the commands were written, so `academics:run-end-of-term --commit` and `run-end-of-year
 * --commit` had NEVER worked. 22 green tests, and the feature was unusable. Found by driving the
 * screen, which is the argument for driving in one line.
 *
 * ── WHY Bus::batch() WITHOUT ->dispatch() IS THE RIGHT PROBE ─────────────────────────────────────
 * The trait check runs when the batch is BUILT, not when it is stored. So constructing a real
 * PendingBatch exercises Laravel's own validation — not a reimplementation of it — while queueing
 * nothing and touching no table. Asserting `class_uses_recursive` instead would restate the rule in
 * our own words and drift from the framework the day it changes.
 */
it('can build a real batch from both rollover jobs', function () {
    // NO Bus::fake() — that is the entire point of this arm.
    $w = rs_runnable_world();
    $curriculum = $w['curriculum'];

    expect(fn () => Bus::batch([
        new MoveToNextYearJob($curriculum, $w['target'], (int) $w['admin']->id, (int) $w['school']->id),
    ]))->not->toThrow(RuntimeException::class);

    expect(fn () => Bus::batch([
        new MoveFromTermJob($curriculum, (int) $w['admin']->id, (int) $w['school']->id),
    ]))->not->toThrow(RuntimeException::class);
});
