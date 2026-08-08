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
 * four exemptions holds.
 *
 * READ THIS QUALIFICATION BEFORE TRUSTING THAT SENTENCE. Coverage is per-pair for permissions this
 * lint can RESOLVE, and {@see $resolvePermissions} reads exactly two forms: `PermissionEnum::X->value`,
 * and a quoted string that is a real enum value at head.
 *
 * WHAT IT NOW RESOLVES BEYOND A SINGLE LINE. Shared fragments are read too — see the fragment model.
 * `$fragment = [ … ];` blocks defined between `function grantsMap()` and its `return [` are tabled
 * with the permissions they carry, and the roles that consume them (`...$fragment,` and
 * `'<role>' => $fragment,`, both forms) are indexed. Two things follow, and both are the whole point:
 *
 *   · An ADDED consumption of a fragment reports one finding per permission the fragment carries,
 *     attributed to the role at that site. This was a SILENT GREEN — a spread is neither accepted
 *     form, so it resolved nothing while `rbac:sync` granted nothing, and the map's most common line
 *     went unseen (`grantsMap()['admin']` opens with six consecutive spreads before its first
 *     literal permission, and `'head_of_school'` repeats the same six).
 *   · A permission added to a fragment's own DEFINITION reports one finding per role that spreads
 *     it. That addition used to resolve fine and then print `?`, which disables exemptions 2 and 3 —
 *     flagged with no path to green, and no marker able to clear it.
 *
 * A NEW GRANT IS NOT A RESTRUCTURE. The model is also built at BASE, so a diff that merely rewrites
 * how a role consumes a fragment it already consumed reports nothing. 9caf958 is the live shape:
 * `'boarding_parent' => $assessments,` became `'boarding_parent' => [ ...$assessments, … ]`, which
 * is an added spread line carrying six pre-existing permissions and zero new grants.
 *
 * WHAT IT STILL DOES NOT RESOLVE. A NESTED spread — a fragment whose own body spreads another —
 * REFUSES rather than passing: it is `NOT LINTED`, never a quiet skip, because resolving
 * transitively would depend on a definition order this scanner does not parse. So does a consumption
 * of a `$name` the table could not find. Neither is a silent zero.
 *
 * The four exemptions:
 *
 *   1. THE PERMISSION IS NEW — the same diff adds its `case` to `app/Enums/Permission.php`. It then
 *      lands in `$newPermissions` and `rbac:sync` grants it. No migration needed.
 *
 *      ⚠️ "NO MIGRATION NEEDED" IS A STATEMENT ABOUT THIS LINT, NOT ABOUT THE DEPLOY. Read as the
 *      latter it is false, and it has already been read that way once (§9 step 4c, 2026-08-09). This
 *      lint asks "will the grant LAND on every environment?" and for a new permission the answer is
 *      yes. It does not ask "will the grant SURVIVE?" — and a FORCING convergence migration dated
 *      later revokes it, because a forcing TARGET makes a role's slice EQUAL a frozen literal and is
 *      scoped by a NAMESPACE PREFIX that never expires. `rbac:sync` will not put it back: by then
 *      the permission is no longer new, and `RbacSeeder::sync()` grants an existing role only
 *      permissions created in that same run.
 *
 *      Live instance: `2026_08_06_100000_move_head_of_school_finance_to_executive_director` forces
 *      `finance.` on `executive_director` / `accounts_supervisor` / `head_of_school`. It stripped
 *      4c's three `finance.opening-balance.*` grants on the runbook order (`rbac:sync`, then
 *      `migrate`) — measured, not reasoned. The repair is a NEW additive convergence migration dated
 *      after it (`2026_08_09_110000`), declared through exemption 3. So: before relying on this
 *      exemption, check whether a forcing migration governs the role and namespace you are granting
 *      into. See ADR 0052 § "A FORCING target freezes a namespace, not a row set".
 *   2. THE ROLE IS NEW — the same diff adds the role to `RbacSeeder::ROLES`. Then
 *      `in_array($roleName, $existingRoles, true)` is false and the role receives the FULL
 *      `$permissions` array. No migration needed.
 *   3. A MIGRATION IN THE DIFF DECLARES THE PAIR — a file ADDED under `database/migrations/`
 *      carrying a line whose whole content is `@converges <role> <permission>`
 *      ({@see declaredConvergences}). The migration's AUTHOR states each pair it converges; this
 *      lint reads that declaration and nothing else in the file. Not merely "a migration exists in
 *      the diff", which any unrelated migration would satisfy; and not "a migration names the
 *      permission", which would have exempted BOTH roles in 7370e89 on a migration converging one —
 *      the marker keeps that per-pair by construction rather than by inference. A null inferred role
 *      does not exempt: there is no pair to look up. See the imprecision note below.
 *
 *      IT USED TO READ PROSE, AND PROSE CANNOT ANSWER THIS QUESTION. The previous design asked
 *      whether the file's raw text named the permission and named the role, both boundary-matched.
 *      A convergence migration documents the roles it deliberately EXCLUDES — which is the right
 *      thing for it to do — and an exclusion, a caveat and a grant are the same bytes. Measured on
 *      the real `2026_08_05_100000_converge_finance_access_grants.php`, those two predicates were
 *      true together for NINE of the fourteen roles. No rewording fixes that, because every future
 *      convergence migration has the same property. {@see declaredConvergences} carries the full
 *      derivation, including the boundary facts the old predicates were built on.
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
 * parse PHP.) It reports `?` rather than guessing. THE PERMISSION AND THE FILE:LINE ARE EXACT; the
 * role is marked `inferred`. This lint's job is to make a human look. It is not a proof.
 *
 * A `?` ROLE IS NOT AN EXEMPTION. It disables exemptions 2 and 3 for that addition, so the addition
 * is flagged. That is the safe direction.
 *
 * WHAT A `?` MEANS NOW, WHICH IS NOT WHAT IT USED TO MEAN. It used to be the answer for the shared
 * `$guardianFull` / `$activityAdmin` style fragments defined ABOVE `return [` — correct as far as it
 * went, and useless to the author, since exemption 3 is a lookup on (role, permission) and there was
 * no role to look up. Those additions are now fanned out to every role that consumes the fragment
 * and arrive with REAL role names. The residual `?` is a shape this lint could not read at all: an
 * addition above `return [` that sits inside no fragment the table resolved. It must still flag, and
 * the remedy is to report the shape — NOT to restructure the seeder, which is a silent green (see
 * $inferRole).
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

/**
 * Every git call this lint makes. Its output is PARSED — `^+` line tests on `git diff` in two places,
 * `substr($t[1], 1, -1)` on tokenized `git show` output — so it must be byte-plain regardless of the
 * invoking user's git config, and that has to be forced here rather than remembered at each call site.
 *
 * WHY IT WAS GREEN BY LUCK. `shell_exec` gives git no tty, so `color.ui=auto` (the default) leaves
 * colour off — which is the only reason this ever worked. `color.ui=always` overrides auto, and then
 * every added line arrives as `\e[32m+…` and the `^+` tests match nothing: measured on `7370e89`, this
 * lint's own canonical red, the run went from `exit 1` with two findings to `exit 0` with zero. A gate
 * whose docblock says IF IT CANNOT LOOK, IT IS NOT GREEN, going silently green on one config flag in
 * one developer's `~/.gitconfig`, with no remote check anywhere to disagree — that is the exact shape
 * it was written to forbid.
 *
 * `-c color.ui=false` beats a config file's `always` (command-line `-c` is applied last) and covers
 * `color.diff` with it.
 *
 * `--no-ext-diff` is the second half, and it goes AFTER the subcommand because it is a diff-family
 * option, not a main-command one. `diff.external` is worse than colour: it does not garble the output,
 * it replaces it. Measured against a global config carrying `diff.external = /bin/false`, an unguarded
 * `git diff` returns `fatal: external diff died` and 0 added lines — which this lint reads as "no
 * additions", exit 0. Note the fix cannot be spelled `-c diff.external=`: git runs the empty string as
 * a command and dies. It has to be the flag.
 */
function git(string ...$args): string
{
    if (in_array($args[0] ?? '', ['diff', 'show', 'log'], true)) {
        array_splice($args, 1, 0, '--no-ext-diff');
    }

    $cmd = 'git -c color.ui=false '
        .implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';

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
 * them — a silent green, the same class the substring hole in exemption 3 was
 * ({@see declaredConvergences}, which records it).
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
 * Every `@converges <role> <permission>` marker declared by the migrations added in this diff.
 *
 * THE MARKER IS THE WHOLE OF EXEMPTION 3. A pair is exempt only if some added migration DECLARES
 * that exact pair. Nothing else in a migration's text can exempt anything, and there is no fallback
 * to reading the file.
 *
 * WHY FREE TEXT WAS REPLACED, AND WHY NO BOUNDARY REGEX COULD HAVE SAVED IT. Exemption 3 used to ask
 * two questions of a migration's raw text — does it name the permission as a whole name, does it
 * name the role as a whole name — and both predicates were boundary-matched from DERIVED facts, not
 * guessed ones. Those facts are kept here, because they are the argument FOR the marker:
 *
 *   · PERMISSION values needed a RIGHT boundary only. Of the 79 enum values there are 9 prefix
 *     pairs, 0 suffix pairs and 0 mid-string pairs:
 *
 *       activity_log.view    ⊂ .view_all / .view_own / .view_system / .view_cross_school / .view_sensitive
 *       guardian.view        ⊂ guardian.view_audit
 *       guardian.update      ⊂ guardian.update_credentials
 *       result.view          ⊂ result.view_scores
 *       student_subject.view ⊂ student_subject.view_history
 *
 *     Under the original raw `str_contains`, a migration naming only `activity_log.view_all`
 *     exempted a grant of `activity_log.view` — a silent green, in exactly the class the gate
 *     exists for (fixed in d08edf0).
 *   · ROLE names have the OPPOSITE shape and needed BOTH boundaries: `admin` is a suffix of
 *     `super_admin`, `teacher` of `form_teacher`. A right-boundary-only test would have let a
 *     migration naming `super_admin` count as naming `admin`.
 *
 * Both fixes were correct, and neither was enough. With both boundaries perfectly placed the
 * predicate still cannot tell an ASSERTION from a MENTION. A convergence migration documents the
 * roles it deliberately EXCLUDES — which is the right thing for a migration to do — and an
 * exclusion, a caveat and a grant are the same bytes. Measured on the real
 * `2026_08_05_100000_converge_finance_access_grants.php`: `namesPermission('finance.access')` was
 * true, and `namesRole` was true for NINE of the fourteen roles — `internal_auditor` off the
 * sentences saying it must NOT receive the permission, `registrar` off the words "registrar cache
 * flushed after". That file was a blanket exemption carrier for 9 roles for as long as it sat inside
 * a diff range, and rewording it fixes nothing: every future convergence migration has the same
 * property. Third repair to this one exemption for this one class (d08edf0 substring, dde75e4 silent
 * greens, now prose), so the MECHANISM was replaced rather than the instance reworded.
 *
 * THE PATTERN. Two things in it are load-bearing, and they are load-bearing for DIFFERENT reasons —
 * measured, because the obvious account of them is wrong:
 *
 *   · THE `$` ANCHOR is what stops a trailing smuggle. `@converges auditor activity_log.view and
 *     also bursar` must declare NOTHING AT ALL — not "declares auditor" — and that is the anchor's
 *     doing. Drop it and the line declares its first pair while reading to a human as if it declared
 *     two. Pinned by the TRAILING PROSE arm in GrantsConvergenceLintTest.
 *
 *   · `[ \t]` RATHER THAN `\s` keeps the whole marker on ONE LINE. Note what this is NOT: `\s` does
 *     not open the trailing-smuggle hole, because prose is not whitespace and `\s*$` cannot cross
 *     it — measured, both forms reject the line above. What `\s` does open is assembly across lines,
 *     since `\s` matches `\n`:
 *
 *          * @converges auditor
 *            activity_log.view
 *
 *     declares the pair under `\s+` separators and declares nothing under `[ \t]+`. A marker must be
 *     a single deliberate line, so keep it tight. Do not loosen either one.
 *
 * The optional `*` / `//` / `#` lead-in lets the marker live in a docblock, a line comment or a hash
 * comment. Nothing else may precede it on the line, and nothing may follow it.
 *
 * WHAT THE PATTERN DELIBERATELY REJECTS, and how the author finds out. Four good-faith shapes yield
 * ZERO matches — a CRLF line ending (`\r` is not `[ \t]`, so the anchor never lands), a marker
 * written on the same line as its docblock opener AND closer (the closer is not whitespace), a line
 * wrapped between the role and the permission, and any tail after the permission. (The middle one
 * cannot be shown here: its closer would end this docblock. The failure notice spells it out, and
 * MARKER 8 in GrantsConvergenceLintTest carries it verbatim.) Every one is safe: it declares nothing and
 * the run goes red. But a red with no explanation is how a gate loses its author, so the CALLER
 * counts `@converges` mentions against parses and prints a notice naming the file. That notice, not
 * a looser pattern, is the answer to "my marker did not work" — see $unparsedMarkers.
 *
 * Markers are returned UNVALIDATED. The caller checks the role against `RbacSeeder::ROLES` at head
 * and the permission against the enum at head, and ECHOES the unrecognised ones rather than gating
 * on them — a typo already fails the run by not exempting, so the red is there either way.
 *
 * @param  array<string, string>  $migrations  path => content
 * @return list<array{path: string, role: string, permission: string}>
 */
function declaredConvergences(array $migrations): array
{
    $out = [];

    foreach ($migrations as $path => $content) {
        if (! preg_match_all(
            '/^[ \t]*(?:\*|\/\/|#)?[ \t]*@converges[ \t]+([A-Za-z0-9_]+)[ \t]+([A-Za-z0-9_.\-]+)[ \t]*$/m',
            $content,
            $m,
            PREG_SET_ORDER
        )) {
            continue;
        }

        foreach ($m as $set) {
            $out[] = ['path' => $path, 'role' => $set[1], 'permission' => $set[2]];
        }
    }

    return $out;
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

// THE ASSERTION THAT MAKES THE COMMENT STRIP HONEST RATHER THAN LUCKY. {@see $resolvePermissions}
// removes a trailing `//` or `#` tail before it scans, so that a permission value MENTIONED in a
// comment cannot be read as one GRANTED by the line. That strip is only safe while no permission
// value contains those characters — today none does, and every value is `[a-z_]+(\.[a-z_-]+)*`.
// Leaving that as a happy accident is how the next enum addition silently truncates a real grant
// line at its own name, so it is asserted here instead: the strip and this check ship together and
// neither is correct without the other.
foreach ($headEnum as $constant => $value) {
    if (str_contains($value, '//') || str_contains($value, '#')) {
        notLinted('permission value '.json_encode($value)." (case {$constant}) contains `//` or `#`."
            .' {@see $resolvePermissions} strips a trailing comment before resolving, so that value'
            .' would truncate the line that grants it and the grant would resolve to nothing.'
            .' Rename the case, or replace the tail-strip with a real lexer.');
    }
}
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
//
// `A` ONLY, AND THAT IS A RULE RATHER THAN A CONVENIENCE. A migration already present on the base
// has already RUN on every seeded environment; a marker added to it now declares a convergence that
// never happened. Collecting `M` here would exempt on a promise nothing kept — a false green of
// exactly the kind this gate exists to stop. (The range is `base...head`, so a migration added in an
// EARLIER COMMIT of the same branch is still `A`: the ordinary workflow — lint goes red, author adds
// the marker to the migration they committed an hour ago — works. The gap needs the migration to
// predate the base, and that case is reported by $markersOnModified below, never exempted.)
$addedMigrations = [];
foreach (explode("\n", git('diff', '--name-status', '--diff-filter=A', $base.'...'.$head)) as $line) {
    $parts = preg_split('/\t/', trim($line));
    if (count($parts) < 2 || ! str_starts_with($parts[1], 'database/migrations/')) {
        continue;
    }
    $addedMigrations[$parts[1]] = git('show', $head.':'.$parts[1]);
}

// Markers on MODIFIED migrations, collected for the NOTICE and nothing else. These exempt nothing
// (see the rule above) and must never reach $addedMigrations or $declared — but an author who wrote
// one is owed the reason, and $unparsedMarkers cannot give it because it walks $addedMigrations.
// Without this, a byte-perfect marker on a pre-existing migration produces a byte-identical red and
// no explanation: the same dead end the `?`-role remedy was rewritten to remove, reached by a
// different route.
// COUNTED ON THE ADDED LINES OF THE PATCH, NOT ON THE FILE AT HEAD — the other half of the
// `--diff-filter=A` rule above. `git show $head:<path>` never compares against the base, so it counts
// markers that were ALREADY THERE and merely sit in a file this branch touched for an unrelated
// reason. The notice's own words assert an author action ("a marker added to it"), so counting the
// file at head makes it accuse an author who did nothing. Live shape, not hypothetical: a comment-only
// docblock edit to a shipped convergence migration is `M` and carries its old markers.
//
// `--unified=0` so no context line can be miscounted, and the `+++` test so the diff header is not
// read as content.
//
// "ADDED" MEANS ADDED-IN-PATCH, AND THAT INCLUDES A MOVE. A marker relocated within an
// already-shipped migration counts and will report. Accepted rather than engineered around: a marker
// on a migration that has already run exempts nothing whichever line it sits on, so the notice is
// still telling the truth about consequences even when it is wrong about intent. And adds are NOT
// netted against deletes — an author who deletes one marker and adds a different one has done exactly
// the thing this notice exists to catch, and netting would silence it.
$markersOnModified = [];
foreach (explode("\n", git('diff', '--name-status', '--diff-filter=M', $base.'...'.$head)) as $line) {
    $parts = preg_split('/\t/', trim($line));
    if (count($parts) < 2 || ! str_starts_with($parts[1], 'database/migrations/')) {
        continue;
    }

    $patch = git('diff', '--unified=0', $base.'...'.$head, '--', $parts[1]);
    $count = 0;
    foreach (explode("\n", $patch) as $patchLine) {
        if ($patchLine === '' || $patchLine[0] !== '+' || str_starts_with($patchLine, '+++')) {
            continue;
        }
        $count += preg_match_all('/@converges/', $patchLine);
    }

    if ($count > 0) {
        $markersOnModified[$parts[1]] = $count;
    }
}

// The `@converges` declarations those migrations carry. Extracted ONCE, here, next to the migrations
// themselves, so exemption 3 below is a single array read rather than a rescan of every migration's
// text per finding.
//
// A marker whose role or permission does not exist at head is NOT a declaration — it is recorded
// separately and echoed on the failing path so a typo is visible, and it exempts nothing. Testing
// membership of `$headRoles` is sufficient and subsumes `$newRoles`: `$newRoles =
// array_diff($headRoles, $baseRoles)` is a subset of `$headRoles` by definition of array_diff.
//
// First declaration wins on a duplicate pair, matching the `break`-at-first-match the text scan had.
$declared = [];         // "role\0permission" => 'database/migrations/<file>'
$unknownMarkers = [];   // markers that matched no role / no permission at head

$allMarkers = declaredConvergences($addedMigrations);

foreach ($allMarkers as $marker) {
    $roleKnown = in_array($marker['role'], $headRoles, true);
    $permissionKnown = isset($headValues[$marker['permission']]);

    if (! $roleKnown || ! $permissionKnown) {
        $unknownMarkers[] = $marker + ['why' => $roleKnown ? 'no such permission' : 'no such role'];

        continue;
    }

    $declared[$marker['role']."\0".$marker['permission']] ??= $marker['path'];
}

// A marker the author WROTE but the parser could not READ produces no ⚠ below — it never became a
// marker, so it is not in $unknownMarkers, which only holds markers that PARSED and then named a
// role or permission absent at head. Measured against the pattern: CRLF line endings, a one-line
// `/** @converges … */`, a line wrapped between the role and the permission, and a tail after the
// permission all yield ZERO matches, and every one of them is an author who believes they declared
// the pair and gets no signal at all.
//
// COUNTING MENTIONS AGAINST PARSES covers that class instead of chasing each shape into the regex.
// Chasing shapes is how the prose predicates this exemption just replaced grew in the first place —
// each accommodation looked local and the sum could not tell an assertion from a mention.
//
// KNOWN FALSE POSITIVE, accepted: prose containing the literal `@converges` (a migration explaining
// the convention) inflates the mention count and fires the notice. The cost is one noise line on an
// already-red run. Deliberately NOT suppressed — a notice that is occasionally chatty is cheaper
// than a second parser, and a second parser is the road this change exists to close.
$unparsedMarkers = [];
foreach ($addedMigrations as $path => $content) {
    $mentions = preg_match_all('/@converges/', $content);
    $parsed = count(array_filter($allMarkers, fn (array $m): bool => $m['path'] === $path));

    if ($mentions > $parsed) {
        $unparsedMarkers[$path] = $mentions - $parsed;
    }
}

/**
 * The nearest preceding `'<role>' => [` above $line in the new file, IF that key is an actual
 * member of `RbacSeeder::ROLES` at head. Inference, not a parse — but inference with a codomain.
 *
 * THE HOLE THE MEMBERSHIP GATE CLOSES — AND IT IS A HAZARD ON THE RECORD, NEVER A SUGGESTION. The
 * scan stops at `return [`, which keeps it out of code BELOW the map's opening but does nothing
 * about associative array literals ABOVE it. The shared fragments (`$guardianFull`, `$activityAdmin`,
 * `$assessments`, …) all live there. Regrouping them beneath a role key was once printed to authors
 * as the remedy for a `?`; it is not, it is a defect generator, and the failure text no longer says
 * it. Do it once —
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
 * exemption 3 either (see below) — so the addition is FLAGGED. Since the fragment model landed, null
 * no longer means "a shared fragment": those additions are attributed to every role that consumes
 * the fragment and never reach this function's null path for their attribution. What remains is a
 * shape this file could not read, which is a parser gap worth a human's eyes.
 */
// A FACTORY rather than a bare closure, because the same inference is now needed over TWO
// revisions: the head seeder (to attribute findings) and the base seeder (to know which roles
// already consumed a fragment before this diff). One mechanism, applied twice — a second
// hand-rolled backward scan over the base lines would be a place for the two to disagree.
// The codomain stays `RbacSeeder::ROLES` AT HEAD in both cases: a role that does not exist at head
// cannot receive anything at head, so nothing is withheld by not consulting the base list.
$inferRoleIn = function (array $lines) use ($headRoles): Closure {
    return function (int $line) use ($lines, $headRoles): ?string {
        for ($i = min($line, count($lines)) - 1; $i >= 0; $i--) {
            if (preg_match('/^\s*[\'"]([a-z0-9_]+)[\'"]\s*=>\s*\[/', $lines[$i], $m)) {
                return in_array($m[1], $headRoles, true) ? $m[1] : null;
            }
            // Do not scan out of the map into unrelated code.
            if (preg_match('/^\s*return \[/', $lines[$i])) {
                return null;
            }
        }

        return null;
    };
};

$inferRole = $inferRoleIn($headSeeder);

/**
 * The ONE permission resolver. Two accepted forms, and a quoted string is kept only when it is a
 * real enum VALUE at head — that is what keeps role keys (`'internal_auditor' => [`) and the
 * dot-less permission names (`view_psychomotor_skills`) apart without guessing at their shape.
 *
 * IT IS A FUNCTION SO THAT THE FRAGMENT TABLE CANNOT DRIFT FROM THE FINDINGS LOOP. Both callers
 * resolve by the same two forms, so "what a fragment carries" and "what an added line grants" are
 * the same question answered by the same code. A second resolver for fragment bodies would be a
 * place for the two to disagree, and the disagreement would be silent in the safe-looking
 * direction — a fragment that resolves fewer permissions than the line-level scanner fans out
 * fewer findings, which reads exactly like "no defect here".
 *
 * A form this cannot read is a permission the line does not carry. That is already the rule
 * everywhere else in this file, and it is why a NESTED spread refuses rather than resolving: the
 * refusal is the alternative to silently carrying zero.
 *
 * @return list<string>
 */
$resolvePermissions = function (string $text) use ($headEnum, $headValues): array {
    // A MENTION IS NOT A GRANT — the same distinction exemption 3 was rewritten for, one layer down.
    // The quoted-string scan below is a FLOATING quote-pair scan, with no idea what a quote means,
    // and a trailing comment is the one place a permission value appears in seeder text without
    // being granted: `PermissionEnum::A->value,  // deliberately NOT 'activity_log.export'`.
    //
    // WHY THAT WAS WORSE THAN A MIS-PARSE. The fragment-body scanner strips only WHOLE-LINE
    // comments, so such a tail reached this function and put the mentioned permission into the
    // fragment's set at BASE — and $baseFragmentGrants is used to SUPPRESS findings. The genuine
    // addition of that permission on the branch then reported nothing. Measured: red on staging's
    // lint, green here, before this strip.
    //
    // A tail-strip is naive on purpose and is safe only because no permission value contains `//`
    // or `#`. That is ASSERTED where $headValues is built, not assumed — the two ship together.
    // Callers are all seeder text; declaredConvergences deliberately does NOT use this, because its
    // markers live in comments by design.
    $text = (string) preg_replace('~(//|#).*$~m', '', $text);

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

    return array_values(array_unique($permissions));
};

// ---------------------------------------------------------------- the fragment model
// THE BLIND SPOT THIS CLOSES. Until this existed, an added `...$fragment,` line resolved to NOTHING
// — a spread is neither of the two forms the resolver accepts — so an added spread of a
// PRE-EXISTING fragment into a PRE-EXISTING role granted every permission in that fragment,
// `rbac:sync` granted none of them, and this gate exited 0. That is not an exotic shape:
// `grantsMap()['admin']` opens with SIX consecutive spreads before its first literal permission,
// and `'head_of_school'` repeats the same six. It is how the map is written.
//
// The model is built over the region between `function grantsMap()` and its `return [` (where
// fragments are defined) and the map array below it (where roles consume them). It is built TWICE —
// once at head, to attribute findings, and once at base, to tell a NEW GRANT from a RESTRUCTURE.
// See $baseFragmentGrants for what the base copy is for and what it deliberately does not cover.
//
// `$strict` is the difference between the two calls. At HEAD an unreadable model is NOT LINTED: the
// gate's own rule, since a missing table silently carries zero permissions per spread line, which is
// the defect this section exists to close. At BASE an unreadable model degrades to `null` and the
// caller treats it as "no prior grants", which over-reports rather than under-reports — a false RED,
// never a false green.
/**
 * @return array{
 *     fragments: array<string, array{from: int, to: int, permissions: list<string>}>,
 *     spreadIndex: array<string, list<string>>,
 *     spreadSites: array<int, array{fragment: string, role: string|null}>
 * }|null
 */
$fragmentModel = function (array $lines, bool $strict) use ($resolvePermissions, $inferRoleIn, $headRoles): ?array {
    $fail = function (string $why) use ($strict): ?array {
        if ($strict) {
            notLinted($why);
        }

        return null;
    };

    $mapFromIdx = $mapToIdx = null;
    foreach ($lines as $i => $line) {
        if ($mapFromIdx === null) {
            if (preg_match('/function\s+grantsMap\s*\(/', $line)) {
                $mapFromIdx = $i;
            }

            continue;
        }
        if (preg_match('/^\s*return \[/', $line)) {
            $mapToIdx = $i;
            break;
        }
    }

    if ($mapFromIdx === null || $mapToIdx === null) {
        return $fail('could not locate `function grantsMap()` and its `return [` in '.SEEDER
            .'. The fragment table cannot be built, so an added `...$fragment,` would resolve no'
            .' permissions and this gate would be green on a diff it did not read.');
    }

    // ---- the fragments themselves: name => line range + the permissions they carry
    $fragments = [];
    $openName = null;
    $openFrom = 0;
    $openPermissions = [];

    for ($i = $mapFromIdx + 1; $i < $mapToIdx; $i++) {
        $line = $lines[$i];

        if ($openName === null) {
            // The SINGLE-LINE form is tested first, and it is tested at all because of the lesson
            // SUPER_ADMIN_PLATFORM's range scan paid for: `$x = ['a'];` never matches `/^\s*\];/` on
            // its own line, so treating it as an opener would run this fragment's range on to the
            // NEXT fragment's terminator and merge the two.
            if (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\[(.*)\];\s*$/', $line, $m)) {
                if (str_contains($m[2], '...$')) {
                    return $fail("fragment \${$m[1]} at ".SEEDER.':'.($i + 1).' nests another'
                        .' fragment. This lint resolves ONE level of spread and will not guess at'
                        .' more; flatten it, or extend the fragment table deliberately.');
                }
                $fragments[$m[1]] = [
                    'from' => $i + 1,
                    'to' => $i + 1,
                    'permissions' => $resolvePermissions($m[2]),
                ];

                continue;
            }
            if (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\[\s*$/', $line, $m)) {
                $openName = $m[1];
                $openFrom = $i + 1;
                $openPermissions = [];
            }

            continue;
        }

        if (preg_match('/^\s*\];/', $line)) {
            $fragments[$openName] = [
                'from' => $openFrom,
                'to' => $i + 1,
                'permissions' => array_values(array_unique($openPermissions)),
            ];
            $openName = null;

            continue;
        }

        $stripped = ltrim($line);
        if (str_starts_with($stripped, '//') || str_starts_with($stripped, '*')
            || str_starts_with($stripped, '/*') || str_starts_with($stripped, '#')) {
            continue;
        }

        // A NESTED SPREAD REFUSES LOUDLY. Resolving transitively is the tempting alternative and it
        // is wrong here: the fan-out would then depend on a definition order this scanner does not
        // parse, and a cycle would not terminate. Skipping quietly is worse still — it is the
        // original defect, one layer down. No fragment nests today, so this costs nothing until
        // someone writes one, at which point the gate says so instead of guessing. Reuses
        // notLinted() rather than inventing a second not-looked message, for the reason that
        // function's own docblock gives.
        if (preg_match('/^\s*\.\.\.\$([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            return $fail("fragment \${$openName} in grantsMap() nests another fragment (...\${$m[1]}"
                .' at '.SEEDER.':'.($i + 1).'). This lint resolves ONE level of spread and will not'
                .' guess at more: a transitive resolution depends on a definition order it does not'
                .' parse. Flatten the fragment, or extend the fragment table deliberately.');
        }

        $openPermissions = array_merge($openPermissions, $resolvePermissions($line));
    }

    if ($openName !== null) {
        return $fail("fragment \${$openName} opened at ".SEEDER.':'.$openFrom.' is never closed by a'
            ." `];` before grantsMap()'s `return [`. The fragment table would be wrong, and a wrong"
            .' table under-reports rather than over-reports — so this gate will not be green on it.');
    }

    // ---- who consumes them: for each fragment, the roles that receive the WHOLE fragment
    //
    // TWO CONSUMPTION FORMS, AND BOTH ARE LOAD-BEARING. `...$fragment,` is the one the map uses
    // today. `'<role>' => $fragment,` grants exactly the same set — it is what `boarding_parent`
    // used before 9caf958 rewrote it — and indexing only the first would have made a permission
    // added to such a fragment fan out to NOBODY and exit 0, where before this change it was a red
    // `?`. Closing one silent green by opening another is not a fix, so both are read.
    //
    // The scan is bounded to the map's own array. Role blocks close with `],`; the first `];` after
    // `return [` is the map's own terminator. The declaration keyword is a second, independent stop,
    // so a malformed map cannot walk this scan into unrelated code and attribute a spread there.
    $inferRole = $inferRoleIn($lines);
    $spreadIndex = [];
    $spreadSites = [];

    for ($i = $mapToIdx + 1; $i < count($lines); $i++) {
        $line = $lines[$i];

        if (preg_match('/^\s*\];/', $line)) {
            break;
        }
        if (preg_match('/^\s*(?:(?:public|protected|private|final|abstract|static)\s+)*(?:const|function)\s/', $line)) {
            break;
        }

        $name = $role = null;

        if (preg_match('/^\s*\.\.\.\$([A-Za-z_][A-Za-z0-9_]*)\s*,/', $line, $m)) {
            // A spread sits INSIDE a role key, so $inferRole resolves a real role here — the null
            // case that prints `?` belongs to additions above `return [`, not to these lines.
            $name = $m[1];
            $role = $inferRole($i + 1);
        } elseif (preg_match('/^\s*[\'"]([a-z0-9_]+)[\'"]\s*=>\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*,/', $line, $m)) {
            // The role is ON this line, not above it — $inferRole would scan past it to the
            // PREVIOUS role key and attribute the whole fragment to the wrong seat.
            $name = $m[2];
            $role = in_array($m[1], $headRoles, true) ? $m[1] : null;
        }

        // A CONSUMPTION THIS SCAN CANNOT READ REFUSES, rather than falling through to `continue`.
        // `'bursar' => [...$activityStaff],` on ONE line grants the whole fragment and matches
        // NEITHER form — the first wants the spread at line start, the second wants a bare `$name`
        // after `=>`. Before this, that line was skipped in silence: defect A in full, on a line
        // `pint --test` is perfectly happy with, and the partial case is worse still (the run goes
        // red naming only the literal, the author writes that one marker, and the fragment's grants
        // ship unconverged behind a green gate).
        //
        // WIDENING THE REGEXES TO PARSE INLINE MIXED ARRAYS IS THE WRONG FIX. That is a parser, and
        // every accommodation it grows is a place for a mention to read as a grant — the road this
        // file has already walked twice. Refusing costs nothing today: no line in the seeder needs it.
        if ($name === null) {
            if (str_contains($line, '...$')) {
                return $fail('grantsMap() consumes a fragment at '.SEEDER.':'.($i + 1).' in a form'
                    .' this lint does not read: '.trim($line).'. Put the spread on its own line as'
                    .' `...$fragment,` inside the role key. Reading it inline would mean parsing'
                    .' mixed array literals, and a scanner that guesses at those is how a MENTION'
                    .' starts reading as a GRANT.');
            }

            continue;
        }

        // A consumption of something the table could not resolve is the original defect wearing a
        // different hat: the line grants a set this lint cannot enumerate. It refuses rather than
        // carrying zero.
        if (! isset($fragments[$name])) {
            return $fail("grantsMap() spreads \${$name} at ".SEEDER.':'.($i + 1).' but no `$'.$name
                .' = [` fragment was found between `function grantsMap()` and its `return [`. This'
                .' lint cannot enumerate what that line grants, and carrying zero permissions for it'
                .' is exactly the silent green the fragment table exists to close.');
        }

        $spreadSites[$i + 1] = ['fragment' => $name, 'role' => $role];

        if ($role !== null && ! in_array($role, $spreadIndex[$name] ?? [], true)) {
            $spreadIndex[$name][] = $role;
        }
    }

    return ['fragments' => $fragments, 'spreadIndex' => $spreadIndex, 'spreadSites' => $spreadSites];
};

$headModel = $fragmentModel($headSeeder, true);
$fragments = $headModel['fragments'];
$spreadIndex = $headModel['spreadIndex'];
$spreadSites = $headModel['spreadSites'];

// WHAT EACH ROLE ALREADY RECEIVED THROUGH A FRAGMENT AT BASE — the difference between a NEW GRANT
// and a RESTRUCTURE.
//
// THE COMMIT THAT FORCED THIS, and it is the one commit in the suite that must never go red. 9caf958
// rewrote `'boarding_parent' => $assessments,` into `'boarding_parent' => [ ...$assessments, … ]`.
// Line-wise that is an ADDED `...$assessments,`; grant-wise it is nothing at all — boarding_parent
// already held all six assessment permissions at base, through the very same fragment. Without this
// set the fan-out reports six convergences for grants that were never absent, on a legitimate
// commit. A gate that fires on the legitimate case is a gate that gets switched off within a week,
// which is exactly what that arm exists to prevent.
//
// WHAT IT COVERS, STATED EXACTLY, because the suppression is the only false-green surface this
// section adds and a reader who thinks it is narrower than it is will under-weight it. The set is
// keyed role => permission and UNIONED over EVERY fragment that role consumed at base — so a
// permission the role already held through a DIFFERENT, overlapping fragment IS covered, not just
// the same one. (Base has `auditor` consuming `$one = [A]` and `$two = [B]`; head adds A to `$two`;
// nothing is reported, correctly — auditor already held A.)
//
// WHAT IT DELIBERATELY DOES NOT COVER: a permission the role already held at base through a LITERAL
// line. Answering that needs the whole base map evaluated rather than just its fragments — the
// carried line-vs-grant diffing ticket, not this work. That residual is a false RED, never a false
// green, so it fails in the direction this gate is allowed to fail in.
$baseModel = $fragmentModel(explode("\n", $baseSeederSrc), false);

$baseFragmentGrants = [];   // role => [permission => true]
foreach ($baseModel['spreadIndex'] ?? [] as $name => $roles) {
    foreach ($roles as $role) {
        foreach ($baseModel['fragments'][$name]['permissions'] as $permission) {
            $baseFragmentGrants[$role][$permission] = true;
        }
    }
}
// ---------------------------------------------------------------- findings
$findings = [];
$exempted = [];

// THE CANDIDATES, FROM THREE SOURCES, JUDGED BY ONE SET OF EXEMPTIONS. The sources are collected
// first and the four exemptions are applied below in a single loop, so a fan-out finding is exempted
// on exactly the same terms as a literal one. There is no fifth exemption, and nothing about the
// fragment work adds one.
//
// DEDUPLICATION, and why it is keyed on (role, permission) rather than on the line. A fragment that
// is NEW in this diff triggers BOTH fragment sources: its definition lines are added AND the
// `...$fragment,` line that consumes it is added. Those describe the same grants — one convergence
// each, not two — so reporting the pair twice would misstate the work the author owes. The FIRST
// occurrence wins, which is the fragment's own definition line: that is where the permission text
// actually sits, and it is the line an author edits to change it.
/** @var list<array{permission: string, line: int, role: ?string, text: string}> $candidates */
$candidates = [];
$fragmentSeen = [];

foreach ($added as [$line, $text]) {
    $stripped = ltrim($text);
    if (str_starts_with($stripped, '//') || str_starts_with($stripped, '*') || str_starts_with($stripped, '/*')) {
        continue;
    }

    // SOURCE 1 — an ADDED consumption of a fragment. Every permission the fragment carries at head
    // lands on the role at that site, and `rbac:sync` applies none of them.
    if (isset($spreadSites[$line])) {
        $site = $spreadSites[$line];

        foreach ($fragments[$site['fragment']]['permissions'] as $permission) {
            // A RESTRUCTURE IS NOT A GRANT. If the role already received this permission through a
            // fragment at base, this added line re-expresses an existing grant and there is nothing
            // for a migration to converge — see $baseFragmentGrants for the commit that proves it.
            if ($site['role'] !== null && isset($baseFragmentGrants[$site['role']][$permission])) {
                continue;
            }

            $key = ($site['role'] ?? '?')."\0".$permission;
            if (isset($fragmentSeen[$key])) {
                continue;
            }
            $fragmentSeen[$key] = true;

            $candidates[] = [
                'permission' => $permission,
                'line' => $line,
                'role' => $site['role'],
                'text' => trim($text),
            ];
        }

        continue;
    }

    // SOURCE 2 — an ADDED permission inside a fragment's own DEFINITION. It lands on every role that
    // consumes that fragment at head. A fragment NO role consumes yields nothing, and that is
    // correct rather than a gap: nothing is granted, so nothing needs converging.
    $inFragment = null;
    foreach ($fragments as $name => $fragment) {
        if ($line >= $fragment['from'] && $line <= $fragment['to']) {
            $inFragment = $name;
            break;
        }
    }

    if ($inFragment !== null) {
        foreach ($resolvePermissions($text) as $permission) {
            foreach ($spreadIndex[$inFragment] ?? [] as $role) {
                // Same rule as source 1: a role that already received this permission through a
                // fragment at base is not newly granted it by an edit to that fragment's body.
                if (isset($baseFragmentGrants[$role][$permission])) {
                    continue;
                }

                $key = $role."\0".$permission;
                if (isset($fragmentSeen[$key])) {
                    continue;
                }
                $fragmentSeen[$key] = true;

                $candidates[] = [
                    'permission' => $permission,
                    'line' => $line,
                    'role' => $role,
                    'text' => trim($text),
                ];
            }
        }

        continue;
    }

    // SOURCE 3 — the original: a permission added directly under a role key. Unchanged.
    foreach ($resolvePermissions($text) as $permission) {
        $candidates[] = [
            'permission' => $permission,
            'line' => $line,
            'role' => $inferRole($line),
            'text' => trim($text),
        ];
    }
}

foreach ($candidates as $candidate) {
    ['permission' => $permission, 'line' => $line, 'role' => $role, 'text' => $text] = $candidate;

    $inSuperAdminConst = $sapFrom !== null && $sapTo !== null && $line >= $sapFrom && $line <= $sapTo;

    // Exemption 3 is a LOOKUP of a declared pair, never a search of a migration's text — see
    // {@see declaredConvergences} for why prose cannot answer this and what it cost. A
    // convergence migration is per (role, permission), and so is the declaration: 7370e89, this
    // lint's own canonical defect, added `finance.access` to two roles, and a migration
    // declaring one of them exempts exactly that one.
    //
    // THE HONEST CONSTRAINT, stated rather than papered over: the role is INFERRED from source
    // text (see $inferRole). So this check is only as sound as that inference, and when the
    // inference yields null there is no pair to look up — an exemption on an unknown pair is a
    // guess in the silent direction. A null role therefore does NOT exempt. Since the fragment
    // table landed, a null role no longer means "a shared fragment" — fragment additions arrive
    // here already attributed — so the residual is a shape this file could not read at all.
    $migration = $role !== null
        ? ($declared[$role."\0".$permission] ?? null)
        : null;

    $exemption = match (true) {
        in_array($permission, $newPermissions, true) => 'permission is NEW in this diff (lands in $newPermissions)',
        $role !== null && in_array($role, $newRoles, true) => "role [{$role}] is NEW in this diff (takes the full \$permissions array)",
        $migration !== null => "migration [{$migration}] declares @converges {$role} {$permission}",
        $inSuperAdminConst => 'inside SUPER_ADMIN_PLATFORM (self-healed by syncPermissions every run, RbacSeeder.php:506-512)',
        default => null,
    };

    $record = [
        'permission' => $permission,
        'line' => $line,
        'role' => $role,
        'text' => $text,
    ];

    if ($exemption !== null) {
        $exempted[] = $record + ['exemption' => $exemption];

        continue;
    }

    $findings[] = $record;
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

// Markers that matched nothing at head, on the FAILING path only. A typo in a marker already fails
// the run by not exempting, so the red the author reads is there regardless — this only makes the
// CAUSE visible, instead of leaving them staring at a migration they believe declares the pair.
// DELIBERATELY NOT A GATE: hard-failing on an unrecognised marker would also have to fire on diffs
// this lint legitimately exits early on, and a gate that fires where it has nothing to say is a gate
// that gets switched off.
if ($unknownMarkers !== []) {
    fwrite(STDERR, "\n  ".count($unknownMarkers).' @converges marker(s) matched no role/permission at head'
        ." (they exempt nothing — check for a typo):\n");
    foreach ($unknownMarkers as $u) {
        fwrite(STDERR, '  '."\u{26a0}".' '.$u['path'].' declares @converges '.$u['role'].' '
            .$u['permission'].' — '.$u['why']."\n");
    }
}

// The syntax half of the same signal — see the derivation at $unparsedMarkers. Failing path only,
// staying symmetric with $unknownMarkers above (the green path already prints its exemptions), and
// NOT a gate, for the same reason: a marker the parser cannot read already fails the run by
// exempting nothing.
if ($unparsedMarkers !== []) {
    fwrite(STDERR, "\n  ".array_sum($unparsedMarkers)
        .' line(s) mention @converges but did not parse as a declaration (they exempt nothing):'."\n");
    foreach ($unparsedMarkers as $path => $count) {
        fwrite(STDERR, '  '."\u{26a0}".' '.$path.' — '.$count.' line'.($count === 1 ? '' : 's')
            .'. Check for CRLF endings, a one-line'."\n"
            .'    /** @converges … */ (the closer needs its own line), a wrapped line, or text after'."\n"
            .'    the permission.'."\n");
    }
}

// The third notice, and the only one whose author did everything right at the level of syntax — see
// the rule at the `--diff-filter=A` collection for why it still cannot exempt. Failing path only and
// not a gate, for the same reasons as the two above.
//
// AND FOR ONE REASON OF ITS OWN, which is the answer to the next person who asks why this is not a
// gate: "not added in this diff" is a property of the RANGE, not of the tree, and the range moves.
// `bin/quality-promote` runs `./bin/quality origin/main`, a wider base than the per-push run, so the
// same tree would gate differently depending on which script invoked it — and the documented replay
// form (`<base> <head>`) would gate differently again. A gate whose verdict depends on the caller's
// base is a gate people learn to route around. The red is already here and already correct: the pair
// is unexempted, so the run exits 1 and the finding names the permission and the role. This notice
// attaches the explanation to that red rather than raising a second one for the same fact.
if ($markersOnModified !== []) {
    fwrite(STDERR, "\n  ".array_sum($markersOnModified)
        .' @converges line(s) sit on a migration that is not new in this diff (they exempt nothing):'."\n");
    foreach ($markersOnModified as $path => $count) {
        fwrite(STDERR, '  '."\u{26a0}".' '.$path.' — '.$count.' line'.($count === 1 ? '' : 's')
            .'. This migration is already on the base,'."\n"
            .'    so it has already run; a marker added to it declares a convergence nothing'."\n"
            .'    performed. Write a NEW convergence migration and declare the pair there.'."\n");
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
    · Ship a convergence migration that grants the permission to the role, and DECLARE the
      pair in it — one line per pair, nothing else on the line (that is exemption 3):

          @converges <role> <permission>

      The marker may sit in a docblock (` * @converges …`), a `//` line or a `#` line.
      Naming the permission and the role in PROSE is NOT enough and is no longer read: a
      migration that documents which roles it EXCLUDES would otherwise exempt them.

      A permission added to a SHARED FRAGMENT needs one marker per role, not one per
      fragment. The findings above already name those roles — a fragment addition is fanned
      out to every role that spreads it — so declare a line for each. Ten grants means ten
      marker lines, and that is the honest cost of ten grants. There is deliberately no
      fragment-level marker: a fragment's contents change after the migration is written, so
      such a marker would exempt permissions the migration never granted.

      If the role above reads `?`, a marker CANNOT clear this: exemption 3 is a lookup on
      (role, permission) and there is no role to look up. A `?` does NOT mean a shared
      fragment — those arrive with real role names. It means a parser gap: an addition above
      `return [` that sits inside no fragment this lint could resolve. Do NOT restructure the
      seeder to make it go away. Moving fragments beneath a role key attributes every LATER
      fragment addition to that key, which is a silent green when the key is new in a diff
      (see $inferRole in this file, which carries the shape). Report the shape instead.
    · The permission is genuinely new — add its `case` to app/Enums/Permission.php in the
      same diff (exemption 1).
    · The role is genuinely new — add it to RbacSeeder::ROLES in the same diff (exemption 2).

  NOT RETROACTIVE: this lint sees diffs only. For grants that have ALREADY drifted, run
  `php artisan rbac:diff-grants`.

TXT);

exit(1);
