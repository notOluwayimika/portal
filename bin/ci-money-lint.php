<?php

/**
 * money-lint — the money-correctness gate, on BOTH sides of the wire.
 *
 * TWO ARMS, because for a while there was one. The UI arm below was written when the rule
 * "all money is displayed through one formatter" was believed to be enforced; it was
 * enforced in the browser and was a convention with nothing behind it on the server, where
 * FOUR spellings of a naira figure had accumulated — a bare `1500.00`, an ISO-prefixed
 * `NGN 1500.00`, a grouped `₦3,476,400.00` hand-rolled in a Finance service, and an
 * identical grouped one in a global helper no production code ever called. The UI rule looked stronger
 * than it was precisely because half the surfaces that render money were outside the only
 * thing checking. ADR 0054 collapsed them onto Money::format(); this arm is what keeps them
 * collapsed.
 *
 * THE JS ARM — the money architecture is integer minor units end to end precisely because JS
 * numbers are floats. Two rules keep the UI from reintroducing the float-money bug the
 * backend exists to prevent — enforced statically and PERMANENTLY:
 *
 *   money-format-outside-formatnaira
 *     `Intl.NumberFormat` / `.toLocaleString(` used to render money. All money is displayed
 *     via formatNaira() (resources/js/lib/format.ts — the ONE exempt file, where the single
 *     Intl call lives). Enforced hard inside the Finance UI (every number there is money),
 *     and by money-identifier heuristic anywhere else (a stray `amount_minor.toLocaleString()`).
 *
 *   money-arithmetic-in-ui
 *     Monetary arithmetic in JavaScript — summing amounts, computing balances/outstanding/
 *     totals client-side. The API returns every figure already computed; the UI only displays.
 *     Best-effort heuristic (money-identifier adjacent to an operator, or a reduce() in the
 *     Finance UI), but standing. The ONLY sanctioned money arithmetic is the integer helpers
 *     in resources/js/lib/format.ts (formatNaira display, nairaToMinor input, minorToNairaInput
 *     prefill, sumMinor total, differenceMinor headroom — all exact integer minor-unit ops); that
 *     file is exempt from BOTH rules and is the single reviewed money boundary. Callers use the
 *     named helper; ad-hoc +/reduce stays banned.
 *
 *     THIS LIST NAMED THREE OF THE FIVE until the vitest branch. It is prose enumerating another
 *     file's exports, so nothing could fail on it going stale, and it did — the same way the count
 *     inside format.ts's own docblock did, independently. Both are corrected together; neither is
 *     mechanised, and a reader who needs the true set should read the `export`s.
 *
 * THE PHP ARM — walks app/ and holds Money::format() as the single server-side renderer.
 * Two rules, both exempting only app/Support/Money.php:
 *
 *   money-render-outside-money-format
 *     `toNaira()` CONSUMED rather than bound — flowing straight into a concatenation, an
 *     interpolation, a sprintf argument, an arrow-fn body or a return. toNaira() is the
 *     ungrouped machine decimal; anything that puts it in front of a human is a second
 *     formatter. Token-based, not regex — see toNairaRenderLines() for why, and for why the
 *     `Δ %d kobo` diagnostics are spared structurally rather than by an exemption list.
 *
 *   money-naira-symbol-outside-money-format
 *     The ₦ character anywhere but the value object. The catch-all: a render built from toKobo()
 *     with str_pad and a comma loop trips neither rule above — measured, by plant — and that is
 *     exactly the deleted naira() helper's shape. Every hand-rolled render must emit the symbol
 *     eventually, so the character is the invariant the techniques are not.
 *
 *   money-number-format-on-money
 *     `number_format(` on a line that also names money. Its declared parameter is `float`, and
 *     the domain's top (intdiv(PHP_INT_MAX, 100) ~ 9.22e16) is an order of magnitude past
 *     float's exact-integer limit (2^53 ~ 9.01e15). Unreachable at school-fee magnitudes; the
 *     point is that a formatter must not be able to alter the figure it displays. ADR 0054 §3.
 *
 * Like the sibling lints, the baseline may only shrink: CI fails on any NEW occurrence;
 * removing a baselined line is reported as progress. It is EMPTY, on both arms, and the
 * intent is that it stays that way — a money render is never a reviewed exception.
 *
 * Usage:
 *   php bin/ci-money-lint.php            # check (CI): exit 1 on new findings
 *   php bin/ci-money-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/money-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

// The ONE file allowed to touch Intl.NumberFormat/toLocaleString for money.
const FORMATNAIRA_HOME = 'resources/js/lib/format.ts';

/**
 * Finance UI paths — every displayed number here is money, so the format ban is total (the
 * money-identifier heuristic that governs the rest of resources/js is not needed, and a figure
 * that dodges the heuristic does not dodge this).
 *
 * resources/js/lib/finance/ WAS MISSING, and the omission was not that the directory is new: it
 * holds the feed builders the approvals screens render from, and approval-feeds.ts already emits
 * an `amount_minor` (`:181`) that a screen puts in front of a bursar. A hand-rolled render added
 * there would have been judged by the heuristic rather than by the total ban — i.e. it would have
 * had to name a money identifier on the same line to be seen at all. The list enumerates
 * directories, so every new one is a decision someone has to remember to make; this is the one
 * that was not made.
 */
function isFinanceUi(string $rel): bool
{
    return str_starts_with($rel, 'resources/js/pages/admin/finance/')
        || str_starts_with($rel, 'resources/js/components/finance/')
        || str_starts_with($rel, 'resources/js/lib/finance/');
}

/** @return array<int, array{0: string, 1: string}> [[relativePath, line], ...] */
function scriptLines(string $dir, string $root): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
        // Skip wayfinder-generated route actions (machine output, not hand-written UI).
        if (str_starts_with($rel, 'resources/js/actions/')) {
            continue;
        }
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            $out[] = [$rel, $line];
        }
    }

    return $out;
}

function isComment(string $line): bool
{
    $t = ltrim($line);

    return str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*');
}

// ─────────────────────────────────────────────────────────────────────────────
// THE PHP ARM. Everything above this line watches resources/js; everything below
// watches app/. See the header docblock for why the server needed its own arm.
// ─────────────────────────────────────────────────────────────────────────────

// The ONE file allowed to turn a Money into a human-readable string: the value object
// itself, where format() and the toNaira() it punctuates both live.
const MONEY_HOME = 'app/Support/Money.php';

/**
 * Every tree that can put a string in front of a person, server-side.
 *
 * WAS `app/` ALONE, and that was the gap: a render in a Blade view, a route file, a console
 * command reachable from database/, or a seeder is exactly as much a second formatter as one in
 * an Action, and the arm could not see any of them.
 *
 * resources/views is IN; resources/js is deliberately NOT — that is the JS arm's territory, it
 * walks .ts/.tsx and holds formatNaira, and a PHP walker entering it would double-report the one
 * file that is allowed to render.
 *
 * tests/ is NOT walked either, and that is a judgement rather than an oversight: a test asserting
 * `->toBe('₦1,234.56')` is CHECKING the formatter, not being a second one, and MoneyTest cannot
 * pin the output without naming it. Banning the character there would force the oracle to describe
 * the string instead of stating it, which is how an oracle stops being one.
 */
// The naira mark, as its own constant so this file never contains a bare one either — the rule
// would otherwise trip on the source of the rule.
const NAIRA_SYMBOL = "\u{20A6}";

const PHP_TREES = ['app', 'routes', 'database', 'config', 'bootstrap', 'resources/views'];

/** Every .php file under app/, as [relativePath, absolutePath]. */
function phpFiles(string $dir, string $root): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $out[] = [ltrim(str_replace($root, '', $file->getPathname()), '/'), $file->getPathname()];
    }
    sort($out);

    return $out;
}

/**
 * money-render-outside-money-format — every line where `toNaira()` is CONSUMED rather than
 * bound.
 *
 * WHY A TOKENISER AND NOT A REGEX. The renders this rule exists to catch are written across
 * several lines:
 *
 *     $findings[] = $this->finding('control_total_mismatch', sprintf(
 *         'Σ of the student totals = %s but --control-total = %s (Δ %d kobo).',
 *         $statedSum->toNaira(),        // <- the offending line knows nothing about sprintf
 *
 * A line-local rule sees `$statedSum->toNaira(),` and cannot tell a sprintf argument from an
 * array_map callback from an ordinary parameter. PHP ships the parser we need in the same
 * runtime, so this walks tokens instead of characters and asks a question a regex cannot.
 *
 * THE QUESTION IS "IS IT BOUND?", NOT "DOES A QUOTE APPEAR NEARBY". A render is a toNaira()
 * whose result flows straight into something else — a concatenation, an interpolation, a
 * sprintf/printf argument, an arrow-fn body handed to array_map, a return. The one shape
 * that is NOT a render is a direct assignment:
 *
 *     $exact = $money->toNaira();        // allowed: a machine value, bound, and visible
 *     'owes '.$money->toNaira()          // flagged
 *     sprintf('%s', $money->toNaira())   // flagged
 *     fn (Money $m) => $m->toNaira()     // flagged  <- the shape "string context" misses
 *
 * That last one is why this is stated as binding rather than as the three string contexts:
 * OpeningBalanceFileValidator built a findings string through array_map + implode, which is a
 * render by any honest reading and sits inside no quote, no dot and no sprintf.
 *
 * A KNOWN LIMIT, STATED AS A LIMIT AND NOT AS A TODO. Bind-then-interpolate passes:
 *
 *     $s = $money->toNaira();
 *     $message = "Total {$s}";      // not flagged, and never will be
 *
 * The binding is legal and the second line names a string, not a Money. Catching it needs flow
 * analysis this repository should not carry for one lint. It is also the most natural way a
 * second spelling comes back — reach for toNaira(), get refused, assign it first. The rule
 * closes the casual route and makes the remaining one conspicuous; it is not airtight, and a
 * reader who thinks it is would be worse off than one who knows where it ends. ADR 0054 records
 * this in the same terms.
 *
 * WHY THIS CANNOT FIRE ON A KOBO DIAGNOSTIC. `Δ %d kobo` figures are toKobo() — integers, on
 * purpose, because sub-naira drift is the thing they exist to expose and formatting them to
 * two decimals would hide it. This rule never looks at toKobo(). The kobo diagnostics are
 * spared STRUCTURALLY, by the rule not applying to them, rather than by an exemption list —
 * an exemption list would be a standing invitation to add the next site to it.
 *
 * @return array<int, int> line numbers
 */
function toNairaRenderLines(string $path): array
{
    $tokens = token_get_all(file_get_contents($path));

    // Normalise to [type, text, line]; type is an int (T_*) or a single-character string.
    $t = [];
    foreach ($tokens as $tok) {
        $t[] = is_array($tok)
            ? ['type' => $tok[0], 'text' => $tok[1], 'line' => $tok[2]]
            : ['type' => $tok, 'text' => $tok, 'line' => $t === [] ? 1 : $t[count($t) - 1]['line']];
    }

    $skippable = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $prevSig = function (int $i) use ($t, $skippable): ?int {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! in_array($t[$j]['type'], $skippable, true)) {
                return $j;
            }
        }

        return null;
    };

    // Bracket pairing, both directions, so a chain can be walked over `foo()['bar']` without
    // mistaking the inside of a subscript for the expression that precedes it.
    $open = [];
    $matchBack = [];
    foreach ($t as $i => $tok) {
        // A literal bracket has type === text (the character); T_CURLY_OPEN is the `{` of a
        // `"{$expr}"` interpolation and closes with a literal `}` like any other.
        if (in_array($tok['type'], ['(', '[', '{'], true) || in_array($tok['type'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $open[] = $i;
        } elseif (in_array($tok['type'], [')', ']', '}'], true)) {
            $o = array_pop($open);
            if ($o !== null) {
                $matchBack[$i] = $o;
            }
        }
    }

    $lines = [];

    foreach ($t as $i => $tok) {
        if ($tok['type'] !== T_STRING || $tok['text'] !== 'toNaira') {
            continue;
        }
        $before = $prevSig($i);
        if ($before === null || ! in_array($t[$before]['type'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue; // a bare identifier named toNaira; not a method call on a Money
        }

        // Walk BACK to the start of the member-access chain: $x->y()['k']->toNaira().
        $start = $before;
        while (true) {
            $p = $prevSig($start);
            if ($p === null) {
                break;
            }
            $type = $t[$p]['type'];
            $text = $t[$p]['text'];
            if (in_array($text, [')', ']'], true) && isset($matchBack[$p])) {
                $start = $matchBack[$p];

                continue;
            }
            if (in_array($type, [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $start = $p;

                continue;
            }
            break;
        }

        // BOUND, and therefore allowed: the whole expression is the right-hand side of an
        // assignment. `$exact = $money->toNaira();` keeps a machine decimal available for a
        // machine consumer, in a form a reader can see and a reviewer can follow.
        $lhs = $prevSig($start);
        if ($lhs !== null && $t[$lhs]['text'] === '=' && $t[$lhs]['type'] === '=') {
            continue;
        }

        $lines[] = $tok['line'];
    }

    return array_values(array_unique($lines));
}

$lines = scriptLines($root.'/resources/js', $root);

$found = [];
$add = function (string $rule, string $rel, string $line) use (&$found) {
    $found[$rule."\t".$rel."\t".trim($line)] = true;
};

foreach ($lines as [$rel, $line]) {
    if (isComment($line)) {
        continue;
    }

    $usesIntlFormatter = str_contains($line, 'Intl.NumberFormat') || preg_match('/\.toLocaleString\s*\(/', $line);

    // money-format-outside-formatnaira. Inside the Finance UI the ban is total (all
    // numbers are money); elsewhere it fires only when a money identifier is on the line,
    // catching a stray money render without flagging legitimate count/date formatting.
    if ($usesIntlFormatter && $rel !== FORMATNAIRA_HOME) {
        $moneyIdentifierOnLine = (bool) preg_match('/(amount_minor|available_credit|\bbalance_minor\b|\.amount\b)/', $line);
        if (isFinanceUi($rel) || $moneyIdentifierOnLine) {
            $add('money-format-outside-formatnaira', $rel, $line);
        }
    }

    // money-arithmetic-in-ui. A money identifier adjacent to an arithmetic operator, or a
    // reduce() inside the Finance UI (almost always a client-side sum). Skip type/interface
    // lines (`amount_minor: number`) — those declare shape, they do not compute — and the
    // money-boundary file itself, where the sanctioned integer helpers (sumMinor et al.) live.
    $isTypeDecl = (bool) preg_match('/:\s*(number|Money|string)\b/', $line);
    if (! $isTypeDecl && $rel !== FORMATNAIRA_HOME) {
        $moneyMath = preg_match('/amount_minor\s*[+\-*\/]/', $line)
            || preg_match('/[+\-*]\s*[\w.$\[\]]*amount_minor/', $line)
            || preg_match('/(available_credit|balance_minor)\s*[+\-*\/]/', $line);
        $financeReduce = isFinanceUi($rel) && preg_match('/\.reduce\s*\(/', $line);
        if ($moneyMath || $financeReduce) {
            $add('money-arithmetic-in-ui', $rel, $line);
        }
    }
}

// ── The PHP arm. Same $found bag, same baseline, same shrink-lock. ──
$phpFiles = [];
foreach (PHP_TREES as $tree) {
    foreach (phpFiles($root.'/'.$tree, $root) as $entry) {
        $phpFiles[] = $entry;
    }
}

foreach ($phpFiles as [$rel, $abs]) {
    if ($rel === MONEY_HOME) {
        continue;
    }

    $source = file($abs, FILE_IGNORE_NEW_LINES);

    // money-render-outside-money-format
    foreach (toNairaRenderLines($abs) as $lineNo) {
        $add('money-render-outside-money-format', $rel.':'.$lineNo, $source[$lineNo - 1] ?? '');
    }

    // money-number-format-on-money. number_format()'s declared parameter is `float`. PHP 8's
    // integer fast-path happens to keep an int argument exact today, but that is an engine
    // detail one cast away from `number_format((float) 92233720368547758) === '…,760'`, and a
    // formatter that rounds the figure it displays is worse than none. Money::format() groups
    // by string surgery instead, so no float is ever in the type signature. Line-local is
    // enough here: number_format's argument is written beside it, unlike a sprintf render.
    foreach ($source as $idx => $line) {
        if (isComment($line) || ! str_contains($line, 'number_format(')) {
            continue;
        }
        if (preg_match('/(amount|balance|kobo|minor|money|naira|currency|price|total)/i', $line)) {
            $add('money-number-format-on-money', $rel.':'.($idx + 1), $line);
        }
    }

    // money-naira-symbol-outside-money-format. THE CHARACTER RULE, and the one that actually
    // closes the door the other two leave open.
    //
    // WHY A CHARACTER AND NOT A TECHNIQUE. money-render-outside-money-format watches toNaira() and
    // money-number-format-on-money watches number_format(. A cold review planted a grouped naira
    // string built from toKobo() — intdiv/modulo, str_pad, a hand-rolled comma loop — and it
    // tripped NEITHER. That is not a corner case: it is precisely the shape of the naira() method
    // this branch deleted, so the gate did not stop the deleted thing coming back through another
    // door. Any hand-rolled render has to emit ₦ EVENTUALLY, whatever route it took to get there,
    // so banning the character catches every synonym at once — including the ones nobody has
    // thought of yet, which is the only kind worth gating against.
    //
    // The symbol is named once, as Money::SYMBOL, so the value object's own render and the single
    // legitimate strip (StoreOpeningBalanceImportRequest, normalising what an operator typed) both
    // refer to the constant and neither needs an exception. The baseline stays empty.
    foreach ($source as $idx => $line) {
        if (isComment($line) || ! str_contains($line, NAIRA_SYMBOL)) {
            continue;
        }
        $add('money-naira-symbol-outside-money-format', $rel.':'.($idx + 1), $line);
    }
}

$found = array_keys($found);
sort($found);

if ($mode === 'generate') {
    $header = <<<'TXT'
# money-lint baseline — intentional exceptions to the UI money rules. May only shrink.
#
# Ideally EMPTY: the Finance UI is new and routes all money through formatNaira(), and no
# pre-existing code renders a money identifier via toLocaleString/Intl. An entry here is a
# deliberate, reviewed exception (e.g. a genuine non-money reduce() that happens to sit in
# a Finance UI file) — never a money render or a money sum.

TXT;
    file_put_contents($baselinePath, $header.($found ? implode("\n", $found)."\n" : ''));
    fwrite(STDERR, 'money-lint: wrote '.count($found)." baseline entries to money-lint-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($new !== []) {
    fwrite(STDERR, "\nmoney-lint: ".count($new)." NEW money-rule violation(s):\n");
    fwrite(STDERR, "  ONE formatter per side — formatNaira() in the UI, Money::format() on the server. Never compute money in JS.\n");
    fwrite(STDERR, "  money-render-outside-money-format has TWO fixes, and which one is right depends on what you meant:\n");
    fwrite(STDERR, "    rendering for a human -> Money::format(). Grouped, symbol, sign before the symbol.\n");
    fwrite(STDERR, "    a machine value       -> BIND IT: \$exact = \$money->toNaira(); format() would inject a\n");
    fwrite(STDERR, "                             symbol and separators into a value meant to round-trip.\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

// SHRINK-LOCK: a baselined entry that no longer occurs must be removed from the baseline,
// or a future re-introduction would pass silently.
if ($fixed !== []) {
    fwrite(STDERR, "\nmoney-lint: ".count($fixed)." baselined exception(s) removed (good!) — lock it in by removing them from money-lint-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-money-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'money-lint: OK — no money-rule violations ('.count($found)." known exception(s)).\n");
exit(0);
