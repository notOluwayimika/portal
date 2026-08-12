#!/usr/bin/env php
<?php

/**
 * sql-clock-lint — MySQL's clock is a DIFFERENT FRAME from the application's, so no raw SQL
 * may read it. Companion to the sibling lints (ci-money-lint.php, ci-boundary-lint.php).
 *
 * WHY THIS EXISTS, and it is a measurement rather than a preference. `config/database.php`
 * pins no connection timezone, so every connection inherits the database server's zone; on the
 * shared production host that zone is `+05:30` and is not ours to set. Laravel writes a UTC
 * wall-clock string, MySQL parses it in the SESSION zone, and the two write paths then fail in
 * OPPOSITE directions:
 *
 *   a PHP-written column   stores early by the session offset, and reads back EXACT;
 *   a NOW()-written column stores the true instant, and reads back AHEAD by that offset.
 *
 * So a SQL-side clock read silently mixes two frames into one table. It shipped once, in the
 * single writer of the money projection: `finance_student_accounts.updated_at` was written by
 * MySQL's `NOW()` while the ledger row it projects was written by PHP's `now()`, and updated_at
 * is surfaced to staff as `last_activity` on the accounts index — five and a half hours in the
 * future on production. Full measurement, and the refusal of the connection-pinning "fix" (it
 * re-renders every timestamp already written, all of them behind append-only triggers):
 * docs/handoff/tickets/stored-epoch-offset.md.
 *
 * THE RULE. These columns are read through Laravel and NEVER compared to — or written from —
 * MySQL's clock inside raw SQL. Use the application's clock and BIND it.
 *
 * MATCHING IS CASE-INSENSITIVE AND SCOPED TO STRING LITERALS. MySQL function names are
 * case-insensitive, so `set updated_at = now()` in a raw SQL string is valid MySQL; a
 * case-sensitive token grep (this lint's first version) could not see it, which is a gate claiming
 * more than it delivers. The naive widening is worse: matched over whole LINES, `now()` collides
 * with PHP's own `now()` helper — the CORRECT thing to use, 100+ times in app/ alone. Requiring a
 * SQL keyword on the line does not rescue that (`->update(['updated_at' => now()])` carries
 * UPDATE): measured, 24 false positives against 1 true one. The distinction that actually holds is
 * that SQL is a STRING and the helper is CODE, so PHP files are tokenised and only string literals
 * are searched. Comments are not string literals, so a docblock explaining this rule — as
 * SubledgerPoster's does — cannot trip it.
 *
 * AND THE MATCH IS ON A TOKEN BOUNDARY, which is not a detail either: without it, `NOW` matches
 * inside "Unknown" and "you are now acting as", and `DATE_ADD` inside `update_address`. Measured on
 * this tree at the commit that added the boundary: 25 findings without it, 0 with it. See
 * findToken() for the shapes, and for the ONE false positive the boundary alone does not remove.
 *
 * FILES SCANNED: PHP everywhere in scope, `.sql` everywhere in scope, and every file in bin/. A
 * `.sql` file is scanned WHOLE rather than by string literal, because it has no PHP around it —
 * see scanText(), where that difference is deliberate.
 *
 * A SECOND PASS READS CODE, NOT STRINGS: `$table->timestamp('x')->useCurrent()` compiles to
 * `DEFAULT CURRENT_TIMESTAMP` and `->useCurrentOnUpdate()` to `ON UPDATE CURRENT_TIMESTAMP`. Both
 * hand the column to the SERVER's clock and neither puts a banned token in PHP source, so the
 * string-literal matcher is structurally blind to them. scanUseCurrent() is that pass — see its
 * docblock for why it has to be a separate one, and TESTS/ below for why the two passes cannot be
 * merged into a single case-insensitive sweep.
 *
 * TESTS/ IS OUT OF SCOPE, and that is a measurement rather than an oversight. The discriminator
 * this whole lint rests on — SQL is a STRING, the helper is CODE — is exactly the thing tests break:
 * an assertion message quotes `now()` as prose, an arch test puts PHP source inside a string to
 * scan it, and this lint's own coverage test plants the violations it asserts on. Measured over
 * tests/ at the commit that added this lint: 16 hits, and NOT ONE of them the defect — 10 in this
 * lint's coverage fixtures, 3 prose or PHP-in-a-string in tests/Feature/Support/SchoolDayTest.php,
 * 1 the deliberate `SELECT NOW()` probe in SubledgerClockFrameTest that exists TO PROVE the two
 * frames differ, and 2 the raw fixture insert at CurrencyShapeConstraintTest:54, which reads no
 * timestamp back. Scanning tests/ would therefore buy a day-one baseline of permanent exemptions,
 * which is the one thing this rule got to avoid by being added at zero.
 *
 * WHAT IT CANNOT SEE, stated so a green is not over-read: it matches TOKENS. A raw comparison that
 * comes at the same defect without naming a clock function — a stored column compared against a
 * value the database computed some other way — is invisible to it. That half was surveyed by hand
 * at the commit that added this lint (57 raw-SQL entry points in app/; no cross-frame comparison
 * found) and stays a reading exercise.
 *
 * Usage:
 *   php bin/ci-sql-clock-lint.php     # exit 1 on any occurrence outside the named exceptions
 */
$root = dirname(__DIR__);

// The scanned surface: every place raw SQL is written by hand. tests/ is deliberately OUT —
// a test may legitimately read MySQL's clock to PROVE the frames differ, which is exactly what
// tests/Feature/Finance/SubledgerClockFrameTest.php does.
const SCANNED_DIRS = ['app', 'database', 'routes', 'bin'];

/**
 * TWO KINDS OF BANNED FUNCTION, and they get DIFFERENT messages because they are wrong for
 * different reasons — a developer who reads "clock" while looking at a line with no clock in it
 * concludes the gate is arbitrary.
 *
 *   clock-read       reads the SERVER's clock. NOW(), CURDATE(), CURRENT_TIMESTAMP and friends
 *                    produce a value in the session zone; every timestamp the application writes
 *                    is in app.timezone. That is the defect this lint was written for.
 *   frame-conversion UNIX_TIMESTAMP(col) INTERPRETS a stored value in the SESSION zone, and
 *                    FROM_UNIXTIME renders back into it, so the epoch they produce or consume
 *                    carries the offset. No clock is read; the frame still moves.
 *
 * WHAT WAS DELIBERATELY REMOVED, because the first version of this lint banned it and was wrong to.
 * DATE_SUB, DATE_ADD, DATEDIFF, TIMESTAMPDIFF, ADDDATE and SUBDATE compute from their ARGUMENTS.
 * They were listed as "the vehicle" — DATE_SUB(NOW(), …) — but the driver is caught in the same
 * expression by the clock-read group, so listing the vehicle adds nothing and costs something real:
 * an ageing query written as `TIMESTAMPDIFF(DAY, i.due_date, ?)` against a PHP-written column with
 * a bound PHP date is entirely frame-safe, and refusing it teaches the next developer that the rule
 * is arbitrary. A rule that refuses safe code is how a gate gets routed around.
 *
 * @var array<string, array{0: string, 1: bool}> token => [rule, legal without parentheses]
 */
const BANNED = [
    // Clock reads. NOW/CURDATE/CURTIME/SYSDATE/UTC_* require parentheses; the CURRENT_* and
    // LOCAL* forms are legal bare (`DEFAULT CURRENT_TIMESTAMP`, `WHERE d < CURRENT_DATE`).
    'NOW' => ['clock-read', false],
    'SYSDATE' => ['clock-read', false],
    'CURDATE' => ['clock-read', false],
    'CURTIME' => ['clock-read', false],
    'UTC_TIMESTAMP' => ['clock-read', true],
    'UTC_DATE' => ['clock-read', true],
    'UTC_TIME' => ['clock-read', true],
    'CURRENT_TIMESTAMP' => ['clock-read', true],
    'CURRENT_DATE' => ['clock-read', true],
    'CURRENT_TIME' => ['clock-read', true],
    'LOCALTIME' => ['clock-read', true],
    'LOCALTIMESTAMP' => ['clock-read', true],

    // Frame conversions.
    'UNIX_TIMESTAMP' => ['frame-conversion', false],
    'FROM_UNIXTIME' => ['frame-conversion', false],
];

/**
 * THE THIRD RULE CLASS, and it lives outside BANNED because it is matched in CODE rather than in a
 * string literal. `->useCurrent()` and `->useCurrentOnUpdate()` are Blueprint methods, so they are
 * PHP tokens, not SQL text — the string-literal matcher cannot reach them by construction.
 *
 * @var array<string, string> method => rule
 */
const DDL_DEFAULTS = [
    'useCurrent' => 'ddl-default',
    'useCurrentOnUpdate' => 'ddl-default',
];

/** Why each rule is a defect — printed per finding, so the message names the actual mechanism. */
const RULE_REASONS = [
    'clock-read' => "reads the DATABASE SERVER's clock (session zone), not the application's (app.timezone)",
    'frame-conversion' => 'converts a stored value THROUGH the session zone, so the epoch it produces carries the offset',
    'ddl-default' => "compiles to a CURRENT_TIMESTAMP column default, so the SERVER's clock writes the column whenever the application does not",
];

/** The rule class a reported token belongs to, across both passes. */
function ruleFor(string $token): string
{
    return BANNED[$token][0] ?? DDL_DEFAULTS[$token];
}

/**
 * NAMED EXCEPTIONS — path plus the exact trimmed line, each with its reason. Not a per-file
 * allow-list and not a baseline file: an exception is keyed to the ONE line it was argued for,
 * so a second clock read in the same file still fails, and so does this one if it is reworded.
 * (The four exceptions in ci-boundary-lint.php are the precedent for arguing them in place.)
 */
const EXCEPTIONS = [
    // ONE-TIME BACKFILL IN AN ALREADY-APPLIED MIGRATION. This statement seeded
    // finance_student_accounts from the ledger at migrate time, stamping created_at/updated_at
    // from MySQL's clock. It is exempt for two reasons, and neither of them is "it is old":
    //
    //   1. A MIGRATION IS A DATED ACT (ADR 0052). This one has run on every environment, so
    //      editing it changes nothing that exists — the repo rolls forward with a new migration,
    //      it does not rewrite applied ones.
    //   2. THE ROWS IT WROTE DO NOT EXIST. finance_student_accounts holds ZERO rows on the dev
    //      copy and production is pre-cutover, so there is no population carrying the stamp.
    //
    // BE PRECISE ABOUT WHAT LATER WRITES REPAIR, because an earlier version of this comment was
    // not: SubledgerPoster's ON DUPLICATE KEY branch sets balance_minor and updated_at only. A
    // backfilled row's updated_at IS re-stamped in the application's frame by the first post()
    // that touches the account; its created_at is NOT, and keeps the MySQL frame permanently.
    // Nothing reads that column — FinanceAccountController:67 surfaces updated_at alone — so the
    // effect is nil, but reason 2 is what carries this exemption, not the re-stamp.
    //
    // A NEW migration doing this would fail this lint, which is the point.
    'database/migrations/2026_07_22_190000_create_finance_student_accounts.php' => [
        'SELECT UUID(), school_id, student_id, SUM(amount_minor), MAX(amount_currency), NOW(), NOW()',
    ],

    // THE THREE PRE-EXISTING ->useCurrent() COLUMNS. All three predate this rule, all three are in
    // APPLIED migrations (a dated act, ADR 0052 — editing them changes nothing that exists), none is
    // on a finance_ table, and in each case the column is written EXPLICITLY BY PHP on every live
    // path, so the DDL default never fires. Each writer was re-derived, not assumed:
    //
    //   jobs.failed_at         vendor/…/Queue/Failed/DatabaseFailedJobProvider.php:57 — `Date::now()`
    //   audit_logs.created_at  App\Models\AuditLog is an Eloquent model with timestamps on
    //                          (UPDATED_AT = null only), so Eloquent supplies created_at on create
    //   authz_observations.occurred_at  app/Support/Authz.php:78 — `'occurred_at' => now()`
    //
    // So the tree is at zero in BEHAVIOUR as well as in tokens, and these three are exempt as
    // history rather than as an argument that the pattern is fine. A NEW column written this way
    // fails, which is the point.
    'database/migrations/0001_01_01_000002_create_jobs_table.php' => [
        "\$table->timestamp('failed_at')->useCurrent();",
    ],
    'database/migrations/2026_04_26_122249_create_audit_logs_table.php' => [
        "\$table->timestampTz('created_at')->useCurrent();",
    ],
    'database/migrations/2026_07_17_100000_create_authz_observations_table.php' => [
        "\$table->timestamp('occurred_at')->useCurrent();",
    ],
];

// This file names every token it bans, so it would report itself.
const SELF = 'bin/ci-sql-clock-lint.php';

/**
 * SQL keywords, for the non-PHP, non-`.sql` files only — the shell scripts in bin/, scanned by LINE
 * (see scanText). A shell script has no `now()` helper to be confused with, but it does have prose;
 * requiring a SQL keyword on the line keeps it honest. THIS IS NOT USED FOR PHP OR `.sql`: inside a
 * string literal the same requirement was measured to hide the defect rather than sharpen it — see
 * findToken().
 */
const SQL_KEYWORDS = ['INSERT', 'UPDATE', 'SELECT', 'SET', 'VALUES', 'WHERE', 'DEFAULT', 'HAVING'];

function isComment(string $line): bool
{
    $t = ltrim($line);

    return str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')
        || str_starts_with($t, '#');
}

/**
 * Case-insensitive, TOKEN-BOUNDARY search for a banned function, returning the EARLIEST-OCCURRING
 * match as [token, offset], or null.
 *
 * THE BOUNDARY IS NOT DECORATION. A bare substring search reports `'We do not know() the answer'`
 * for NOW() and `'update_address is required'` for the DATE_ADD this lint used to ban — real
 * shapes for a validation message or a permission name, and the only escape hatch would be adding
 * that string to EXCEPTIONS, which permanently records unrelated prose as a clock-read exemption.
 * So: `\bTOKEN\s*\(` for the function forms, `\bTOKEN\b` for the forms MySQL accepts bare.
 *
 * EARLIEST-OCCURRING, NOT FIRST-LISTED, and that word is the whole of the second fix here. This
 * used to return on the first token in DECLARATION ORDER that matched anywhere in the haystack, and
 * scanPhp() then advanced the cursor PAST it — so a violation positioned earlier in the string but
 * later in the map was stepped over and never seen. That is not a cosmetic ordering bug: it made
 * the stated exception guarantee false. Against the real exempted migration, a UTC_TIMESTAMP read
 * placed BEFORE the exempted `NOW(), NOW()` line produced `exit=0` and "no SQL-side clock reads",
 * because NOW is listed first, matched later in the string, and the advance jumped the cursor past
 * the earlier read. Moving the same UTC_TIMESTAMP after the exempted line reported it correctly.
 * A whole-gate false green, so the map is now scanned in full and the lowest offset wins.
 *
 * THE BARE FORMS CARRY NO EXTRA CONDITION, and the condition they used to carry was the third fix.
 * CURRENT_TIMESTAMP, CURRENT_DATE, LOCALTIME and the UTC_* family are legal MySQL without
 * parentheses (`DEFAULT CURRENT_TIMESTAMP`, `WHERE d < CURRENT_DATE`), so they cannot require a
 * `(`. They used to require a SQL KEYWORD in the same literal instead, to keep `'the current_date
 * filter'` out — and that requirement made the gate MISS THE DEFECT IT WAS WRITTEN FOR on the
 * dominant raw-SQL surface, because in `whereRaw`/`selectRaw`/`orderByRaw`/`DB::raw` (57 entry
 * points in app/) THE KEYWORD IS THE METHOD NAME and never appears inside the string:
 *
 *     DB::raw('LOCALTIMESTAMP')                 — an exact synonym of NOW(), writing the projection
 *     ->whereRaw('due_date < CURRENT_DATE')       column, and every one of these passed the gate
 *     ->orderByRaw('CURRENT_TIMESTAMP - posted_at')
 *
 * The 24-to-1 false-positive measurement that once justified a keyword requirement was taken over
 * LINES, where `->update([...])` and `->where(...)` supply UPDATE and WHERE for free. Re-measured
 * where it actually applies — over string literals only, with the requirement dropped, across
 * app/, database/, routes/ and bin/ — it is **0 hits**, on every file in scope. So the requirement
 * bought nothing real and hid the dominant case.
 *
 * THE RESIDUAL, stated rather than discovered later: a string literal that is EXACTLY or CONTAINS a
 * bare token for a non-SQL reason is now reported — `'The current_date filter'`, or an array key
 * `'current_time' => now()`. Nothing in the scanned tree has that shape today (that is the 0
 * above), and the arm in the coverage test pins the behaviour so it is a decision rather than a
 * surprise. It is the safe direction: a false positive is loud, lands on the author's own new line,
 * and is argued in EXCEPTIONS once; the miss it replaces was silent and shipped a timestamp five
 * and a half hours wrong.
 *
 * @return array{0: string, 1: int}|null
 */
function findToken(string $haystack): ?array
{
    $earliest = null;

    foreach (BANNED as $token => [$rule, $bareIsLegal]) {
        $pattern = $bareIsLegal
            ? '/\b'.preg_quote($token, '/').'\b/i'
            : '/\b'.preg_quote($token, '/').'\s*\(/i';

        if (preg_match($pattern, $haystack, $m, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        if ($earliest === null || $m[0][1] < $earliest[1]) {
            $earliest = [$token, $m[0][1]];
        }
    }

    return $earliest;
}

/**
 * The CODE pass: `->useCurrent()` / `->useCurrentOnUpdate()`, returning [[lineNumber, method], ...].
 *
 * WHY THIS CANNOT BE PART OF THE STRING PASS. `$table->timestamp('x')->useCurrent()` emits
 * `DEFAULT CURRENT_TIMESTAMP` and `->useCurrentOnUpdate()` emits `ON UPDATE CURRENT_TIMESTAMP`;
 * both hand the column to the server's clock, and both do it with the banned token nowhere in the
 * source. The string matcher is precise BECAUSE it only reads string literals, so it is blind to
 * this by construction — the answer is a second, narrower pass rather than a looser first one.
 *
 * WHY IT MATCHES ON `->` AND NOT ON THE NAME. Reading the method call rather than the word keeps
 * the docblock that explains this rule — including the one above — from reporting itself, for the
 * same reason the string pass is immune to comments: the tokeniser hands comments back as comment
 * tokens, and a T_OBJECT_OPERATOR only ever appears in code.
 *
 * @return array<int, array{0: int, 1: string}>
 */
function scanUseCurrent(string $source): array
{
    $hits = [];
    $tokens = token_get_all($source);

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        // Skip whitespace/comments between `->` and the method name; `->  useCurrent()` is legal PHP.
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($next) && $next[0] === T_STRING) {
                // PHP method names are case-INSENSITIVE at the call site, so `->UseCurrent()` runs.
                // Match that way and report the canonical spelling, so ruleFor() still resolves.
                foreach (array_keys(DDL_DEFAULTS) as $method) {
                    if (strcasecmp($next[1], $method) === 0) {
                        $hits[] = [$next[2], $method];
                        break;
                    }
                }
            }
            break;
        }
    }

    return $hits;
}

/**
 * A PHP file, scanned INSIDE ITS STRING LITERALS ONLY — which is the discriminator this rule
 * actually needs, and the reason the first version of this lint was case-sensitive instead.
 *
 * MySQL function names are case-INSENSITIVE, so `set updated_at = now()` inside a raw SQL string is
 * valid MySQL and a case-sensitive token grep cannot see it. But matching `now()` case-insensitively
 * over whole LINES collides head-on with PHP's own `now()` helper — the correct thing to use — and
 * over 100 legitimate calls in app/ alone. Requiring a SQL keyword on the same line does not rescue
 * it either: `->update(['updated_at' => now()])` and `->where('created_at', '>=', now())` carry
 * UPDATE and WHERE, and that heuristic was measured at 24 false positives against 1 true one.
 *
 * The real distinction is not the case and not the keywords: it is that SQL is a STRING and the
 * helper is CODE. So this tokenises and looks only at string literals (single- and double-quoted,
 * heredoc/nowdoc bodies, and interpolated segments). PHP's `now()` is never inside one; a raw SQL
 * clock read always is. Comments fall out for free — the tokeniser hands them back as comment
 * tokens, which are not scanned — so a docblock explaining this rule cannot trip it.
 *
 * @return array<int, array{0: int, 1: string}> [[lineNumber, token], ...]
 */
function scanPhp(string $source): array
{
    $hits = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }
        [$id, $text, $line] = $token;
        if (! in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        // A string may span many lines (every raw SQL statement here does), and the tokeniser
        // reports only where it STARTED — so the offset of the match is converted back to a line.
        $offset = 0;
        while (($found = findToken(substr($text, $offset))) !== null) {
            [$name, $at] = $found;
            $absolute = $offset + $at;
            $hits[] = [$line + substr_count(substr($text, 0, $absolute), "\n"), $name];
            $offset = $absolute + strlen($name);
        }
    }

    return $hits;
}

/**
 * A non-PHP file, scanned by LINE because there is no tokeniser to confine the search with. Two
 * shapes, and the difference between them is deliberate:
 *
 *   .sql            the whole file IS SQL. String-literal confinement is meaningless here and must
 *                   not be imposed — a backfill script is not PHP with SQL inside it, it is SQL.
 *                   So every line is treated as SQL text and the bare forms need no keyword.
 *   everything else the shell scripts in bin/. No `now()` helper to be confused with, but plenty of
 *                   prose, so a line must carry a SQL keyword to be reported at all. Weaker, and it
 *                   is the weaker half of the surface: bin/ holds gate scripts, not application SQL.
 *
 * @param  array<int, string>  $lines
 * @return array<int, array{0: int, 1: string}>
 */
function scanText(array $lines, bool $isSqlFile): array
{
    $hits = [];

    foreach ($lines as $i => $line) {
        if (isComment($line) || str_starts_with(ltrim($line), '--')) {   // `--` is SQL's comment
            continue;
        }

        $found = findToken($line);
        if ($found === null) {
            continue;
        }

        if ($isSqlFile) {
            $hits[] = [$i + 1, $found[0]];

            continue;
        }

        foreach (SQL_KEYWORDS as $keyword) {
            if (preg_match('/\b'.$keyword.'\b/i', $line) === 1) {
                $hits[] = [$i + 1, $found[0]];
                break;
            }
        }
    }

    return $hits;
}

/** @return array<int, string> absolute paths */
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
            // bin/ holds extensionless shell scripts (bin/quality et al.) that could embed SQL, so
            // it is scanned whole. Elsewhere, PHP and .sql — a .sql seed or backfill under
            // database/ is the natural home for a server-clock write and used to pass straight
            // through. There are ZERO .sql files in the scanned dirs today (the repo's only one is
            // docs/runbooks/, out of scope), so this is a rule added ahead of its first file, on
            // purpose: it costs nothing now and the alternative is discovering it from a defect.
            if ($dir !== 'bin' && ! in_array($file->getExtension(), ['php', 'sql'], true)) {
                continue;
            }
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

$found = [];

foreach (scannedFiles($root) as $path) {
    $rel = ltrim(str_replace($root, '', $path), '/');
    if ($rel === SELF) {
        continue;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    if (str_ends_with($rel, '.php')) {
        $source = implode("\n", $lines);
        // Two passes over the same file, because they read different things: one searches string
        // literals for SQL text, the other searches CODE for the Blueprint methods that emit a
        // server-clock column default. Merged and re-sorted so findings read down the file.
        $hits = array_merge(scanPhp($source), scanUseCurrent($source));
        usort($hits, fn (array $a, array $b): int => $a[0] <=> $b[0]);
    } else {
        $hits = scanText($lines, str_ends_with($rel, '.sql'));
    }

    foreach ($hits as [$line, $token]) {
        $text = trim($lines[$line - 1] ?? '');

        // Exceptions are keyed to the exact line, not the file — a second clock read in the same
        // file still fails, and a reworded one fails until it is re-argued.
        if (in_array($text, EXCEPTIONS[$rel] ?? [], true)) {
            continue;
        }

        $found[] = [$rel, $line, $token, $text];
    }
}

if ($found !== []) {
    fwrite(STDERR, "\nsql-clock-lint: ".count($found)." SQL-side clock read(s) / frame conversion(s) / server-clock column default(s) — MySQL's clock and frame are not the application's:\n");
    foreach ($found as [$rel, $line, $token, $text]) {
        $rule = ruleFor($token);
        fwrite(STDERR, '  '."\u{2717}".' '.$rel.':'.$line.'  ['.$token.' — '.$rule.']  '.$text."\n");
        fwrite(STDERR, '      '.$token.' '.RULE_REASONS[$rule]."\n");
    }
    fwrite(STDERR, "\n  The database server's session zone is not app.timezone (production: +05:30), so a value\n");
    fwrite(STDERR, "  MySQL's clock produces is in a DIFFERENT FRAME from every timestamp the application writes.\n");
    fwrite(STDERR, "  Stored one way it reads back exact; stored the other it reads back offset — mixing them in\n");
    fwrite(STDERR, "  one table or one comparison is silently wrong. Capture the instant in PHP and BIND it.\n");
    fwrite(STDERR, "  For a ddl-default finding the fix is the same one: drop ->useCurrent()/->useCurrentOnUpdate()\n");
    fwrite(STDERR, "  and have the application write the column, so one clock owns it in every environment.\n");
    fwrite(STDERR, "  Why the connection cannot simply be pinned: docs/handoff/tickets/stored-epoch-offset.md\n");
    exit(1);
}

$exceptionCount = array_sum(array_map('count', EXCEPTIONS));
fwrite(STDERR, 'sql-clock-lint: OK — no SQL-side clock reads ('.$exceptionCount." named exception(s)).\n");
exit(0);
