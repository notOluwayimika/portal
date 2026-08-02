#!/usr/bin/env php
<?php

/**
 * grants-convergence lint — "a PRE-EXISTING permission added to `RbacSeeder::grantsMap()` ships a
 * convergence migration".
 *
 * THE DEFECT IT GUARDS. `RbacSeeder::sync()` is non-destructive by contract, and the mechanism
 * (`database/seeders/RbacSeeder.php:494-496`) is:
 *
 *     $toGrant = in_array($roleName, $existingRoles, true)
 *         ? array_values(array_intersect($permissions, $newPermissions))
 *         : $permissions;
 *
 * `$newPermissions` (`:478`) is the set of permissions CREATED ON THIS RUN. So adding an
 * already-existing permission to an already-existing role in `grantsMap()` grants NOTHING on every
 * environment where the seeder has already run: the map says the role holds it, the database says it
 * does not, and the next `rbac:sync` will not close the gap. It needs a convergence migration.
 * `finance.access` on `head_of_school` and `principal` is the live instance (`7370e89`).
 *
 * THIS LINT PROTECTS THE FUTURE ONLY. IT IS NOT RETROACTIVE. A future reader who assumes otherwise
 * will draw a false conclusion from a green gate, so it is stated here and in `bin/quality`: the
 * invariant CANNOT be asserted from state. CI's database is freshly seeded, and on a fresh seed
 * `grantsMap()` always matches, by construction — `$existingRoles` is empty, so every role takes the
 * `: $permissions` branch. The live production copy is the only witness and CI does not have it. The
 * diff is therefore the only place the invariant is visible. The division of labour in the change
 * that introduced this: `php artisan rbac:diff-grants` covers the PAST (it enumerates what already
 * drifted, against a real database), this lint covers the FUTURE.
 *
 * THE RULE. Fail when the diff `<base>..<head>` adds a permission to `grantsMap()` and NONE of these
 * four exemptions holds:
 *
 *   1. THE PERMISSION IS NEW — the same diff adds its `case` to `app/Enums/Permission.php`. It then
 *      lands in `$newPermissions` and `rbac:sync` grants it. No migration needed.
 *   2. THE ROLE IS NEW — the same diff adds the role to `RbacSeeder::ROLES`. Then
 *      `in_array($roleName, $existingRoles, true)` is false and the role receives the FULL
 *      `$permissions` array. No migration needed.
 *   3. A MIGRATION IN THE DIFF NAMES THE PERMISSION — a file ADDED under `database/migrations/`
 *      whose content contains the permission string. Not merely "a migration exists in the diff":
 *      the weak form would be exempted by any unrelated migration, which is wallpaper.
 *   4. THE ADDITION IS INSIDE `RbacSeeder::SUPER_ADMIN_PLATFORM` — `grantsMap()['super_admin']` IS
 *      that const, and the self-heal block at `RbacSeeder.php:506-512` runs
 *      `syncPermissions(self::SUPER_ADMIN_PLATFORM)` UNCONDITIONALLY on every sync, outside the
 *      `$fresh` branch and outside the `$newPermissions` intersection. A super_admin addition
 *      therefore lands on the next `rbac:sync` with no migration. This exemption was NOT in the
 *      brief; without it the gate fires on a legitimate case, which is how a gate gets disabled.
 *
 * KNOWN IMPRECISION — READ BEFORE TRUSTING THE ROLE COLUMN. Attributing an added grant line to its
 * role from source text is inference, not proof. This lint resolves the role by finding the added
 * line's number in the new file and scanning BACKWARDS for the nearest preceding `'<role>' => [`.
 * (That is deliberately stronger than scanning the diff hunk, which loses the role whenever the hunk
 * is tight — `7370e89`'s `head_of_school` hunk is exactly that case. It is still inference: it does
 * not parse PHP.) It reports `?` rather than guessing when there is no preceding role key — which is
 * the correct answer for the shared `$guardianFull` / `$activityAdmin` style fragments defined ABOVE
 * `return [`, since those are granted to every role that spreads them. THE PERMISSION AND THE
 * FILE:LINE ARE EXACT; the role is marked `inferred`. This lint's job is to make a human look. It is
 * not a proof.
 *
 * NO BASELINE FILE. This is an absolute rule from day one, not a ratchet — there is nothing
 * pre-existing to grandfather, because it only ever sees new diffs.
 *
 * Usage:
 *   php bin/ci-grants-convergence-lint.php <base>           # diff <base>..HEAD  (what bin/quality runs)
 *   php bin/ci-grants-convergence-lint.php <base> <head>    # diff <base>..<head> — replay any range
 */
$root = dirname(__DIR__);
chdir($root);

const SEEDER = 'database/seeders/RbacSeeder.php';
const ENUM = 'app/Enums/Permission.php';

function git(string ...$args): string
{
    $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';

    return (string) shell_exec($cmd);
}

function rev(string $ref): ?string
{
    $sha = trim(git('rev-parse', '--verify', '--quiet', $ref.'^{commit}'));

    return $sha === '' ? null : $sha;
}

/**
 * Permission enum values at a revision, as constant => value. Parsed statically — this lint runs
 * without booting Laravel, exactly like its sibling lints.
 *
 * @return array<string, string>
 */
function enumValues(string $ref): array
{
    preg_match_all(
        '/case\s+([A-Z0-9_]+)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/',
        git('show', $ref.':'.ENUM),
        $m,
        PREG_SET_ORDER
    );

    $out = [];
    foreach ($m as $set) {
        $out[$set[1]] = $set[2];
    }

    return $out;
}

/**
 * The string members of a `const <NAME> = [ ... ];` block at a revision.
 *
 * @return list<string>
 */
function constMembers(string $ref, string $const): array
{
    $src = git('show', $ref.':'.SEEDER);

    if (! preg_match('/const\s+'.preg_quote($const, '/').'\s*=\s*\[(.*?)\];/s', $src, $m)) {
        return [];
    }

    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $q);

    return $q[1];
}

// ---------------------------------------------------------------- arguments
$baseArg = $argv[1] ?? '';
$headArg = $argv[2] ?? 'HEAD';

$base = $baseArg === '' ? null : rev($baseArg);
$head = rev($headArg);

// A gate that cannot see the diff must NOT exit 0. A green here would mean "I did not look", which is
// worse than a red — the same failure mode bin/lint-changed.sh calls out for unresolvable paths.
if ($base === null || $head === null) {
    fwrite(STDERR, "grants-convergence-lint: NOT LINTED — could not resolve base '"
        .($baseArg === '' ? '<empty>' : $baseArg)."' / head '{$headArg}' to a commit. Pass a valid base ref.\n");
    exit(1);
}

// ---------------------------------------------------------------- the seeder diff
// -U25: the role key `'<role>' => [` is frequently more than three lines above an added grant, and a
// hunk-local scan loses it. The role is resolved against the new FILE below regardless; the wide
// context is what makes the hunk's own text readable in the failure message.
// `<base>...<head>`, three-dot, matching bin/lint-changed.sh:51 exactly — "what head adds since the
// merge-base", so an unrelated base ref cannot make this report changes head did not make. When
// bin/quality supplies "$BASE" it is already a merge-base, so the two forms coincide there.
$diff = git('diff', '-U25', $base.'...'.$head, '--', SEEDER);

if (trim($diff) === '') {
    fwrite(STDERR, 'grants-convergence-lint: OK — '.SEEDER." is unchanged in this diff.\n");
    exit(0);
}

// Added lines, with their line number in the NEW file.
/** @var list<array{int, string}> $added */
$added = [];
$newLine = 0;
foreach (explode("\n", $diff) as $line) {
    if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m)) {
        $newLine = (int) $m[1];

        continue;
    }
    if ($line === '' || $line[0] === '\\') {
        continue;
    }
    if ($line[0] === '+') {
        if (! str_starts_with($line, '+++')) {
            $added[] = [$newLine, substr($line, 1)];
            $newLine++;
        }

        continue;
    }
    if ($line[0] === '-') {
        continue;
    }
    if ($line[0] === ' ') {
        $newLine++;
    }
}

// ---------------------------------------------------------------- head-side facts
$headEnum = enumValues($head);          // constant => value
$baseEnum = enumValues($base);
$headValues = array_flip($headEnum);    // value => constant
$newPermissions = array_values(array_diff($headEnum, $baseEnum));

$newRoles = array_values(array_diff(constMembers($head, 'ROLES'), constMembers($base, 'ROLES')));

// The SUPER_ADMIN_PLATFORM line range in the NEW file, for exemption 4 (line-precise, not name-based:
// naming would over-exempt a permission added to super_admin AND to another role in the same diff).
$headSeeder = explode("\n", git('show', $head.':'.SEEDER));
$sapFrom = $sapTo = null;
foreach ($headSeeder as $i => $line) {
    if ($sapFrom === null && str_contains($line, 'const SUPER_ADMIN_PLATFORM')) {
        $sapFrom = $i + 1;

        continue;
    }
    if ($sapFrom !== null && preg_match('/^\s*\];/', $line)) {
        $sapTo = $i + 1;
        break;
    }
}

// Migrations ADDED in this diff, with their content — exemption 3.
$addedMigrations = [];
foreach (explode("\n", git('diff', '--name-status', '--diff-filter=A', $base.'...'.$head)) as $line) {
    $parts = preg_split('/\t/', trim($line));
    if (count($parts) < 2 || ! str_starts_with($parts[1], 'database/migrations/')) {
        continue;
    }
    $addedMigrations[$parts[1]] = git('show', $head.':'.$parts[1]);
}

/** The nearest preceding `'<role>' => [` above $line in the new file. Inference, not a parse. */
$inferRole = function (int $line) use ($headSeeder): ?string {
    for ($i = min($line, count($headSeeder)) - 1; $i >= 0; $i--) {
        if (preg_match('/^\s*[\'"]([a-z0-9_]+)[\'"]\s*=>\s*\[/', $headSeeder[$i], $m)) {
            return $m[1];
        }
        // Do not scan out of the map into unrelated code.
        if (preg_match('/^\s*return \[/', $headSeeder[$i])) {
            return null;
        }
    }

    return null;
};

// ---------------------------------------------------------------- findings
$findings = [];
$exempted = [];

foreach ($added as [$line, $text]) {
    $stripped = ltrim($text);
    if (str_starts_with($stripped, '//') || str_starts_with($stripped, '*') || str_starts_with($stripped, '/*')) {
        continue;
    }

    // Resolve the permission NAME. Two accepted forms, and a quoted string is kept only when it is a
    // real enum VALUE at head — that is what keeps role keys ('internal_auditor' => [) and the
    // dot-less permission names (view_psychomotor_skills) apart without guessing at their shape.
    $permissions = [];

    if (preg_match_all('/PermissionEnum::([A-Z0-9_]+)->value/', $text, $m)) {
        foreach ($m[1] as $constant) {
            if (isset($headEnum[$constant])) {
                $permissions[] = $headEnum[$constant];
            }
        }
    }

    if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $text, $m)) {
        foreach ($m[1] as $quoted) {
            if (isset($headValues[$quoted])) {
                $permissions[] = $quoted;
            }
        }
    }

    foreach (array_unique($permissions) as $permission) {
        $role = $inferRole($line);

        $inSuperAdminConst = $sapFrom !== null && $sapTo !== null && $line >= $sapFrom && $line <= $sapTo;

        $migration = null;
        foreach ($addedMigrations as $path => $content) {
            if (str_contains($content, $permission)) {
                $migration = $path;
                break;
            }
        }

        $exemption = match (true) {
            in_array($permission, $newPermissions, true) => 'permission is NEW in this diff (lands in $newPermissions)',
            $role !== null && in_array($role, $newRoles, true) => "role [{$role}] is NEW in this diff (takes the full \$permissions array)",
            $migration !== null => "migration [{$migration}] in this diff names it",
            $inSuperAdminConst => 'inside SUPER_ADMIN_PLATFORM (self-healed by syncPermissions every run, RbacSeeder.php:506-512)',
            default => null,
        };

        $record = [
            'permission' => $permission,
            'line' => $line,
            'role' => $role,
            'text' => trim($text),
        ];

        if ($exemption !== null) {
            $exempted[] = $record + ['exemption' => $exemption];

            continue;
        }

        $findings[] = $record;
    }
}

// ---------------------------------------------------------------- report
$scope = substr($base, 0, 7).'..'.substr($head, 0, 7);

if ($findings === []) {
    fwrite(STDERR, 'grants-convergence-lint: OK — no unexempted grant addition in '.SEEDER
        ." ({$scope}; ".count($exempted)." exempted).\n");
    foreach ($exempted as $e) {
        fwrite(STDERR, '  · '.$e['permission'].' @ '.SEEDER.':'.$e['line'].' — exempt: '.$e['exemption']."\n");
    }
    exit(0);
}

fwrite(STDERR, "\ngrants-convergence-lint: ".count($findings)
    .' grant addition(s) in '.SEEDER." that rbac:sync will NOT apply ({$scope}):\n\n");

foreach ($findings as $f) {
    fwrite(STDERR, '  '."\u{2717}".' '.$f['permission'].'  @  '.SEEDER.':'.$f['line']."\n");
    fwrite(STDERR, '      role: '.($f['role'] ?? '?').' (INFERRED from the nearest preceding \'<role>\' => [ — verify it)'."\n");
    fwrite(STDERR, '      line: '.$f['text']."\n");
}

// Print the exemptions on the FAILING path too. A reader must be able to see that the gate
// distinguishes the legitimate additions in the same diff from the ones it is failing on — otherwise
// the only visible behaviour is "it fired", and a gate nobody can audit is a gate that gets disabled.
if ($exempted !== []) {
    fwrite(STDERR, "\n  ".count($exempted)." addition(s) in the same diff were EXEMPT:\n");
    foreach ($exempted as $e) {
        fwrite(STDERR, '  '."\u{2713}".' '.$e['permission'].'  @  '.SEEDER.':'.$e['line'].' — '.$e['exemption']."\n");
    }
}

fwrite(STDERR, <<<'TXT'

  WHY THIS FAILS. The permission already exists, so it is not in $newPermissions
  (RbacSeeder.php:478), and the role already exists, so the grant loop takes the
  array_intersect branch (:494-496) and grants NOTHING. On a fresh seed the map and the
  database agree by construction, so no test and no fixture will ever show this — the
  drift is invisible until someone runs `php artisan rbac:diff-grants` against a real
  database.

  TO RESOLVE, pick the one that is true:
    · Ship a convergence migration that grants the permission to the role, and make its
      content name the permission (that is exemption 3).
    · The permission is genuinely new — add its `case` to app/Enums/Permission.php in the
      same diff (exemption 1).
    · The role is genuinely new — add it to RbacSeeder::ROLES in the same diff (exemption 2).

  NOT RETROACTIVE: this lint sees diffs only. For grants that have ALREADY drifted, run
  `php artisan rbac:diff-grants`.

TXT);

exit(1);
