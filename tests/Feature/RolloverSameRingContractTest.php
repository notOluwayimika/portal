<?php

use App\Services\ProgressionGraph;
use App\Services\Rollover\RolloverPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ONE WALK, SEVERAL CALLERS — proved by making them agree on the same named ring.
 *
 * ── WHY THIS TEST AND NOT A DOCBLOCK ─────────────────────────────────────────────────────────────
 * `academics:validate-progression` and the rollover pre-flight both report a progression cycle. They
 * must be reporting the SAME cycle, derived by the SAME walk — otherwise the config screen tells a
 * registrar the graph is fine while the rollover refuses, or worse, the reverse.
 *
 * The way that breaks is not a wrong answer; it is a second implementation. Someone adds a "quick"
 * cycle check inside the planner, both are correct on the day, and they diverge the first time
 * `ProgressionGraph` changes. So the assertion is not "the planner detects cycles" — it is "the
 * planner's ring is byte-identical to the walk's, and to the command's".
 *
 * ── THIS TEST SPANS TWO SLICES, DELIBERATELY, AND SAYS SO IN CODE ────────────────────────────────
 * The third caller — slice 2's UI pre-flight payload — does not exist yet. The pending arm below is
 * written out in full rather than left as a comment, because a cross-slice assertion parked as prose
 * is one that quietly never lands: the slice that would complete it has no failing test to remind
 * anyone. A skipped test with the assertion already written shows up in every run until it is
 * un-skipped.
 */
it('reports the same named ring from the walk and from the rollover planner', function () {
    $w = rollover_cyclic_world();

    // The walk itself — the definition.
    $fromWalk = ProgressionGraph::findCycle($w['school']->id);

    expect($fromWalk)->not->toBeNull()
        ->and($fromWalk)->toBeArray();

    // The rollover pre-flight, reached through the planner rather than through a command's exit code.
    $plan = app(RolloverPlanner::class)->planEndOfYear($w['source'], $w['target']);

    // IDENTICAL, not merely "both non-null". A planner that ran its own walk would very likely also
    // find a ring, and a both-truthy assertion would pass while the two definitions drifted.
    expect($plan->progressionCycle)->toBe($fromWalk);

    // And the plan is blocked BY THAT GATE, named — not merely "not runnable".
    expect($plan->blockedBy)->toContain('progression-cycle')
        ->and($plan->isRunnable())->toBeFalse();
});

it('surfaces that same ring in the command output', function () {
    $w = rollover_cyclic_world();

    $ring = ProgressionGraph::findCycle($w['school']->id);
    $rendered = implode(' -> ', $ring);

    // The command's half of the contract: the operator reads the ring the walk returned, in order.
    $this->artisan('academics:run-end-of-year', [
        'sourceSession' => $w['source']->uuid,
        'targetSession' => $w['target']->uuid,
    ])
        ->expectsOutputToContain($rendered)
        ->assertExitCode(1);
});

/**
 * THE THIRD CALLER — LIVE AS OF SLICE 2, and this was the first thing slice 2 made pass.
 *
 * Written in slice 1 behind markTestSkipped with the assertion already spelled out, precisely so
 * that landing the endpoint could not quietly skip proving the contract. Un-skipping it was the
 * first move of slice 2 rather than the last.
 *
 * "One walk, three callers" is now end to end: the walk, the command, and the UI pre-flight payload
 * all report the SAME named ring.
 */
it('surfaces that same ring in the UI pre-flight payload', function () {
    $w = rollover_cyclic_world();
    rollover_grant($w['admin'], $w['school']);

    $ring = ProgressionGraph::findCycle($w['school']->id);

    $this->actingAs($w['admin'])
        ->postJson('/api/rollover/end-of-year/preview', [
            'source_session_id' => $w['source']->uuid,
            'target_session_id' => $w['target']->uuid,
        ])
        ->assertOk()
        // The SAME array, not a re-derived or re-formatted one.
        ->assertJsonPath('progression_cycle', $ring)
        ->assertJsonPath('blocked_by', fn (array $b) => in_array('progression-cycle', $b, true))
        ->assertJsonPath('is_runnable', false)
        // The applicability flag travels with it: this check RAN and found a ring, which is a
        // different state from an end-of-term plan that never ran it.
        ->assertJsonPath('progression_check_ran', true)
        ->assertJsonPath('progression_is_acyclic', false);
});
