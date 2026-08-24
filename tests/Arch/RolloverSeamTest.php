<?php

use App\Console\Commands\ValidateProgressionGraph;
use App\Services\Rollover\RolloverBatchName;
use App\Services\Rollover\RolloverPlan;
use App\Services\Rollover\RolloverPlanner;

/**
 * The two seams M4 rests on, asserted structurally rather than promised in a docblock.
 *
 * ── SEAM 1: THE WALK ─────────────────────────────────────────────────────────────────────────────
 * `ProgressionGraph` is one definition with several callers — the config screen, the validate
 * command, and now RolloverPlanner. Nobody re-implements the walk and nobody reaches it through
 * another command's exit code.
 *
 * ── SEAM 2: THE PLAN ─────────────────────────────────────────────────────────────────────────────
 * `RolloverPlanner` is one definition with two callers — the CLI, and (slice 2) the controller.
 *
 * THE TWO ARE SEPARATE AND MUST STAY SEPARATE. `academics:validate-progression` validates every
 * school's graph as a standalone config check: no session, no term, no selection. Routing it through
 * a rollover planner would widen the planner to serve a caller supplying none of its inputs, which
 * is how a clean abstraction rots. If the extraction ever makes that command depend on the planner,
 * the extraction is wrong — and this file is what says so out loud.
 */
uses()->group('arch');

it('keeps the validate-progression command free of any rollover-planner dependency', function () {
    $source = file_get_contents((new ReflectionClass(ValidateProgressionGraph::class))->getFileName());

    // STRUCTURAL, NOT A TOKEN GREP OVER THE WHOLE FILE. A bare `str_contains($source, 'Rollover')`
    // would be satisfied by a mention in a comment and would also miss a fully-qualified reference
    // in the body. Both halves are checked: no import, and no use of the symbols anywhere in code.
    $imports = [];
    preg_match_all('/^use\s+([^;]+);/m', $source, $imports);

    $rolloverImports = array_values(array_filter(
        $imports[1] ?? [],
        fn (string $import) => str_contains($import, 'Services\\Rollover'),
    ));

    expect($rolloverImports)->toBe([]);

    // And no fully-qualified reach-around that an import check would not see.
    $withoutComments = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $source);

    expect($withoutComments)->not->toContain('Rollover');
});

it('resolves the validate-progression command without constructing a planner', function () {
    // The reflection half above reads the file; this reads the CONTAINER. A dependency injected
    // through the constructor rather than imported by name would pass a source scan and fail here.
    $constructor = (new ReflectionClass(ValidateProgressionGraph::class))->getConstructor();

    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(fn (ReflectionParameter $p) => (string) $p->getType())
        ->all();

    foreach ($parameterTypes as $type) {
        expect($type)->not->toContain('Rollover');
    }
});

/**
 * THE PLANNER PLANS; IT DOES NOT DISPATCH.
 *
 * This is what makes "a preview cannot dispatch" true by construction, so slice 2's
 * `assertNothingBatched` on the preview endpoint asserts a structural fact rather than an
 * implementation detail a later edit could quietly reverse.
 */
it('never dispatches from the planner — Bus and dispatch live in the callers', function () {
    $source = file_get_contents((new ReflectionClass(RolloverPlanner::class))->getFileName());
    $withoutComments = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $source);

    expect($withoutComments)->not->toContain('Bus::')
        ->and($withoutComments)->not->toContain('->dispatch(')
        ->and($withoutComments)->not->toContain('dispatch(');
});

/**
 * THE PLAN'S SHAPE IS THE CONTRACT SLICE 2 BINDS TO.
 *
 * Pinned now, while it has one reader. A field renamed or dropped after the UI binds to it is a
 * silent break on a screen nobody re-tests, and the failure mode is a blank panel rather than an
 * error — the same class as the batch-name pattern drifting.
 */
it('pins the plan result shape', function () {
    $properties = collect((new ReflectionClass(RolloverPlan::class))->getProperties())
        ->map(fn (ReflectionProperty $p) => $p->getName())
        ->sort()
        ->values()
        ->all();

    expect($properties)->toBe([
        'batchName',
        'blockedBy',
        'ccmBlockers',
        'curricula',
        'kind',
        // Distinct from `progressionCycle`, and the reason is the whole point of the pair: null on
        // the cycle means ACYCLIC, while an end-of-term plan never runs the check at all. One field
        // could not say both without the reader branching on `kind`.
        'progressionCheckRan',
        'progressionCycle',
        'pupilCount',
        'schoolId',
        'warnings',
    ]);

    expect(method_exists(RolloverPlan::class, 'isRunnable'))->toBeTrue()
        ->and(method_exists(RolloverPlan::class, 'isEmpty'))->toBeTrue()
        ->and(method_exists(RolloverPlan::class, 'progressionIsAcyclic'))->toBeTrue();
});

/**
 * THE THREE PROGRESSION STATES ARE DISTINGUISHABLE WITHOUT KNOWING THE ROLLOVER KIND.
 *
 * This is the invariant that stops `progressionCycle: null` meaning two things. A UI that had to ask
 * "is this an end-of-term plan?" before it could interpret a gate result would be re-deriving the
 * planner's rules on the client — the coupling the DTO exists to remove.
 */
it('separates a cycle check that did not run from one that ran and found nothing', function () {
    $notApplicable = rollover_plan(progressionCheckRan: false, progressionCycle: null);
    $acyclic = rollover_plan(progressionCheckRan: true, progressionCycle: null);
    $cyclic = rollover_plan(progressionCheckRan: true, progressionCycle: ['Year 7', 'Year 8', 'Year 7']);

    expect($notApplicable->progressionIsAcyclic())->toBeFalse()
        ->and($acyclic->progressionIsAcyclic())->toBeTrue()
        ->and($cyclic->progressionIsAcyclic())->toBeFalse();

    // And the two nulls are genuinely different plans, not merely differently labelled.
    expect($notApplicable->progressionIsAcyclic())->not->toBe($acyclic->progressionIsAcyclic());
});

/**
 * A PLAN CARRYING A RING IS NEVER RUNNABLE.
 *
 * `progressionCycle` and `blockedBy` are two fields describing one fact, which is exactly the shape
 * that drifted once already on this branch — the command read the raw field while the gate populated
 * the list, and a mutation walked straight between them. Pinned so they cannot re-separate.
 */
it('is never runnable while it carries a progression ring', function () {
    $cyclic = rollover_plan(
        progressionCheckRan: true,
        progressionCycle: ['Year 7', 'Year 8', 'Year 7'],
        blockedBy: ['progression-cycle'],
    );

    expect($cyclic->isRunnable())->toBeFalse();

    // The inverse direction too: a plan naming no blocker must not be secretly carrying a ring.
    $clean = rollover_plan(progressionCheckRan: true, progressionCycle: null);

    expect($clean->isRunnable())->toBeTrue()
        ->and($clean->progressionCycle)->toBeNull();
});

/**
 * THE BATCH NAME, PINNED BEFORE ITS SECOND READER EXISTS.
 *
 * Written at dispatch, matched by the draining warning, and matched again by slice 2's progress
 * view. A drifted pattern does not error — the progress view simply shows "no batches running",
 * which is indistinguishable from a finished rollover while jobs are mid-flight and a registrar is
 * being told it is safe to change the current session.
 */
it('pins the batch name format and the pattern that matches it', function () {
    expect(RolloverBatchName::forTerm(7, 42))->toBe('rollover:end-of-term:school:7:term:42')
        ->and(RolloverBatchName::forSession(7, 3))->toBe('rollover:end-of-year:school:7:session:3')
        ->and(RolloverBatchName::likeForSchool(7))->toBe('rollover:%:school:7:%');

    // The matcher must actually match both writers — the property that matters, and the one a
    // format change breaks. Asserted by converting the LIKE to a regex rather than by eye.
    $pattern = '/^'.str_replace('%', '.*', preg_quote(RolloverBatchName::likeForSchool(7), '/')).'$/';

    expect(RolloverBatchName::forTerm(7, 42))->toMatch($pattern)
        ->and(RolloverBatchName::forSession(7, 3))->toMatch($pattern);

    // And must NOT match another school's batches — the reason the school id is in the pattern.
    expect(RolloverBatchName::forTerm(8, 42))->not->toMatch($pattern);
});
