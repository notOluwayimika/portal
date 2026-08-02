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

// ── Fixture commits, for the two cases history does not contain ─────────────
//
// Arms (a) below need a diff shape that has NEVER occurred in this repository: a grant addition
// alongside a migration naming only a LONGER SIBLING of the permission. There is no commit to
// replay, so those arms are FIXTURE-DRIVEN and this comment says so rather than dressing a fixture
// up as history. (Exemption 4's arm is NOT a fixture — cf9d2a2 is real. See it below.)
//
// The fixtures are still real git: commits built with plumbing (`hash-object` / `update-index` /
// `write-tree` / `commit-tree`) into the object database, which the lint then reads through exactly
// the same `git diff` and `git show <rev>:<path>` calls it uses in life. Nothing is stubbed.
//
// NOTHING IN THE REPOSITORY IS MUTATED. `GIT_INDEX_FILE` points at a scratch index, so the real
// `.git/index` is never written; no ref, no branch, no HEAD and no working-tree file is touched. The
// commits are unreferenced objects and are collected by the next `git gc`.

/** Write one blob and return its sha. */
function gclBlob(string $content): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'gcl');
    file_put_contents($tmp, $content);

    try {
        return trim(Process::path(base_path())->run(['git', 'hash-object', '-w', $tmp])->output());
    } finally {
        @unlink($tmp);
    }
}

/**
 * Build a commit from a path => content map, optionally on top of $parent (inheriting its tree).
 *
 * @param  array<string, string>  $files
 */
function gclCommit(array $files, ?string $parent = null): string
{
    $index = tempnam(sys_get_temp_dir(), 'gclidx');
    @unlink($index); // git wants to create it itself
    $git = fn (array $cmd) => Process::path(base_path())->env(['GIT_INDEX_FILE' => $index])->run($cmd);

    try {
        if ($parent !== null) {
            $git(['git', 'read-tree', $parent]);
        }

        foreach ($files as $path => $content) {
            $git(['git', 'update-index', '--add', '--cacheinfo', '100644,'.gclBlob($content).','.$path]);
        }

        $tree = trim($git(['git', 'write-tree'])->output());

        $cmd = ['git', 'commit-tree', $tree, '-m', 'gcl fixture'];
        if ($parent !== null) {
            $cmd[] = '-p';
            $cmd[] = $parent;
        }

        return trim($git($cmd)->output());
    } finally {
        @unlink($index);
    }
}

/**
 * The shared fixture BASE: both permissions already declared, the role already in ROLES, and the
 * role not yet granted the permission. So neither exemption 1 (permission is new) nor exemption 2
 * (role is new) can fire, and the only question left is exemption 3.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function gclFixtureBase(): array
{
    $enum = <<<'PHP'
<?php

enum Permission: string
{
    case ACTIVITY_LOG_VIEW = 'activity_log.view';
    case ACTIVITY_LOG_VIEW_ALL = 'activity_log.view_all';
}
PHP;

    $seeder = <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
    ];

    public static function grantsMap(): array
    {
        return [
            'auditor' => [
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            ],
        ];
    }
}
PHP;

    $files = ['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $seeder];

    return [gclCommit($files), $files];
}

/** The same seeder with `activity_log.view` granted to the pre-existing `auditor` role. */
function gclSeederWithGrant(string $seeder): string
{
    return str_replace(
        "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n",
        "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n                PermissionEnum::ACTIVITY_LOG_VIEW->value,\n",
        $seeder
    );
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

it('exemption 3 — a migration naming the permission EXACTLY exempts it; one naming only a longer sibling does NOT', function () {
    // FIXTURE-DRIVEN, and deliberately so: the sibling shape has never occurred in this repository's
    // history, so there is no commit to replay (unlike every arm above). See the block comment on the
    // fixture helpers for what is and is not real about these commits.
    //
    // The sibling half is the one that matters. `str_contains($content, $permission)` — the original
    // test — is a raw substring match, and the enum carries NINE prefix pairs today
    // (activity_log.view ⊂ .view_all/.view_own/.view_system/.view_cross_school/.view_sensitive,
    // guardian.view ⊂ .view_audit, guardian.update ⊂ .update_credentials, result.view ⊂ .view_scores,
    // student_subject.view ⊂ .view_history). Under that test, a migration naming ONLY
    // `activity_log.view_all` exempted a grant of `activity_log.view` — a SILENT GREEN in exactly the
    // class the gate exists for, which is the worst failure a gate can have because it is
    // indistinguishable from working.
    [$base, $files] = gclFixtureBase();

    $migration = fn (string $names): string => "<?php\n\n// converge: grants '{$names}' to auditor\n";
    $withGrant = ['database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php'])];

    // (i) EXACT — exempt.
    $exact = gclCommit(
        $withGrant + ['database/migrations/2099_01_01_000000_converge.php' => $migration('activity_log.view')],
        $base
    );
    $r = gclRun($base, $exact);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('activity_log.view @')
        ->and($r['output'])->toContain('in this diff names it');

    // (ii) SIBLING ONLY — must NOT exempt. Before the boundary fix this returned exit 0.
    $sibling = gclCommit(
        $withGrant + ['database/migrations/2099_01_01_000000_converge.php' => $migration('activity_log.view_all')],
        $base
    );
    $r = gclRun($base, $sibling);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('activity_log.view')
        ->and($r['output'])->toContain('that rbac:sync will NOT apply')
        // And it must not have been swallowed by the migration exemption on the way past.
        ->and($r['output'])->not->toContain('in this diff names it');
});

it('exemption 4 — an addition inside SUPER_ADMIN_PLATFORM is exempt (cf9d2a2, real history)', function () {
    // NOT a fixture. cf9d2a2 created SUPER_ADMIN_PLATFORM with four permissions that all already
    // existed, wired `'super_admin' => self::SUPER_ADMIN_PLATFORM` into grantsMap(), and added no
    // migration. Without exemption 4 the gate fires on it — and that commit is the legitimate case,
    // because the self-heal block (RbacSeeder.php:506-512) runs syncPermissions on that const
    // unconditionally, so the addition lands on the next rbac:sync with no migration at all.
    if (! gclHasCommit('cf9d2a2')) {
        $this->markTestSkipped('history not reachable (shallow clone?)');
    }

    $r = gclRun('cf9d2a2^', 'cf9d2a2');

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('inside SUPER_ADMIN_PLATFORM')
        ->and($r['output'])->toContain('rbac.impersonate')
        ->and($r['output'])->toContain('activity_log.view_cross_school');
});

it('FAILS rather than passing when it cannot resolve the base — a gate that cannot look must not be green', function () {
    // The failure mode bin/lint-changed.sh names for unresolvable paths: a green here would mean
    // "I did not look", which is worse than a red.
    $r = gclRun('definitely-not-a-ref');

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED');
});
