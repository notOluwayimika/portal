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
 * THE FORM. A citation is COMPLIANT when it carries the symbol it points at and that symbol is
 * actually near the cited line:
 *
 *     app/Support/ActiveSchool.php:99 (getOrFail)      compliant, if `getOrFail` occurs
 *                                                      within +/-3 lines of line 99
 *     app/Support/ActiveSchool.php:99                  NOT compliant — nothing to check against
 *
 * A range is matched on its START line: `Foo.php:99-140 (bar)` is checked at 99. Widening the
 * window to the whole range would make a long range self-approving.
 *
 * WHY THE WINDOW IS 3, AND WHAT THE MEASUREMENT ACTUALLY SAID. Two curves were measured over this
 * tree at the commit that added this lint, and the finding is that they CROSS — no window absorbs
 * drift while still discriminating, so N is NOT a drift-tolerance knob and must not be grown into
 * one.
 *
 *   (a) DISCRIMINATION — 3,708 adjacent-declaration pairs across 842 files in the scanned dirs.
 *       For each declaration, how far away is the NEXT declaration's name (i.e. would a citation
 *       that names the wrong neighbouring symbol still pass?):
 *
 *           N=0  2.5%    N=1  4.2%    N=2 20.0%    N=3 23.2%
 *           N=5 36.0%    N=8 53.7%    N=10 61.4%   N=20 81.6%   N=50 95.4%
 *
 *   (b) DRIFT — 2,222 symbols matched by name between a sha 30 days back and HEAD; of the 754 that
 *       MOVED, how many are still inside the window:
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
 * tolerates ordinary jitter above a symbol — an added `use`, an attribute, a blank line; 29.7% of
 * all 60-day moves are 3 lines or fewer — rather than a tolerance for real movement. N=1
 * discriminates better (4.2% against 23.2%) and was rejected only because a one-line insert above
 * a symbol is not a defect and should not fail a gate.
 *
 * MATCHING IS ON THE WHOLE FILE, NOT ON COMMENTS. Unlike the sibling lints this one does NOT skip
 * comment lines — a citation's natural home IS a docblock, and skipping comments would skip the
 * defect. It also does not try to tell a citation from a pasted `grep -n` line or a fenced code
 * block, because it structurally cannot: `grep -n` output is byte-identical to a citation. That is
 * the whole reason for the scope decision below, and inside the scanned dirs the consequence is
 * accepted — a scanned file that pastes tool output carrying a `path:LINE` token gets a finding,
 * and the answer is the baseline, argued once.
 *
 * SCOPE, and the exclusion is the load-bearing decision.
 *
 *   SCANNED: app/ tests/ bin/ database/ config/ routes/ bootstrap/ .claude/skills/
 *   NOT SCANNED: docs/
 *
 * docs/ is out because REPORTS PASTE RAW COMMAND OUTPUT BY RULE, and `grep -n` output cannot be
 * told from a citation by any matcher. The ticket measured this: at `2b3cdbb`, SEVEN of the NINE
 * past-EOF hits in the whole tree were that ticket's own self-quotations of census output. A lint
 * over docs/ opens with a baseline dominated by its own documentation, which is how a gate becomes
 * decoration.
 *
 * THE COST OF THAT EXCLUSION IS REAL AND IS NOT COVERED BY ANYTHING. Citations inside tickets and
 * reports stay unguarded, and two of the six recorded instances were exactly that — the malformed-200
 * ticket's citation, and the seven numbers in that report's sections 9 and 11.2. This lint does not
 * cover them and no green here should be read as if it did.
 *
 * .claude/skills/ IS IN SCOPE AND IS THE POINT: skills are what agents read as INSTRUCTIONS, and
 * one skill file needed two separate citation-correction rounds — `6b14a43` (7 citations on the
 * removed side, 3 of which changed value) and `ec2b56a` (3 on the removed side, 2 changed). Both
 * re-derived from the commits themselves, not carried from the ticket.
 *
 * VENDOR, AND WHY IT IS RESOLVED RATHER THAN GUESSED. A cited path this repository does not contain
 * is EXEMPT when it resolves under vendor/ — by prefix (`vendor/…`) or by unique path SUFFIX
 * (`Models/Role.php`). This is not politeness toward dependencies; it is the only thing that stops
 * the lint MANUFACTURING findings. The ticket measured two of its three past-EOF hits as artefacts
 * of basename-resolving a vendor path onto an app file of the same name: `Models/Role.php:186-188`
 * is `vendor/spatie/laravel-permission/src/Models/Role.php` (221 lines, and `:186` is exactly the
 * `findByParam` block the sentence describes), which a basename resolver retargets at
 * `app/Models/Role.php` (36 lines) and reports as past-EOF. A LINE-SCOPED `vendor` GUARD IS NOT
 * ENOUGH — in one of the two the word "Vendor" opens the PREVIOUS line — which is why the check is
 * a filesystem resolution against vendor/ and not a word search.
 *
 * VENDOR WINS TIES. A bare suffix that matches BOTH a vendor file and an in-tree file is exempt,
 * because that ambiguity is precisely the shape that manufactured the two findings. The price is
 * that a genuine in-tree citation written as a bare basename which happens to collide with a vendor
 * path is silently unchecked. Precision over recall, deliberately.
 *
 * A BARE BASENAME IS NEVER RESOLVED AGAINST THE REPO. `SeedDriveFixture.php:155` is reported as
 * `citation-not-repo-relative` rather than resolved to `app/Console/Commands/SeedDriveFixture.php`,
 * because a basename resolver is the defect generator described above. Requiring the repo-relative
 * path in new citations is the ticket's own cheapest step, is checkable without resolving anything,
 * and is a precondition for the resolution to mean anything.
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
 * WHAT A GREEN DOES NOT PROVE. A citation whose symbol appears near the cited line can still be
 * wrong about what it claims. This lint checks that the pointer lands somewhere sane; it cannot
 * check that the sentence is true. The ticket's instances 3 and 5 were false when written with the
 * citation itself well-formed.
 *
 * Usage:
 *   php bin/ci-citation-lint.php            # check: exit 1 on any NEW or GROWN violation
 *   php bin/ci-citation-lint.php generate   # (re)write the baseline
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

/**
 * The two files that contain citations AS DATA rather than as claims: this script's own header, and
 * the coverage test whose fixtures are citation strings in heredocs. Same exemption `SELF` carries
 * in bin/ci-sql-clock-lint.php, and it is a real hole in exactly two named files: a genuine stale
 * citation written in either of them is not seen.
 */
const SELF = [
    'bin/ci-citation-lint.php',
    'tests/Arch/CitationLintCoverageTest.php',
];

/**
 * THE TICKET'S OWN REGEX, REUSED VERBATIM — it is already tuned, and the census figures quoted in
 * the header were taken with it. The negative lookbehind is what keeps it off the middle of a
 * longer path and off `…/foo.php:12:` continuations.
 */
const CITATION_RX = '#(?<![\w/.-])([A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:php|ts|tsx|js|jsx|md|sh|sql|json|xml)):(\d+)#';

/** Why each rule is a defect — printed per finding, so a message names the actual mechanism. */
const RULE_REASONS = [
    'citation-missing-symbol' => 'carries no symbol, so nothing about it can be checked — write `path:LINE (symbolName)`',
    'citation-symbol-not-found' => 'names a symbol that does not occur within '.WINDOW.' lines of the cited line',
    'citation-past-eof' => 'cites a line beyond the end of the file it points at',
    'citation-not-repo-relative' => 'is a bare basename; write the repo-relative path (a basename resolver manufactures findings)',
    'citation-unresolvable' => 'points at no file in this repository and at nothing under vendor/',
];

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
 * Does $suffix name a file under vendor/ — by prefix, or as a unique-or-not path suffix?
 *
 * ONE PASS over vendor/, and only when there is something to look up. The suffix form is the half
 * that matters: `Models/Role.php` is how the manufactured findings in the ticket were written, and
 * nothing short of resolving it against vendor/ would have stopped them.
 *
 * @param  array<int, string>  $suffixes
 * @return array<string, true> the subset that resolves under vendor/
 */
function vendorResolvable(string $root, array $suffixes): array
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
                $hit[$suffix] = true;
            }
        }
    }

    return $hit;
}

/**
 * Is $text one identifier rather than a phrase? `ensureBankAccount`, `SubledgerPoster::post`,
 * `$table->timestamp`, `handle()` — yes. `the statement page`, `invoice-addressed`, `see below` —
 * no. See citationsIn() for the measurement that put this in.
 */
function isSymbolShaped(string $text): bool
{
    return preg_match('/^\$?[A-Za-z_][A-Za-z0-9_]*(?:(?:::|->|\\\\)\$?[A-Za-z_][A-Za-z0-9_]*)*(?:\(\))?$/', trim($text)) === 1;
}

/**
 * Every citation in one line of text, as [citedPath, citedLine, rawToken, symbolText|null].
 *
 * The symbol is read from a parenthesised group that FOLLOWS the token immediately (one optional
 * range, then optional whitespace). `Foo.php:12 (bar)` carries one; `Foo.php:12` does not, and
 * neither does `Foo.php:12 — see bar()`: a rule that accepted any nearby parenthesis would accept
 * the prose that already surrounds most citations.
 *
 * AND THE GROUP MUST BE SYMBOL-SHAPED — one identifier, optionally qualified (`Class::method`,
 * `$table->timestamp`, `handle()`), with NO SPACES AND NO PROSE. THE MATCHER WAS NARROWED HERE AND
 * THE NUMBERS ARE RECORDED BOTH WAYS. Accepting any parenthesised text and asking whether any word
 * in it occurs near the cited line, 2 of the 194 citations in scope passed BY COINCIDENCE:
 * `routes/endpoints/finance.php:24 (invoice-addressed)` passes because the word "invoice" is in the
 * route path on the next line, and `routes/web.php:237 (the statement page)` the same way. Both are
 * prose, neither names a symbol, and a coincidental pass is worse than a baseline entry because it
 * reads like verification. Requiring the symbol shape, the coincidental passes are 0 of 194 and
 * both of those citations go to the baseline where they belong.
 *
 * @return array<int, array{0: string, 1: int, 2: string, 3: string|null}>
 */
function citationsIn(string $line): array
{
    if (! preg_match_all(CITATION_RX, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $out = [];
    foreach ($matches as $m) {
        $raw = $m[0][0];
        $end = $m[0][1] + strlen($raw);
        $tail = substr($line, $end);

        $symbol = null;
        if (preg_match('/^(?:-\d+)?\s*\(([^)]{1,120})\)/', $tail, $s) === 1 && isSymbolShaped($s[1])) {
            $symbol = $s[1];
        }

        $out[] = [$m[1][0], (int) $m[2][0], $raw, $symbol];
    }

    return $out;
}

/**
 * Does any identifier named in $symbol occur, as a whole word, within WINDOW lines of $line?
 *
 * ANY rather than EVERY: `SubledgerPoster::post` names two identifiers and the class name is
 * routinely not on the same line as the method. Identifiers shorter than three characters are
 * dropped — `$a`, `x` and the like match everywhere and would make the check vacuous.
 *
 * @param  array<int, string>  $target
 */
function symbolIsNear(array $target, int $line, string $symbol): bool
{
    preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,}/', $symbol, $ids);
    if ($ids[0] === []) {
        return false;
    }

    $from = max(1, $line - WINDOW);
    $to = min(count($target), $line + WINDOW);

    for ($i = $from; $i <= $to; $i++) {
        foreach ($ids[0] as $id) {
            if (preg_match('/\b'.preg_quote($id, '/').'\b/', $target[$i - 1]) === 1) {
                return true;
            }
        }
    }

    return false;
}

// ---------------------------------------------------------------------------------------------
// Pass 1 — collect every citation in scope, and every cited path that the repo does not contain.
// ---------------------------------------------------------------------------------------------
$citations = [];
$unresolved = [];

foreach (scannedFiles($root) as $rel) {
    if (in_array($rel, SELF, true)) {
        continue;
    }

    $lines = file($root.'/'.$rel, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    foreach ($lines as $i => $line) {
        foreach (citationsIn($line) as [$cited, $citedLine, $raw, $symbol]) {
            $citations[] = [$rel, $i + 1, $cited, $citedLine, $raw, $symbol];
            if (! is_file($root.'/'.$cited) && ! str_starts_with($cited, 'vendor/')) {
                $unresolved[$cited] = true;
            }
        }
    }
}

$vendorHit = vendorResolvable($root, array_keys($unresolved));

// ---------------------------------------------------------------------------------------------
// Pass 2 — classify. Order matters: vendor is decided BEFORE anything is resolved in-tree, which
// is the whole of the anti-manufacturing guarantee.
// ---------------------------------------------------------------------------------------------
$targetCache = [];
$found = [];

foreach ($citations as [$rel, , $cited, $citedLine, $raw, $symbol]) {
    if (str_starts_with($cited, 'vendor/') || isset($vendorHit[$cited])) {
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

        if ($citedLine < 1 || $citedLine > count($target)) {
            $rule = 'citation-past-eof';
        } elseif ($symbol === null) {
            $rule = 'citation-missing-symbol';
        } elseif (! symbolIsNear($target, $citedLine, $symbol)) {
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
# Every entry here is a citation the lint cannot verify — most of them bare `path:LINE` tokens
# written before the symbol form existed. Burn one down by giving it a symbol
# (`path:LINE (symbolName)`) and re-deriving the line, then regenerate:
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
    fwrite(STDERR, "\n  Write the citation as `path:LINE (symbolName)` with a repo-relative path, and re-derive\n");
    fwrite(STDERR, '  the line so the symbol is within '.WINDOW." lines of it. The lint checks that the pointer\n");
    fwrite(STDERR, "  lands somewhere sane; it cannot check that the sentence around it is true.\n");
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
