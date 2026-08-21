<?php

/*
 * Coverage for bin/ci-citation-lint.php itself.
 *
 * SqlClockLintCoverageTest's header records what the absence of this costs, and BoundaryLintCoverageTest's
 * before it: a lint whose BEHAVIOUR was forbidden but whose TOKEN was missing from the pattern printed
 * OK while the violation sat in the tree. The exposure here is wider than either, because this lint has
 * four moving parts that can each neuter it into a permanent green — the scanned-directory list, the
 * vendor resolver that decides what is EXEMPT before anything is checked, the symbol-shape matcher, and
 * a baseline whose key carries an occurrence COUNT. Nothing else in the suite would notice any of them.
 *
 * A lint rule that has never failed has not been tested; it has been written. So these plant real files
 * on disk, run the real script, and assert what it reports — no matcher is re-implemented here, because
 * a test that re-implements the thing it tests passes when both are wrong together.
 *
 * ⚠️ THESE PLANT INTO THE REAL TREE, SO THEY ARE SAFE ONLY WHILE PEST RUNS SEQUENTIALLY — the same
 * constraint the two sibling coverage tests carry, and for the same reason: one arm asserts the lint is
 * GREEN over the tree while other arms have violations planted in it. Verified at this commit:
 * `bin/quality` runs `pest --group=arch` and then plain `pest`, and `--parallel` appears only on Pint.
 *
 * TWO ARMS MUTATE TRACKED FILES rather than planting new ones — the duplicate-citation arm and the
 * shrink-lock arm — because both need a citation that is ALREADY in the baseline, which a new file by
 * definition never has. Each saves the exact bytes first and restores them in a finally, per path.
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
function runCitationLint(): array
{
    $output = [];
    $exit = 0;
    exec('php '.escapeshellarg(citationRoot().'/bin/ci-citation-lint.php').' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

/** A fixture name that matches the .gitignore patterns, so a SIGKILL residue is not committable. */
function citationFixtureName(): string
{
    return 'CitationLintFixture'.Str::random(8);
}

/**
 * The TARGET half of the symbol arms: a planted file whose contents are known, so the arms do not
 * depend on any real file keeping its line numbers. The caller is handed the line numbers back and
 * asserts on them, which is the point — a heredoc miscounted by one would otherwise make an arm test
 * the wrong thing quietly.
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
        return $schoolId;
    }

    public function padding01(): void {}

    public function padding02(): void {}

    public function padding03(): void {}

    public function padding04(): void {}

    public function padding05(): void {}

    public function padding06(): void {}

    public function padding07(): void {}

    public function padding08(): void {}

    public function farAwaySymbol(): void {}
}
PHP;

    return ['app/Finance/'.$name.'.php', $body];
}

it('a — reports a NEW bare path:LINE citation in app/, which is the whole rule', function () {
    [$targetRel, $targetBody] = citationTargetFile();
    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // A bare citation: a path, a line, and nothing to check it against. This is the form 98 of the
    // 180 baselined citations are in, and the form the rule forbids from here on.
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
    // THE ARM SAYS WHY. Reports paste raw command output by rule, and `grep -n` output is
    // byte-identical to a citation — the ticket measured SEVEN of the NINE past-EOF hits in the whole
    // tree at 2b3cdbb as that ticket's own self-quotations of census output. A lint over docs/ opens
    // with a baseline dominated by its own documentation.
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

it('c — accepts path:LINE (symbol) when the symbol is within the window', function () {
    [$targetRel, $targetBody] = citationTargetFile();

    // Self-check on the heredoc FIRST: if the method is not on line 7 this arm proves nothing, and a
    // miscounted heredoc is exactly the silent way that happens.
    $lines = explode("\n", $targetBody);
    expect($lines[6])->toContain('ensureBankAccount');

    $citer = 'app/Finance/'.citationFixtureName().'.php';

    // Line 7 exactly, line 9 (two below, inside the window of 3), and line 4 (three above, the edge).
    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:7 (ensureBankAccount)\n"
            ."// {$targetRel}:9 (ensureBankAccount)\n"
            ."// {$targetRel}:4 (ensureBankAccount)\n"
            ."final class CiterC {}\n",
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain(basename($citer));
});

it('d — reports a symbol that is not there, AND one that is there but outside the window', function () {
    [$targetRel, $targetBody] = citationTargetFile();

    $lines = explode("\n", $targetBody);
    expect($lines[6])->toContain('ensureBankAccount');
    // farAwaySymbol is real, and far: the second citation below is the one that proves the WINDOW is
    // doing work rather than a bare "does this word appear in the file" check.
    $far = 0;
    foreach ($lines as $i => $l) {
        if (str_contains($l, 'farAwaySymbol')) {
            $far = $i + 1;
        }
    }
    expect($far)->toBeGreaterThan(7 + 3);

    $citer = 'app/Finance/'.citationFixtureName().'.php';

    [$exit, $output] = citationLintWith([
        $targetRel => $targetBody,
        $citer => "<?php\n\nnamespace App\\Finance;\n\n"
            ."// {$targetRel}:7 (noSuchSymbolAnywhere)\n"
            ."// {$targetRel}:8 (farAwaySymbol)\n"
            ."final class CiterD {}\n",
    ]);

    // BOTH citations must be reported, and they are asserted SEPARATELY because they fail for
    // different reasons: :7 names a symbol that is nowhere in the file, :8 names one that is in the
    // file but 20-odd lines away. Widen the window to the whole file and the second finding vanishes
    // while the first survives — so an arm that only asserted "exit 1" would stay green through
    // exactly the mutation that guts the window.
    expect($exit)->toBe(1)
        ->and($output)->toContain('citation-symbol-not-found')
        ->and($output)->toContain($targetRel.':7')
        ->and($output)->toContain($targetRel.':8');
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

    // …and the negative arm is not vacuous. The tree really does contain bare citations — 180 of them
    // at this commit — and the lint is green only because they are recorded. This reads the baseline,
    // takes a real entry, and asserts the citing file STILL carries that token, so a green above
    // cannot mean "the baselined citations were quietly deleted and nothing is being forgiven".
    $entries = [];
    foreach (file(citationRoot().'/citation-lint-baseline.txt') as $raw) {
        $raw = rtrim($raw, "\n");
        if ($raw === '' || str_starts_with($raw, '#')) {
            continue;
        }
        $entries[] = explode("\t", $raw);
    }

    expect(count($entries))->toBeGreaterThan(100);

    foreach (array_slice($entries, 0, 5) as [$rule, $citing, $token, $count]) {
        expect(file_get_contents(citationRoot().'/'.$citing))->toContain($token);
    }
});

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
    // finding the ticket measured, twice.
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

it('j — treats a citation inside a fenced block or a quoted grep -n line as a citation', function () {
    // THE DECISION, and it is a decision rather than an oversight: inside a SCANNED file, a
    // `path:LINE` token is a citation wherever it sits. The lint does not try to tell quoted tool
    // output from prose because it structurally cannot — `grep -n` output is byte-identical to a
    // citation, which is the whole reason docs/ is out of scope (arm b). Inside app/, tests/, bin/
    // and .claude/skills/ the consequence is accepted: a scanned file that pastes tool output gets a
    // finding, and the answer is the baseline, argued once.
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
    foreach (file($root.'/citation-lint-baseline.txt') as $raw) {
        $raw = rtrim($raw, "\n");
        if ($raw === '' || str_starts_with($raw, '#')) {
            continue;
        }
        [$rule, $citing, $token, $count] = explode("\t", $raw);
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
    $root = citationRoot();
    $path = $root.'/citation-lint-baseline.txt';
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

it('m — is clean on the tree as it stands', function () {
    [$exit, $output] = runCitationLint();

    expect($exit)->toBe(0)
        ->and($output)->toContain('no new citation violations');
});
