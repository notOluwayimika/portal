<?php

use App\Http\Controllers\RolloverController;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Services\Rollover\RolloverPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `Curriculum::operatorLabel()` — the shared half of a label that had drifted.
 *
 * RolloverPlanner::describe() and RolloverController::describe() both assembled "Year 9 A", and the
 * two copies disagreed about the edge cases: the planner trimmed and fell back to `curriculum#{id}`,
 * the controller did neither and returned an em dash. Naming the class in the fold refusal would have
 * made a THIRD copy, so the assembly moved here.
 *
 * It returns null rather than a fallback on purpose — both existing fallbacks are defensible and
 * baking either one in would silently change a screen. These arms pin the assembly AND the fact that
 * each caller kept its own.
 */
it('assembles class level, arm and stream into the label the gate shows', function () {
    $w = rc_world();
    [$level, $arm] = rc_level($w['school'], 'Year 9', 9, [1]);
    $t = rc_term($w['source'], 1);
    $c = rc_curriculum($w['school'], $arm, $t, $w['examType']);

    // rc_level's arm is labelled 'B' — assert the ASSEMBLED string, not that a label exists, so a
    // change that dropped the arm or reordered the parts reds.
    expect($c->operatorLabel())->toBe('Year 9 B');
});

it('returns NULL rather than a fallback, so each caller keeps its own', function () {
    $w = rc_world();
    [$level, $arm] = rc_level($w['school'], 'Year 9', 9, [1]);
    $t = rc_term($w['source'], 1);
    $c = rc_curriculum($w['school'], $arm, $t, $w['examType']);

    // Detach the arm — the one state where there is nothing to name.
    Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('id', $c->id)->update(['class_level_arm_id' => null]);

    expect($c->fresh()->operatorLabel())->toBeNull();
});

it('the two callers still fall back DIFFERENTLY, which is why null is returned', function () {
    // THE ARM THAT PROTECTS THE REFACTOR, and it invokes the real methods rather than grepping their
    // source: sharing the assembly must not impose one caller's fallback on the other. The planner
    // identifies an unnameable class by id, because a plan has to point at something; the controller
    // renders an em dash, because a table cell reads better that way. If a future change makes
    // operatorLabel() return a fallback itself, one of these two silently changes on screen.
    $w = rc_world();
    [$level, $arm] = rc_level($w['school'], 'Year 9', 9, [1]);
    $t = rc_term($w['source'], 1);
    $c = rc_curriculum($w['school'], $arm, $t, $w['examType']);

    Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('id', $c->id)->update(['class_level_arm_id' => null]);

    $armless = $c->fresh();

    $call = function (object $target, Curriculum $curriculum): string {
        $m = new ReflectionMethod($target, 'describe');
        $m->setAccessible(true);

        return $m->invoke($target, $curriculum);
    };

    expect($call(app(RolloverPlanner::class), $armless))
        ->toBe('curriculum#'.$armless->id)
        ->and($call(app(RolloverController::class), $armless))
        ->toBe('—');
});
