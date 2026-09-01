<?php

/*
 * ONE DEFINITION OF RELEASED-TO-PAYERS, AND THE RULE THAT KEEPS IT ONE.
 *
 * The release rule used to have THREE implementations — `Invoice::isReviewed()`,
 * `Invoice::scopeReviewed()` and an inline `whereNull('reviewed_at')` in
 * `InvoiceReadModel::guardianAccountPositionForStudent()` — agreeing only because all three
 * happened to mean not-null. Three copies were an ACCIDENTAL CROSS-CHECK: a reader updating one had
 * a chance of noticing the others. Collapsing them onto `Invoice::RELEASE_STAMP_COLUMN` removes
 * that chance, so the redundancy has to be replaced by prevention rather than simply deleted. That
 * is this file: the column name may appear in app/ CODE at exactly one place, so a FOURTH spelling
 * cannot be written without a red.
 *
 * ── WHY TOKENS AND NOT A GREP ───────────────────────────────────────────────────────────────────
 *
 * A grep cannot tell `'reviewed_at'` in a query from `reviewed_at` in a sentence, and this
 * repository documents the collapse in prose at four sites — including the `@property` docblock
 * Larastan needs in order to type the attribute at all. A comment-blind matcher would either red on
 * its own explanation or, worse, be "fixed" by deleting the explanation. So the instrument is
 * `token_get_all()`: comments are a bucket with a stated reason, not an invisible skip.
 *
 * ── THREE NUMBERS, NOT TWO (CLAUDE.md § gates) ──────────────────────────────────────────────────
 *
 * EXAMINED — every token of every .php under app/.
 * EXCLUDED WITH A REASON — T_COMMENT / T_DOC_COMMENT. Prose cannot read a column.
 * UNRECOGNISED — a token carrying the column name in a kind this scanner cannot classify. Asserted
 * ZERO, so a spelling in a token type nobody has thought of REDS instead of vanishing into a
 * skipped count. Arm 6 proves that bucket is wired rather than decorative.
 *
 * SCOPE IS app/ AND ONLY app/, deliberately. `database/migrations/` names the column because it is
 * the DDL that CREATES it — a definition, not a second reader of the rule — and tests/ names it to
 * plant rows and to assert the rollback drops it, which is what a test is for. Widening this to
 * either would red on the two files that are allowed to know the physical name.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/**
 * Token kinds that can carry the column name AS CODE.
 *
 * RECOGNISE BROADLY, JUDGE NARROWLY. This list is not "the ways someone would sensibly read the
 * column"; it is every kind whose text could contain the identifier at all — a bare fetch
 * (`->reviewed_at`), a quoted argument, a variable, an interpolated or heredoc SQL fragment, a
 * qualified name, and raw output outside `<?php`. Anything here is a code occurrence and is judged
 * against the single permitted site; anything NOT here and not a comment is unrecognised and reds.
 */
function releaseStampCodeKinds(): array
{
    return [
        T_CONSTANT_ENCAPSED_STRING,   // 'reviewed_at'
        T_ENCAPSED_AND_WHITESPACE,    // "... reviewed_at ..." / heredoc body
        T_STRING,                     // ->reviewed_at, RELEASE_STAMP_COLUMN, a function name
        T_STRING_VARNAME,             // "{$row->reviewed_at}" varname half
        T_VARIABLE,                   // $reviewed_at
        T_NAME_QUALIFIED,             // Foo\reviewed_at
        T_NAME_FULLY_QUALIFIED,       // \Foo\reviewed_at
        T_INLINE_HTML,                // text outside <?php
    ];
}

/**
 * Scan $files and bucket every token whose text carries `reviewed_at` as a whole word.
 *
 * $codeKinds is injectable ONLY so arm 6 can narrow it and prove the unrecognised bucket fires;
 * production callers pass null and get releaseStampCodeKinds().
 *
 * @param  list<string>  $files  absolute paths
 * @return array{examined: int, code: list<array{file: string, line: int, text: string}>, comment: int, unrecognised: list<array{file: string, line: int, kind: string}>}
 */
function releaseStampScan(array $files, ?array $codeKinds = null): array
{
    $codeKinds = $codeKinds ?? releaseStampCodeKinds();
    $root = dirname(__DIR__, 2).'/';

    $out = ['examined' => 0, 'code' => [], 'comment' => 0, 'unrecognised' => []];

    foreach ($files as $file) {
        $relative = str_replace($root, '', $file);

        foreach (token_get_all(file_get_contents($file)) as $token) {
            $out['examined']++;

            // A single-character token ('(', ';', '=') is returned as a bare string and is one
            // character long, so it cannot carry an identifier. Normalised rather than skipped, so
            // that if one ever did it would still be classified below.
            [$id, $text, $line] = is_array($token) ? $token : [null, $token, 0];

            if (! preg_match('/\breviewed_at\b/', $text)) {
                continue;
            }

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $out['comment']++;

                continue;
            }

            if (in_array($id, $codeKinds, true)) {
                $out['code'][] = ['file' => $relative, 'line' => $line, 'text' => trim($text)];

                continue;
            }

            $out['unrecognised'][] = [
                'file' => $relative,
                'line' => $line,
                'kind' => $id === null ? 'literal-character' : token_name($id),
            ];
        }
    }

    return $out;
}

/** Every .php under app/. */
function releaseStampAppFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));

    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** Write $body to a throwaway .php file, scan it, delete it. */
function releaseStampScanSource(string $body, ?array $codeKinds = null): array
{
    $path = sys_get_temp_dir().'/release_stamp_'.Str::random(12).'.php';
    file_put_contents($path, $body);

    try {
        return releaseStampScan([$path], $codeKinds);
    } finally {
        @unlink($path);
    }
}

it('reads reviewed_at in exactly one place in app/, and that place is the constant', function () {
    $files = releaseStampAppFiles();

    expect($files)->not->toBeEmpty();

    $scan = releaseStampScan($files);

    // ── THE THIRD NUMBER. A construct this scanner cannot classify must not be silently absorbed
    // into a skipped count — that is how a clean run gets taken over a matcher that was reading
    // half the population. Asserted FIRST because it invalidates everything below it.
    expect($scan['unrecognised'])->toBe([]);

    expect($scan['code'])->toHaveCount(1);

    $only = $scan['code'][0];

    expect($only['file'])->toBe('app/Finance/Models/Invoice.php');

    // Not merely "one occurrence in that file" — the occurrence must BE the constant declaration.
    // Without this the arm would stay green if the const were deleted and a single inline
    // `whereNotNull('reviewed_at')` put back in its place, which is the exact state it exists to
    // forbid.
    $line = file(dirname(__DIR__, 2).'/app/Finance/Models/Invoice.php')[$only['line'] - 1];

    expect(trim($line))->toBe("public const RELEASE_STAMP_COLUMN = 'reviewed_at';");
});

it('does not count the column name in a comment or a docblock, which is where it is explained', function () {
    // THE ARM THAT STOPS THIS FILE PASSING BY REFUSING EVERYTHING — and it is not hypothetical:
    // app/ carries four prose mentions today, one of them the @property Larastan types from.
    $scan = releaseStampScanSource(<<<'PHP'
    <?php

    /** @property Carbon|null $reviewed_at released to parents; NULL = withheld */
    class Explains
    {
        // reviewed_at is the stamp; read it through Invoice::RELEASE_STAMP_COLUMN.
        public function noop(): void {}
    }
    PHP);

    expect($scan['code'])->toBe([])
        ->and($scan['comment'])->toBe(2)
        ->and($scan['unrecognised'])->toBe([]);
});

it('catches a fourth spelling written as a quoted column name', function () {
    $scan = releaseStampScanSource(<<<'PHP'
    <?php

    class FourthSpelling
    {
        public function withheld($query)
        {
            return $query->whereNull('reviewed_at');
        }
    }
    PHP);

    expect($scan['code'])->toHaveCount(1)
        ->and($scan['code'][0]['text'])->toBe("'reviewed_at'")
        ->and($scan['unrecognised'])->toBe([]);
});

it('catches a fourth spelling written as a bare property fetch, which a quote-only matcher misses', function () {
    // This is the spelling the collapsed `isReviewed()` used, and it carries no quotes at all. A
    // matcher built around `'reviewed_at'` would report a clean run over it.
    $scan = releaseStampScanSource(<<<'PHP'
    <?php

    class FourthSpelling
    {
        public function released($invoice): bool
        {
            return $invoice->reviewed_at !== null;
        }
    }
    PHP);

    expect($scan['code'])->toHaveCount(1)
        ->and($scan['code'][0]['text'])->toBe('reviewed_at')
        ->and($scan['unrecognised'])->toBe([]);
});

it('catches a fourth spelling buried in a heredoc SQL string', function () {
    $scan = releaseStampScanSource(<<<'PHP'
    <?php

    class FourthSpelling
    {
        public function sql(): string
        {
            $table = 'finance_invoices';

            return <<<SQL
            SELECT id FROM {$table} WHERE reviewed_at IS NULL
            SQL;
        }
    }
    PHP);

    expect($scan['code'])->toHaveCount(1)
        ->and($scan['code'][0]['text'])->toContain('reviewed_at')
        ->and($scan['unrecognised'])->toBe([]);
});

it('routes a spelling it cannot classify into unrecognised rather than into silence', function () {
    // THE THIRD BUCKET, PROVED BY INJECTION. There is no PHP construct today that carries an
    // identifier in a kind releaseStampCodeKinds() omits — which is the point of the list — so the
    // only honest way to show the bucket is wired is to narrow the kinds and watch the SAME source
    // that was code in the previous arm land in unrecognised instead. Without this, arm 1's
    // `expect($scan['unrecognised'])->toBe([])` could be green because the bucket never fills.
    $source = <<<'PHP'
    <?php

    class FourthSpelling
    {
        public function released($invoice): bool
        {
            return $invoice->reviewed_at !== null;
        }
    }
    PHP;

    $narrowed = releaseStampScanSource($source, [T_CONSTANT_ENCAPSED_STRING]);

    expect($narrowed['code'])->toBe([])
        ->and($narrowed['unrecognised'])->toHaveCount(1)
        ->and($narrowed['unrecognised'][0]['kind'])->toBe('T_STRING');
});
