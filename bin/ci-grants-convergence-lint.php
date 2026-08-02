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
 *   3. A MIGRATION IN THE DIFF CONVERGES THE PAIR — a file ADDED under `database/migrations/`
 *      whose content names the permission AS A WHOLE NAME ({@see namesPermission}, a boundary match
 *      and NOT a substring test — see that docblock for the prefix-pair hole a substring test leaves
 *      open) AND names the ROLE the addition was attributed to ({@see namesRole}, two-sided because
 *      role names DO have suffix pairs). Both halves are required. Not merely "a migration exists
 *      in the diff", which any unrelated migration would satisfy; and not merely "a migration names
 *      the permission", which would have exempted BOTH roles in 7370e89 on a migration converging
 *      one. A null inferred role does not exempt — see the imprecision note below.
 *   4. THE ADDITION IS INSIDE `RbacSeeder::SUPER_ADMIN_PLATFORM` — `grantsMap()['super_admin']` IS
 *      that const, and the self-heal block at `RbacSeeder.php:506-512` runs
 *      `syncPermissions(self::SUPER_ADMIN_PLATFORM)` UNCONDITIONALLY on every sync, outside the
 *      `$fresh` branch and outside the `$newPermissions` intersection. A super_admin addition
 *      therefore lands on the next `rbac:sync` with no migration. This exemption was NOT in the
 *      brief; without it the gate fires on a legitimate case, which is how a gate gets disabled.
 *      Its line range is bounded at both ends — see the derivation at the range scan itself.
 *
 * IF IT CANNOT LOOK, IT IS NOT GREEN. The base ref, both revisions of the seeder, both revisions of
 * the enum, and `RbacSeeder::ROLES` at both revisions must all read and parse. Any of them empty and
 * the lint exits 1 as `NOT LINTED` ({@see notLinted}) rather than exempting its way to zero findings.
 * Every one of those empty sets was a silent green before: an unreadable base enum exempts
 * everything through exemption 1, an unreadable head enum resolves no permissions at all, and a
 * seeder moved out from under `SEEDER` looks exactly like a seeder nobody edited.
 *
 * KNOWN IMPRECISION — READ BEFORE TRUSTING THE ROLE COLUMN. Attributing an added grant line to its
 * role from source text is inference, not proof. This lint resolves the role by finding the added
 * line's number in the new file and scanning BACKWARDS for the nearest preceding `'<role>' => [`,
 * and then keeps the answer only if it is a real member of `RbacSeeder::ROLES` at head. (That is
 * deliberately stronger than scanning the diff hunk, which loses the role whenever the hunk is
 * tight — `7370e89`'s `head_of_school` hunk is exactly that case. It is still inference: it does not
 * parse PHP.) It reports `?` rather than guessing — which is the correct answer for the shared
 * `$guardianFull` / `$activityAdmin` style fragments defined ABOVE `return [`, since those are
 * granted to every role that spreads them. THE PERMISSION AND THE FILE:LINE ARE EXACT; the role is
 * marked `inferred`. This lint's job is to make a human look. It is not a proof.
 *
 * A `?` ROLE IS NOT AN EXEMPTION. It disables exemptions 2 and 3 for that addition, so the addition
 * is flagged. That is the safe direction and it is also the correct one for the fragment case: a
 * permission added to a shared fragment reaches every pre-existing role that spreads it, and each
 * of those needs the convergence migration.
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
 * The ONE failure shape for "this gate could not look". Never invent a second: a reader who has
 * learned to recognise `NOT LINTED` must recognise every instance of it, and a gate with two
 * different not-looked messages teaches only one of them.
 */
function notLinted(string $why): never
{
    fwrite(STDERR, "grants-convergence-lint: NOT LINTED — {$why}\n");
    exit(1);
}

/**
 * Permission enum values at a revision, as constant => value. Parsed statically — this lint runs
 * without booting Laravel, exactly like its sibling lints.
 *
 * STILL A REGEX, DELIBERATELY, while {@see constMembers} below is not. The pattern is anchored on
 * `case <NAME> = ` before it ever reaches a quote, so a stray apostrophe in a comment cannot shift
 * its quote pairing the way it shifted the const scan — there is no floating `['"]([^'"]+)['"]`
 * here for parity to slide through. The known residual is a commented-out `case X = 'v';`, which
 * would be read as declared; that has never occurred in this file and is not the defect being
 * fixed, so it is recorded rather than pre-empted.
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
 * THE BUG THIS EXISTS TO CLOSE, and why it is a LEXER and not a second regex. The first version
 * matched the const body with `/const NAME = \[(.*?)\];/s` and then scanned it with
 * `/['"]([^'"]+)['"]/` — a floating quote-pair scan with no idea what a quote MEANS. One
 * apostrophe inside a comment flips the pairing for everything after it. `RbacSeeder::ROLES`
 * carries exactly that, at `// Primary's senior commenter`, and the measured parse was:
 *
 *     [ 7] "form_teacher"
 *     [ 8] "s senior commenter — see the grants map below.\n        "     <- junk
 *     [ 9] ",\n        "                                                  <- junk
 *     [10] ",\n        // Finance seats — Brookstone"                     <- junk
 *     [11] "accounts_officer"
 *
 * `key_stage_coordinator` and `registrar` were LOST, and three junk members took their place.
 * (Parity was restored by luck, by the second apostrophe in `Brookstone's` — which is why the
 * finance roles below it survived.)
 *
 * Both sides of a diff garble identically, so nothing fired. But `array_diff(head, base)` turns
 * ANY reword of an apostrophe-bearing comment into manufactured members: removing the apostrophe
 * from `Primary's` makes head parse correctly while base does not, and `key_stage_coordinator`
 * and `registrar` appear as NEW ROLES. Exemption 2 then exempts a grant addition to either of
 * them — a silent green, the same class the substring hole in {@see namesPermission} was.
 *
 * WHY `token_get_all` RATHER THAN STRIPPING COMMENTS FIRST. Stripping line and block comments with
 * a regex fixes this instance by adding a second scanner that also cannot tell a delimiter from a
 * character — a `//` inside a string literal would be eaten, reproducing the same class one layer
 * down. The failure is a LEXING failure, so it is answered with PHP's own lexer: in core, no
 * Laravel boot required (this lint runs without one, like its siblings), and
 * `T_CONSTANT_ENCAPSED_STRING` is unambiguous by construction. Comments arrive as `T_COMMENT` and
 * are never mistaken for anything else.
 *
 * Returns `[]` when the revision, the file or the const cannot be read — the caller turns that
 * into `NOT LINTED`, never into a pass.
 *
 * @return list<string>
 */
function constMembers(string $ref, string $const): array
{
    $src = git('show', $ref.':'.SEEDER);

    if (trim($src) === '') {
        return [];
    }

    $tokens = @token_get_all($src);
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
            continue;
        }

        // The const NAME is the next token that is not whitespace or a comment.
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= $n || ! is_array($tokens[$j]) || $tokens[$j][1] !== $const) {
            continue;
        }

        while ($j < $n && $tokens[$j] !== '[') {
            $j++;
        }

        $members = [];
        $depth = 0;
        for (; $j < $n; $j++) {
            $t = $tokens[$j];
            if ($t === '[') {
                $depth++;

                continue;
            }
            if ($t === ']') {
                if (--$depth === 0) {
                    return $members;
                }

                continue;
            }
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $members[] = substr($t[1], 1, -1);
            }
        }

        return $members;
    }

    return [];
}

/**
 * Does $content name $permission — as a WHOLE permission name, not as a prefix of a longer one?
 *
 * THE BUG THIS EXISTS TO CLOSE. The first version of exemption 3 was `str_contains($content,
 * $permission)`, a raw substring test, and the enum is full of prefix pairs that make that unsound.
 * Nine of them today, re-derived from app/Enums/Permission.php rather than remembered:
 *
 *   activity_log.view      ⊂ .view_all / .view_own / .view_system / .view_cross_school / .view_sensitive
 *   guardian.view          ⊂ guardian.view_audit
 *   guardian.update        ⊂ guardian.update_credentials
 *   result.view            ⊂ result.view_scores
 *   student_subject.view   ⊂ student_subject.view_history
 *
 * So a diff adding `activity_log.view` to a pre-existing role, alongside a convergence migration
 * that names only `activity_log.view_all`, was EXEMPTED — with no migration for the permission
 * actually added. A silent green, in exactly the class the gate exists for. That is the worst
 * failure a gate can have: it is indistinguishable from working.
 *
 * The fix is a right boundary — the permission must not be followed by another permission-name
 * character. Only the RIGHT side is guarded, and that is a derived decision, not an oversight: of
 * the 79 enum values there are 9 prefix pairs, 0 suffix pairs and 0 mid-string pairs, so no enum
 * value can be matched inside the tail of another. A future permission that is a SUFFIX of an
 * existing one would need the mirror lookbehind; there is none today, so adding one now would be
 * guarding nothing and is left out deliberately.
 *
 * KNOWN FALSE NEGATIVE, and it is the safe direction: `.` is in the forbidden-following set, so a
 * comment ending "…grants finance.access." does not count as naming it. It could be dropped, since
 * all nine of today's pairs extend with `_` rather than `.` — but a future `finance.access.read`
 * would reopen the hole, and a false negative here means the gate FIRES and a human reads the
 * message, while a false positive means a silent green. Prefer the red.
 */
function namesPermission(string $content, string $permission): bool
{
    return (bool) preg_match('/'.preg_quote($permission, '/').'(?![A-Za-z0-9_.\-])/', $content);
}

/**
 * Does $content name $role — as a WHOLE role name?
 *
 * BOTH boundaries here, unlike {@see namesPermission}, and the asymmetry is derived rather than
 * inconsistent. Permission values have 9 prefix pairs, 0 suffix pairs and 0 mid pairs, so a right
 * boundary alone is sufficient there. ROLE names do NOT have that shape — re-derived from
 * `RbacSeeder::ROLES`:
 *
 *   admin    is a SUFFIX of  super_admin
 *   teacher  is a SUFFIX of  form_teacher
 *
 * A right-boundary-only test would therefore let a migration naming `super_admin` count as naming
 * `admin`, and one naming `form_teacher` count as naming `teacher` — over-exemption, the silent
 * direction. The lookbehind is load-bearing here and vacuous there; each side gets what its own
 * data requires.
 */
function namesRole(string $content, string $role): bool
{
    return (bool) preg_match(
        '/(?<![A-Za-z0-9_.\-])'.preg_quote($role, '/').'(?![A-Za-z0-9_.\-])/',
        $content
    );
}

// ---------------------------------------------------------------- arguments
$baseArg = $argv[1] ?? '';
$headArg = $argv[2] ?? 'HEAD';

$base = $baseArg === '' ? null : rev($baseArg);
$head = rev($headArg);

// A gate that cannot see the diff must NOT exit 0. A green here would mean "I did not look", which is
// worse than a red — the same failure mode bin/lint-changed.sh calls out for unresolvable paths.
if ($base === null || $head === null) {
    notLinted("could not resolve base '".($baseArg === '' ? '<empty>' : $baseArg)
        ."' / head '{$headArg}' to a commit. Pass a valid base ref.");
}

// ------------------------------------------------------- the inputs, or NOT LINTED
// THE SAME RULE AS THE BASE REF ABOVE, APPLIED TO THE TWO FILES THIS LINT HARDCODES. `git()` is
// `shell_exec` with `2>/dev/null`, so an unreadable revision or a moved file returns '' — and every
// parser below turns '' into an EMPTY SET rather than an error. Each empty set exempts, silently:
//
//   base enum unreadable  -> $baseEnum = [] -> $newPermissions = every value at head
//                            -> exemption 1 exempts EVERY addition -> exit 0.
//   head enum unreadable  -> $headEnum = [] -> no added line ever resolves to a permission
//                            -> zero findings, on this diff and on every future one.
//   seeder moved/renamed  -> the diff below is empty -> "OK — unchanged in this diff", forever.
//
// That last one is why these checks sit ABOVE the unchanged-diff early return and not below it: a
// seeder that has been moved out from under the lint is indistinguishable, at that early return,
// from a seeder nobody touched. Reuses notLinted() rather than inventing a second failure shape.
//
// SUPER_ADMIN_PLATFORM is deliberately NOT required: cf9d2a2 is the commit that created it, so it
// is legitimately absent at cf9d2a2^ and across older history. Its absence disables exemption 4,
// which fails toward RED.
$headSeederSrc = git('show', $head.':'.SEEDER);
$baseSeederSrc = git('show', $base.':'.SEEDER);

$headEnum = enumValues($head);          // constant => value
$baseEnum = enumValues($base);
$headRoles = constMembers($head, 'ROLES');
$baseRoles = constMembers($base, 'ROLES');

foreach ([
    [trim($headSeederSrc) === '', SEEDER.' is unreadable at head '.substr($head, 0, 7)],
    [trim($baseSeederSrc) === '', SEEDER.' is unreadable at base '.substr($base, 0, 7)],
    [$headEnum === [], 'no `case NAME = \'value\';` parsed from '.ENUM.' at head '.substr($head, 0, 7)],
    [$baseEnum === [], 'no `case NAME = \'value\';` parsed from '.ENUM.' at base '.substr($base, 0, 7)],
    [$headRoles === [], 'no members parsed from RbacSeeder::ROLES at head '.substr($head, 0, 7)],
    [$baseRoles === [], 'no members parsed from RbacSeeder::ROLES at base '.substr($base, 0, 7)],
] as [$broken, $why]) {
    if ($broken) {
        notLinted($why.'. Either the file moved or the revision is unreachable; this gate cannot'
            .' look, so it will not be green. If a path changed, update SEEDER/ENUM in this script.');
    }
}

// A member of ROLES that is not shaped like a role name means the parse is unsound, whatever the
// parser. The tokenizer in constMembers() makes the apostrophe class impossible, but this is the
// backstop that does not depend on being right about which classes exist — it is the assertion
// that would have caught the original defect without anyone having thought of apostrophes.
foreach ([[$headRoles, 'head'], [$baseRoles, 'base']] as [$members, $side]) {
    foreach ($members as $member) {
        if (! preg_match('/^[a-z0-9_]+$/', $member)) {
            notLinted("RbacSeeder::ROLES at {$side} parsed a member that is not a role name: "
                .json_encode($member).'. The parse is unsound; exemption 2 cannot be trusted.');
        }
    }
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
// ($headEnum / $baseEnum / $headRoles / $baseRoles were read and validated above, before the
// unchanged-diff early return, so that an unreadable input is NOT LINTED rather than green.)
$headValues = array_flip($headEnum);    // value => constant
$newPermissions = array_values(array_diff($headEnum, $baseEnum));

$newRoles = array_values(array_diff($headRoles, $baseRoles));

$headSeeder = explode("\n", $headSeederSrc);

// The SUPER_ADMIN_PLATFORM line range in the NEW file, for exemption 4 (line-precise, not name-based:
// naming would over-exempt a permission added to super_admin AND to another role in the same diff).
//
// THE TERMINATOR SEARCH IS BOUNDED, and both halves of that matter:
//
//  · The DECLARATION must be a real declaration whose line ENDS in `[`. The first version matched
//    any line CONTAINING `const SUPER_ADMIN_PLATFORM`, so a docblock or comment mentioning the const
//    anchored $sapFrom to itself; the window then ran to whatever `];` came next, which is not the
//    const's, and the real members fell OUTSIDE it. Anchoring on `= [$` excludes comment lines
//    (`*`, `//`) by construction.
//  · A SINGLE-LINE const gets a range of exactly its own line. `= ['a', 'b'];` never matches
//    `/^\s*\];/` on its own line, so the old scan ran on to the NEXT array's terminator: measured
//    on the real seeder, collapsing this const to one line grew the window from 5 lines to 31 and
//    swallowed `ROLES` whole. Put the same collapsed const anywhere above `grantsMap()`'s closing
//    `];` and the window swallows the entire map — every grant addition silently exempt.
//  · The forward scan STOPS at the next `const` / `function` / visibility keyword. If the const's
//    own `];` is not found before the next declaration, the parse is wrong and the range is
//    discarded (null), which disables exemption 4 — failing toward RED.
$sapFrom = $sapTo = null;
foreach ($headSeeder as $i => $line) {
    if (preg_match('/^\s*(?:(?:public|protected|private|final)\s+)*const\s+SUPER_ADMIN_PLATFORM\s*=\s*\[\s*$/', $line)) {
        $sapFrom = $i + 1;
        break;
    }
    // The single-line form: declaration and terminator on one line. Range = that line, nothing more.
    if (preg_match('/^\s*(?:(?:public|protected|private|final)\s+)*const\s+SUPER_ADMIN_PLATFORM\s*=\s*\[.*\];\s*$/', $line)) {
        $sapFrom = $sapTo = $i + 1;
        break;
    }
}

if ($sapFrom !== null && $sapTo === null) {
    for ($i = $sapFrom; $i < count($headSeeder); $i++) {
        if (preg_match('/^\s*\];/', $headSeeder[$i])) {
            $sapTo = $i + 1;
            break;
        }
        if (preg_match('/^\s*(?:(?:public|protected|private|final|abstract|static)\s+)*(?:const|function)\s/', $headSeeder[$i])) {
            $sapFrom = null;   // ran past the end of the const without finding its terminator
            break;
        }
    }
    if ($sapTo === null) {
        $sapFrom = null;
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

/**
 * The nearest preceding `'<role>' => [` above $line in the new file, IF that key is an actual
 * member of `RbacSeeder::ROLES` at head. Inference, not a parse — but inference with a codomain.
 *
 * THE HOLE THE MEMBERSHIP GATE CLOSES. The scan stops at `return [`, which keeps it out of code
 * BELOW the map's opening but does nothing about associative array literals ABOVE it. The shared
 * fragments (`$guardianFull`, `$activityAdmin`, `$assessments`, …) all live there, and today they
 * scan back to nothing and correctly report `?`. Regroup them once —
 *
 *     $byRole = [
 *         'accounts_supervisor' => [ ... ],
 *     ];
 *
 *     $activityAdmin = [
 *         PermissionEnum::ACTIVITY_LOG_VIEW->value,     <- now attributed to accounts_supervisor
 *
 * — and every later fragment addition is attributed to that key. Usually that is only a wrong
 * label on a red. It becomes a SILENT GREEN when the wrong key is in $newRoles, and
 * {@see constMembers}'s apostrophe defect could manufacture exactly that.
 *
 * ONE MECHANISM, NOT TWO PATCHES, and the claim is checked rather than asserted:
 * `$newRoles = array_diff($headRoles, $baseRoles)` is a SUBSET of `$headRoles` by definition of
 * array_diff. So restricting this function's range to `$headRoles` can never withhold a role that
 * exemption 2 would have matched — exemption 2 is unreachable for anything outside that set. The
 * gate is therefore free on the legitimate path and total on the illegitimate one. It also holds
 * independently of the tokenizer fix: junk members contain spaces, newlines and `//`, so they can
 * never be produced by the `[a-z0-9_]+` key pattern here even if the parse were still garbled.
 *
 * Returning null is not a soft outcome. A null role cannot satisfy exemption 2, and cannot satisfy
 * exemption 3 either (see below) — so the addition is FLAGGED. That is correct for the fragment
 * case: a permission added to a shared fragment lands on every pre-existing role that spreads it,
 * and every one of those needs the convergence migration.
 */
$inferRole = function (int $line) use ($headSeeder, $headRoles): ?string {
    for ($i = min($line, count($headSeeder)) - 1; $i >= 0; $i--) {
        if (preg_match('/^\s*[\'"]([a-z0-9_]+)[\'"]\s*=>\s*\[/', $headSeeder[$i], $m)) {
            return in_array($m[1], $headRoles, true) ? $m[1] : null;
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

        // Exemption 3 needs the migration to converge THIS PAIR, not merely to mention the
        // permission. The first version stopped at the first added migration whose content named
        // the permission, with no role check at all — so 7370e89, this lint's own canonical defect,
        // would have been exempted on BOTH roles by a migration converging only one of them. A
        // convergence migration is per (role, permission); the exemption has to be too.
        //
        // THE HONEST CONSTRAINT, stated rather than papered over: the role is INFERRED from source
        // text (see $inferRole). So this check is only as sound as that inference, and when the
        // inference yields null there is nothing to check against — the pair is unknown, and an
        // exemption on an unknown pair is a guess in the silent direction. A null role therefore
        // does NOT exempt. The cost is a false red on a legitimate shared-fragment convergence;
        // the failure message says which role it could not resolve, and a red a human reads is the
        // outcome this gate is for.
        $migration = null;
        foreach ($addedMigrations as $path => $content) {
            if ($role !== null && namesPermission($content, $permission) && namesRole($content, $role)) {
                $migration = $path;
                break;
            }
        }

        $exemption = match (true) {
            in_array($permission, $newPermissions, true) => 'permission is NEW in this diff (lands in $newPermissions)',
            $role !== null && in_array($role, $newRoles, true) => "role [{$role}] is NEW in this diff (takes the full \$permissions array)",
            $migration !== null => "migration [{$migration}] in this diff names it AND names role [{$role}]",
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
      content name BOTH the permission and the role (that is exemption 3 — both halves,
      because a migration converging one role must not exempt the same permission added
      to another). If the role above reads `?`, the addition is in a shared fragment and
      the migration must converge every pre-existing role that spreads it.
    · The permission is genuinely new — add its `case` to app/Enums/Permission.php in the
      same diff (exemption 1).
    · The role is genuinely new — add it to RbacSeeder::ROLES in the same diff (exemption 2).

  NOT RETROACTIVE: this lint sees diffs only. For grants that have ALREADY drifted, run
  `php artisan rbac:diff-grants`.

TXT);

exit(1);
