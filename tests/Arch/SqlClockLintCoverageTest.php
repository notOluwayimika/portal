<?php

/*
 * Coverage for bin/ci-sql-clock-lint.php itself.
 *
 * BoundaryLintCoverageTest's header records what the absence of this costs: a lint whose BEHAVIOUR
 * was forbidden but whose TOKEN was missing from the pattern printed OK while the violation sat in
 * the tree. The same exposure applies here, and more sharply, because this lint's matcher is not a
 * grep — it tokenises PHP and searches string literals only. A change to SCANNED_DIRS, to the
 * token list, to the tokeniser branch or to EXCEPTIONS can neuter it into a permanent green, and
 * nothing else in the suite would notice.
 *
 * A lint rule that has never failed has not been tested; it has been written. So these plant a real
 * violating file on disk, run the real script, and assert it is REPORTED — no matcher is
 * re-implemented here, because a test that re-implements the thing it tests passes when both are
 * wrong together.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/**
 * Write $body to a temp PHP file under app/Finance/, run the real lint, delete the file, and return
 * [exitCode, stderr+stdout, basename]. The finally is load-bearing: a leaked fixture would fail the
 * sql-clock lint step for everyone afterwards, for a reason that has nothing to do with their
 * change. `.gitignore` covers the residue the finally cannot — a SIGKILL leaves an UNTRACKED file,
 * the one outcome that outlives the run and is committable.
 *
 * ⚠️ THIS PLANTS INTO THE REAL TREE, SO IT IS SAFE ONLY WHILE PEST RUNS SEQUENTIALLY — the same
 * constraint BoundaryLintCoverageTest carries, and for the same reason: the third test asserts the
 * lint is GREEN over the tree while the first two have violations planted in it. Verified at this
 * commit: `bin/quality:280 (arch)` is `pest --group=arch` and `:341` is plain `pest`; `--parallel`
 * appears only on Pint. If you parallelise, give the lint a root argument and point the fixtures at a temp
 * directory first.
 */
function lintWithClockFixture(string $body): array
{
    return lintWithPlantedFile(dirname(__DIR__, 2).'/app/Finance/SqlClockLintFixture'.Str::random(8).'.php', $body);
}

/** Same, for any path — the .sql arm plants under database/, which is a different scan path. */
function lintWithPlantedFile(string $path, string $body): array
{
    $root = dirname(__DIR__, 2);

    file_put_contents($path, $body);

    try {
        $output = [];
        $exit = 0;
        exec('php '.escapeshellarg($root.'/bin/ci-sql-clock-lint.php').' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output), basename($path)];
    } finally {
        @unlink($path);
    }
}

it('reports an UPPERCASE clock read inside a raw SQL string — the defect this lint was written for', function () {
    [$exit, $output, $file] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Support\Facades\DB;

final class SqlClockLintFixture
{
    public function touch(int $id): void
    {
        DB::update(
            'UPDATE finance_student_accounts
                SET updated_at = NOW()
              WHERE id = ?',
            [$id],
        );
    }
}
PHP);

    // Exit 1 AND named. An exit code alone would also be produced by an unrelated violation
    // elsewhere in the tree, so the assertion names the file and the token.
    expect($exit)->toBe(1)
        ->and($output)->toContain($file)
        ->and($output)->toContain('NOW()')
        ->and($output)->toContain('SQL-side clock read');
});

it('reports the LOWERCASE form, and does NOT report PHP\'s now() helper on a line carrying UPDATE', function () {
    // Arm A — the case the first version of this lint could not see. MySQL function names are
    // case-insensitive, so this is the same defect written differently; a case-sensitive token grep
    // passes it. Make findToken()'s patterns case-SENSITIVE (drop the `/i`) and this arm alone
    // goes red.
    [$exitA, $outputA, $fileA] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Support\Facades\DB;

final class SqlClockLintFixture
{
    public function touch(int $id): void
    {
        DB::update('update finance_student_accounts set updated_at = now() where id = ?', [$id]);
    }
}
PHP);

    // Arm B — the FALSE POSITIVE the widening must not buy, and it is the reason the matcher reads
    // string literals rather than lines. Both lines here carry a SQL keyword (UPDATE, WHERE) and
    // PHP's `now()` helper, which is the CORRECT thing to use; a line-based case-insensitive match
    // reports both, and there are 100+ more like them in app/. This arm asserts the tree stays
    // green with them planted.
    [$exitB, $outputB, $fileB] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Support\Facades\DB;

final class SqlClockLintFixture
{
    public function touch(int $id): void
    {
        DB::table('finance_student_accounts')->where('id', $id)->update(['updated_at' => now()]);
    }
}
PHP);

    expect($exitA)->toBe(1)
        ->and($outputA)->toContain($fileA)
        ->and($outputA)->toContain('now()');

    expect($exitB)->toBe(0)
        ->and($outputB)->not->toContain($fileB);
});

it('does not report prose that merely CONTAINS a token, and does not report frame-safe date arithmetic', function () {
    // The false positives a substring search buys, all of them realistic string literals:
    // `update_address` contains DATE_ADD, `update_subject` contains DATE_SUB, and `know()` contains
    // NOW(). The last constant is the taxonomy arm: TIMESTAMPDIFF over a stored column and a BOUND
    // date reads no clock and converts no frame — it is frame-safe, and a lint that refuses it
    // teaches the next developer that the rule is arbitrary.
    [$exit, $output, $file] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

final class SqlClockLintFixture
{
    public const M1 = 'update_address is required';
    public const M2 = 'update_subject is required';
    public const M3 = 'We do not know() the answer';
    public const M4 = 'TIMESTAMPDIFF(DAY, i.due_date, ?) AS days_overdue';
}
PHP);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain($file);
});

it('reports a BARE clock form inside a *Raw fragment, where the SQL keyword is the method name', function () {
    // THE DEFECT THIS LINT EXISTS FOR, SPELLED THE WAY IT PASSED. The bare forms used to require a
    // SQL keyword inside the same string literal — and `whereRaw`/`orderByRaw`/`selectRaw`/`DB::raw`
    // never carry one, because the keyword IS the method name. That is the dominant raw-SQL surface
    // here (57 entry points in app/), and LOCALTIMESTAMP is an exact synonym of NOW(), so a
    // `DB::raw('LOCALTIMESTAMP')` writing the projection column is the ORIGINAL money defect,
    // re-spelled, sailing straight through the gate written to stop it. All four lines below
    // returned exit 0 before the requirement was dropped.
    [$exit, $output, $file] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Support\Facades\DB;

final class SqlClockLintFixture
{
    public function overdue(): void
    {
        DB::table('finance_invoices')
            ->whereRaw('due_date < CURRENT_DATE')
            ->orderByRaw('CURRENT_TIMESTAMP - posted_at')
            ->selectRaw('CURRENT_DATE AS today')
            ->get();
    }

    public function touch(int $id): void
    {
        DB::table('finance_student_accounts')->where('id', $id)->update([
            'updated_at' => DB::raw('LOCALTIMESTAMP'),
        ]);
    }
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain($file.':12')      // whereRaw     — CURRENT_DATE
        ->and($output)->toContain($file.':13')      // orderByRaw   — CURRENT_TIMESTAMP
        ->and($output)->toContain($file.':14')      // selectRaw    — CURRENT_DATE
        ->and($output)->toContain($file.':21')      // DB::raw      — LOCALTIMESTAMP
        ->and($output)->toContain('LOCALTIMESTAMP');
});

it('reports a bare token in NON-SQL prose too — the priced residual of dropping the keyword requirement', function () {
    // THIS ARM ASSERTS A FALSE POSITIVE, DELIBERATELY, so the trade is a recorded decision rather
    // than something a later reader discovers. Dropping the SQL-keyword requirement for the bare
    // forms is what lets the *Raw arm above be caught at all, and its cost is that a literal
    // carrying `current_date` or `current_time` for a non-SQL reason — prose, or an array key like
    // `'current_time' => now()` — is now reported.
    //
    // The cost was measured, not assumed: with the requirement dropped, string literals across
    // app/, database/, routes/ and bin/ produce ZERO bare-form hits, so nothing in the tree pays
    // it today. If a future one does, the escape hatch is EXCEPTIONS, argued once, at the line.
    // A false positive is loud and lands on the author's own new line; the miss it replaces was
    // silent and put `last_activity` five and a half hours in the future on production.
    [$exit, $output, $file] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

final class SqlClockLintFixture
{
    public const M1 = 'The current_date filter';
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain($file.':7')
        ->and($output)->toContain('CURRENT_DATE');
});

it('reports EVERY clock read in one string literal, in position order — not just the first one LISTED', function () {
    // The matcher used to walk BANNED in declaration order and return the first token that matched
    // ANYWHERE in the haystack; the caller then advanced past that match. A violation positioned
    // earlier in the string but later in the map was therefore stepped over and never seen.
    //
    // That is not a cosmetic ordering bug, and this shape is the reason: it is the real exempted
    // backfill, with a UTC_TIMESTAMP read placed BEFORE the exempted line. NOW is listed first in
    // the map and occurs LATER in the string, so the old matcher reported the NOW line — which is
    // exempt by exact text — skipped it, and printed "no SQL-side clock reads" with a live clock
    // read sitting two lines above. A whole-gate false green. Line 13 is the assertion that goes
    // red on the old matcher; line 14 is the control that passed even then.
    [$exit, $output, $file] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Support\Facades\DB;

final class SqlClockLintFixture
{
    public function backfill(): void
    {
        DB::statement(
            'INSERT INTO finance_probe (a, b)
             SELECT * FROM (SELECT UTC_TIMESTAMP) x WHERE 1=0 UNION ALL
             SELECT NOW(), NOW() FROM finance_ledger_transactions'
        );
    }
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain($file.':13')
        ->and($output)->toContain('UTC_TIMESTAMP')
        ->and($output)->toContain($file.':14');
});

it('reports ->useCurrent() and ->useCurrentOnUpdate(), which put no banned token in the source at all', function () {
    // The second pass, and it reads CODE rather than string literals because there is nothing to
    // read in a string here: `->useCurrent()` compiles to DEFAULT CURRENT_TIMESTAMP and
    // `->useCurrentOnUpdate()` to ON UPDATE CURRENT_TIMESTAMP. Both hand the column to the server's
    // clock — the exact defect — with the token appearing nowhere in PHP. The string-literal
    // matcher is blind to this by construction, so a green from it alone never meant what it read
    // like it meant.
    [$exit, $output, $file] = lintWithClockFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SqlClockLintFixture
{
    public function up(): void
    {
        Schema::create('finance_probe', function (Blueprint $table) {
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamp('changed_at')->useCurrentOnUpdate();
        });
    }
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain($file.':13')
        ->and($output)->toContain('useCurrent')
        ->and($output)->toContain($file.':14')
        ->and($output)->toContain('useCurrentOnUpdate')
        ->and($output)->toContain('ddl-default');
});

it('reports a clock read in a .sql file under database/, where there is no PHP to confine the search to', function () {
    // A .sql seed or backfill is the natural home for a server-clock write and used to pass
    // straight through: the scanner skipped every non-PHP file outside bin/. The whole file is SQL,
    // so the string-literal confinement does not apply — and must not, or nothing in it is visible.
    $root = dirname(__DIR__, 2);
    @mkdir($root.'/database/sql');

    [$exit, $output, $file] = lintWithPlantedFile(
        $root.'/database/sql/SqlClockLintFixture'.Str::random(8).'.sql',
        <<<'SQL'
-- This SQL comment mentions NOW() and must NOT be reported.
UPDATE finance_student_accounts SET updated_at = NOW() WHERE id = 1;
SQL
    );

    @rmdir($root.'/database/sql');

    expect($exit)->toBe(1)
        ->and($output)->toContain($file)
        ->and($output)->toContain('clock-read')
        // The line number is the statement, not the comment above it — proof the SQL comment
        // was skipped rather than merely out-counted.
        ->and($output)->toContain($file.':2');
});

it('is clean on the tree as it stands, and the one named exception is still the line it was argued for', function () {
    $root = dirname(__DIR__, 2);
    $output = [];
    $exit = 0;
    exec('php '.escapeshellarg($root.'/bin/ci-sql-clock-lint.php').' 2>&1', $output, $exit);

    expect($exit)->toBe(0)
        ->and(implode("\n", $output))->toContain('no SQL-side clock reads');

    // …and the negative arm is not vacuous. The tree really does contain a clock read — the
    // one-time backfill in an already-applied migration — and the lint is green only because that
    // ONE LINE is exempted by exact text. If the migration is reworded the exception stops matching
    // and the lint fails, which is the safe direction; this asserts the line is still there, so a
    // green above cannot mean "the exempted code was quietly deleted".
    expect(file_get_contents($root.'/database/migrations/2026_07_22_190000_create_finance_student_accounts.php'))
        ->toContain('SELECT UUID(), school_id, student_id, SUM(amount_minor), MAX(amount_currency), NOW(), NOW()');

    // Same non-vacuity check for the three ->useCurrent() exemptions. Each is an APPLIED migration
    // whose column is written explicitly by PHP on every live path, so the DDL default never fires;
    // they are exempt as history, not as an argument that the pattern is acceptable. If one is
    // reworded the exemption stops matching and the lint fails — the safe direction — and this
    // asserts the lines are still there, so the green above cannot mean the exemptions went stale.
    $exempted = [
        'database/migrations/0001_01_01_000002_create_jobs_table.php' => "\$table->timestamp('failed_at')->useCurrent();",
        'database/migrations/2026_04_26_122249_create_audit_logs_table.php' => "\$table->timestampTz('created_at')->useCurrent();",
        'database/migrations/2026_07_17_100000_create_authz_observations_table.php' => "\$table->timestamp('occurred_at')->useCurrent();",
    ];

    foreach ($exempted as $path => $line) {
        expect(file_get_contents($root.'/'.$path))->toContain($line);
    }
});
