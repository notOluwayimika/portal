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

/**
 * The same run, with git config entries injected into the SUBPROCESS's environment.
 *
 * `GIT_CONFIG_COUNT` / `GIT_CONFIG_KEY_n` / `GIT_CONFIG_VALUE_n` is git's own mechanism for "as if
 * `-c` had been passed", and the lint shells out to git with `shell_exec`, which inherits this
 * environment — so this reaches every git call the lint makes, which is the point. It models a
 * developer whose `~/.gitconfig` carries the setting; it does not require writing one.
 *
 * @param  array<string, string>  $config
 * @return array{exit: int, output: string}
 */
function gclRunWithGitConfig(array $config, string ...$args): array
{
    $env = ['GIT_CONFIG_COUNT' => (string) count($config)];
    $i = 0;
    foreach ($config as $key => $value) {
        $env["GIT_CONFIG_KEY_{$i}"] = $key;
        $env["GIT_CONFIG_VALUE_{$i}"] = $value;
        $i++;
    }

    $result = Process::path(base_path())->env($env)->run(
        array_merge(['php', 'bin/ci-grants-convergence-lint.php'], $args)
    );

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
 * $removes deletes paths from the inherited tree — `--force-remove` acts on the SCRATCH index like
 * every other call here, so it removes a path from the fixture commit and never from the checkout.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $removes
 */
function gclCommit(array $files, ?string $parent = null, array $removes = []): string
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

        foreach ($removes as $path) {
            $git(['git', 'update-index', '--force-remove', $path]);
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

/**
 * The TWO-role fixture base: `auditor` and `bursar` both pre-existing, both already holding
 * `activity_log.view_all`, neither holding `activity_log.view`. Exemptions 1 and 2 are unreachable
 * for both, so the only question left is exemption 3 — and it is asked about TWO pairs at once,
 * which is what makes "a migration that declares one role must not exempt the other" testable.
 *
 * @return array{0: string, 1: string} [base commit sha, seeder source]
 */
function gclTwoRoleBase(): array
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
        'bursar',
    ];

    public static function grantsMap(): array
    {
        return [
            'auditor' => [
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            ],
            'bursar' => [
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            ],
        ];
    }
}
PHP;

    return [
        gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $seeder]),
        $seeder,
    ];
}

/** The two-role seeder with `activity_log.view` granted to BOTH pre-existing roles. */
function gclTwoRoleWithGrant(string $seeder): string
{
    return str_replace(
        "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n",
        "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n                PermissionEnum::ACTIVITY_LOG_VIEW->value,\n",
        $seeder
    );
}

/**
 * Split a FAILING run's output at the exemption header, so an arm can assert what is in the failure
 * block and what is in the exemption block separately. A bare `toContain('auditor')` over the whole
 * output cannot tell "flagged" from "exempt", and that distinction is the entire subject of the
 * marker arms.
 *
 * @return array{0: string, 1: string} [failures, exemptions] — exemptions is '' when there are none
 */
function gclSplit(string $output): array
{
    $at = strpos($output, 'were EXEMPT');

    return $at === false ? [$output, ''] : [substr($output, 0, $at), substr($output, $at)];
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

it('exemption 3 — a migration declaring the pair EXACTLY exempts it; one declaring only a longer sibling does NOT', function () {
    // FIXTURE-DRIVEN, and deliberately so: the sibling shape has never occurred in this repository's
    // history, so there is no commit to replay (unlike every arm above). See the block comment on the
    // fixture helpers for what is and is not real about these commits.
    //
    // THE MECHANISM UNDER THIS ARM CHANGED, and the arm was kept rather than deleted. It used to test
    // a boundary regex over the migration's free text: the enum carries NINE prefix pairs
    // (activity_log.view ⊂ .view_all/.view_own/.view_system/.view_cross_school/.view_sensitive,
    // guardian.view ⊂ .view_audit, guardian.update ⊂ .update_credentials, result.view ⊂ .view_scores,
    // student_subject.view ⊂ .view_history), so a raw substring test exempted a grant of
    // `activity_log.view` on a migration naming only `activity_log.view_all`. Exemption 3 no longer
    // reads free text at all — it reads an `@converges <role> <permission>` declaration and compares
    // the pair by EXACT EQUALITY, so the prefix-pair hole is closed by construction rather than by a
    // boundary. Do not re-derive the prefix-pair argument from this arm; what it now pins is that
    // equality really is equality, which is the property a future rewrite could still break.
    [$base, $seeder] = gclTwoRoleBase();

    $migration = fn (string $permission): string => "<?php\n\n// @converges auditor {$permission}\n";
    $withGrant = ['database/seeders/RbacSeeder.php' => gclTwoRoleWithGrant($seeder)];

    // (i) EXACT — exempt. (bursar is flagged in the same run; this half is about auditor.)
    $exact = gclCommit(
        $withGrant + ['database/migrations/2099_01_01_000000_converge.php' => $migration('activity_log.view')],
        $base
    );
    $r = gclRun($base, $exact);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)   // bursar is unconverged — that is arm 4c's subject, not this one
        ->and($exemptions)->toContain('declares @converges auditor activity_log.view')
        ->and($failures)->toContain('role: bursar')
        ->and($failures)->not->toContain('role: auditor');

    // (ii) SIBLING ONLY — must NOT exempt auditor either. `activity_log.view_all` is a different
    //      pair, and a declaration of it says nothing about `activity_log.view`.
    $sibling = gclCommit(
        $withGrant + ['database/migrations/2099_01_01_000000_converge.php' => $migration('activity_log.view_all')],
        $base
    );
    $r = gclRun($base, $sibling);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('activity_log.view')
        ->and($r['output'])->toContain('that rbac:sync will NOT apply')
        // Nothing was exempted at all — not auditor, not bursar.
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('declares @converges')
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->toContain('role: bursar');
});

// ── The five holes the cold review of 79798c8...85805da found, armed permanently ─────────────
//
// Every one of these was a SILENT GREEN or a false red before the fix, and every arm below is
// written so that reverting its fix turns the arm red rather than merely changing a message.

it('4a — a ROLES member that is not shaped like a role name is NOT LINTED, not silently trusted', function () {
    // THE BACKSTOP, armed at the level of the defect class rather than at the level of apostrophes.
    // constMembers() used a floating `['"]([^'"]+)['"]` quote-pair scan over the raw const body, so
    // one apostrophe in a comment flipped the pairing and produced junk members. token_get_all()
    // makes that specific class impossible — but this arm is the assertion that would have caught
    // the original defect without anyone having had to think of apostrophes first: a member of
    // ROLES that is not `[a-z0-9_]+` means the parse is unsound, whatever broke it, and exemption 2
    // cannot be trusted on an unsound parse.
    //
    // Before the fix this fixture exits 0 — the grant is exempted as a new role, on a ROLES list
    // the lint had no way to know it had misread.
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
    public const ROLES = [
        'auditor',
        'Finance Seats',
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

    $base = gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $seeder]);

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => str_replace(
            ["        'Finance Seats',\n", "            'auditor' => [\n                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n            ],\n"],
            ["        'Finance Seats',\n        'bursar',\n", "            'auditor' => [\n                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n            ],\n            'bursar' => [\n                PermissionEnum::ACTIVITY_LOG_VIEW->value,\n            ],\n"],
            $seeder
        ),
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED')
        ->and($r['output'])->toContain('not a role name')
        ->and($r['output'])->toContain('Finance Seats')
        // And it must not have quietly exempted its way there instead.
        ->and($r['output'])->not->toContain('is NEW in this diff');
});

it('4a EXPLOITED — rewording an apostrophe-bearing comment in ROLES must not manufacture a new role', function () {
    // THE LIVE EXPLOIT, built from the REAL RbacSeeder.php rather than a synthetic one, because the
    // apostrophe that carries it is real: `// Primary's senior commenter` inside RbacSeeder::ROLES.
    // The measured parse under the old scanner lost `key_stage_coordinator` and `registrar` and
    // produced three junk members in their place. Both sides of a diff garbled identically, so
    // nothing fired — until a diff REWORDED the comment. Then head parses correctly, base does not,
    // and array_diff() hands exemption 2 two roles that have existed for months.
    //
    // Before the fix: exit 0, `role [key_stage_coordinator] is NEW in this diff`. After: exit 1.
    $seeder = file_get_contents(base_path('database/seeders/RbacSeeder.php'));
    $enum = file_get_contents(base_path('app/Enums/Permission.php'));

    $comment = "// Primary's senior commenter";
    $anchor = "PermissionEnum::MANAGE_KEY_STAGE_COORDINATOR_COMMENTS->value,\n";

    // Fail loudly if the exploit's preconditions have gone away, rather than passing vacuously on a
    // file that no longer contains what this arm is about. If the apostrophe comment is ever
    // reworded for real, re-derive this arm against whatever carries the parity flip then — do not
    // delete it, and do not let it decay into asserting nothing.
    expect(str_contains($seeder, $comment))->toBeTrue()
        ->and(str_contains($seeder, $anchor))->toBeTrue();

    $base = gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $seeder]);

    // The two edits a real diff would make: reword the comment (no apostrophe), and grant
    // key_stage_coordinator a permission that has existed since long before this diff.
    $headSeeder = str_replace(
        [$comment, $anchor],
        ['// Primary senior commenter', $anchor.'                PermissionEnum::ACTIVITY_LOG_EXPORT->value,'."\n"],
        $seeder
    );

    $head = gclCommit(['database/seeders/RbacSeeder.php' => $headSeeder], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('activity_log.export')
        ->and($r['output'])->toContain('key_stage_coordinator')
        ->and($r['output'])->toContain('that rbac:sync will NOT apply')
        // The exemption that must NOT have fired. This is the whole arm.
        ->and($r['output'])->not->toContain('is NEW in this diff (takes the full');
});

it('4b — an unreadable enum or seeder at either revision is NOT LINTED, never a pass', function () {
    // `git()` is shell_exec with 2>/dev/null, so an unreadable revision or a moved file returns ''
    // and every parser turns '' into an EMPTY SET. Each empty set exempted, silently:
    //   head enum gone -> no added line resolves to a permission  -> zero findings, forever
    //   base enum gone -> every value at head is "new"            -> exemption 1 exempts everything
    // The lint already stated the right rule for an unresolvable base ref and did not apply it here.
    [$base, $files] = gclFixtureBase();
    $withGrant = ['database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php'])];

    // (i) The enum has moved out from under the lint at HEAD. Before the fix: exit 0, zero findings,
    //     and it would have stayed exit 0 on every future diff too.
    $headNoEnum = gclCommit($withGrant, $base, ['app/Enums/Permission.php']);
    $r = gclRun($base, $headNoEnum);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED')
        ->and($r['output'])->toContain('app/Enums/Permission.php')
        ->and($r['output'])->not->toContain('no unexempted grant addition');

    // (ii) The enum is unreadable at BASE. Before the fix: exemption 1 swallowed every addition.
    $baseNoEnum = gclCommit(['database/seeders/RbacSeeder.php' => $files['database/seeders/RbacSeeder.php']]);
    $headFromIt = gclCommit($withGrant + ['app/Enums/Permission.php' => $files['app/Enums/Permission.php']], $baseNoEnum);
    $r = gclRun($baseNoEnum, $headFromIt);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED')
        ->and($r['output'])->not->toContain('permission is NEW in this diff');

    // (iii) The SEEDER itself has moved. This one sat ABOVE the unchanged-diff early return: a
    //       seeder renamed out from under SEEDER is indistinguishable, at that return, from a
    //       seeder nobody edited — so the old lint said "unchanged in this diff" and exited 0.
    $headNoSeeder = gclCommit([], $base, ['database/seeders/RbacSeeder.php']);
    $r = gclRun($base, $headNoSeeder);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED')
        ->and($r['output'])->not->toContain('is unchanged in this diff');
});

it('4c — a migration converging role A does NOT exempt the same permission added to role B', function () {
    // 7370e89, this lint's own canonical defect, added `finance.access` to head_of_school AND
    // principal. Exemption 3 stopped at the first added migration whose CONTENT named the
    // permission, with no check that it converged the ROLE — so a migration converging one of the
    // two would have exempted both. A convergence migration is per (role, permission); so is the
    // exemption now.
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
        'bursar',
    ];

    public static function grantsMap(): array
    {
        return [
            'auditor' => [
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            ],
            'bursar' => [
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            ],
        ];
    }
}
PHP;

    $base = gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $seeder]);

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => str_replace(
            "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n",
            "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n                PermissionEnum::ACTIVITY_LOG_VIEW->value,\n",
            $seeder
        ),
        // Declares the pair (auditor, activity_log.view). Declares nothing about bursar.
        'database/migrations/2099_01_01_000000_converge_auditor.php' => "<?php\n\n// @converges auditor activity_log.view\n",
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        // bursar is flagged...
        ->and($r['output'])->toContain('bursar')
        ->and($r['output'])->toContain('that rbac:sync will NOT apply')
        // ...and auditor is still exempt in the same run, by the migration that does declare it.
        ->and($r['output'])->toContain('declares @converges auditor activity_log.view');

    // The failure block must name bursar, not auditor — otherwise the arm would pass on a lint that
    // simply flags everything.
    [$failures] = gclSplit($r['output']);
    expect($failures)->toContain('role: bursar')
        ->and($failures)->not->toContain('role: auditor');
});

it('4d — the SUPER_ADMIN_PLATFORM range is bounded at both ends', function () {
    $enum = <<<'PHP'
<?php

enum Permission: string
{
    case ACTIVITY_LOG_VIEW = 'activity_log.view';
    case ACTIVITY_LOG_VIEW_ALL = 'activity_log.view_all';
}
PHP;

    // (i) SINGLE-LINE CONST — the window must not run on to the next array's terminator.
    //     `= ['a'];` never matches /^\s*\];/ on its own line, so the old scan kept going. Measured
    //     on the real seeder, collapsing the const to one line grew the window from 5 lines to 31.
    //     Here the next `];` is grantsMap()'s, so the window swallowed the entire map and the added
    //     grant below was exempted as "inside SUPER_ADMIN_PLATFORM". Before the fix: exit 0.
    $collapsed = <<<'PHP'
<?php

class RbacSeeder
{
    public const ROLES = [
        'auditor',
    ];

    public const SUPER_ADMIN_PLATFORM = ['activity_log.view_all'];

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

    $base = gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $collapsed]);
    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => str_replace(
            "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n",
            "                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n                PermissionEnum::ACTIVITY_LOG_VIEW->value,\n",
            $collapsed
        ),
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('activity_log.view')
        ->and($r['output'])->not->toContain('inside SUPER_ADMIN_PLATFORM');

    // (ii) AN EARLIER TEXTUAL MENTION — a docblock naming the const must not anchor the window to
    //      itself. Under `str_contains($line, 'const SUPER_ADMIN_PLATFORM')` the window ran from the
    //      docblock to ROLES' `];`, so the const's real members fell OUTSIDE it and a legitimate
    //      super_admin addition was FLAGGED. That is the safe direction but it is still wrong, and a
    //      gate that fires on the legitimate case is a gate that gets switched off. Before the fix:
    //      exit 1. After: exit 0, exempt.
    $documented = <<<'PHP'
<?php

/**
 * Platform grants live in const SUPER_ADMIN_PLATFORM, below the role list.
 */
class RbacSeeder
{
    public const ROLES = [
        'auditor',
    ];

    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public static function grantsMap(): array
    {
        return [
            'auditor' => [
                PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            ],
            'super_admin' => self::SUPER_ADMIN_PLATFORM,
        ];
    }
}
PHP;

    $base = gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $documented]);
    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => str_replace(
            "        'activity_log.view_all',\n",
            "        'activity_log.view_all',\n        'activity_log.view',\n",
            $documented
        ),
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('inside SUPER_ADMIN_PLATFORM');
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

// ── The `@converges` marker: exemption 3 reads a DECLARATION, never prose ─────────────
//
// The third repair to exemption 3 for one class of defect (d08edf0 substring, dde75e4 silent greens,
// this one prose). The predicate that was replaced asked whether a migration's raw text named the
// permission and named the role, both boundary-matched — and it could not tell an ASSERTION from a
// MENTION. Measured on the real 2026_08_05_100000_converge_finance_access_grants.php, it was true
// for NINE of the fourteen roles: `internal_auditor` off the sentences saying it must NOT receive
// the permission, `registrar` off the words "registrar cache flushed after". That is not a wording
// bug in one migration — documenting the exclusions is the right thing for a migration to do, so
// every future one carries the same property.
//
// Each arm below is written so that REVERTING the marker fix turns it red, not merely changes a
// message. Arms 1, 2 and 3 all exit 0 under the free-text predicates.

it('MARKER 1 — PROSE IS NOT A DECLARATION: naming a role it EXCLUDES must not exempt that role', function () {
    [$base, $seeder] = gclTwoRoleBase();

    // Exactly the shape a real convergence migration has: one declared pair, and a docblock that
    // documents the role deliberately left out. Under the old predicates BOTH roles were exempt and
    // this returned exit 0 — the migration was a blanket exemption carrier for every role it named.
    $migration = <<<'PHP'
<?php

/**
 * Converges `activity_log.view` onto the auditor seat.
 *
 * @converges auditor activity_log.view
 *
 * `bursar` deliberately does NOT receive `activity_log.view` — that is a separate decision and this
 * migration must not grant it. activity_log.view is granted to auditor and to nobody else.
 */
PHP;

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclTwoRoleWithGrant($seeder),
        'database/migrations/2099_01_01_000000_converge_auditor.php' => $migration,
    ], $base);

    $r = gclRun($base, $head);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and($failures)->toContain('role: bursar')
        ->and($failures)->not->toContain('role: auditor')
        // ...and the declared pair is still exempt in the same run.
        ->and($exemptions)->toContain('declares @converges auditor activity_log.view')
        ->and($exemptions)->not->toContain('bursar');
});

it('MARKER 2 — NO MARKER AT ALL: a migration that only names the pair in prose exempts nothing', function () {
    // The direct regression arm for the old behaviour. This exact fixture was exit 0 before.
    [$base, $files] = gclFixtureBase();

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php']),
        'database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// Converges activity_log.view for the auditor role.\n",
    ], $base);

    $r = gclRun($base, $head);
    [, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('that rbac:sync will NOT apply')
        ->and($r['output'])->toContain('activity_log.view')
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('declares @converges');
});

it('MARKER 3 — TRAILING PROSE DOES NOT SMUGGLE: a tail on the marker line declares NOTHING, not the first pair', function () {
    // THE `$` ANCHOR is what this arm pins, and it is worth being exact about which character does
    // the work. Swapping `[ \t]` for `\s` does NOT open this hole — prose is not whitespace, so
    // `\s*$` cannot cross it either (measured; `\s` opens a different hole, assembling a marker from
    // two lines, which is why the pattern stays tight). Drop the `$` and this line declares
    // (auditor, activity_log.view) while reading to a human as though it declared bursar too. With
    // the anchor, the whole line fails to match and NEITHER role is exempt — the safe direction.
    [$base, $seeder] = gclTwoRoleBase();

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclTwoRoleWithGrant($seeder),
        'database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// @converges auditor activity_log.view and also bursar\n",
    ], $base);

    $r = gclRun($base, $head);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        // BOTH roles flagged — declaring nothing, not declaring auditor.
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->toContain('role: bursar')
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('declares @converges')
        // The line is not silently dropped either: it mentions @converges and parsed as nothing, so
        // the unparsed-marker notice names it. See MARKER 8 for the mechanism.
        ->and($r['output'])->toContain('did not parse as a declaration')
        ->and($r['output'])->toContain('2099_01_01_000000_converge.php');
});

it('MARKER 4 — MULTI-PAIR: one migration declaring two pairs exempts both in a single run', function () {
    [$base, $seeder] = gclTwoRoleBase();

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclTwoRoleWithGrant($seeder),
        'database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// @converges auditor activity_log.view\n// @converges bursar activity_log.view\n",
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('OK — no unexempted grant addition')
        ->and($r['output'])->toContain('declares @converges auditor activity_log.view')
        ->and($r['output'])->toContain('declares @converges bursar activity_log.view');
});

it('MARKER 5 — DOCBLOCK LEAD-IN: ` * @converges …` exempts identically to a `//` line', function () {
    // The form every real migration will use, since the marker belongs in the file's own docblock.
    [$base, $files] = gclFixtureBase();

    $migration = <<<'PHP'
<?php

/**
 * Converges the auditor read seat.
 *
 * @converges auditor activity_log.view
 */
PHP;

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php']),
        'database/migrations/2099_01_01_000000_converge.php' => $migration,
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('declares @converges auditor activity_log.view');
});

it('MARKER 6 — an unrecognised marker is ECHOED on the failing path, and exempts nothing', function () {
    // A typo already fails the run by not exempting, so the red is there either way; the echo is what
    // stops the author staring at a migration they believe declares the pair. It is a MESSAGE, not a
    // gate — asserted here only because it costs one fixture.
    [$base, $files] = gclFixtureBase();
    $withGrant = ['database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php'])];

    // (i) The ROLE is misspelled.
    $badRole = gclCommit(
        $withGrant + ['database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// @converges bursor activity_log.view\n"],
        $base
    );
    $r = gclRun($base, $badRole);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('declares @converges bursor activity_log.view — no such role')
        ->and($r['output'])->toContain('that rbac:sync will NOT apply');

    // (ii) The PERMISSION is misspelled.
    $badPermission = gclCommit(
        $withGrant + ['database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// @converges auditor activity_log.viewww\n"],
        $base
    );
    $r = gclRun($base, $badPermission);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('declares @converges auditor activity_log.viewww — no such permission');
});

it('MARKER 7 / F9 — a `?` role is NOT exemptible by any marker, and the failure names a PARSER GAP', function () {
    // THE INVARIANT, UNCHANGED: exemption 3 is a lookup on (role, permission), so a finding whose
    // role could not be inferred has nothing to look up and no marker can clear it.
    //
    // WHAT CHANGED IS THE FIXTURE, AND WHY. This arm used to be built on the fragment case — a
    // fragment defined above `return [` and spliced into two pre-existing roles. Fragment additions
    // are now fanned out to every role that spreads them and arrive with REAL role names, so that
    // fixture no longer yields a `?` at all and this arm would have stayed green while asserting
    // nothing its name claims. It is rebuilt here on the RESIDUAL `?`: a shape above `return [` that
    // sits inside no fragment the table could resolve. That is a parser gap, and it must still flag.
    //
    // The assertions on the literals are the only thing stopping the wrong instruction from creeping
    // back. Two revisions of this heredoc have now told a `?`-role author something unfollowable:
    // first "declare a line for every pre-existing role it spreads to" (ship the markers, re-run,
    // byte-identical red), then "ATTRIBUTE IT — move the addition under a `'<role>' => [` key, or
    // regroup the fragments beneath one" — which manufactures the silent green $inferRole's own
    // docblock warns about. Both are asserted ABSENT. Do not soften `not->toContain('ATTRIBUTE IT')`.
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
    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
            'bursar' => [
                ...$activityStaff,
            ],
        ];
    }
}
PHP;

    $base = gclCommit(['app/Enums/Permission.php' => $enum, 'database/seeders/RbacSeeder.php' => $seeder]);

    // The added line sits above `return [` and is NOT a `$name = [` fragment, so the fragment table
    // cannot resolve it and there is nothing to fan out to. Scanning backwards reaches neither a
    // `'<role>' => [` key nor `return [`, so the role is null.
    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => str_replace(
            "        return [\n",
            "        \$extra = array_merge(\$activityStaff, [PermissionEnum::ACTIVITY_LOG_VIEW->value]);\n\n        return [\n",
            $seeder
        ),
        // The migration does everything the author could be asked to do: two syntactically valid
        // markers, one per pre-existing role. It still exempts nothing.
        'database/migrations/2099_01_01_000000_converge_fragment.php' => "<?php\n\n/**\n * @converges auditor activity_log.view\n * @converges bursar activity_log.view\n */\n",
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('role: ?')
        // The markers are valid, so they are neither exempt nor flagged as unrecognised — the
        // migration is not cited anywhere in the run.
        ->and($r['output'])->not->toContain('2099_01_01_000000_converge_fragment.php')
        ->and($r['output'])->not->toContain('were EXEMPT')
        // The remedy names the real cause and does NOT send the author to restructure the seeder.
        ->and($r['output'])->toContain('parser gap')
        ->and($r['output'])->not->toContain('ATTRIBUTE IT');
});

it('MARKER 8 — a marker the parser cannot READ is reported, not swallowed', function () {
    // $unknownMarkers covers typo'd OPERANDS (a role or permission absent at head). It cannot cover
    // typo'd SYNTAX: a line that fails the pattern never becomes a marker at all. Measured, four
    // good-faith shapes fail that way — CRLF endings, a one-line `/** … */`, a wrapped line, and a
    // tail after the permission — so the notice counts `@converges` MENTIONS against PARSES rather
    // than special-casing each shape. One arm per mechanism: the one-line docblock stands for the
    // class, and MARKER 3 already pins the tail.
    [$base, $files] = gclFixtureBase();

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php']),
        'database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n/** @converges auditor activity_log.view */\n",
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        // It declared nothing...
        ->and($r['output'])->toContain('that rbac:sync will NOT apply')
        ->and($r['output'])->not->toContain('declares @converges')
        // ...and the author is told that, rather than left staring at a file they believe declares it.
        ->and($r['output'])->toContain('did not parse as a declaration')
        ->and($r['output'])->toContain('2099_01_01_000000_converge.php');
});

it('MARKER 9 — a marker added to a migration that is NOT new in the diff exempts nothing, and says why', function () {
    // TWO SEPARATE CLAIMS, and only the second is a defect the lint had.
    //
    // (a) It must not exempt. A migration already on the base has already RUN on every seeded
    //     environment, so a marker added to it declares a convergence nothing performed. Exempting
    //     on it would be a false green of exactly the class this gate exists to stop — which is why
    //     $addedMigrations is `--diff-filter=A` and must stay that way. This half is the RULE.
    // (b) It must say so. The author wrote a byte-perfect marker and got a byte-identical red;
    //     $unparsedMarkers cannot explain it, because that walks $addedMigrations too. Same dead end
    //     the `?`-role remedy was rewritten to remove, reached by a different route.
    //
    // The fixture is the real workflow: the migration exists on the base WITHOUT a marker, and the
    // branch edits it to add one. That is what puts it in `--diff-filter=M`.
    [$base, $files] = gclFixtureBase();

    $migrationPath = 'database/migrations/2098_01_01_000000_already_shipped.php';

    $base = gclCommit($files + [$migrationPath => "<?php\n\n// converges the auditor read seat\n"], null);

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php']),
        $migrationPath => "<?php\n\n// converges the auditor read seat\n// @converges auditor activity_log.view\n",
    ], $base);

    $r = gclRun($base, $head);
    [, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        // (a) not exempt, and not cited as one.
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('declares @converges')
        // (b) and the author is told which file and why.
        ->and($r['output'])->toContain('sit on a migration that is not new in this diff')
        ->and($r['output'])->toContain($migrationPath)
        ->and($r['output'])->toContain('Write a NEW convergence migration');
});

it('MARKER 9b — a marker ALREADY on the base is not reported when the branch touches that file for other reasons', function () {
    // THE SIBLING THAT MAKES MARKER 9 MEAN SOMETHING. In MARKER 9 the marker is both present-at-head
    // AND added-in-diff, so that arm is green whether the notice counts the file at head or counts
    // the patch — it proves the rule half, not the notice half. Here the marker is present-at-head
    // and NOT added, which separates the two implementations.
    //
    // The notice's words assert an author action ("a marker added to it"). Counting `git show
    // $head:<path>` cannot observe that action, so it accuses an author who did nothing — and the
    // triggering shape is ordinary: a comment-only docblock edit to a shipped convergence migration
    // is `M` and carries its old markers. This branch did exactly that to two real migrations.
    [$base, $files] = gclFixtureBase();

    $migrationPath = 'database/migrations/2098_01_01_000000_already_shipped.php';
    $shipped = "<?php\n\n// @converges auditor activity_log.view\n// converges the auditor read seat\n";

    $base = gclCommit($files + [$migrationPath => $shipped], null);

    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclSeederWithGrant($files['database/seeders/RbacSeeder.php']),
        // The unrelated touch: one comment line reworded. The marker line is byte-identical.
        $migrationPath => str_replace('// converges the auditor read seat', '// converges the auditor seat', $shipped),
    ], $base);

    $r = gclRun($base, $head);
    [, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        // The rule half is unchanged: a marker on a migration already on the base exempts nothing.
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('declares @converges')
        // ...and the notice stays silent, because no marker was added in this diff.
        ->and($r['output'])->not->toContain('sit on a migration that is not new in this diff')
        ->and($r['output'])->not->toContain($migrationPath);
});

it('MARKER 10 — a hostile git config cannot change the verdict (forced colour, external diff driver)', function () {
    // THE GATE WAS GREEN BY LUCK. `shell_exec` gives git no tty, so the default `color.ui=auto`
    // leaves colour off — and that, not any decision, is why the `^+` line tests ever worked.
    // `color.ui=always` overrides auto and every added line arrives as `\e[32m+…`; `diff.external`
    // replaces the diff wholesale. Either one silently yields zero added lines, which this lint reads
    // as "no additions" and reports as OK. Measured before the fix on `7370e89`, its own canonical
    // red: exit 1 with two findings by default, exit 0 with ZERO under either setting.
    //
    // The enforcement floor here is permanently local (CLAUDE.md) — there is no remote check to
    // disagree — so one line in one developer's `~/.gitconfig` was the whole of it. This arm is the
    // only thing that keeps `git()`'s guard from being quietly dropped.
    [$base, $seeder] = gclTwoRoleBase();
    $head = gclCommit(['database/seeders/RbacSeeder.php' => gclTwoRoleWithGrant($seeder)], $base);

    $plain = gclRun($base, $head);

    expect($plain['exit'])->toBe(1)
        ->and($plain['output'])->toContain('role: auditor')
        ->and($plain['output'])->toContain('role: bursar');

    // `/bin/echo` rather than a missing binary on purpose: a driver that FAILS makes git die loudly,
    // while one that SUCCEEDS and prints something else is the silent-green shape — git exits 0 and
    // the diff simply is not there.
    foreach ([
        ['color.ui' => 'always'],
        ['diff.external' => '/bin/echo'],
        ['color.ui' => 'always', 'diff.external' => '/bin/echo'],
    ] as $config) {
        $r = gclRunWithGitConfig($config, $base, $head);

        expect($r['exit'])->toBe($plain['exit'])
            ->and($r['output'])->toContain('role: auditor')
            ->and($r['output'])->toContain('role: bursar')
            ->and($r['output'])->not->toContain('OK — no unexempted grant addition');
    }
});

it('FAILS rather than passing when it cannot resolve the base — a gate that cannot look must not be green', function () {
    // The failure mode bin/lint-changed.sh names for unresolvable paths: a green here would mean
    // "I did not look", which is worse than a red.
    $r = gclRun('definitely-not-a-ref');

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED');
});

// ── FRAGMENT RESOLUTION (F1–F10) ─────────────────────────────────────────────
//
// The two defects these arms pin, both inside this gate's own defect class:
//
//   A. THE SILENT GREEN. An added `...$fragment,` line resolved ZERO permissions. Permission
//      resolution accepts `PermissionEnum::X->value` and a quoted string that is a real enum value
//      at head; a spread line is neither. So an added spread of a PRE-EXISTING fragment into a
//      PRE-EXISTING role granted every permission in that fragment, `rbac:sync` granted none of
//      them, and the gate exited 0. Not exotic: `grantsMap()['admin']` opens with SIX consecutive
//      spreads before its first literal permission, and `'head_of_school'` repeats the same six.
//   B. THE DEAD END. A permission added to a fragment's own DEFINITION (above `return [`) resolved
//      fine, but `$inferRole` scanned backwards, hit `return [`, and returned null. The role printed
//      `?`, which disables exemptions 2 and 3 — so the addition was flagged with NO path to green,
//      and the remedy text told the author to regroup the fragments beneath a role key, which is the
//      silent green `$inferRole`'s own docblock warns about.
//
// These fixtures are the same harness as everything above (gclCommit / gclBlob into the object
// database, scratch index, nothing in the repository mutated). No second harness was built.

/**
 * The enum every fragment arm shares: three permissions, all PRE-EXISTING on both sides of every
 * diff below. That is what makes exemption 1 unreachable, so each arm asks about the fragment
 * machinery alone rather than about "the permission is new".
 */
function gclFragmentEnum(): string
{
    return <<<'PHP'
<?php

enum Permission: string
{
    case ACTIVITY_LOG_VIEW = 'activity_log.view';
    case ACTIVITY_LOG_VIEW_ALL = 'activity_log.view_all';
    case ACTIVITY_LOG_EXPORT = 'activity_log.export';
}
PHP;
}

/** Commit a seeder (plus the fragment enum) as a standalone base. */
function gclFragmentBase(string $seeder): string
{
    return gclCommit([
        'app/Enums/Permission.php' => gclFragmentEnum(),
        'database/seeders/RbacSeeder.php' => $seeder,
    ]);
}

/** One fragment, spread into `auditor` only. `bursar` holds a literal. */
function gclOneSpreadSeeder(): string
{
    return <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
            'bursar' => [
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            ],
        ];
    }
}
PHP;
}

/** The same seeder with `...$activityStaff,` ALSO spread into the pre-existing `bursar`. */
function gclWithSpreadIntoBursar(string $seeder): string
{
    return str_replace(
        "                PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n",
        "                ...\$activityStaff,\n                PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n",
        $seeder
    );
}

/** One fragment, spread into BOTH pre-existing roles — the fan-out shape. */
function gclTwoSpreadSeeder(): string
{
    return <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
            'bursar' => [
                ...$activityStaff,
            ],
        ];
    }
}
PHP;
}

/** Add a PRE-EXISTING permission to the fragment's own DEFINITION. */
function gclWithPermissionInFragment(string $seeder): string
{
    return str_replace(
        "            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n        ];\n",
        "            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n            PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n        ];\n",
        $seeder
    );
}

/** Count the ✗ finding markers in a run's output. */
function gclFindingCount(string $output): int
{
    return substr_count($output, "\u{2717}");
}

it('F1 — an ADDED spread into a PRE-EXISTING role flags every permission in the fragment, by real role name', function () {
    // DEFECT A, armed. Before the fix this fixture is exit 0 with zero findings: the added line is
    // `...$activityStaff,`, which resolves to no permission under either accepted form, while
    // rbac:sync grants neither of the two permissions it actually carries.
    $base = gclFragmentBase(gclOneSpreadSeeder());
    $head = gclCommit(
        ['database/seeders/RbacSeeder.php' => gclWithSpreadIntoBursar(gclOneSpreadSeeder())],
        $base
    );

    $r = gclRun($base, $head);
    [$failures] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and(gclFindingCount($r['output']))->toBe(2)
        ->and($failures)->toContain('activity_log.view')
        ->and($failures)->toContain('activity_log.view_all')
        // The role is REAL, not `?` — a spread sits inside a role key, so $inferRole resolves it.
        ->and($failures)->toContain('role: bursar')
        ->and($failures)->not->toContain('role: ?')
        // ...and auditor is untouched: only the ADDED spread is a finding source.
        ->and($failures)->not->toContain('role: auditor');
});

it('F2 — F1 plus a migration declaring ALL the pairs is exempt in full', function () {
    // Exemption 3, unchanged, applied to fan-out findings. Ten grants means ten marker lines; this
    // is the two-permission case of that rule.
    $base = gclFragmentBase(gclOneSpreadSeeder());
    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclWithSpreadIntoBursar(gclOneSpreadSeeder()),
        'database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// @converges bursar activity_log.view\n// @converges bursar activity_log.view_all\n",
    ], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('OK — no unexempted grant addition')
        ->and($r['output'])->toContain('declares @converges bursar activity_log.view')
        ->and($r['output'])->toContain('declares @converges bursar activity_log.view_all');
});

it('F3 — F1 plus a migration declaring all but ONE pair fails naming exactly that pair', function () {
    // The per-pair property, on the fan-out. A fragment-level marker would exempt both here; there
    // is deliberately no such marker (the fragment's contents change after the migration is written,
    // so it would exempt permissions the migration never granted).
    $base = gclFragmentBase(gclOneSpreadSeeder());
    $head = gclCommit([
        'database/seeders/RbacSeeder.php' => gclWithSpreadIntoBursar(gclOneSpreadSeeder()),
        'database/migrations/2099_01_01_000000_converge.php' => "<?php\n\n// @converges bursar activity_log.view\n",
    ], $base);

    $r = gclRun($base, $head);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and(gclFindingCount($r['output']))->toBe(1)
        ->and($failures)->toContain('activity_log.view_all')
        ->and($exemptions)->toContain('declares @converges bursar activity_log.view');
});

it('F4 — a permission added to a fragment DEFINITION fans out to every spreading role, with no `?`', function () {
    // DEFECT B, armed. Before the fix this is ONE finding with `role: ?` and no path to green:
    // exemption 3 is a lookup on (role, permission) and there is no role to look up.
    $base = gclFragmentBase(gclTwoSpreadSeeder());
    $head = gclCommit(
        ['database/seeders/RbacSeeder.php' => gclWithPermissionInFragment(gclTwoSpreadSeeder())],
        $base
    );

    $r = gclRun($base, $head);
    [$failures] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and(gclFindingCount($r['output']))->toBe(2)
        ->and($failures)->toContain('activity_log.export')
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->toContain('role: bursar')
        // The whole point of the change: each finding is attributable, so each is markable.
        ->and($failures)->not->toContain('role: ?');
});

it('F5 — F4 where one spreading role is NEW in the diff: that one exempt by 2, the other flagged', function () {
    // Exemption 2 reaching a fan-out finding. `bursar` is created in this diff, so it takes the full
    // $permissions array and needs no migration; `auditor` is pre-existing and needs one.
    $base = gclFragmentBase(<<<'PHP'
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
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
        ];
    }
}
PHP);

    $head = gclCommit(['database/seeders/RbacSeeder.php' => <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
            PermissionEnum::ACTIVITY_LOG_EXPORT->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
            'bursar' => [
                ...$activityStaff,
            ],
        ];
    }
}
PHP], $base);

    $r = gclRun($base, $head);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and($failures)->toContain('activity_log.export')
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->not->toContain('role: bursar')
        ->and($exemptions)->toContain('bursar')
        ->and($exemptions)->toContain('is NEW in this diff (takes the full $permissions array)');
});

it('F6 — a fragment NEW in this diff, spread into a pre-existing role, is STILL flagged', function () {
    // THE TEMPTING FIFTH EXEMPTION, refused. A fragment added in this diff, spread into a
    // pre-existing role, carrying PRE-EXISTING permissions, is the defect in full: rbac:sync grants
    // nothing, because neither the permission nor the role is new. The fragment being new says
    // nothing about the permissions.
    //
    // It also pins DEDUPLICATION: both new finding sources fire here (the spread line is added AND
    // the definition lines are added), and the same (role, permission) pair must be reported ONCE.
    $base = gclFragmentBase(<<<'PHP'
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
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            ],
        ];
    }
}
PHP);

    $head = gclCommit(['database/seeders/RbacSeeder.php' => <<<'PHP'
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
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            ],
        ];
    }
}
PHP], $base);

    $r = gclRun($base, $head);
    [$failures, $exemptions] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        // Exactly two — one per permission the new fragment carries, not four.
        ->and(gclFindingCount($r['output']))->toBe(2)
        ->and($failures)->toContain('activity_log.view')
        ->and($failures)->toContain('activity_log.view_all')
        ->and($failures)->toContain('role: auditor')
        // Nothing here is exempt. "The fragment is new" is NOT an exemption.
        ->and($exemptions)->toBe('');
});

it('F7 — a permission added to a fragment NO role spreads yields zero findings', function () {
    // Correct, and it is the honest converse of the fan-out: nothing is granted, so nothing needs
    // converging. Note this is only sound because the spread index reads BOTH consumption forms —
    // see F10 for the one that would otherwise be a fresh silent green.
    $unspread = <<<'PHP'
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
        $unused = [
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            ],
        ];
    }
}
PHP;

    $base = gclFragmentBase($unspread);
    $head = gclCommit(['database/seeders/RbacSeeder.php' => str_replace(
        "            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n        ];\n",
        "            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n            PermissionEnum::ACTIVITY_LOG_VIEW->value,\n        ];\n",
        $unspread
    )], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('OK — no unexempted grant addition')
        ->and($r['output'])->not->toContain('role: ?');
});

it('F8 — a fragment containing a NESTED spread refuses loudly: NOT LINTED, never a quiet skip', function () {
    // Decision 3. No fragment nests today, so this costs nothing until someone writes one — at which
    // point the gate says so instead of resolving transitively or skipping. It reuses the existing
    // notLinted() rather than inventing a second not-looked message, for the reason that function's
    // own docblock gives.
    $base = gclFragmentBase(gclOneSpreadSeeder());

    $head = gclCommit(['database/seeders/RbacSeeder.php' => <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        $combined = [
            ...$activityStaff,
            PermissionEnum::ACTIVITY_LOG_EXPORT->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
            'bursar' => [
                ...$combined,
            ],
        ];
    }
}
PHP], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED')
        ->and($r['output'])->toContain('combined')
        ->and($r['output'])->not->toContain('no unexempted grant addition');
});

it('F10 — a fragment consumed as `\'<role>\' => $fragment,` is indexed too, not read as spread by nobody', function () {
    // THE SILENT GREEN THIS CHANGE WOULD OTHERWISE HAVE CREATED. `'<role>' => $fragment,` grants the
    // whole fragment exactly as `...$fragment,` does. Index only the spread form and a permission
    // added to such a fragment fans out to nothing and the run exits 0 — where TODAY, before this
    // change, it is a red `?`. That would be strictly worse than the defect being fixed, so both
    // consumption forms are indexed.
    //
    // (This is the shape the previous MARKER 7 fixture was built on; MARKER 7 has been rebuilt on
    // the residual-`?` shape below, which is what its name has always claimed to assert.)
    $assigned = <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => $activityStaff,
            'bursar' => $activityStaff,
        ];
    }
}
PHP;

    $base = gclFragmentBase($assigned);
    $head = gclCommit(['database/seeders/RbacSeeder.php' => str_replace(
        "            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n        ];\n",
        "            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,\n            PermissionEnum::ACTIVITY_LOG_VIEW->value,\n        ];\n",
        $assigned
    )], $base);

    $r = gclRun($base, $head);
    [$failures] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and(gclFindingCount($r['output']))->toBe(2)
        ->and($failures)->toContain('activity_log.view')
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->toContain('role: bursar')
        ->and($failures)->not->toContain('role: ?');
});

it('F11 — changing HOW a role consumes a fragment it already consumed grants nothing, and is not flagged', function () {
    // A RESTRUCTURE IS NOT A GRANT, and this is not a hypothetical shape: it is what 9caf958 did.
    // That commit rewrote `'boarding_parent' => $assessments,` into
    // `'boarding_parent' => [ ...$assessments, … ]`. Line-wise that is an ADDED `...$assessments,`
    // carrying six PRE-EXISTING permissions into a PRE-EXISTING role — defect A's exact signature.
    // Grant-wise it is nothing at all: the role already held all six, through the same fragment.
    //
    // Without the base-side fragment model the fan-out reports six convergences for grants that were
    // never absent, and it does it on the one commit in this file whose arm exists to prove the gate
    // does NOT fire on the legitimate case. The real-history arm for 9caf958 is the primary proof;
    // this fixture is the isolated statement of the rule, so a mutant that drops the base model
    // reddens something that names the rule rather than only something that names a commit.
    $assigned = <<<'PHP'
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
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => $activityStaff,
        ];
    }
}
PHP;

    $base = gclFragmentBase($assigned);

    // The same grants, expressed the other way, plus one genuinely new literal so the diff is not
    // vacuous. Only the literal is a new grant.
    $head = gclCommit(['database/seeders/RbacSeeder.php' => str_replace(
        "            'auditor' => \$activityStaff,\n",
        "            'auditor' => [\n                ...\$activityStaff,\n                PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n            ],\n",
        $assigned
    )], $base);

    $r = gclRun($base, $head);
    [$failures] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        // Exactly ONE finding: the literal. Not three.
        ->and(gclFindingCount($r['output']))->toBe(1)
        ->and($failures)->toContain('activity_log.export')
        ->and($failures)->not->toContain('activity_log.view_all')
        ->and($failures)->toContain('role: auditor');
});

// ── The two holes the cold review of a573401 found ───────────────────────────
//
// NUMBERING: the review brief calls these F10 and F11. Those names were already taken by the
// arms above (the `'<role>' => $fragment,` consumption form, and the restructure rule), so they
// are F12 and F13 here. Nothing was renumbered.

it('F12 — a quoted permission value in a TRAILING COMMENT must not feed the resolver', function () {
    // THE SILENCING DIRECTION, which is what makes this worse than a mis-parse. $resolvePermissions
    // ends in a floating quote-pair scan, and the fragment-body scanner strips only WHOLE-LINE
    // comments — so a trailing `// … 'activity_log.export' …` inside a fragment body at BASE put
    // that permission into $baseFragmentGrants. The base set is used to SUPPRESS findings, so the
    // genuine addition of that permission on the branch reported nothing at all.
    //
    // This is the class constMembers()'s docblock is an essay about: a quote-pair scan with no idea
    // what a quote MEANS, poisoned by a comment. That one lost roles and was caught; this one lost
    // a finding. Measured before the fix: exit 0. staging's lint, which has no base-side model at
    // all, reports this diff red.
    $withComment = <<<'PHP'
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
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,   // deliberately NOT 'activity_log.export'
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
        ];
    }
}
PHP;

    $base = gclFragmentBase($withComment);

    // The branch now genuinely grants what the comment merely mentioned.
    $head = gclCommit(['database/seeders/RbacSeeder.php' => str_replace(
        "            PermissionEnum::ACTIVITY_LOG_VIEW->value,   // deliberately NOT 'activity_log.export'\n",
        "            PermissionEnum::ACTIVITY_LOG_VIEW->value,   // deliberately NOT 'activity_log.export'\n            PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n",
        $withComment
    )], $base);

    $r = gclRun($base, $head);
    [$failures] = gclSplit($r['output']);

    expect($r['exit'])->toBe(1)
        ->and(gclFindingCount($r['output']))->toBe(1)
        ->and($failures)->toContain('activity_log.export')
        ->and($failures)->toContain('role: auditor');
});

it('F13 — a consumption line the two forms do not match REFUSES, and does not skip quietly', function () {
    // `'<role>' => [...$fragment],` on ONE line is Pint-clean and grants the whole fragment. Neither
    // consumption regex matches it (the first wants the spread at line start, the second wants a bare
    // `$name` after `=>`), so before this fix the line was skipped with no refusal and no finding —
    // defect A in full, on a line no formatter objects to.
    //
    // The docblock at the head of the lint asserts that a consumption the table could not find is
    // never a silent zero. That sentence was FALSE at a573401 and this arm is what makes it true.
    $inline = <<<'PHP'
<?php

class RbacSeeder
{
    public const SUPER_ADMIN_PLATFORM = [
        'activity_log.view_all',
    ];

    public const ROLES = [
        'auditor',
        'bursar',
    ];

    public static function grantsMap(): array
    {
        $activityStaff = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
            PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value,
        ];

        return [
            'auditor' => [
                ...$activityStaff,
            ],
            'bursar' => [
                PermissionEnum::ACTIVITY_LOG_EXPORT->value,
            ],
        ];
    }
}
PHP;

    $base = gclFragmentBase($inline);

    $head = gclCommit(['database/seeders/RbacSeeder.php' => str_replace(
        "            'bursar' => [\n                PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n            ],\n",
        "            'bursar' => [...\$activityStaff],\n",
        $inline
    )], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(1)
        ->and($r['output'])->toContain('NOT LINTED')
        ->and($r['output'])->toContain('own line')
        ->and($r['output'])->not->toContain('no unexempted grant addition');
});

it('F14 — a permission already held at base through a DIFFERENT fragment is suppressed, not re-reported', function () {
    // THE SUPPRESSION'S ACTUAL SCOPE, pinned. $baseFragmentGrants is keyed role => permission and
    // UNIONED over every fragment that role consumed at base — so "auditor already holds
    // activity_log.export" is true whichever fragment carried it. An earlier revision of this file's
    // docblock, and of the implementation report, claimed the overlapping-fragment case was NOT
    // covered. It is; the claim was wrong and the behaviour was right.
    //
    // THE RED THIS ARM HAS, AND WHY IT DOES NOT COUNT. Against `staging`'s lint the fixture DOES
    // fail — exit 1, not 0 — but for an unrelated reason: that revision has no fragment model at
    // all, so the added line takes the generic path, `$inferRole` returns null and it reports
    // `role: ?`. Nothing about that red discriminates a per-role set from a per-fragment one, which
    // is the only thing this arm claims. So it is NOT offered as the watched red.
    //
    // M10 is the discriminating mutant: key $baseFragmentGrants per (role, fragment) instead of
    // unioning per role, and this arm reddens while every other arm stays green. Said plainly
    // rather than dressed up, because a red that fires for the wrong reason is worse than none —
    // it reads as coverage the arm does not have.
    //
    // What is uncovered, and deliberately: a permission the role held at base through a LITERAL
    // line. That needs the whole base map evaluated — the carried line-vs-grant diffing ticket.
    $twoFragments = <<<'PHP'
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
        $one = [
            PermissionEnum::ACTIVITY_LOG_EXPORT->value,
        ];

        $two = [
            PermissionEnum::ACTIVITY_LOG_VIEW->value,
        ];

        return [
            'auditor' => [
                ...$one,
                ...$two,
            ],
        ];
    }
}
PHP;

    $base = gclFragmentBase($twoFragments);

    // `activity_log.export` is added to $two. auditor already receives it through $one, so the map
    // grants it no more widely than before and there is nothing for a migration to converge.
    $head = gclCommit(['database/seeders/RbacSeeder.php' => str_replace(
        "        \$two = [\n            PermissionEnum::ACTIVITY_LOG_VIEW->value,\n        ];\n",
        "        \$two = [\n            PermissionEnum::ACTIVITY_LOG_VIEW->value,\n            PermissionEnum::ACTIVITY_LOG_EXPORT->value,\n        ];\n",
        $twoFragments
    )], $base);

    $r = gclRun($base, $head);

    expect($r['exit'])->toBe(0)
        ->and($r['output'])->toContain('OK — no unexempted grant addition')
        ->and($r['output'])->not->toContain('activity_log.export');
});
