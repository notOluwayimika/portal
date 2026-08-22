<?php

/*
 * Coverage for bin/ci-citation-lint.php itself.
 *
 * SqlClockLintCoverageTest's header records what the absence of this costs, and BoundaryLintCoverageTest's
 * before it: a lint whose BEHAVIOUR was forbidden but whose TOKEN was missing from the pattern printed
 * OK while the violation sat in the tree. The exposure here is wider than either, because this lint has
 * several moving parts that can each neuter it into a permanent green — the scanned-directory list, the
 * path pattern, the vendor resolver that decides what is EXEMPT before anything is checked, the two
 * symbol spellings, the two halves of the compliance rule, and a baseline whose key carries an
 * occurrence COUNT.
 *
 * A lint rule that has never failed has not been tested; it has been written. So these plant real files
 * on disk, run the real script, and assert what it reports — no matcher is re-implemented here, because
 * a test that re-implements the thing it tests passes when both are wrong together.
 *
 * THE ARMS BELOW ARE NOT DECORATION EITHER, and a cold review proved it: three mutations of the lint
 * survived the first thirteen arms untouched — deleting the window's upper bound, dropping the range
 * from the symbol-last pattern, and anchoring a range at its END line. Every one of them is pinned here
 * now, and the arms that pin them say which mutation they exist for.
 *
 * ⚠️ THESE PLANT INTO THE REAL TREE, SO THEY ARE SAFE ONLY WHILE PEST RUNS SEQUENTIALLY — the same
 * constraint the two sibling coverage tests carry, and for the same reason: several arms assert the lint
 * is GREEN over the tree while other arms have violations planted in it. Verified at this commit:
 * `bin/quality` runs `pest --group=arch` and then plain `pest`, and `--parallel` appears only on Pint.
 *
 * FOUR ARMS MUTATE TRACKED FILES rather than planting new ones — the duplicate-citation arm, the
 * shrink-lock arm, the untracked-generate arm and the exemplar arm — because each needs state a new
 * file cannot have. Every one saves the exact bytes first and restores them per path in a finally.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/** Repo root. */
function citationRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Plant `[relativePath => contents]`, run the real lint, remove every planted file, and return
 * [exitCode, stderr+stdout]. The finally is load-bearing: a leaked fixture would fail the citation-lint
 * step for everyone afterwards, for a reason that has nothing to do with their change. `.gitignore`
 * covers the residue the finally cannot — a SIGKILL leaves an UNTRACKED file, the one outcome that
 * outlives the run and is committable.
 *
 * @param  array<string, string>  $files
 * @return array{0: int, 1: string}
 */
function citationLintWith(array $files): array
{
    $root = citationRoot();
    $written = [];

    try {
        foreach ($files as $rel => $body) {
            file_put_contents($root.'/'.$rel, $body);
            $written[] = $root.'/'.$rel;
        }

        return runCitationLint();
    } finally {
        foreach ($written as $abs) {
            @unlink($abs);
        }
    }
}

/** @return array{0: int, 1: string} */
function runCitationLint(string $mode = 'check'): array
{
    $output = [];
    $exit = 0;
    exec('php '.escapeshellarg(citationRoot().'/bin/ci-citation-lint.php').' '.escapeshellarg($mode).' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

/** A fixture name that matches the .gitignore patterns, so a SIGKILL residue is not committable. */
function citationFixtureName(): string
{
    return 'CitationLintFixture'.Str::random(8);
}

/**
 * The TARGET half of the symbol arms: a planted file whose contents are known, so the arms do not
 * depend on any real file keeping its line numbers. Its shape is deliberate — two methods with real
 * BODIES, because the compliance rule has a nearest-preceding-declaration half that a file of one-line
 * methods cannot exercise, and a third method far below for the out-of-window arms.
 *
 *   line  5  class CitationLintTarget
 *   line  7  ensureBankAccount()     body runs to 19
 *   line 21  laterMethod()           body runs to 29
 *   line 31  farAwaySymbol()
 *
 * @return array{0: string, 1: string} [relativePath, contents]
 */
function citationTargetFile(): array
{
    $name = citationFixtureName();
    $body = <<<'PHP'
<?php

namespace App\Finance;

final class CitationLintTarget
{
    public function ensureBankAccount(int $schoolId): int
    {
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        $f = 6;
        $g = 7;
        $h = 8;

        return $schoolId + $a + $b + $c + $d + $e + $f + $g + $h;
    }

    public function laterMethod(): void
    {
        $x = 1;
        $y = 2;
        $z = 3;
        $w = 4;
        $v = 5;
        $u = 6;
    }

    public function farAwaySymbol(): void {}
}
PHP;

    return ['app/Finance/'.$name.'.php', $body];
}

/** The heredoc above, asserted rather than trusted — a miscount would make an arm test the wrong thing. */
function assertTargetShape(string $body): void
{
    $lines = explode("\n", $body);
    expect($lines[4])->toContain('class CitationLintTarget')
        ->and($lines[6])->toContain('ensureBankAccount')
        ->and($lines[20])->toContain('laterMethod')
        ->and($lines[30])->toContain('farAwaySymbol')
        ->and(count($lines))->toBe(32);
}

it('a — reports a NEW bare path:LINE citation in app/, which is the whole rule', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // A bare citation: a path, a line, and nothing to check it against. This is the form 107 of the
    // 187 baselined citations are in, and the form the rule forbids from here on.
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n// see {$targetRel}:7 for the writer\nfinal class CiterA {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain(basename($citer))
        ->and($output)->toContain($targetRel.':7')
        ->and($output)->toContain('citation-missing-symbol');
});

it('b — does NOT report the same token in docs/, which is out of scope on purpose', function () {
    // THE ARM SAYS WHY, and the why is VOLUME rather than false positives. Add `docs` to SCANNED_DIRS
    // at this commit and `generate` produces 1,347 keys / 1,579 citations, of which docs/ contributes
    // 1,177 keys / 1,392 citations — seven and a half times the code baseline of 187, essentially all
    // of it unverifiable prose and pasted output. Skipping fenced blocks does not rescue it: only 372
    // of the 1,444 citation tokens in docs/ sit inside a fence, so a prose-only baseline still opens
    // at about 1,072.
    //
    // The cost is that citations inside tickets and reports stay unguarded, and two of the six
    // recorded instances of this defect were exactly that. This arm pins the exclusion as a decision;
    // it is not a claim that the exclusion is free.
    $doc = 'docs/handoff/tickets/'.citationFixtureName().'.md';

    [$exit, $output] = citationLintWith([
        $doc => "# fixture\n\nsee app/Support/ActiveSchool.php:99 for the writer\n",
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(basename($doc))
        ->and($output)->toContain('no new citation violations');
});

it('c — accepts path:LINE (symbol) when the symbol is within the window, in BOTH spellings', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // Line 7 exactly, line 9 (two below), line 4 (three above, the edge) — then the same citation
    // written SYMBOL-FIRST, which is this repository's own house style and which the first version of
    // this lint refused while telling the author the citation "carries no symbol".
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:7 (ensureBankAccount)\n"
            ."// {$targetRel}:9 (ensureBankAccount)\n"
            ."// {$targetRel}:4 (ensureBankAccount)\n"
            ."// ensureBankAccount ({$targetRel}:7)\n"
            ."final class CiterC {}\n",
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(basename($citer));
});

it('c2 — accepts a citation INSIDE a method that names that method (nearest preceding declaration)', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // Line 18 is the `return` inside ensureBankAccount(), eleven lines below the declaration and far
    // outside the window. Under the window alone this citation is refused, which would force every
    // citation in the repository onto a declaration line — and this repository routinely cites a
    // specific guard inside a method (`app/Support/ActiveSchool.php:42 (ActiveSchool::id)`, where
    // `id()` spans 28-60 and :42 is the session branch the citing test is about).
    //
    // The rule is NEAREST preceding, not "any symbol above" — the arm for that distinction is below.
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:18 (ensureBankAccount)\n"
            ."// ensureBankAccount ({$targetRel}:18)\n"
            ."final class CiterC2 {}\n",
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(basename($citer));
});

it('c3 — reports a symbol declared ABOVE the cited line that is not the NEAREST one [mutation: $from = 1]', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // THE MUTATION THIS ARM EXISTS FOR: `$from = max(1, $line - WINDOW)` becomes `$from = 1`, which
    // deletes the window's upper half so that ANY symbol declared anywhere above the cited line
    // passes — the class name, the namespace, every earlier method. Thirteen arms missed it.
    //
    // Line 27 sits inside laterMethod(). `ensureBankAccount` is declared at 7, well above it, and is
    // NOT the nearest preceding declaration — laterMethod is. So this must be reported.
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:27 (ensureBankAccount)\n"
            ."final class CiterC3 {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain($targetRel.':27')
        ->and($output)->toContain('citation-symbol-not-found');
});

it('d — reports a symbol that is not there, AND one that is there but outside the window', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // BOTH citations must be reported, and they are asserted SEPARATELY because they fail for
    // different reasons: :7 names a symbol that is nowhere in the file, :8 names `farAwaySymbol`,
    // which IS in the file, 23 lines away, and is not the nearest declaration above line 8 either.
    // Widen the window to the whole file and the second finding vanishes while the first survives —
    // so an arm that only asserted "exit 1" would stay green through the mutation that guts it.
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:7 (noSuchSymbolAnywhere)\n"
            ."// {$targetRel}:8 (farAwaySymbol)\n"
            ."final class CiterD {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('citation-symbol-not-found')
        ->and($output)->toContain($targetRel.':7')
        ->and($output)->toContain($targetRel.':8');
});

it('d2 — accepts a RANGE, anchored at its START line [mutations: dropped range, END anchor]', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // TWO MUTATIONS THIS ARM EXISTS FOR, and ranges had ZERO coverage across the first thirteen arms:
    //
    //   drop `(?:-\d+)?` from the symbol-last pattern -> the symbol is no longer seen at all and this
    //                                                    citation becomes `citation-missing-symbol`.
    //   anchor the range at its END line              -> line 31 is farAwaySymbol, so
    //                                                    `(ensureBankAccount)` stops matching.
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:7-31 (ensureBankAccount)\n"
            ."final class CiterD2 {}\n",
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(basename($citer));
});

it('d3 — a RANGE does not approve itself: the symbol must be at the START, not somewhere inside', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // The other half of the range decision. `:7-31` spans the whole class; `farAwaySymbol` is at 31,
    // INSIDE the range but nowhere near its start and not the nearest declaration above line 7.
    // Widening the check to the whole range — or anchoring at the end — makes a long range
    // self-approving, which is precisely what the header claims this does not do.
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:7-31 (farAwaySymbol)\n"
            ."final class CiterD3 {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain($targetRel.':7')
        ->and($output)->toContain('citation-symbol-not-found');
});

it('e — reports a cited file that does not exist, and a bare basename it refuses to resolve', function () {
    $citer = 'app/Finance/'.citationFixtureName().'.php';
    $ghost = 'app/Finance/NoSuchFile'.Str::random(8).'.php';

    expect(is_file(citationRoot().'/'.$ghost))->toBeFalse();

    // The second citation is the ticket's own instance-4 shape. It is NOT resolved against the repo:
    // a basename resolver is what manufactured two of the three past-EOF hits in the ticket's census,
    // so the answer is to refuse the form rather than to guess at the target.
    [$exit, $output] = citationLintWith([
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$ghost}:12 (handle)\n"
            ."// SeedDriveFixture.php:155 (info)\n"
            ."final class CiterE {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('citation-unresolvable')
        ->and($output)->toContain($ghost.':12')
        ->and($output)->toContain('citation-not-repo-relative')
        ->and($output)->toContain('SeedDriveFixture.php:155');
});

it('f — reports a cited line past the end of the file', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    assertTargetShape($targetBody);
    $length = count(explode("\n", $targetBody));
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // Past EOF is checked BEFORE the symbol, and the citation below carries a real symbol — so a
    // green here could not be explained away as "it never got to the line check".
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            .'// '.$targetRel.':'.($length + 500)." (ensureBankAccount)\n"
            ."final class CiterF {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('citation-past-eof')
        ->and($output)->toContain($targetRel.':'.($length + 500));
});

it('g — is green on a baselined bare citation, and the baseline is not vacuous', function () {
    [$exit, $output] = runCitationLint();

    expect($exit)->toBe(0)
        ->and($output)->toContain('no new citation violations');

    // …and the negative arm is not vacuous. The tree really does contain bare citations — 187 of them
    // at this commit — and the lint is green only because they are recorded. This reads the baseline,
    // takes real entries, and asserts the citing file STILL carries that token, so a green above
    // cannot mean "the baselined citations were quietly deleted and nothing is being forgiven".
    $entries = citationBaselineEntries();

    expect(count($entries))->toBeGreaterThan(100);

    foreach (array_slice($entries, 0, 5) as [$rule, $citing, $token, $count]) {
        expect(file_get_contents(citationRoot().'/'.$citing))->toContain($token);
    }
});

/** @return array<int, array{0: string, 1: string, 2: string, 3: string}> */
function citationBaselineEntries(): array
{
    $entries = [];
    foreach (file(citationRoot().'/citation-lint-baseline.txt') as $raw) {
        $raw = rtrim($raw, "\n");
        if ($raw === '' || str_starts_with($raw, '#')) {
            continue;
        }
        $entries[] = explode("\t", $raw);
    }

    return $entries;
}

it('h — fails when the baseline grows by one, naming the new entry', function () {
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    [$exit, $output] = citationLintWith([
        $citer => "<?php\n\nnamespace App\\Finance;\n\n// app/Support/ActiveSchool.php:99\nfinal class CiterH {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('NEW or GROWN')
        ->and($output)->toContain(basename($citer))
        ->and($output)->toContain('app/Support/ActiveSchool.php:99');

    // Exactly ONE finding, not a cascade — the arm is about the ratchet admitting one entry, so a
    // failure caused by something else in the tree would be a different (and misleading) green-to-red.
    expect($output)->toContain('1 NEW or GROWN citation violation(s)');
});

it('i — does not manufacture a finding from a vendor citation, in either of the ticket\'s two shapes', function () {
    $root = citationRoot();

    // NON-VACUITY FIRST, because this arm asserts a GREEN and a green proves nothing unless the trap
    // is really armed. app/Models/Role.php is short; the vendor file the citation actually points at
    // is long; so a basename resolver WOULD report :186 as past end-of-file. That is the manufactured
    // finding the ticket measured, twice. It is also the tie the IN-RANGE test must still resolve
    // toward vendor — vendor contains line 186, the in-tree file does not.
    $appRole = $root.'/app/Models/Role.php';
    $vendorRole = $root.'/vendor/spatie/laravel-permission/src/Models/Role.php';
    expect(is_file($appRole))->toBeTrue()
        ->and(count(file($appRole)))->toBeLessThan(186)
        ->and(is_file($vendorRole))->toBeTrue()
        ->and(count(file($vendorRole)))->toBeGreaterThanOrEqual(186);

    $onLine = 'app/Finance/'.citationFixtureName().'.php';
    $prevLine = 'app/Finance/'.citationFixtureName().'.php';

    [$exit, $output] = citationLintWith([
        // Shape 1 — the word "vendor" on the citing line, which a line-scoped guard would also catch.
        $onLine => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// findByParam (vendor Models/Role.php:186-188) does the team scoping\n"
            ."final class CiterI1 {}\n",
        // Shape 2 — the word "Vendor" opening the PREVIOUS line. The ticket measured this one as the
        // hit a line-scoped vendor guard still misses, which is why the exemption resolves against
        // vendor/ on disk instead of searching for the word.
        $prevLine => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// Vendor behaviour, for reference:\n"
            ."// findByParam Models/Role.php:186-188 does the team scoping\n"
            ."final class CiterI2 {}\n",
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(basename($onLine))
        ->and($output)->not->toContain(basename($prevLine))
        ->and($output)->not->toContain('Models/Role.php:186');
});

it('i2 — the vendor exemption is CONDITIONAL on the cited line existing in a vendor candidate', function () {
    $root = citationRoot();

    // THE UNCONDITIONAL VERSION HID A LIVE STALE CITATION FOR A WHOLE BRANCH. There are three
    // basenames in this tree that match on both sides, and only one of them should be exempt. The
    // arm asserts the four file lengths that decide it, so the fixtures below are not resting on an
    // assumption about somebody else's file.
    $vendorUser = $root.'/vendor/laravel/framework/src/Illuminate/Foundation/Auth/User.php';
    $vendorPermission = $root.'/vendor/spatie/laravel-permission/src/Models/Permission.php';
    expect(count(file($vendorUser)))->toBeLessThan(412)                   // :412 cannot be in vendor
        ->and(count(file($root.'/app/Models/User.php')))->toBeGreaterThanOrEqual(412)
        ->and(count(file($vendorPermission)))->toBeGreaterThanOrEqual(158)  // both sides in range
        ->and(count(file($root.'/app/Enums/Permission.php')))->toBeGreaterThanOrEqual(158);

    $citer = 'app/Finance/'.citationFixtureName().'.php';

    [$exit, $output] = citationLintWith([
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// overridden at User.php:412\n"
            ."// the submit half of the triple at Permission.php:158-160\n"
            ."final class CiterI3 {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('User.php:412')
        ->and($output)->toContain('Permission.php:158')
        ->and($output)->toContain('citation-not-repo-relative');
});

it('j — treats a citation inside a fenced block or a quoted grep -n line as a citation', function () {
    // THE DECISION, and it is a decision rather than an oversight: inside a SCANNED file, a
    // `path:LINE` token is a citation wherever it sits. The lint does not try to tell quoted tool
    // output from prose because it structurally cannot — `grep -n` output is byte-identical to a
    // citation. Inside app/, tests/, bin/ and .claude/skills/ the consequence is accepted: a scanned
    // file that pastes tool output gets a finding, and the answer is the baseline, argued once.
    //
    // .claude/skills/ IS the file type where this bites, and it is in scope on purpose: skills are
    // what agents read as instructions, and one skill file needed two separate citation-correction
    // rounds (6b14a43, ec2b56a).
    $skill = '.claude/skills/'.citationFixtureName().'.md';

    [$exit, $output] = citationLintWith([
        $skill => "# fixture skill\n\n```\n\$ grep -n ActiveSchool app/Support/ActiveSchool.php\napp/Support/ActiveSchool.php:99:    public static function getOrFail(): int\n```\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain(basename($skill))
        ->and($output)->toContain('app/Support/ActiveSchool.php:99');
});

it('k — fails on a SECOND byte-identical citing line in a baselined file, which is the boundary-lint trap', function () {
    // docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md records ci-boundary-lint.php
    // keying on `rule \t path \t trim($line)` with NO occurrence count, so a seventh byte-identical
    // violation produces a key that is already present and is admitted silently. This lint carries a
    // COUNT in the baseline for exactly that reason, and this arm is the bite-proof.
    //
    // It has to mutate a TRACKED file, because the trap needs a citation that is already baselined and
    // a new file never has one. The original bytes are saved first and restored in the finally.
    $root = citationRoot();

    $entry = null;
    foreach (citationBaselineEntries() as [$rule, $citing, $token, $count]) {
        if ($rule === 'citation-missing-symbol' && (int) $count === 1 && str_ends_with($citing, '.php')) {
            $entry = [$citing, $token];
            break;
        }
    }

    expect($entry)->not->toBeNull();
    [$citing, $token] = $entry;

    $abs = $root.'/'.$citing;
    $original = file_get_contents($abs);

    try {
        // A second occurrence of the SAME token in the SAME file, in a line that is a PHP comment so
        // the file stays parseable. Under a text-keyed baseline this is invisible; under a counted one
        // it takes the key from 1 to 2.
        file_put_contents($abs, $original."\n// {$token}\n");
        [$exit, $output] = runCitationLint();
    } finally {
        file_put_contents($abs, $original);
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain($citing)
        ->and($output)->toContain($token)
        ->and($output)->toContain('baselined 1, now 2');

    // …and the restore really restored it, so the next arm is not measuring this one's residue.
    expect(file_get_contents($abs))->toBe($original);
    [$exitAfter] = runCitationLint();
    expect($exitAfter)->toBe(0);
});

it('l — fails when a baselined citation has been fixed but left in the baseline (shrink-lock)', function () {
    // The sibling defect this repo has already paid for twice: ci-authz-lint and ci-boundary-lint both
    // WARNED on a stale baseline entry and still exited 0, so the baseline could sit above the true
    // count indefinitely and a future regression could hide in the slack. This asserts the lock is a
    // failure here, not a note.
    $path = citationRoot().'/citation-lint-baseline.txt';
    $original = file_get_contents($path);

    try {
        file_put_contents($path, $original."citation-missing-symbol\tapp/Finance/NoSuchCiter.php\tapp/Nowhere.php:1\t1\n");
        [$exit, $output] = runCitationLint();
    } finally {
        file_put_contents($path, $original);
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain('fixed (good!)')
        ->and($output)->toContain('app/Nowhere.php:1');

    expect(file_get_contents($path))->toBe($original);
});

it('m — sees an EXTENSIONLESS executable, which the path pattern could not match before', function () {
    // `bin/quality`, `bin/landed`, `bin/is-docs-only-push`, `bin/quality-promote`, `bin/quality-clean-db`
    // and `.githooks/pre-push` are cited throughout this repository and matched NOTHING: the path
    // pattern needed a file extension to know a path when it sees one. `bin/quality:99999` passed.
    //
    // This branch walked into that hole itself — adding a step moved `bin/quality` by 13 lines and
    // staled five in-scope citations of it that the gate could not see.
    $root = citationRoot();
    expect(count(file($root.'/bin/quality')))->toBeLessThan(99999)
        ->and(count(file($root.'/.githooks/pre-push')))->toBeLessThan(99999);

    $citer = 'app/Finance/'.citationFixtureName().'.php';

    [$exit, $output] = citationLintWith([
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// bin/quality:99999 (thisSymbolIsNowhere)\n"
            ."// .githooks/pre-push:99999 (alsoNowhere)\n"
            ."final class CiterM {}\n",
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('bin/quality:99999')
        ->and($output)->toContain('.githooks/pre-push:99999')
        ->and($output)->toContain('citation-past-eof');
});

it('n — generate reads TRACKED files only, while check still reads the working tree', function () {
    // An untracked file that got into `generate` would bake a path nobody else has into a shrink-only
    // baseline, and every other checkout would then fail with "fixed (good!)" naming a file that does
    // not exist there. The asymmetry with `check` is deliberate and is what every arm above depends
    // on: `check` must still see a file you have just written.
    $root = citationRoot();
    $citer = 'app/Finance/'.citationFixtureName().'.php';
    $baselinePath = $root.'/citation-lint-baseline.txt';
    $originalBaseline = file_get_contents($baselinePath);

    file_put_contents($root.'/'.$citer, "<?php\n\nnamespace App\\Finance;\n\n// app/Support/ActiveSchool.php:99\nfinal class CiterN {}\n");

    try {
        // check SEES it…
        [$checkExit, $checkOutput] = runCitationLint();

        // …and generate does NOT bake it in.
        runCitationLint('generate');
        $regenerated = file_get_contents($baselinePath);
    } finally {
        @unlink($root.'/'.$citer);
        file_put_contents($baselinePath, $originalBaseline);
    }

    expect($checkExit)->toBe(1)
        ->and($checkOutput)->toContain(basename($citer))
        ->and($regenerated)->not->toContain(basename($citer));

    // The regeneration is otherwise a no-op at this commit, which is also the assertion that the
    // committed baseline is exactly what `generate` produces — a drifted baseline would show up here.
    expect($regenerated)->toBe($originalBaseline);
    expect(file_get_contents($baselinePath))->toBe($originalBaseline);
});

it('o — the lint reads its OWN file, and its worked example is compliant', function () {
    // The lint used to exempt itself, and that is how its worked example came to cite
    // `ActiveSchool.php` line 99 for `getOrFail`, which is at line 66 — the exemplar failing the
    // exemplar's own rule, in the one file the rule could not read. Only the coverage test is exempt
    // now.
    $root = citationRoot();
    $source = file_get_contents($root.'/bin/ci-citation-lint.php');

    expect($source)->not->toContain("'bin/ci-citation-lint.php',")
        ->and($source)->toContain('app/Support/ActiveSchool.php:66 (getOrFail)');

    // The exemplar is a real citation about a real file, asserted here rather than assumed.
    $activeSchool = file($root.'/app/Support/ActiveSchool.php', FILE_IGNORE_NEW_LINES);
    expect($activeSchool[65])->toContain('getOrFail');

    // …and the lint reds it if it drifts. The exemplar is moved to a line where `getOrFail` is not,
    // and is not the nearest declaration above either, and the lint must say so about its own file.
    $original = $source;
    try {
        file_put_contents(
            $root.'/bin/ci-citation-lint.php',
            str_replace('app/Support/ActiveSchool.php:66 (getOrFail)', 'app/Support/ActiveSchool.php:99 (getOrFail)', $original)
        );
        [$exit, $output] = runCitationLint();
    } finally {
        file_put_contents($root.'/bin/ci-citation-lint.php', $original);
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain('bin/ci-citation-lint.php')
        ->and($output)->toContain('app/Support/ActiveSchool.php:99');

    expect(file_get_contents($root.'/bin/ci-citation-lint.php'))->toBe($original);
});

it('p — the measurement script and the lint share one declaration regex', function () {
    // bin/citation-window-measure.php is what measured the nearest-preceding half of the compliance
    // rule, and it carries its own copy of the declaration regex. If the two drift apart the published
    // measurement silently stops describing the lint, which is the failure this whole branch is about
    // one level up.
    $root = citationRoot();

    foreach (['bin/ci-citation-lint.php', 'bin/citation-window-measure.php'] as $file) {
        $source = file_get_contents($root.'/'.$file);
        expect($source)->toContain("'/\\b(?:function|class|interface|trait|enum)\\s+([A-Za-z_][A-Za-z0-9_]*)/'")
            ->and($source)->toContain("'/\\bconst\\s+([A-Z_][A-Z0-9_]*)/'");
    }
});

it('q — is clean on the tree as it stands', function () {
    [$exit, $output] = runCitationLint();

    expect($exit)->toBe(0)
        ->and($output)->toContain('no new citation violations');
});
