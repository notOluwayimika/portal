<?php

// `bin/ci-grants-convergence-lint.php` — the diff-aware gate for "a PRE-EXISTING permission added to
// RbacSeeder::grantsMap() ships a convergence migration".
//
// HOW THESE ARMS AVOID BEING WALLPAPER. The obvious way to test a diff-aware lint is to hand it a
// constructed diff, and that is exactly the thing worth refusing: a fixture diff is shaped by the same
// assumptions as the lint, so it can only confirm them. These arms instead REPLAY REAL COMMITS from
// this repository's own history — commits that predate the lint, were written by someone who had never
// heard of it, and whose outcomes are independently known:
//
//   7370e89  added `finance.access` (pre-existing since 9caf958) to `head_of_school` and `principal`,
//            with two migrations in the same commit that do NOT name it. It is the live defect: that
//            grant is STILL absent on the production copy today, which `rbac:diff-grants` shows.
//   9caf958  added 19 brand-new permission cases and granted them in the same commit, with no
//            migration. Every one of those grants landed correctly, because they were new. The lint
//            MUST pass here — a gate that fires on the legitimate case gets disabled within a week.
//   a0ab3d7  added four pre-existing finance checker permissions to `head_of_school`. History settled
//            the question independently: 01fdeda later shipped a convergence migration for exactly
//            those grants.
//
// The arms depend on git and on those SHAs being reachable, so each skips rather than lies if the
// history is not there (a shallow clone, an export). A skip is visible; a false green is not.

use Illuminate\Support\Facades\Process;

/** @return array{exit: int, output: string} */
function gclRun(string ...$args): array
{
    $result = Process::path(base_path())->run(
        array_merge(['php', 'bin/ci-grants-convergence-lint.php'], $args)
    );

    // The lint writes its report to STDERR, like its sibling lints.
    return ['exit' => $result->exitCode(), 'output' => $result->errorOutput().$result->output()];
}

function gclHasCommit(string $ref): bool
{
    return Process::path(base_path())->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}'])->successful();
}

it('fires on 7370e89 — a pre-existing permission added to two pre-existing roles, no migration naming it', function () {
    if (! gclHasCommit('7370e89')) {
        $this->markTestSkipped('history not reachable (shallow clone?)');
    }

    $r = gclRun('7370e89^', '7370e89');

    // The permission is the subject of the failure, by name — not "2 violations".
    expect($r['output'])->toContain('finance.access')
        ->and($r['exit'])->toBe(1);

    // Both roles, and the role attribution is marked INFERRED rather than asserted. head_of_school is
    // the case that matters: its `'head_of_school' => [` key is 25 lines above the added grant, so a
    // hunk-local scan would have lost it entirely.
    expect($r['output'])->toContain('head_of_school')
        ->and($r['output'])->toContain('principal')
        ->and($r['output'])->toContain('INFERRED');

    // And the three genuinely-new permissions in the SAME commit are exempted, not swept into the
    // failure. Without this the lint would be indistinguishable from "any grant addition fails".
    expect($r['output'])->toContain('finance.discount-policy.change.submit')
        ->and($r['output'])->toContain('permission is NEW in this diff');
});

it('PASSES on 9caf958 — 19 genuinely-new permissions granted in the same commit, no migration', function () {
    // The direction that matters most for the gate's survival. Exemption 1 is the legitimate case, and
    // a gate that fires on it will be switched off rather than fixed.
    if (! gclHasCommit('9caf958')) {
        $this->markTestSkipped('history not reachable (shallow clone?)');
    }

    $r = gclRun('9caf958^', '9caf958');

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('OK — no unexempted grant addition')
        ->and($r['output'])->toContain('permission is NEW in this diff');
});

it('fires on a0ab3d7 — the four pre-existing finance checker grants history later needed 01fdeda to converge', function () {
    if (! gclHasCommit('a0ab3d7')) {
        $this->markTestSkipped('history not reachable (shallow clone?)');
    }

    $r = gclRun('a0ab3d7^', 'a0ab3d7');

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('finance.fee-schedule.change.approve')
        ->and($r['output'])->toContain('finance.discount-policy.change.reject');

    // The same commit created the five finance seats, and grants to a NEW role need no migration —
    // exemption 2. Both branches are exercised by this one real commit.
    expect($r['output'])->toContain('is NEW in this diff (takes the full $permissions array)');
});

it('passes when RbacSeeder.php is not in the diff at all', function () {
    $r = gclRun('HEAD', 'HEAD');

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('is unchanged in this diff');
});

it('FAILS rather than passing when it cannot resolve the base — a gate that cannot look must not be green', function () {
    // The failure mode bin/lint-changed.sh names for unresolvable paths: a green here would mean
    // "I did not look", which is worse than a red.
    $r = gclRun('definitely-not-a-ref');

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED');
});
