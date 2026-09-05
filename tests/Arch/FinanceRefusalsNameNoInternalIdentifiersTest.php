<?php

/*
 * A FINANCE REFUSAL MAY NOT NAME THE THING IT REFUSES BY AN INTERNAL IDENTIFIER.
 *
 * `docs/handoff/tickets/the-fold-refusal-names-ids-where-the-gate-names-the-class.md` is the
 * argument, written by the first human to see such a refusal rendered, and it is cited rather than
 * re-derived: a remedy is "correct and unactionable in the same breath" when it names the thing in a
 * vocabulary the operator does not have. There is no screen in this product where a bursar looks a
 * bill up by uuid or a colleague up by integer id.
 *
 * Ten such sentences shipped inside `ApproveInvoice` and `ReturnInvoice` and five more inside
 * `FeeScheduleLineMapper`, every one of them reaching a human screen, and every one of them survived
 * review because NOBODY HAD SEEN THEM RENDERED. That is not a defect a reader catches; the strings
 * read as precise. So the fix is not "the fifteen were corrected" — it is this file, because the
 * SIXTEENTH is written by someone who never read the ticket.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────────────────────────
 *
 * No `new BusinessRuleException(...)` under app/Finance/ builds its message from a `uuid` or from
 * the literal `user#`.
 *
 * ── WHAT IT EXAMINES, AND WHAT IT DELIBERATELY DOES NOT ─────────────────────────────────────────
 *
 * IT EXAMINES the ARGUMENT EXPRESSION of a `new BusinessRuleException(...)`, and nothing else. That
 * is the whole design and it is not an optimisation. `'invoice_uuid' => $invoice->uuid` in an
 * activity-log properties array is LEGITIMATE — the log is machine-readable and correlatable, and
 * this commit deliberately did not touch it — and it sits in the SAME METHOD, often four lines from
 * a refusal. A rule keyed on `->uuid` appearing in a FILE would red on the audit trail; a rule keyed
 * on the argument expression cannot.
 *
 * IT DOES NOT EXAMINE a message assembled into a variable first —
 * `$m = 'Invoice '.$i->uuid; throw new BusinessRuleException($m);` — because the argument expression
 * is then just `$m`. Stated rather than left to be discovered: that is a real hole, it is the price
 * of keeping the matcher local and comprehensible, and no such construction exists under
 * app/Finance/ today (every one of the 118 sites passes its message inline). Nor does it examine
 * `getAttribute('uuid')` or `$invoice['uuid']`, for the same reason and with the same status.
 *
 * ── TWO SHAPES THAT WERE AN UNDECLARED BLIND SPOT, AND ARE NOW NEITHER ──────────────────────────
 *
 * The first version of this file tracked the argument expression by comparing token TEXT to `(`,
 * `[` and `{`. `#[Attr]` (T_ATTRIBUTE, text `#[`) and `"${k}"` (T_DOLLAR_OPEN_CURLY_BRACES, text
 * `${`) open a bracket whose text is neither, and both close with a bare one — so each drove the
 * depth count to zero early and silently truncated the range, dropping the rest of the argument
 * into the activity-log bucket. A refusal spelled `"Invoice ${n} released by user#{$id}."` passed
 * clean, with `unrecognised` still zero.
 *
 * That was worse than a declared hole, which is why it is written out here rather than quietly
 * fixed: this docblock listed its exclusions BY NAME and neither shape was among them, so a reader
 * doing the right thing came away with a wrong model of coverage.
 *
 * Both are handled now, and — the point — WITHOUT EITHER BEING NAMED IN THE WALKER. The depth count
 * is over bracket CHARACTERS in the token text, excluding the string-like kinds whose brackets are
 * content, so a future token of this shape is handled the day it exists. The list that would have
 * gone stale was the first fix attempted here and it was rejected for that reason; see the walker.
 *
 * IT DOES NOT EXAMINE any other exception class, or any directory but app/Finance/. Those are
 * separate decisions with separate blast radii, not oversights.
 *
 * ── WHY TOKENS AND NOT A GREP ───────────────────────────────────────────────────────────────────
 *
 * The sibling `ReleasedToPayersHasOneDefinitionTest` makes the argument and this file takes its
 * shape. A grep cannot tell `->uuid` in a refusal from `->uuid` in a comment EXPLAINING the refusal
 * — and this repository now carries several such comments, including the ones added by the commit
 * that removed the uuids. A comment-blind matcher would either red on its own explanation or be
 * "fixed" by deleting it. So comments are a bucket with a stated reason, not an invisible skip, and
 * an arm below proves that bucket is wired.
 *
 * ── THREE NUMBERS, NOT TWO (CLAUDE.md § gates) ──────────────────────────────────────────────────
 *
 * EXAMINED — every token of every .php under app/Finance/.
 * EXCLUDED WITH A REASON — (a) T_COMMENT / T_DOC_COMMENT: prose cannot construct a message;
 *   (b) every token outside a `new BusinessRuleException(...)` argument expression: that is the
 *   activity-log carve-out above, and it is the reason this gate can exist at all.
 * UNRECOGNISED — a token INSIDE an argument expression that carries a forbidden marker in a token
 *   kind this scanner cannot classify. Asserted ZERO, so a spelling in a kind nobody anticipated
 *   REDS instead of vanishing into a skip.
 *
 * Two further zeros are asserted, each closing a way the scanner could examine LESS than it claims:
 * UNRESOLVED-NEW — a `new` whose class could not be resolved to a name at all (`new $class(...)`),
 *   which would be a construct site the rule silently never visited; and
 * UNBALANCED — an argument list that ran to end of file, ended on something other than a `)`, or
 *   drove the depth negative. NOT a general catch-all: measured on this machine, the `#[Attr]`
 *   defect below ended its range on a real (nested, early) `)` and this check never fired. It is
 *   the cheap secondary; the character-counting walker is the actual fix.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/**
 * Token kinds that can carry a forbidden marker AS CODE inside an argument expression.
 *
 * RECOGNISE BROADLY, JUDGE NARROWLY — the sibling's rule. This is not "the ways someone would
 * sensibly interpolate an id"; it is every kind whose text could contain one at all: a quoted
 * string, an interpolated or heredoc body, a bare property name after `->`, the varname half of
 * `{$x->uuid}`, a variable, a qualified name, and raw output outside `<?php`.
 */
function refusalCodeKinds(): array
{
    return [
        T_CONSTANT_ENCAPSED_STRING,   // 'Invoice '.$i->uuid  /  ' by user#'
        T_ENCAPSED_AND_WHITESPACE,    // "Invoice {$i->uuid} …" / heredoc body
        T_STRING,                     // ->uuid
        // "${uuid}" — the pre-8.4 interpolation form. Its OPENER is T_DOLLAR_OPEN_CURLY_BRACES,
        // which the range-walker missed until the fix recorded above; listing this kind here was
        // true about the classifier and false about the coverage, which is the exact shape of an
        // overclaiming description. Both halves work now.
        T_STRING_VARNAME,
        T_VARIABLE,                   // $uuid
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_INLINE_HTML,
    ];
}

/**
 * Whether a token's text carries a forbidden marker.
 *
 * `uuid` NOT ANCHORED TO `->`, and the left boundary is deliberately `(?<![A-Za-z0-9])` rather than
 * `\b`. `\b` would NOT match `invoice_uuid`, because `_` is a word character — so a refusal built
 * from `$row->invoice_uuid`, or from the activity-log key of that name, would pass a `\buuid\b`
 * matcher clean. Underscore-prefixed spellings are the ones actually in this codebase, so the
 * boundary that misses them is the wrong one.
 *
 * BROAD ON PURPOSE in the other direction too: prose inside an operator-facing refusal has no
 * business saying "uuid" either — that is the SAME vocabulary problem the ticket is about, one layer
 * thinner — so there is no legitimate occurrence to carve out, and a broad marker cannot be evaded
 * by `getAttribute('uuid')` or a string key.
 *
 * `user#` as a literal, which is the exact spelling the fifteen used.
 */
function refusalCarriesMarker(string $text): bool
{
    return (bool) preg_match('/(?<![A-Za-z0-9])uuid\b/i', $text) || str_contains($text, 'user#');
}

/**
 * Scan $files for `new BusinessRuleException(...)` and bucket every token.
 *
 * $codeKinds is injectable ONLY so an arm can narrow it and prove the unrecognised bucket fires;
 * production callers pass null.
 *
 * @param  list<string>  $files  absolute paths
 * @return array{examined: int, excludedComment: int, excludedOutside: int, sites: int, violations: list<array{file: string, line: int, text: string}>, unrecognised: list<array{file: string, line: int, kind: string}>, unresolvedNew: list<array{file: string, line: int}>, unbalanced: list<array{file: string, line: int, closedOn: string}>}
 */
function refusalScan(array $files, ?array $codeKinds = null): array
{
    $codeKinds ??= refusalCodeKinds();
    $root = dirname(__DIR__, 2).'/';

    $out = [
        'examined' => 0,
        'excludedComment' => 0,
        'excludedOutside' => 0,
        'sites' => 0,
        'violations' => [],
        'unrecognised' => [],
        'unresolvedNew' => [],
        'unbalanced' => [],
    ];

    foreach ($files as $file) {
        $relative = str_replace($root, '', $file);
        $tokens = PhpToken::tokenize(file_get_contents($file));
        $count = count($tokens);

        // Pass 1: the index ranges that are argument expressions of the construct under the rule.
        $inside = [];

        for ($i = 0; $i < $count; $i++) {
            if (! $tokens[$i]->is(T_NEW)) {
                continue;
            }

            $j = $i + 1;

            while ($j < $count && $tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $j++;
            }

            if ($j >= $count) {
                continue;
            }

            // A `new` whose class is a DYNAMIC EXPRESSION is reported rather than skipped:
            // `new $class(…)` and `new ($expr)(…)` would be construct sites the rule never
            // visited, and a silent skip is how a gate reports a clean run over a population it
            // never read.
            //
            // `new static(…)`, `new self(…)` and `new class {…}` are NOT in that bucket, and the
            // distinction is the difference between a gate and a nuisance. Each resolves to a
            // class that is knowably not `BusinessRuleException` — the enclosing class, or an
            // anonymous one declared on the spot — so reporting them would make this arm RED on
            // correct code. That is the broken-closed failure: a gate that refuses everything is
            // indistinguishable from a strict one right up until somebody disables it, and then
            // you have neither the gate nor the knowledge that it is gone. `app/Finance/` carries
            // five `new self(…)` today (they lex as T_STRING and fall through the name check
            // below) and zero of the other two; this branch is written for the ones that do not
            // exist yet.
            if (! $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if ($tokens[$j]->is(T_VARIABLE) || $tokens[$j]->text === '(') {
                    $out['unresolvedNew'][] = ['file' => $relative, 'line' => $tokens[$j]->line];
                }

                continue;
            }

            if (! preg_match('/(^|\\\\)BusinessRuleException$/', $tokens[$j]->text)) {
                continue;
            }

            $k = $j + 1;

            while ($k < $count && $tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $k++;
            }

            // `new BusinessRuleException` with no argument list at all — nothing to judge.
            if ($k >= $count || $tokens[$k]->text !== '(') {
                continue;
            }

            $out['sites']++;
            $depth = 0;
            $closedOn = null;

            // THE DEPTH COUNT IS OVER BRACKET CHARACTERS IN THE TOKEN TEXT, NOT OVER A LIST OF
            // TOKENS THAT OPEN BRACKETS. That choice is the fix, and it is worth stating why the
            // obvious alternative was rejected.
            //
            // The first version compared token TEXT to `(`, `[`, `{`. Two PHP tokens open a bracket
            // whose text is NOT the bare character, and both close with a bare one — measured on
            // this project's PHP 8.3.32:
            //
            //     #[Attr]   T_ATTRIBUTE                 text '#['   closes ']'
            //     "${k}"    T_DOLLAR_OPEN_CURLY_BRACES  text '${'   closes '}'
            //
            // Each took a closer it had never counted an opener for, so the depth ran one low, the
            // range ended at the first NESTED `)`, and everything after it was marked "outside" —
            // into the activity-log bucket. Silent, in the OPEN direction: the sixteenth refusal
            // vanishes rather than false-alarming.
            //
            // NAMING THE TWO TOKENS WOULD HAVE BEEN A LIST, AND A LIST IS THE THING THAT GOES
            // STALE. `${` is removed in PHP 8.4; whatever joins `#[` in a later version is not in
            // it. Counting characters needs no such list: any token whose text embeds a bracket is
            // handled the day it is introduced, without this file knowing its name.
            //
            // The exclusion below is over STRING-LIKE and COMMENT kinds, whose brackets are
            // CONTENT and not structure — `'a(b'`, a heredoc body, `// (`. That is a far more
            // stable set than "tokens that open a bracket": it is a property of the language's
            // lexical categories rather than of its syntax, and it has not changed in a decade.
            $carriesTextNotCode = [
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
                T_INLINE_HTML,
            ];

            for ($m = $k; $m < $count; $m++) {
                $token = $tokens[$m];
                $before = $depth;

                if (! $token->is($carriesTextNotCode)) {
                    $depth += substr_count($token->text, '(')
                        + substr_count($token->text, '[')
                        + substr_count($token->text, '{');
                    $depth -= substr_count($token->text, ')')
                        + substr_count($token->text, ']')
                        + substr_count($token->text, '}');
                }

                // The opening `(` itself is the range's boundary, not part of the argument.
                if ($before === 0) {
                    continue;
                }

                if ($depth <= 0) {
                    $closedOn = $token->text;

                    break;
                }

                $inside[$m] = true;
            }

            // A SECOND, NARROWER GUARD — and its limits are stated because measuring them is what
            // produced the rule above.
            //
            // It fires when the range ended on something that is not a `)`, or ran to end of file.
            // It does NOT catch an off-by-one that still lands on a `)`, which is exactly what the
            // `#[Attr]` defect did: traced on this machine, the text-only walker ended that range
            // on a genuine `)` — a NESTED one, early — so this check was satisfied throughout.
            // Writing it up as the half that survives an upgrade would have been the same
            // overclaiming description this whole file is a correction to.
            //
            // It is kept because it is free and it does catch the unterminated case, and because a
            // NEGATIVE depth means the counter has certainly gone wrong.
            if ($closedOn !== ')' || $depth < 0) {
                $out['unbalanced'][] = [
                    'file' => $relative,
                    'line' => $tokens[$k]->line,
                    'closedOn' => $closedOn ?? '(end of file)',
                ];
            }
        }

        // Pass 2: bucket every token.
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $out['examined']++;

            if (! refusalCarriesMarker($token->text)) {
                continue;
            }

            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $out['excludedComment']++;

                continue;
            }

            if (! isset($inside[$i])) {
                $out['excludedOutside']++;

                continue;
            }

            if (in_array($token->id, $codeKinds, true)) {
                $out['violations'][] = [
                    'file' => $relative,
                    'line' => $token->line,
                    'text' => trim($token->text),
                ];

                continue;
            }

            $out['unrecognised'][] = [
                'file' => $relative,
                'line' => $token->line,
                'kind' => token_name($token->id),
            ];
        }
    }

    return $out;
}

/** Every .php under app/Finance/. */
function refusalFinanceFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Finance', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Write $body to a throwaway .php file OUTSIDE THE REPOSITORY, scan it, delete it.
 *
 * Not under app/Finance/, deliberately: the first arm scans that directory for real, so a probe
 * living there — even for milliseconds — is a file the gate could read as production code, and a
 * crashed run would leave it behind for the next reader to find. The scanner takes explicit paths,
 * so the probe does not need to be inside the scanned tree to be scanned.
 */
function refusalScanSource(string $body, ?array $codeKinds = null): array
{
    $path = sys_get_temp_dir().'/refusal_gate_probe_'.Str::random(12).'.php';
    file_put_contents($path, $body);

    try {
        return refusalScan([$path], $codeKinds);
    } finally {
        @unlink($path);
    }
}

it('constructs no finance refusal whose message names a uuid or a user#', function () {
    $files = refusalFinanceFiles();

    // THE DENOMINATOR, ASSERTED BEFORE THE ZERO. A scan of no files satisfies "no violations"
    // perfectly, which is the failure the CLAUDE.md gates entry names: an instrument that examined
    // nothing must not report success. A literal floor, not a count derived from the scan — the
    // latter would assert the scan equals itself.
    expect(count($files))->toBeGreaterThan(150);

    $scan = refusalScan($files);

    // AND THE POPULATION THE RULE ACTUALLY VISITED. `files > 150` proves files were read; this
    // proves the construct the rule is ABOUT was found in them. A refactor that renamed the
    // exception class would otherwise leave this arm green over zero sites.
    expect($scan['sites'])->toBeGreaterThan(100);

    // ── THE THIRD NUMBER, ASSERTED FIRST BECAUSE IT INVALIDATES EVERYTHING BELOW IT.
    expect($scan['unrecognised'])->toBe([]);

    // A construct site whose class could not be resolved is a site the rule never visited.
    expect($scan['unresolvedNew'])->toBe([]);

    // An argument list that did not end on its own `)` was mis-tracked, so whatever followed the
    // mis-tracked point was never judged. Same class as the two above: examined-less-than-claimed.
    expect($scan['unbalanced'])->toBe([]);

    expect($scan['violations'])->toBe([]);
});

it('catches a refusal whose argument carries an ATTRIBUTE, which a text-matched walker truncates', function () {
    // THE ARM THAT SURVIVES A PHP UPGRADE. `#[Attr]` is T_ATTRIBUTE with text `#[` and it closes
    // with a bare `]`, on 8.3 and on 8.4 alike — unlike `${`, which 8.4 removes. So this is the
    // case a `${`-only fix does not cover, and the one that would still be open after a fix that
    // looked complete.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Exceptions\BusinessRuleException;

    class Probe
    {
        public function act($x)
        {
            throw new BusinessRuleException((#[Foo] fn () => 'Invoice ')().' released by user#7.');
        }
    }
    PHP);

    expect($scan['violations'])->toHaveCount(1)
        ->and($scan['violations'][0]['text'])->toContain('user#')
        ->and($scan['unbalanced'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('catches a refusal interpolated as "${…}", whose opener is not the bare brace either', function () {
    // The second shape. Built by concatenation rather than written literally, because the `${…}`
    // interpolation is a PARSE ERROR on PHP 8.4 and this file must keep lexing after the upgrade
    // that removes it — the source is assembled at runtime so only the TOKENISER sees it, and on a
    // version that no longer produces T_DOLLAR_OPEN_CURLY_BRACES the arm still passes, because
    // `{$k}` and `${k}` are then the same token stream.
    $body = '<?php'."\n\n"
        .'namespace App\Finance;'."\n\n"
        .'use App\Exceptions\BusinessRuleException;'."\n\n"
        .'class Probe'."\n"
        .'{'."\n"
        .'    public function act($k)'."\n"
        .'    {'."\n"
        .'        throw new BusinessRuleException("Invoice ${k} released by user#7.");'."\n"
        .'    }'."\n"
        .'}'."\n";

    $scan = refusalScanSource($body);

    expect($scan['violations'])->toHaveCount(1)
        ->and($scan['violations'][0]['text'])->toContain('user#')
        ->and($scan['unbalanced'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('does NOT red on either shape written in a comment, which is still what earns the tokeniser', function () {
    // THE INVERSE OF THE TWO ARMS ABOVE. The fix must not have widened the matcher into prose:
    // this very file now explains both shapes in its own docblock, quoting them.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    class Probe
    {
        /**
         * Once passed clean: new BusinessRuleException("Invoice ${k} released by user#7.") and
         * the #[Attr] form beside it, both truncating the range at their bare closer.
         */
        public function act(): void
        {
            // #[Attr] and ${k} and user#7 and uuid, all of it prose.
        }
    }
    PHP);

    expect($scan['violations'])->toBe([])
        ->and($scan['excludedComment'])->toBe(2)
        ->and($scan['sites'])->toBe(0)
        ->and($scan['unbalanced'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('does not red on the activity log, which carries the uuid ON PURPOSE and four lines away', function () {
    // THE ARM THAT EARNS THE ARGUMENT-EXPRESSION DESIGN. This source is what both actions actually
    // look like: a refusal and an audit trail in one method. A rule keyed on `->uuid` appearing in
    // the FILE reds here, and the "fix" would be to stop recording the correlation id.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Exceptions\BusinessRuleException;

    class Probe
    {
        public function act($invoice)
        {
            if ($invoice->void) {
                throw new BusinessRuleException('Invoice '.$invoice->displayNumber().' is void.');
            }

            activity('finance')->withProperties([
                'invoice_uuid' => $invoice->uuid,
            ])->log('done');
        }
    }
    PHP);

    expect($scan['violations'])->toBe([])
        ->and($scan['sites'])->toBe(1)
        ->and($scan['excludedOutside'])->toBe(2)   // 'invoice_uuid' and the ->uuid fetch
        ->and($scan['unrecognised'])->toBe([]);
});

it('reds on a sixteenth refusal that interpolates a uuid', function () {
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Exceptions\BusinessRuleException;

    class Probe
    {
        public function act($x)
        {
            throw new BusinessRuleException('Invoice '.$x->uuid.' cannot be released.');
        }
    }
    PHP);

    expect($scan['violations'])->toHaveCount(1)
        ->and($scan['violations'][0]['text'])->toBe('uuid')
        ->and($scan['unrecognised'])->toBe([]);
});

it('reds on a sixteenth refusal that interpolates user#, including inside a double-quoted string', function () {
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Exceptions\BusinessRuleException;

    class Probe
    {
        public function act($x)
        {
            throw new BusinessRuleException("Already released by user#{$x->reviewed_by_user_id}.");
        }
    }
    PHP);

    expect($scan['violations'])->toHaveCount(1)
        ->and($scan['violations'][0]['text'])->toContain('user#')
        ->and($scan['unrecognised'])->toBe([]);
});

it('does NOT red on the same text written in a comment, which is what earns the tokeniser', function () {
    // The commit that removed the fifteen uuids explains itself in prose, quoting the strings it
    // removed. A comment-blind matcher would red on its own explanation, and the cheapest way to
    // make it green again is to delete the explanation.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    class Probe
    {
        /**
         * This used to read `new BusinessRuleException('Invoice '.$x->uuid.' is void.')` and it
         * named the reviewer as ' by user#'.$reviewer, which no operator can act on.
         */
        public function act(): void
        {
            // Also once: new BusinessRuleException('Invoice '.$x->uuid.' by user#7.')
        }
    }
    PHP);

    expect($scan['violations'])->toBe([])
        ->and($scan['excludedComment'])->toBe(2)
        ->and($scan['sites'])->toBe(0)
        ->and($scan['unrecognised'])->toBe([]);
});

it('routes a marker it cannot classify into unrecognised rather than into silence', function () {
    // THE THIRD BUCKET, PROVED BY INJECTION — the sibling's method. No PHP construct today carries
    // a marker in a kind refusalCodeKinds() omits, which is the point of the list, so the only
    // honest proof the bucket is wired is to narrow the kinds and watch the SAME source that was a
    // violation above land in unrecognised instead. Without this, the first arm's
    // `expect($scan['unrecognised'])->toBe([])` could be green because the bucket never fills.
    $source = <<<'PHP'
    <?php

    namespace App\Finance;

    use App\Exceptions\BusinessRuleException;

    class Probe
    {
        public function act($x)
        {
            throw new BusinessRuleException('Invoice '.$x->uuid.' cannot be released.');
        }
    }
    PHP;

    $narrowed = refusalScanSource($source, [T_CONSTANT_ENCAPSED_STRING]);

    expect($narrowed['violations'])->toBe([])
        ->and($narrowed['unrecognised'])->toHaveCount(1)
        ->and($narrowed['unrecognised'][0]['kind'])->toBe('T_STRING');
});

it('does NOT red on `new static`, `new self` or an anonymous class, which are knowably not it', function () {
    // THE KNOWN-NEGATIVE ARM FOR THE unresolvedNew BUCKET. Its positive arm below proves the
    // bucket fills; without this one, a bucket that filled on EVERY `new` would satisfy that
    // positive arm perfectly and red the whole gate on correct code. A gate needs the free arm
    // more than a test does, because refusing everything looks like strictness.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    class Probe
    {
        public function copy(): static
        {
            return new static();
        }

        public function dup(): self
        {
            return new self();
        }

        public function anon(): object
        {
            return new class
            {
                public int $n = 1;
            };
        }
    }
    PHP);

    expect($scan['unresolvedNew'])->toBe([])
        ->and($scan['violations'])->toBe([])
        ->and($scan['sites'])->toBe(0);
});

it('reports a construct site whose class it could not resolve rather than skipping it', function () {
    // `new $class(...)` is a site the rule cannot read. It must not be silently absent from the
    // denominator — that is the "handed no input" shape from CLAUDE.md, one construct wide.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    class Probe
    {
        public function act($class, $x)
        {
            throw new $class('Invoice '.$x->uuid.' cannot be released.');
        }
    }
    PHP);

    expect($scan['unresolvedNew'])->toHaveCount(1)
        ->and($scan['sites'])->toBe(0)
        ->and($scan['violations'])->toBe([]);
});

it('reports a dynamic class written as a parenthesised expression too', function () {
    // The other spelling of the same hole. `new ($this->cls)(…)` lexes with `(` where a class name
    // would be, and an earlier draft of this scanner dropped it silently — it neither resolved to
    // a name nor matched the T_VARIABLE branch.
    $scan = refusalScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    class Probe
    {
        public function act($x)
        {
            throw new ($this->cls)('Invoice '.$x->uuid.' cannot be released.');
        }
    }
    PHP);

    expect($scan['unresolvedNew'])->toHaveCount(1)
        ->and($scan['sites'])->toBe(0)
        ->and($scan['violations'])->toBe([]);
});
