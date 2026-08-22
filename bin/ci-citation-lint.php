#!/usr/bin/env php
<?php

/**
 * citation-lint — a NEW `path:LINE` citation must name a symbol, and the symbol must be there.
 *
 * WHY THIS EXISTS. `docs/handoff/tickets/stale-path-line-citations.md` records six instances across
 * four consecutive branches of a citation that pointed at the wrong place, every one of them found
 * by a human or a cold review and none of them by anything automatic. The house rule is that a
 * convention with no mechanism behind it is wallpaper, and "cite accurately" had been exactly that.
 *
 * THE FORM. A citation is COMPLIANT when it names the symbol it points at, in EITHER of the two
 * spellings this repository already uses, and that symbol is really there:
 *
 *     app/Support/ActiveSchool.php:66 (getOrFail)        symbol-last
 *     getOrFail (app/Support/ActiveSchool.php:66)        symbol-first
 *     app/Support/ActiveSchool.php with a bare line number and no symbol   NOT compliant —
 *                                                        nothing to check against
 *
 * SYMBOL-FIRST IS NOT A CONCESSION, IT IS THE HOUSE STYLE. The first version of this lint accepted
 * only symbol-last and said "carries no symbol" of citations that carried one — a false message on
 * 15 of the 90 keys it then baselined. A symbol-shaped token immediately preceding the opening
 * parenthesis is unambiguous, so both spellings are read.
 *
 * "THE SYMBOL IS REALLY THERE" MEANS EITHER OF TWO THINGS, and the second is what lets a citation
 * point INSIDE a method:
 *
 *   1. the symbol occurs, as a whole word, within +/-WINDOW lines of the cited line; or
 *   2. the symbol IS the NEAREST PRECEDING DECLARATION to the cited line.
 *
 * Rule 2 exists because this repository routinely cites a specific guard inside a method —
 * `app/Support/ActiveSchool.php:42 (ActiveSchool::id)`, where `id()` spans 28-60 and `:42` is the
 * session branch the citing test is about. Under rule 1 alone that citation is refused, which would
 * force every citation onto a declaration line.
 *
 * NEAREST, NOT "ANY SYMBOL ABOVE" — measured. `bin/citation-window-measure.php nearest` runs the
 * same wrong-neighbour adversary as the window measurement, moved off declaration lines and onto
 * every line of a declaration's body, and reports:
 *
 *     wrong-symbol adversary, 70,778 body-line pairs
 *       window only        passes 20.64%
 *       window OR nearest  passes 20.64%      <- no degradation, because the NEXT declaration is
 *                                                never the nearest PRECEDING one inside this body
 *     citations the rule is FOR (a body line naming its enclosing declaration)
 *       window only        accepts 25.93%
 *       window OR nearest  accepts 100%       <- 74.07% of body lines newly accepted
 *
 * WHAT RULE 2 GIVES UP, stated because the measurement is what makes it a decision: inside one
 * declaration's body every line is equivalent, so a citation may drift anywhere within its
 * enclosing method and stay compliant. Body length in this tree is p50=8, p75=19, p90=39, p99=161 —
 * for half of them the flat region is barely wider than the window, and for one in a hundred it is
 * 161 lines.
 *
 * WHY THE WINDOW IS 3, AND WHAT THE MEASUREMENT ACTUALLY SAID. Two curves, both re-runnable with
 * `bin/citation-window-measure.php`, and the finding is that they CROSS — no window absorbs drift
 * while still discriminating, so N is NOT a drift-tolerance knob and must not be grown into one.
 *
 *   (a) DISCRIMINATION — 3,742 adjacent-declaration pairs across 844 files. If a citation names the
 *       WRONG neighbouring symbol, does the window still accept it?
 *
 *           N=0  2.5%    N=1  4.1%    N=2 20.1%    N=3 23.3%
 *           N=5 36.1%    N=8 53.7%    N=10 61.3%   N=20 81.5%   N=50 95.4%
 *
 *   (b) DRIFT — declarations matched by name between a sha 30 days back and the worktree; 754 of
 *       them moved. How many are still inside the window:
 *
 *           N=0  0.0%    N=1 18.2%    N=2 25.2%    N=3 31.2%
 *           N=5 42.6%    N=8 48.0%    N=10 52.1%   N=20 68.8%   N=50 86.1%
 *
 *       Drift is heavy-tailed: median move among moved symbols is 9 lines, p75 is 29, p90 is 129.
 *
 * Read (a) and (b) together at any N and the window admits about as many WRONG symbols as it
 * retains DRIFTED-but-right ones — at N=8, 53.7% against 48.0%; at N=50, 95.4% against 86.1%. So
 * enlarging N buys nothing on that trade, and a citation whose symbol has drifted out of the window
 * SHOULD fail: that is the stale citation this lint exists for, failing on the branch that moved
 * the target, which is where it is cheap to fix. N=3 is therefore the smallest window that still
 * tolerates ordinary jitter above a symbol — an added `use`, an attribute, a blank line — rather
 * than a tolerance for real movement. N=1 discriminates better (4.1% against 23.3%) and was
 * rejected only because a one-line insert above a symbol is not a defect and should not fail a gate.
 *
 * MATCHING IS ON THE WHOLE FILE, NOT ON COMMENTS. Unlike the sibling lints this one does NOT skip
 * comment lines — a citation's natural home IS a docblock, and skipping comments would skip the
 * defect. It also does not try to tell a citation from a pasted `grep -n` line or a fenced code
 * block, because it structurally cannot: `grep -n` output is byte-identical to a citation. Inside
 * the scanned dirs that consequence is accepted — a scanned file that pastes tool output gets a
 * finding, and the answer is the baseline, argued once.
 *
 * SCOPE, and the exclusion is the load-bearing decision.
 *
 *   SCANNED: app/ tests/ bin/ database/ config/ routes/ bootstrap/ .claude/skills/
 *   NOT SCANNED: docs/
 *
 * THE REASON FOR EXCLUDING docs/ IS VOLUME, NOT FALSE POSITIVES, and the distinction matters
 * because the wrong reason invites the wrong fix. An earlier version of this comment said docs/ was
 * excluded because pasted `grep -n` output is indistinguishable from a citation and would
 * MANUFACTURE findings. That prediction does not describe this tree — docs/ manufactures none. Run
 * `generate` with `docs` added to SCANNED_DIRS at this commit and it produces 1,347 keys / 1,579
 * citations, of which docs/ contributes 1,177 keys / 1,392 citations: SEVEN AND A HALF TIMES the
 * code baseline of 187, essentially all of it unverifiable prose and pasted output. Skipping fenced
 * blocks does not rescue it either — of the 1,444 citation tokens in docs/, only 372 are inside a
 * fence, so a prose-only baseline still opens at about 1,072. A baseline that size is not a ratchet,
 * it is a directory listing. So: docs/ is out because a shrink-only baseline of that size means
 * nothing, and NOT because a fence-skipper would make it safe.
 *
 * THE COST OF THAT EXCLUSION IS REAL AND IS NOT COVERED BY ANYTHING. Citations inside tickets and
 * reports stay unguarded, and two of the six recorded instances were exactly that — the
 * malformed-200 ticket's citation, and the seven numbers in that report's sections 9 and 11.2. This
 * lint does not cover them and no green here should be read as if it did.
 *
 * .claude/skills/ IS IN SCOPE AND IS THE POINT: skills are what agents read as INSTRUCTIONS, and
 * one skill file needed two separate citation-correction rounds — `6b14a43` (7 citations on the
 * removed side, 3 of which changed value) and `ec2b56a` (3 on the removed side, 2 changed).
 *
 * EXTENSIONLESS EXECUTABLES ARE CITED HERE AND ARE MATCHED BY NAME. The path pattern needs a file
 * extension to know a path when it sees one, so a citation of `bin/quality` by line matched nothing
 * at all — and this
 * lint's own branch walked into that hole the day it shipped, moving `bin/quality` by 13 lines and
 * staling five in-scope citations the gate could not see. The six extensionless executables in this
 * repository are therefore named explicitly in CITATION_RX. Measured cost of that alternation
 * across the scanned dirs: 11 citations found, 0 false positives.
 *
 * VENDOR, AND WHY IT IS RESOLVED RATHER THAN GUESSED. A cited path this repository does not contain
 * is EXEMPT when it resolves under vendor/ — by prefix (`vendor/...`) or by path SUFFIX
 * (`Models/Role.php`). This is not politeness toward dependencies; it is the only thing that stops
 * the lint MANUFACTURING findings. The ticket measured two of its three past-EOF hits as artefacts
 * of basename-resolving a vendor path onto an app file of the same name: `Models/Role.php:186-188`
 * is `vendor/spatie/laravel-permission/src/Models/Role.php` (221 lines, and `:186` is exactly the
 * `findByParam` block the sentence describes), which a basename resolver retargets at
 * `app/Models/Role.php` (36 lines) and reports as past-EOF. A LINE-SCOPED `vendor` GUARD IS NOT
 * ENOUGH — in one of the two the word "Vendor" opens the PREVIOUS line — which is why the check is
 * a filesystem resolution against vendor/ and not a word search.
 *
 * THE EXEMPTION IS CONDITIONAL ON THE LINE EXISTING, and the unconditional version hid a live
 * defect for a whole branch. There are THREE basenames in this tree that match on both sides:
 *
 *     User.php line 412         vendor candidate is 20 lines — 412 cannot be in it;
 *                               app/Models/User.php is 543.          -> NOT exempt
 *     Models/Role.php:186-188   vendor 221 (in range), app 36 (out). -> exempt, as measured above
 *     Permission.php line 158   vendor Models/Permission.php is 165 (in range) AND
 *                               app/Enums/Permission.php is 300 (in range).   -> NOT exempt
 *
 * The third one was a citation that had gone 48 lines stale — correct at `b1d5e50`, where the
 * constant sat at :158, and today at :206 — sitting unbaselined and unreported because an
 * unconditional vendor tie-break swallowed it. So: exempt only when SOME vendor candidate contains
 * the cited line and NO in-tree candidate does. When both sides could contain it, the citation is
 * reported as `citation-not-repo-relative`, which is what this header already demands of every bare
 * basename — and the anti-manufacturing guarantee is untouched, because `Models/Role.php:186` is
 * still exempt for a reason that is now stated rather than accidental.
 *
 * A BARE BASENAME IS NEVER RESOLVED AGAINST THE REPO. `SeedDriveFixture.php` cited by bare line is
 * reported as
 * `citation-not-repo-relative` rather than resolved to `app/Console/Commands/SeedDriveFixture.php`,
 * because a basename resolver is the defect generator described above. The in-tree candidate list is
 * consulted ONLY to decide whether the vendor exemption applies; no symbol is ever checked against a
 * guessed target.
 *
 * VENDOR/ MUST BE INSTALLED FOR THE EXEMPTION TO FIRE. `bin/quality` step 1 is dependency integrity
 * (composer.lock against vendor/), so it is present wherever this gate runs; on a checkout without
 * it a bare vendor citation is reported as `citation-not-repo-relative` or `citation-unresolvable`
 * instead of being exempt. That is a wrong MESSAGE on a citation that is non-compliant either way,
 * not a manufactured in-tree finding — the resolver never falls back to the repo.
 *
 * THE BASELINE, AND THE KEY IS THE KNOWN TRAP. Every citation in the tree that is not compliant
 * today is recorded in `citation-lint-baseline.txt`, which MAY ONLY SHRINK: a new key fails, and so
 * does a RISING COUNT on an existing key.
 *
 *     rule \t citingPath \t citedToken \t count
 *
 * The count is the fix `docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md` prescribes
 * for the hole it records in `bin/ci-boundary-lint.php`, where the key is `rule \t path \t
 * trim($line)` with no count and a SEVENTH byte-identical line is therefore admitted silently. Here
 * a second byte-identical citing line raises the count from 1 to 2 and FAILS.
 *
 * WHAT THIS KEY CANNOT DISTINGUISH, stated rather than discovered later:
 *
 *   - WHICH occurrence is forgiven. Delete a baselined bare citation and add a different new bare
 *     citation of the SAME target in the SAME file, and the count is still 1 — green. The baseline
 *     forgives N occurrences of a target in a file, not N specific sentences.
 *   - WHERE in the file the citation sits. Keying on the citing line NUMBER was rejected for the
 *     reason that ticket gives: it would fail on every unrelated edit above a baselined line and
 *     train people to regenerate reflexively, which is how a ratchet stops meaning anything.
 *   - The citing line's TEXT. Rewording the sentence around a baselined citation is invisible.
 *
 * `generate` READS TRACKED FILES ONLY; `check` READS THE WORKING TREE. An untracked file that got
 * into `generate` would bake a path nobody else has into a shrink-only baseline, and every other
 * checkout would then fail with "fixed (good!)" naming a file that does not exist there. The
 * asymmetry is deliberate: `check` must still see a file you have just written, or every coverage
 * arm below is testing nothing. `bootstrap/cache/` is skipped in BOTH modes — it is generated,
 * gitignored, and sits inside a scanned directory.
 *
 * WHAT A GREEN DOES NOT PROVE. A citation whose symbol appears near the cited line can still be
 * wrong about what it claims. This lint checks that the pointer lands somewhere sane; it cannot
 * check that the sentence is true. The ticket's instances 3 and 5 were false when written with the
 * citation itself well-formed.
 *
 * Usage:
 *   php bin/ci-citation-lint.php            # check: exit 1 on any NEW or GROWN violation
 *   php bin/ci-citation-lint.php generate   # (re)write the baseline, from tracked files only
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/citation-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

/**
 * The window, in lines, around the cited line in which the named symbol must occur. Three. The two
 * measured curves behind that number are in the header; the short version is that they cross, so N
 * is a jitter tolerance and never a drift tolerance.
 */
const WINDOW = 3;

/** Where citations are read FROM. docs/ is deliberately absent — see the header. */
const SCANNED_DIRS = ['app', 'tests', 'bin', 'database', 'config', 'routes', 'bootstrap', '.claude/skills'];

/** Generated, gitignored, and inside a scanned directory. Skipped in both modes. */
const SKIPPED_PREFIXES = ['bootstrap/cache/'];

/**
 * The one file that contains citations AS DATA rather than as claims: the coverage test, whose
 * fixtures are citation strings in heredocs pointing at files that exist only mid-arm. It is a real
 * hole in one named file — a genuine stale citation written there is not seen.
 *
 * THIS SCRIPT IS NOT ON THE LIST. It used to be, and that is how its own worked example came to
 * cite `ActiveSchool.php` line 99 for `getOrFail`, which is at line 66 — the lint's exemplar failing
 * the lint's own rule, in the one file the lint could not read. The exemplar is now a real,
 * compliant citation that this lint checks on every run; every other illustrative token in the
 * header is deliberately unmatched (`:NN`, `:NNN`) or exempt for a reason the header states.
 */
const SELF = [
    'tests/Arch/CitationLintCoverageTest.php',
];

/**
 * THE TICKET'S OWN REGEX, plus one alternation it could not have.
 *
 * The path half is the ticket's, verbatim: a name ending in one of ten known extensions. The
 * negative lookbehind is what keeps it off the middle of a longer path and off `.../foo.php:12:`
 * continuations.
 *
 * THE SECOND ALTERNATIVE IS A FIXED LIST OF THIS REPOSITORY'S EXTENSIONLESS EXECUTABLES, longest
 * first so `bin/quality-promote` is not eaten by `bin/quality`. They are cited throughout the repo
 * and matched nothing before, which is a hole exactly the width of the list. A fixed list rather
 * than "any path under bin/" on purpose: the latter would match prose.
 */
const CITATION_RX = '#(?<![\w/.-])((?:[A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:php|ts|tsx|js|jsx|md|sh|sql|json|xml))|bin/quality-clean-db|bin/quality-promote|bin/is-docs-only-push|bin/quality|bin/landed|\.githooks/pre-push):(\d+)#';

/** Why each rule is a defect — printed per finding, so a message names the actual mechanism. */
const RULE_REASONS = [
    'citation-missing-symbol' => 'names no symbol in either spelling — write `path:LINE (symbolName)` or `symbolName (path:LINE)`',
    'citation-symbol-not-found' => 'names a symbol that is neither within '.WINDOW.' lines of the cited line nor the nearest declaration above it',
    'citation-past-eof' => 'cites a line beyond the end of the file it points at',
    'citation-not-repo-relative' => 'is a bare basename; write the repo-relative path (a basename resolver manufactures findings)',
    'citation-unresolvable' => 'points at no file in this repository and at nothing under vendor/',
];

/**
 * The declaration extractor, for the nearest-preceding half of the compliance rule.
 *
 * ⚠️ THIS REGEX IS DUPLICATED IN `bin/citation-window-measure.php`, which is what measured the rule
 * it implements. `tests/Arch/CitationLintCoverageTest.php` asserts the two are byte-identical, so
 * the measurement cannot quietly stop describing the lint.
 *
 * @param  array<int, string>  $lines
 * @return array<int, array{0: int, 1: string}> [[lineNumber, name], ...] in file order
 */
function declarationsIn(array $lines): array
{
    $out = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/\b(?:function|class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            $out[] = [$i + 1, $m[1]];
        } elseif (preg_match('/\bconst\s+([A-Z_][A-Z0-9_]*)/', $line, $m)) {
            $out[] = [$i + 1, $m[1]];
        }
    }

    return $out;
}

/**
 * Is $text one identifier rather than a phrase? `ensureBankAccount`, `SubledgerPoster::post`,
 * `$table->timestamp`, `handle()`, `App\Models\User` — yes. `the statement page`,
 * `invoice-addressed`, `see below` — no.
 *
 * THE NARROWING IS MEASURED. Accepting any parenthesised text and asking whether any word in it
 * occurs near the cited line, 2 of the 194 citations then in scope passed BY COINCIDENCE:
 * `routes/endpoints/finance.php` line 24 with `(invoice-addressed)` passes because the word
 * "invoice" is in the route path on the next line, and `routes/web.php` line 237 with `(the statement
 * page)` the same way. Both are prose. Requiring the symbol shape, the coincidental passes are 0.
 */
function isSymbolShaped(string $text): bool
{
    return preg_match('/^\$?[A-Za-z_][A-Za-z0-9_]*(?:(?:::|->|\\\\)\$?[A-Za-z_][A-Za-z0-9_]*)*(?:\(\))?$/', trim($text)) === 1;
}

/**
 * Is a symbol-first CANDIDATE really a symbol, or is it the last word of a sentence?
 *
 * Two ways to qualify, and each was chosen against the six prose false positives this tree
 * supplies:
 *
 *   1. IT CARRIES A CODE MARKER — `::`, `->`, `\`, or a trailing `()`. `StudentCurriculum::booted()`
 *      and `ActiveSchool::id()` qualify here; no English word does.
 *   2. IT IS DECLARED IN THE FILE IT POINTS AT. `SchoolScope (app/Models/Scopes/SchoolScope.php:NN)`
 *      qualifies because `SchoolScope` really is a class declared in that file — while `Pint`,
 *      `TESTS`, `client`, `school`, `change` and `throughout` are declared in nothing, so they fall
 *      back to `citation-missing-symbol`, which is the true statement about them.
 *
 * This is not circular. Test 2 asks whether the word names a declaration ANYWHERE in the cited file;
 * the compliance rule then asks whether it is at the cited LINE. A citation naming a symbol that is
 * used but not declared in its target is missed by test 2 and reported as carrying no symbol —
 * a wrong message on a citation that is unverifiable either way.
 *
 * @param  array<int, string>  $target
 */
function qualifiesAsLeadingSymbol(string $candidate, array $target): bool
{
    if (preg_match('/::|->|\\\\|\(\)$/', $candidate) === 1) {
        return true;
    }

    foreach (declarationsIn($target) as [, $name]) {
        if ($name === $candidate) {
            return true;
        }
    }

    return false;
}

/**
 * Every citation in one line of text, as [citedPath, citedLine, rawToken, symbolText|null].
 *
 * TWO SPELLINGS, symbol-last preferred when both are present:
 *
 *   symbol-last   the token, an optional `-END` range, an optional closing backtick, then a
 *                 parenthesised SYMBOL-SHAPED group. `Foo.php:NN (bar)`, `Foo.php:NN-40 (bar)`.
 *   symbol-first  a symbol-shaped token, then `(` (optionally followed by a backtick), then the
 *                 token. `bar (Foo.php:NN)`, ``SchoolScope (`Foo.php:NN-14`, "…")``.
 *
 * `Foo.php:NN — see bar()` carries neither, deliberately: a rule that accepted any nearby
 * parenthesis would accept the prose that already surrounds most citations.
 *
 * THE SYMBOL-FIRST CANDIDATE IS RETURNED, NOT ACCEPTED — see qualifiesAsLeadingSymbol(). "A
 * symbol-shaped token before the parenthesis" is NOT unambiguous, and this tree says so: six of the
 * citations it would have admitted are ordinary prose — `throughout (\`bin/quality:NNN\`)`,
 * `only on Pint (\`composer.json:NN,70\`)`, `IN TESTS (phpunit.xml:NN)`, `no client (…)`,
 * `no school (…)`, `RBAC change (…)`. Capitalisation does not separate them from the real ones
 * (`Pint` and `SchoolScope` are the same shape), so the caller applies a second test that does.
 *
 * THE RANGE IS CONSUMED BUT NEVER CHECKED. A range is checked at its START line; widening to the
 * whole range would make a long range self-approving, and checking the END would move every
 * citation's anchor off the line the reader is pointed at.
 *
 * @return array<int, array{0: string, 1: int, 2: string, 3: string|null, 4: string|null}>
 *                                                                                         [citedPath, citedLine, rawToken, acceptedSymbol, leadingCandidate]
 */
function citationsIn(string $line): array
{
    if (! preg_match_all(CITATION_RX, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $out = [];
    foreach ($matches as $m) {
        $raw = $m[0][0];
        $start = $m[0][1];
        $tail = substr($line, $start + strlen($raw));

        $symbol = null;
        if (preg_match('/^(?:-\d+)?`?\s*\(([^)]{1,120})\)/', $tail, $s) === 1 && isSymbolShaped($s[1])) {
            $symbol = $s[1];
        }

        $leading = null;
        if ($symbol === null) {
            $head = substr($line, 0, $start);
            if (preg_match('/(\S+)\s*\(\s*`?$/', $head, $h) === 1) {
                $candidate = trim($h[1], '`');
                if (isSymbolShaped($candidate)) {
                    $leading = $candidate;
                }
            }
        }

        $out[] = [$m[1][0], (int) $m[2][0], $raw, $symbol, $leading];
    }

    return $out;
}

/**
 * Is the named symbol really at the cited line — by window, or by being the nearest declaration
 * above it?
 *
 * ANY identifier of a qualified symbol counts, not EVERY one: `SubledgerPoster::post` names two and
 * the class name is routinely not on the method's line. For the WINDOW half, identifiers shorter
 * than three characters are dropped — `$a`, `x` and the like match everywhere and would make the
 * check vacuous. THE NEAREST-DECLARATION HALF APPLIES NO LENGTH FILTER, because equality with a
 * declaration name is already unambiguous and the filter would otherwise refuse the rule's own
 * worked example, `ActiveSchool::id`.
 *
 * @param  array<int, string>  $target
 */
function symbolIsAt(array $target, int $line, string $symbol): bool
{
    preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $symbol, $ids);
    if ($ids[0] === []) {
        return false;
    }

    $windowIds = array_values(array_filter($ids[0], fn (string $id): bool => strlen($id) >= 3));
    $from = max(1, $line - WINDOW);
    $to = min(count($target), $line + WINDOW);

    for ($i = $from; $i <= $to; $i++) {
        foreach ($windowIds as $id) {
            if (preg_match('/\b'.preg_quote($id, '/').'\b/', $target[$i - 1]) === 1) {
                return true;
            }
        }
    }

    $nearest = null;
    foreach (declarationsIn($target) as [$declLine, $name]) {
        if ($declLine > $line) {
            break;
        }
        $nearest = $name;
    }

    return $nearest !== null && in_array($nearest, $ids[0], true);
}

/** @return array<int, string> relative paths of every readable text file in the scanned dirs */
function scannedFiles(string $root): array
{
    $out = [];
    foreach (SCANNED_DIRS as $dir) {
        $path = $root.'/'.$dir;
        if (! is_dir($path)) {
            continue;
        }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            foreach (SKIPPED_PREFIXES as $prefix) {
                if (str_starts_with($rel, $prefix)) {
                    continue 2;
                }
            }
            // Binary files carry no citations and would only produce mojibake in a finding.
            $head = (string) file_get_contents($file->getPathname(), false, null, 0, 8192);
            if (str_contains($head, "\0")) {
                continue;
            }
            $out[] = $rel;
        }
    }
    sort($out);

    return $out;
}

/**
 * Paths `git ls-files` reports, as a set. Used for two different things and for nothing else:
 * restricting `generate` to tracked files, and deciding whether a bare basename has an IN-TREE
 * candidate that could contain the cited line. If git is unavailable the set is empty, which makes
 * `generate` refuse to narrow and makes the vendor exemption strictly more generous — never less,
 * so the anti-manufacturing guarantee does not depend on git being there.
 *
 * @return array<string, true>
 */
function trackedFiles(string $root): array
{
    $listing = shell_exec('git -C '.escapeshellarg($root).' ls-files 2>/dev/null');
    $set = [];
    foreach (explode("\n", trim((string) $listing)) as $p) {
        if ($p !== '') {
            $set[$p] = true;
        }
    }

    return $set;
}

/**
 * For each cited path this repo does not contain, the LINE COUNTS of every vendor file whose path
 * ends with it. One pass over vendor/, and only when there is something to look up.
 *
 * The suffix form is the half that matters: `Models/Role.php` is how the manufactured findings in
 * the ticket were written, and nothing short of resolving it against vendor/ would have stopped
 * them. The COUNTS are what makes the exemption conditional — see the header.
 *
 * @param  array<int, string>  $suffixes
 * @return array<string, array<int, int>> suffix => [lineCount, ...]
 */
function vendorCandidates(string $root, array $suffixes): array
{
    $hit = [];
    if ($suffixes === [] || ! is_dir($root.'/vendor')) {
        return $hit;
    }

    $wanted = [];
    foreach ($suffixes as $s) {
        $wanted[basename($s)][] = $s;
    }

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/vendor', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($rii as $file) {
        $name = $file->getFilename();
        if (! isset($wanted[$name])) {
            continue;
        }
        $abs = str_replace('\\', '/', $file->getPathname());
        foreach ($wanted[$name] as $suffix) {
            if (str_ends_with($abs, '/'.$suffix)) {
                $hit[$suffix][] = count(file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: []);
            }
        }
    }

    return $hit;
}

/**
 * The LINE COUNTS of every TRACKED repo file whose path ends with $suffix. Consulted ONLY to decide
 * whether the vendor exemption applies; the result is never used as a citation's target.
 *
 * @param  array<string, true>  $tracked
 * @return array<int, int>
 */
function inTreeCandidates(string $root, array $tracked, string $suffix): array
{
    $counts = [];
    foreach (array_keys($tracked) as $path) {
        if ($path === $suffix || str_ends_with($path, '/'.$suffix)) {
            $counts[] = count(file($root.'/'.$path, FILE_IGNORE_NEW_LINES) ?: []);
        }
    }

    return $counts;
}

// ---------------------------------------------------------------------------------------------
// Pass 1 — collect every citation in scope, and every cited path that the repo does not contain.
// ---------------------------------------------------------------------------------------------
$tracked = trackedFiles($root);
$files = scannedFiles($root);

if ($mode === 'generate' && $tracked !== []) {
    $files = array_values(array_filter($files, fn (string $rel): bool => isset($tracked[$rel])));
}

$citations = [];
$unresolved = [];

foreach ($files as $rel) {
    if (in_array($rel, SELF, true)) {
        continue;
    }

    $lines = file($root.'/'.$rel, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    foreach ($lines as $i => $line) {
        foreach (citationsIn($line) as [$cited, $citedLine, $raw, $symbol, $leading]) {
            $citations[] = [$rel, $i + 1, $cited, $citedLine, $raw, $symbol, $leading];
            if (! is_file($root.'/'.$cited) && ! str_starts_with($cited, 'vendor/')) {
                $unresolved[$cited] = true;
            }
        }
    }
}

$vendorHit = vendorCandidates($root, array_keys($unresolved));
$inTreeHit = [];
foreach (array_keys($unresolved) as $suffix) {
    $inTreeHit[$suffix] = inTreeCandidates($root, $tracked, $suffix);
}

// ---------------------------------------------------------------------------------------------
// Pass 2 — classify. Order matters: vendor is decided BEFORE anything is resolved in-tree, which
// is the whole of the anti-manufacturing guarantee — but only when a vendor candidate could
// actually contain the cited line and no in-tree candidate could.
// ---------------------------------------------------------------------------------------------
$targetCache = [];
$found = [];

foreach ($citations as [$rel, , $cited, $citedLine, $raw, $symbol, $leading]) {
    if (str_starts_with($cited, 'vendor/')) {
        continue;
    }

    $vendorInRange = false;
    foreach ($vendorHit[$cited] ?? [] as $count) {
        if ($citedLine <= $count) {
            $vendorInRange = true;
            break;
        }
    }
    $inTreeInRange = false;
    foreach ($inTreeHit[$cited] ?? [] as $count) {
        if ($citedLine <= $count) {
            $inTreeInRange = true;
            break;
        }
    }
    if ($vendorInRange && ! $inTreeInRange) {
        continue;
    }

    $rule = null;

    if (! is_file($root.'/'.$cited)) {
        // A bare basename is NEVER resolved against the repo — that resolver is the finding
        // generator described in the header. It is reported as a form defect instead.
        $rule = str_contains($cited, '/') ? 'citation-unresolvable' : 'citation-not-repo-relative';
    } else {
        if (! isset($targetCache[$cited])) {
            $targetCache[$cited] = file($root.'/'.$cited, FILE_IGNORE_NEW_LINES) ?: [];
        }
        $target = $targetCache[$cited];

        if ($symbol === null && $leading !== null && qualifiesAsLeadingSymbol($leading, $target)) {
            $symbol = $leading;
        }

        if ($citedLine < 1 || $citedLine > count($target)) {
            $rule = 'citation-past-eof';
        } elseif ($symbol === null) {
            $rule = 'citation-missing-symbol';
        } elseif (! symbolIsAt($target, $citedLine, $symbol)) {
            $rule = 'citation-symbol-not-found';
        }
    }

    if ($rule === null) {
        continue;
    }

    $key = $rule."\t".$rel."\t".$raw;
    $found[$key] = ($found[$key] ?? 0) + 1;
}

ksort($found);

if ($mode === 'generate') {
    $header = <<<'TXT'
# citation-lint baseline — `path:LINE` citations that predate the rule. MAY ONLY SHRINK.
#
# Key: rule \t citing file \t cited token \t COUNT. The count is deliberate: without it a NEW
# citation whose token is identical to a baselined one in the same file would be admitted
# silently, which is the hole recorded against ci-boundary-lint.php in
# docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md.
#
# Generated from TRACKED files only — an untracked path baked in here fails for everybody else.
#
# Every entry here is a citation the lint cannot verify — most of them bare `path:LINE` tokens
# written before the symbol form existed. Burn one down by giving it a symbol, in either
# spelling (`path:LINE (symbolName)` or `symbolName (path:LINE)`), and re-deriving the line,
# then regenerate:
#
#   php bin/ci-citation-lint.php generate
#
# A green from this lint says the pointer lands somewhere sane. It does NOT say the sentence
# around it is true.

TXT;
    $body = '';
    foreach ($found as $key => $count) {
        $body .= $key."\t".$count."\n";
    }
    file_put_contents($baselinePath, $header.$body);
    fwrite(STDERR, 'citation-lint: wrote '.count($found).' baseline entries ('.array_sum($found)." citation(s)) to citation-lint-baseline.txt\n");
    exit(0);
}

$baseline = [];
if (is_file($baselinePath)) {
    foreach (file($baselinePath) as $raw) {
        $raw = rtrim($raw, "\r\n");
        if ($raw === '' || str_starts_with($raw, '#')) {
            continue;
        }
        $parts = explode("\t", $raw);
        $count = (int) array_pop($parts);
        $baseline[implode("\t", $parts)] = $count;
    }
}

$new = [];
$grown = [];
$fixed = [];

foreach ($found as $key => $count) {
    if (! isset($baseline[$key])) {
        $new[$key] = $count;
    } elseif ($count > $baseline[$key]) {
        $grown[$key] = [$baseline[$key], $count];
    }
}
foreach ($baseline as $key => $count) {
    $now = $found[$key] ?? 0;
    if ($now < $count) {
        $fixed[$key] = [$count, $now];
    }
}

$render = function (string $key): string {
    [$rule, $rel, $raw] = array_pad(explode("\t", $key), 3, '');

    return $rel.'  '.$raw.'  ['.$rule.']';
};

if ($new !== [] || $grown !== []) {
    fwrite(STDERR, "\ncitation-lint: ".(count($new) + count($grown))." NEW or GROWN citation violation(s) — a citation must name a symbol, and the symbol must be there:\n");
    foreach ($new as $key => $count) {
        [$rule] = explode("\t", $key);
        fwrite(STDERR, '  '."\u{2717}".' '.$render($key).($count > 1 ? '  x'.$count : '')."\n");
        fwrite(STDERR, '      '.RULE_REASONS[$rule]."\n");
    }
    foreach ($grown as $key => [$was, $now]) {
        [$rule] = explode("\t", $key);
        fwrite(STDERR, '  '."\u{2717}".' '.$render($key).'  baselined '.$was.', now '.$now."\n");
        fwrite(STDERR, '      '.RULE_REASONS[$rule]."\n");
    }
    fwrite(STDERR, "\n  Name the symbol — `path:LINE (symbolName)` or `symbolName (path:LINE)` — with a\n");
    fwrite(STDERR, '  repo-relative path, and re-derive the line so the symbol is within '.WINDOW." lines of it or is\n");
    fwrite(STDERR, "  the nearest declaration above it. The lint checks that the pointer lands somewhere sane;\n");
    fwrite(STDERR, "  it cannot check that the sentence around it is true.\n");
    fwrite(STDERR, "  Why this rule exists: docs/handoff/tickets/stale-path-line-citations.md\n");
    exit(1);
}

// SHRINK-LOCK, the sibling ratchets' semantics: a baselined citation that has been fixed must be
// removed from the baseline, or a re-introduction would pass silently.
if ($fixed !== []) {
    fwrite(STDERR, "\ncitation-lint: ".count($fixed)." baselined citation(s) fixed (good!) — lock it in by regenerating the baseline:\n");
    foreach ($fixed as $key => [$was, $now]) {
        fwrite(STDERR, '  - '.$render($key).'  baselined '.$was.', now '.$now."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-citation-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'citation-lint: OK — no new citation violations ('.count($found).' baselined key(s), '.array_sum($found)." citation(s)).\n");
exit(0);
