<?php

/*
 * EXACTLY ONE CLASS UNDER app/ MAY PRODUCE `InvoiceStatus::Void`.
 *
 * `docs/handoff/tickets/nothing-pins-the-single-writer-of-invoice-void.md` is the argument and is
 * cited rather than re-derived. Its core: voiding is the only act in the system that reverses a
 * whole charge, every one of the four guards over it sits on the REQUEST TABLE
 * (`finance_void_requests`), and a second action that set `status = Void` directly — no request
 * row, no submitter, no checker — would compile, pass every gate, and be invisible to all four,
 * because a void with no request never touches the table they guard.
 *
 * The one detective control, `AuditLedgerCoherence`'s check I4, sees only the MONEY limb: a bypass
 * that voids AND posts a correct reversal produces `rev_count = 1` and is invisible to it. That is
 * the likelier bypass, because anyone writing a second void path would copy `ApproveVoidRequest`'s
 * ledger posting — it sits eight lines below the status write.
 *
 * WHY NOW, AND NOT AFTER. The correction mechanism approved for Brookstone adds a SECOND
 * legitimate producer of this value. Written first, this file makes that producer an explicit,
 * argued line in `voidWriterPermittedFiles()`. Written after, it baptises whatever shipped.
 *
 * ── WHAT IS PINNED: PRODUCTION, NOT "THE WRITE" ─────────────────────────────────────────────────
 *
 * The ticket says "writer" and the shape in the tree is an ORM write —
 * `$invoice->update(['status' => InvoiceStatus::Void, …])`. THIS FILE JUDGES SOMETHING WIDER, and
 * says so rather than letting the name overclaim in the other direction: it judges every place the
 * VALUE IS PRODUCED INTO A SLOT — an array value (`=>`), an assignment (`=`), a `return`, or an
 * argument to a call that writes attributes.
 *
 * The superset is deliberate, and it is what closes the laundering hole the sibling
 * `FinanceRefusalsNameNoInternalIdentifiersTest` has to DECLARE rather than close. That file cannot
 * see `$m = 'Invoice '.$i->uuid; throw new BusinessRuleException($m);` because it keys on an
 * argument expression. Here, `$s = InvoiceStatus::Void;` IS a bare `=` and IS therefore a
 * production — so writing the value into a variable first does not evade this gate, it trips it one
 * line earlier. A pin on the `update()` call alone would have that hole; this one does not.
 *
 * It costs nothing today: of the occurrences in the tree, exactly one is a production and the rest
 * are comparisons or reader arguments, so the wider rule and the narrow one name the same file.
 *
 * ── HOW THE FIRST VERSION OF THIS FILE WAS DEFEATED, AND WHAT CHANGED ───────────────────────────
 *
 * A cold review beat the shipped gate with three spellings, all three reproduced on this tree
 * BEFORE anything was changed — three clean runs, `unrecognised` zero in every one:
 *
 *     $invoice->setAttribute('status', InvoiceStatus::Void)   -> counted as an ARGUMENT
 *     $invoice->setAttribute('status', 'void')                -> counted as a non-status literal
 *     data_set($invoice, 'status', InvoiceStatus::Void)       -> counted as an ARGUMENT
 *
 * THE CAUSE WAS ORDERING, NOT A MISSING SPELLING. The classifier read the one token before the
 * occurrence and stopped: a `,` meant "an argument, therefore benign", decided before anything
 * asked what it was an argument TO. Adding the three shapes to a list would have left the next
 * positional setter exactly as invisible.
 *
 * So the fix inverts the default. An occurrence is benign only if it can be POSITIVELY justified —
 * an operand of an equality operator, or an argument to a call on the READER list. Everything else
 * is a production or a bucket asserted ZERO. `voidWriterVerdict()` is where that lives and it is
 * the whole change; the two method lists are bookkeeping under it.
 *
 * ── WHAT IS DELIBERATELY NOT JUDGED, EACH WITH ITS STATE ────────────────────────────────────────
 *
 * An UNDECLARED blind spot is worse than a declared one — this repository's standard, and the
 * sibling's own `#[Attr]` defect is the example of getting it wrong. So each row below is either
 * CLOSED, or BUCKETED AND ASSERTED ZERO (a new instance reds), or NAMED here as open. The
 * positional-setter family is in the table WHATEVER ITS STATE, so the table maps the decision
 * rather than the leftovers.
 *
 *   CLOSED   POSITIONAL SETTERS — `setAttribute`, `setRawAttributes`, `setAttributeValue`,
 *            `offsetSet`, `fill`, `forceFill`, `data_set`, `data_fill`, `set`, in either spelling
 *            of the value. These are the three defeats above and their family. Live under app/ on
 *            064de707: `->forceFill(` 31, `->setAttribute(` 2, `->fill(` 1; zero for the rest,
 *            listed anyway. See `voidWriterMutatorCalls()`.
 *   CLOSED   ARRAY-SHAPED WRITES — `update`, `create`, `save`, `firstOrCreate`, `make`,
 *            `updateOrCreate`, `insert`, `upsert` and their siblings, via the same mechanism.
 *   CLOSED   `$s = InvoiceStatus::Void;` then written elsewhere — the `=` production above.
 *   CLOSED   `'status' => 'void'`, `$i->status = 'void'` and `setAttribute('status', 'void')` — the
 *            enum's backing value spelled as a string. A second marker, because a bypass spelled
 *            this way would arrive unreviewed for exactly the reason the ticket exists. Keyed on the
 *            KEY being `status`, so `'type' => 'void'` in `VoidRequestResource` — a live witness in
 *            the scanned tree — does not red.
 *   BUCKETED A call in NEITHER method list. `unlistedCall`, asserted ZERO. This is the row that
 *            makes the two lists safe to be lists at all: a name nobody has classified reds, and
 *            reds saying the vocabulary does not cover it rather than accusing it of writing.
 *   BUCKETED `InvoiceStatus::from(…)` / `tryFrom(…)` / `cases()` / `InvoiceStatus::{$x}` — a case
 *            this scanner cannot read. `dynamicCase`, asserted ZERO. Zero today.
 *   BUCKETED Raw SQL naming the column against the literal (`… status = 'void' …` inside a string).
 *            A WHERE and a SET are indistinguishable without a SQL parser, so rather than guess,
 *            every such string is listed and the LIST is pinned: `voidWriterPermittedRawSqlSites()`
 *            holds the one comparison that exists (`AuditLedgerCoherence`'s I4 query), and an
 *            `UPDATE finance_invoices SET status = 'void'` written tomorrow reds as a new entry.
 *   BUCKETED A production position this classifier has no rule for — `?:`, a `match` arm label, a
 *            spread. `unrecognised`, asserted ZERO.
 *            tests/Feature/Finance/GatewayInitiateTest.php:72 (gitFixture) carries such a shape
 *            (`$s === 'void' ? InvoiceStatus::Void : …`), out of scope; under app/ it would red
 *            here, conservatively and correctly.
 *   BUCKETED A bracket stack that underflowed, which would mis-attribute every enclosing call after
 *            the point it lost its place. `unbalanced`, asserted ZERO.
 *   OPEN     THE STRING MARKER LAUNDERED THROUGH A VARIABLE — `$s = 'void'; $i->update(['status' =>
 *            $s]);`. The ENUM marker closes this (a bare `=` is a production); the STRING marker
 *            cannot, because `$s = 'void'` has key `s`, and knowing what a variable holds is a
 *            different instrument. Asymmetric, and stated rather than left to be found.
 *   OPEN     An import ALIAS — `use App\Finance\Enums\InvoiceStatus as S; S::Void`. The name check
 *            is on the token text, so an alias evades it. Measured: all seven imports under app/
 *            are unaliased. Left open rather than resolved, because resolving `use` statements is a
 *            second parser for a shape nobody writes.
 *   OPEN     A CALL THROUGH A VARIABLE — `$m = 'setAttribute'; $i->$m('status', …)`, or
 *            `call_user_func([$i, 'setAttribute'], …)`. The callee is not a name token, so no name
 *            is pushed. MEASURED, because the guess was wrong in one of the three: at method top
 *            level the occurrence lands in `unrecognised` (call `(none)`); inside another call it
 *            lands in `unlistedCall` naming the OUTER call — `transaction`, not `$m`; and
 *            `call_user_func` lands in `unlistedCall` naming `call_user_func`. All three RED, which
 *            is why this is open rather than a defect, but each reds as unclassifiable rather than
 *            as the write it is, and one of them names a call that is not the culprit.
 *   OPEN     Raw `DB::statement()` assembled at runtime from fragments no string token carries
 *            whole. Nothing under app/ does this to `finance_invoices`.
 *
 * ── SCOPE IS app/ AND ONLY app/, MEASURED ───────────────────────────────────────────────────────
 *
 * `tests/` carries eight occurrences outside this file, FIVE of which are
 * `update(['status' => InvoiceStatus::Void->value])` planting a void row to test something else —
 * which is what a test is for. Two more are `->toBe(InvoiceStatus::Void…)` assertions and the last
 * is the ternary named above. Widening this rule to `tests/` would red on the five plants and put
 * the ternary into `unrecognised`. `database/migrations/` declares the column. None of them is a
 * second producer of the business act.
 *
 * ── WHY TOKENS AND NOT A GREP ───────────────────────────────────────────────────────────────────
 *
 * The sibling `ReleasedToPayersHasOneDefinitionTest` makes the argument and this file takes its
 * shape. A WRITE IS NOT AN OCCURRENCE: of the six occurrences of `InvoiceStatus::Void` under app/,
 * FIVE are not productions — four comparisons (`isVoid()` and three guards) and one reader argument
 * (`scopeExcludingVoid()`, which passes `->value` to `where()`) — and a substring matcher cannot
 * tell any of them from the write, nor from a docblock naming the constant, nor from a string
 * literal. So comments are a bucket with a stated reason, not an invisible skip, and an arm below
 * proves that bucket is wired.
 *
 * ── THE RANGE WALKER THIS FILE NOW HAS, AND THE CLAIM IT NO LONGER MAKES ────────────────────────
 *
 * AN EARLIER VERSION OF THIS DOCBLOCK SAID THE SIBLING'S TRUNCATION DEFECT WAS "ABSENT BY
 * CONSTRUCTION" HERE, because the classifier read only adjacent tokens and tracked no range. That
 * sentence was TRUE ABOUT THE MECHANISM AND FALSE AS A SAFETY CLAIM, and it is worth keeping the
 * correction visible rather than quietly deleting it: reading only adjacent tokens is precisely
 * WHY three positional setters walked through. The absence of a range walker was not a guarantee;
 * it was the hole.
 *
 * There is a range walker now — `voidWriterCallScopes()` — and no structural guarantee replaces the
 * one withdrawn. What can be said positively is bounded and is the whole of it:
 *
 *   RECOGNISED  an occurrence's role (arrow, assign, return, comparison, separator) and the name of
 *               the nearest enclosing call, tracked by counting bracket CHARACTERS rather than by
 *               enumerating token names — the sibling's shipped fix, so `#[` and `${` are handled
 *               without either being named, and an arm below proves it on both.
 *   REDS ON     any production not on the permitted list; any role with no rule (`unrecognised`);
 *               any call in neither method list (`unlistedCall`); any runtime-resolved case
 *               (`dynamicCase`); any stack underflow (`unbalanced`). All four asserted ZERO.
 *   OPEN        the rows marked OPEN in the table above. They are holes, they are named, and
 *               nothing here claims a class of defect is impossible.
 *
 * ── FIVE NUMBERS, NOT TWO (CLAUDE.md § gates) ───────────────────────────────────────────────────
 *
 * EXAMINED — every token of every .php under app/, plus the file count, asserted against a literal
 *   floor so a scan that read NOTHING cannot report a clean run.
 * EXCLUDED WITH A REASON — comments naming the value (prose cannot write a row); comparisons;
 *   arguments to a call on the READER list; a `'void'` literal whose key is not `status`.
 * UNRECOGNISED, UNLISTEDCALL, DYNAMICCASE, UNBALANCED — the four ways this scanner can fail to
 *   justify what it saw. Each asserted ZERO and each a separate bucket, because "I cannot classify
 *   this role", "I do not know this method", "this case is chosen at runtime" and "my stack is
 *   lost" are four different things to fix and folding them together destroys the instruction.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/**
 * The classes permitted to produce `InvoiceStatus::Void`.
 *
 * ONE ENTRY TODAY, and the file is the unit because PSR-4 maps `app/` to `App\` one class per file
 * (composer.json), and the first arm additionally asserts the permitted file declares the FQCN
 * named here — so moving or renaming the class reds rather than silently re-pointing the pin.
 *
 * ADDING A SECOND ENTRY IS THE POINT OF THIS LIST, not a workaround for it: the correction
 * mechanism now being designed adds a legitimate one. Whoever adds it writes the reason beside it.
 *
 * @return list<string>
 */
function voidWriterPermittedFiles(): array
{
    return [
        // The maker-checker void approval. Reached only through an approved `finance_void_requests`
        // row, whose maker≠checker is enforced by DB trigger; posts the reversal eight lines below.
        'app/Finance/Actions/ApproveVoidRequest.php',
    ];
}

/**
 * The FQCN each permitted file must declare — path pinned to identity, so a move reds.
 *
 * @return array<string, string>
 */
function voidWriterPermittedClasses(): array
{
    return [
        'app/Finance/Actions/ApproveVoidRequest.php' => 'App\Finance\Actions\ApproveVoidRequest',
    ];
}

/**
 * Files permitted to carry `status = 'void'` inside a STRING — i.e. raw SQL.
 *
 * A WHERE and a SET are indistinguishable without a SQL parser, so this rule does not try to judge
 * them apart: it lists every such string and pins the list. The one entry is a comparison; an
 * `UPDATE … SET status = 'void'` written tomorrow arrives as a new entry and reds.
 *
 * @return list<string>
 */
function voidWriterPermittedRawSqlSites(): array
{
    return [
        // Check I4 of the daily ledger-coherence audit: `WHERE i.school_id = ? AND i.status =
        // 'void'` — the detective control the ticket names. A read, not a write.
        'app/Finance/Console/AuditLedgerCoherence.php',
    ];
}

/**
 * Methods that WRITE attributes. An occurrence in one of these argument lists is a production.
 *
 * ── WHY A LIST OF METHOD NAMES IS ACCEPTABLE WHERE A LIST OF TOKEN NAMES WAS NOT ────────────────
 *
 * The sibling `FinanceRefusalsNameNoInternalIdentifiersTest` rejected a list of TOKEN kinds and
 * counts bracket characters instead, on the grounds that a list of PHP's own lexical names goes
 * stale the day the language adds a shape — `${` is gone in 8.4, and whatever joins `#[` later is
 * in nobody's list. That reasoning is about a vocabulary THIS REPOSITORY DOES NOT CONTROL.
 *
 * These are different in kind: they are the vocabulary of Eloquent and of this codebase, they
 * change only when somebody writes a call, and — the part that makes the difference load-bearing —
 * **a name missing from both lists does not vanish into a benign bucket.** It lands in
 * `unlistedCall`, which is asserted ZERO. The token-name list failed because omission was SILENT;
 * omission here is a RED. Same lesson, applied rather than copied.
 *
 * OVER-INCLUSION IS THE CHEAP DIRECTION and this list is generous on purpose. A method wrongly in
 * here makes an occurrence a production (a red against the permitted list); a method left out makes
 * it `unlistedCall` (also a red). Both refuse; only the sentence differs. The expensive direction is
 * the READER list below, which is the only thing that can make an argument benign.
 *
 * Measured under app/ on 064de707: `->update(` 142, `->create(` 96, `->save(` 43, `->firstOrCreate(`
 * 38, `->forceFill(` 31, `->make(` 26, `->updateOrCreate(` 13, `->insert(` 5, `->insertOrIgnore(` 4,
 * `->setAttribute(` 2, `->fill(` 1, `->updateQuietly(` 1, `->upsert(` 1. Zero for `setRawAttributes`,
 * `offsetSet`, `forceCreate`, `firstOrNew`, `data_set`, `data_fill`, `Arr::set` — listed anyway,
 * because an unused entry costs nothing and a missing one costs a reviewed line under time pressure.
 *
 * @return list<string>
 */
function voidWriterMutatorCalls(): array
{
    return [
        // Attribute setters — POSITIONAL, the family that defeated the first version of this gate:
        // `setAttribute('status', InvoiceStatus::Void)` puts the occurrence after a comma, and the
        // comma alone was enough to have it called benign.
        'setAttribute', 'setRawAttributes', 'setAttributeValue', 'offsetSet',
        'fill', 'forceFill',
        // Laravel's positional helpers. Bare functions, so the callee is the token before the `(`
        // exactly as for a method.
        'data_set', 'data_fill', 'set',
        // Array-shaped writes.
        'update', 'updateQuietly', 'updateOrCreate', 'updateOrInsert',
        'create', 'forceCreate', 'firstOrCreate', 'firstOrNew', 'make',
        'insert', 'insertGetId', 'insertOrIgnore', 'upsert',
        'save', 'saveQuietly', 'increment', 'decrement',
    ];
}

/**
 * Methods that can only READ or FILTER. An occurrence in one of these argument lists is benign.
 *
 * THIS IS THE EXPENSIVE LIST, and it is deliberately shorter than the mutator one. It is the ONLY
 * route by which an occurrence in argument position is excused, so a name wrongly in here is a
 * silent false green — the exact defect this revision exists to close. A name wrongly ABSENT is
 * merely a red that names the method.
 *
 * Measured under app/ on 064de707: `->where(` 867, `->whereNull(` 87, `->whereIn(` 76,
 * `->whereHas(` 53, `->orWhere(` 45, `->whereNotNull(` 33, `->whereColumn(` 13, `->whereRaw(` 10,
 * `->whereNotIn(` 8, `->whereDoesntHave(` 6, `in_array(` 58, `->orWhereIn(` 2. Zero for `whereNot`,
 * `orWhereNot`, `orWhereNotIn`, `having`, `orHaving` — listed because they are the same family and
 * each is incapable of writing.
 *
 * @return list<string>
 */
function voidWriterReaderCalls(): array
{
    return [
        'where', 'orWhere', 'whereNot', 'orWhereNot',
        'whereIn', 'whereNotIn', 'orWhereIn', 'orWhereNotIn',
        'whereColumn', 'whereNull', 'whereNotNull', 'whereRaw',
        'whereHas', 'whereDoesntHave',
        'having', 'orHaving', 'havingRaw',
        'in_array', 'array_search',
    ];
}

/**
 * The POSITIONAL ROLE of an occurrence: the ONE significant token before it.
 *
 * ROLE ALONE NO LONGER DECIDES. `separator` used to mean "an argument, therefore benign", and that
 * single step is what three spellings of a direct void write walked through — the comma claimed the
 * occurrence before anything asked what it was an argument TO. Role is now half the answer; the
 * enclosing call is the other half, and `voidWriterVerdict()` combines them.
 *
 * Returns null for a shape with no rule, which the caller records as `unrecognised`.
 */
function voidWriterRole(?PhpToken $before): ?string
{
    if ($before === null) {
        return null;
    }

    if ($before->text === '=>') {
        return 'arrow';
    }

    // A BARE `=`. `===`, `!==`, `==`, `!=` are each a single token of their own text, so an
    // assignment can never be read as a comparison here. The live witness in the scanned tree that
    // the two are told apart: app/Finance/Services/InvoiceSettlement.php:65 (for)
    if ($before->text === '=') {
        return 'assign';
    }

    if ($before->is(T_RETURN)) {
        return 'return';
    }

    if ($before->is([T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL])) {
        return 'comparison';
    }

    if ($before->text === ',' || $before->text === '(' || $before->text === '[') {
        return 'separator';
    }

    return null;
}

/**
 * The verdict for an occurrence, from its role and the call whose argument list it sits in.
 *
 * ── THE INVARIANT: BENIGN MUST BE EARNED, NEVER DEFAULTED ───────────────────────────────────────
 *
 * There are exactly two ways out of this function that do not refuse:
 *
 *   'comparison'     — the occurrence is an operand of `===`/`!==`/`==`/`!=`. An operand of an
 *                      equality operator cannot write a row whatever encloses it.
 *   'readerArgument' — the occurrence sits in the argument list of a call on the READER list, every
 *                      member of which is incapable of writing.
 *
 * Everything else is either a production — judged against the permitted list — or a bucket asserted
 * ZERO. There is no path on which an occurrence the classifier cannot POSITIVELY justify becomes
 * silence. That inversion IS the fix; the two lists are bookkeeping under it.
 *
 * @param  string|null  $call  the nearest enclosing call's name, or null if the occurrence is not
 *                             inside any call's argument list
 */
function voidWriterVerdict(?string $role, ?string $call): string
{
    if ($role === null) {
        return 'unrecognised';
    }

    // Assignment and return produce the value into a slot whatever encloses them. `$s =
    // InvoiceStatus::Void;` inside a reader's closure is still a production — the value now exists
    // in a variable and this gate cannot follow it further.
    if ($role === 'assign' || $role === 'return') {
        return 'write';
    }

    if ($role === 'comparison') {
        return 'comparison';
    }

    // role is 'arrow' or 'separator' — the two that live inside argument lists and array literals.
    if ($call === null) {
        // A bare array value with no enclosing call: `$data = ['status' => InvoiceStatus::Void];`.
        // The array is heading somewhere and this is the last point at which the value is visible.
        if ($role === 'arrow') {
            return 'write';
        }

        // A bare array ELEMENT with no key and no call — `$statuses = [InvoiceStatus::Void];`. It
        // may be a lookup table or it may be a payload. The classifier cannot tell, so it refuses.
        return 'unrecognised';
    }

    if (in_array($call, voidWriterMutatorCalls(), true)) {
        return 'write';
    }

    if (in_array($call, voidWriterReaderCalls(), true)) {
        return 'readerArgument';
    }

    // ── THE FOURTH BUCKET, AND WHY IT IS NOT SIMPLY A VIOLATION ─────────────────────────────────
    //
    // A method in neither list reds either way, so this choice is not about safety — it is about
    // what the failure SAYS. Reporting `$x->frobnicate('status', InvoiceStatus::Void)` as an
    // unpermitted PRODUCER asserts that `frobnicate` writes the row, which the classifier does not
    // know and which may be false; the reader would then go looking for a void bypass that is not
    // there. `unlistedCall` asserts only what is true — the vocabulary does not cover this call —
    // and the remedy it implies is the correct one: add the name to one of the two lists,
    // deliberately, which is the same reviewed-line discipline as the permitted list itself.
    //
    // Same shape as reporting UNRECOGNISED separately from EXCLUDED rather than folding them
    // together, and the same as CLAUDE.md's rule that an absence must not be rendered as a value:
    // "I cannot classify this" and "this is a violation" are two states, and collapsing them
    // destroys the one that tells the next reader what to do.
    return 'unlistedCall';
}

/**
 * Index of the nearest significant token in $step direction, skipping whitespace and comments.
 *
 * @param  list<PhpToken>  $tokens
 */
function voidWriterAdjacent(array $tokens, int $from, int $step): ?int
{
    for ($i = $from + $step; $i >= 0 && $i < count($tokens); $i += $step) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/** A token's text as a bare lower-cased value: `'status'` and `status` both give `status`. */
function voidWriterLiteralValue(string $text): string
{
    $trimmed = trim($text);

    if (strlen($trimmed) >= 2) {
        $first = $trimmed[0];

        if (($first === "'" || $first === '"') && str_ends_with($trimmed, $first)) {
            $trimmed = substr($trimmed, 1, -1);
        }
    }

    return strtolower($trimmed);
}

/** Whether a comment's prose names the value in either of the spellings the code detectors read. */
function voidWriterProseNamesIt(string $text): bool
{
    return str_contains($text, 'InvoiceStatus::Void')
        || (bool) preg_match('/[\'"]void[\'"]/i', $text);
}

/**
 * The name of the call enclosing every token index, as a stack walked over BRACKET CHARACTERS.
 *
 * ── THE WALKER, AND WHY IT COUNTS CHARACTERS ────────────────────────────────────────────────────
 *
 * The sibling refusals gate shipped a defect here and then a fix, and the fix is what is copied:
 * depth is counted over bracket CHARACTERS IN THE TOKEN TEXT, excluding the string-like and comment
 * kinds whose brackets are content. Its first version compared token TEXT to `(`, `[`, `{`, and two
 * tokens open a bracket whose text is neither — `#[` (T_ATTRIBUTE) and `${`
 * (T_DOLLAR_OPEN_CURLY_BRACES) — each taking a closer it had never counted an opener for. Counting
 * characters needs no list of token names, so a future shape is handled the day it exists.
 *
 * A bare `(` token pushes the CALL NAME before it — a T_STRING or qualified name, which covers
 * `->method(`, `Class::method(` and a bare `helper(`. Every other opening bracket pushes null:
 * `if (`, `fn (`, `match (`, a grouping paren, an array literal, an interpolation brace. The
 * "enclosing call" of a token is then the topmost NON-NULL entry, so an occurrence inside an array
 * literal inside `update(…)` still reports `update`.
 *
 * A pop on an empty stack is recorded and asserted ZERO, so a walker that has lost its place says
 * so rather than silently mis-attributing every call after it.
 *
 * @param  list<PhpToken>  $tokens
 * @return array{0: array<int, string|null>, 1: int}
 */
function voidWriterCallScopes(array $tokens): array
{
    $carriesTextNotCode = [
        T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
        T_INLINE_HTML,
    ];

    $stack = [];
    $enclosing = [];
    $underflow = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // Recorded BEFORE this token's own brackets are applied, so the `(` that opens a call is
        // attributed to the scope OUTSIDE it and the first argument to the scope inside.
        $enclosing[$i] = null;

        for ($d = count($stack) - 1; $d >= 0; $d--) {
            if ($stack[$d] !== null) {
                $enclosing[$i] = $stack[$d];

                break;
            }
        }

        if ($token->is($carriesTextNotCode)) {
            continue;
        }

        if ($token->text === '(') {
            $nameIdx = voidWriterAdjacent($tokens, $i, -1);
            $stack[] = ($nameIdx !== null
                && $tokens[$nameIdx]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]))
                ? $tokens[$nameIdx]->text
                : null;

            continue;
        }

        foreach (str_split($token->text) as $char) {
            if ($char === '(' || $char === '[' || $char === '{') {
                $stack[] = null;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($stack === []) {
                    $underflow++;
                } else {
                    array_pop($stack);
                }
            }
        }
    }

    return [$enclosing, $underflow];
}

/**
 * Scan $files and bucket every occurrence of the void status in either spelling.
 *
 * @param  list<string>  $files  absolute paths
 * @return array<string, mixed>
 */
function voidWriterScan(array $files): array
{
    $root = dirname(__DIR__, 2).'/';

    $out = [
        'examined' => 0,
        'comment' => 0,
        'writes' => [],
        'comparison' => [],
        'readerArgument' => [],
        'unlistedCall' => [],
        'unrecognised' => [],
        'dynamicCase' => [],
        'literalVoidNotStatus' => [],
        'rawSqlNamingVoid' => [],
        'unbalanced' => [],
    ];

    foreach ($files as $file) {
        $relative = str_replace($root, '', $file);
        $tokens = PhpToken::tokenize(file_get_contents($file));
        $count = count($tokens);
        [$enclosing, $underflow] = voidWriterCallScopes($tokens);

        if ($underflow > 0) {
            $out['unbalanced'][] = ['file' => $relative, 'underflow' => $underflow];
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $out['examined']++;

            // ── EXCLUDED WITH A REASON: prose cannot write a row. Counted rather than skipped, so
            // the bucket is a number in the report and not an invisible hole.
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                if (voidWriterProseNamesIt($token->text)) {
                    $out['comment']++;
                }

                continue;
            }

            // ── A STRING THAT NAMES THE COLUMN AGAINST THE LITERAL. Judged as a LIST, not as a
            // verdict — see voidWriterPermittedRawSqlSites(). Checked before the bare-literal
            // branch below; the two are disjoint (a bare `'void'` carries no `status =`).
            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML])
                && preg_match('/status\s*=\s*[\'"]?void\b/i', $token->text)) {
                $out['rawSqlNamingVoid'][] = ['file' => $relative, 'line' => $token->line];

                continue;
            }

            // ── THE ENUM'S BACKING VALUE SPELLED AS A STRING. An occurrence only when the KEY is
            // `status` — the token before the `=>`, the `=`, or the `,`. The COMMA case is
            // `setAttribute('status', 'void')`, which the first version of this file did not read.
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && voidWriterLiteralValue($token->text) === 'void') {
                $beforeIdx = voidWriterAdjacent($tokens, $i, -1);
                $role = voidWriterRole($beforeIdx === null ? null : $tokens[$beforeIdx]);
                $keyIdx = ($beforeIdx === null || ! in_array($role, ['arrow', 'assign', 'separator'], true))
                    ? null
                    : voidWriterAdjacent($tokens, $beforeIdx, -1);
                $key = $keyIdx === null ? null : voidWriterLiteralValue($tokens[$keyIdx]->text);

                if ($key !== 'status') {
                    $out['literalVoidNotStatus'][] = [
                        'file' => $relative,
                        'line' => $token->line,
                        'key' => $key ?? '(none)',
                    ];

                    continue;
                }

                $verdict = voidWriterVerdict($role, $enclosing[$i]);
                $out[$verdict === 'write' ? 'writes' : $verdict][] = [
                    'file' => $relative,
                    'line' => $token->line,
                    'text' => trim($token->text),
                    'via' => 'status-string',
                    'role' => $role ?? '(none)',
                    'call' => $enclosing[$i] ?? '(none)',
                ];

                continue;
            }

            // ── THE ENUM CASE. Anchored on the `::`, so the name before it and the case after it
            // are both read, and neither is guessed from a substring.
            if (! $token->is(T_DOUBLE_COLON)) {
                continue;
            }

            $nameIdx = voidWriterAdjacent($tokens, $i, -1);

            if ($nameIdx === null
                || ! $tokens[$nameIdx]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                || ! preg_match('/(^|\\\\)InvoiceStatus$/', $tokens[$nameIdx]->text)) {
                continue;
            }

            $caseIdx = voidWriterAdjacent($tokens, $i, 1);
            $case = $caseIdx === null ? null : $tokens[$caseIdx];

            // `InvoiceStatus::class` lexes as T_CLASS and names no case. Anything else that is not
            // a plain identifier — `InvoiceStatus::{$x}`, `::$prop` — is a member access this
            // scanner cannot read, and is reported rather than skipped.
            if ($case === null || ! $case->is(T_STRING)) {
                if ($case !== null && $case->is(T_CLASS)) {
                    continue;
                }

                $out['dynamicCase'][] = [
                    'file' => $relative,
                    'line' => $token->line,
                    'text' => $case === null ? '(end of file)' : trim($case->text),
                ];

                continue;
            }

            // A case RESOLVED AT RUNTIME could be Void and this rule cannot tell. Bucketed, not
            // skipped — the "handed no input" shape from CLAUDE.md, one construct wide.
            if (in_array($case->text, ['from', 'tryFrom', 'cases'], true)) {
                $out['dynamicCase'][] = [
                    'file' => $relative,
                    'line' => $token->line,
                    'text' => 'InvoiceStatus::'.$case->text,
                ];

                continue;
            }

            if ($case->text !== 'Void') {
                continue;
            }

            $beforeIdx = voidWriterAdjacent($tokens, $nameIdx, -1);
            $role = voidWriterRole($beforeIdx === null ? null : $tokens[$beforeIdx]);
            $verdict = voidWriterVerdict($role, $enclosing[$nameIdx]);

            $out[$verdict === 'write' ? 'writes' : $verdict][] = [
                'file' => $relative,
                'line' => $case->line,
                'text' => 'InvoiceStatus::Void',
                'via' => 'enum-case',
                'role' => $role ?? '(none)',
                'call' => $enclosing[$nameIdx] ?? '(none)',
            ];
        }
    }

    return $out;
}

/**
 * Every .php under app/.
 *
 * @return list<string>
 */
function voidWriterAppFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS)
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
 * Not under app/, deliberately: the first arm scans that directory for real, so a probe living
 * there — even for milliseconds — is a file the gate could read as production code, and a crashed
 * run would leave it behind for the next reader to find.
 */
function voidWriterScanSource(string $body): array
{
    $path = sys_get_temp_dir().'/void_writer_probe_'.Str::random(12).'.php';
    file_put_contents($path, $body);

    try {
        return voidWriterScan([$path]);
    } finally {
        @unlink($path);
    }
}

/** @return list<string> distinct files carrying a production, sorted. */
function voidWriterFilesWithWrites(array $scan): array
{
    $files = array_values(array_unique(array_column($scan['writes'], 'file')));
    sort($files);

    return $files;
}

it('produces InvoiceStatus::Void in exactly the classes on the permitted list', function () {
    $files = voidWriterAppFiles();

    // THE DENOMINATOR, ASSERTED BEFORE ANY VERDICT. A scan of no files satisfies "no unexpected
    // writers" perfectly — the "handed no input" failure from CLAUDE.md § gates. A LITERAL floor,
    // not one derived from the scan, which would assert the scan equals itself.
    expect(count($files))->toBeGreaterThan(500);

    $scan = voidWriterScan($files);

    // ── THE THIRD NUMBER, ASSERTED FIRST BECAUSE IT INVALIDATES EVERYTHING BELOW IT. A production
    // position with no rule, or a case resolved at runtime, is a site the verdict never judged.
    expect($scan['unrecognised'])->toBe([]);
    expect($scan['dynamicCase'])->toBe([]);

    // A CALL THE VOCABULARY DOES NOT COVER is a site the verdict could not justify either way.
    // Asserted zero alongside the other two, and for the same reason: the danger is never the
    // bucket that fills, it is the one that quietly does not.
    expect($scan['unlistedCall'])->toBe([]);

    // A walker that lost its place would mis-attribute every call after the point it lost it, and
    // would do so while reporting a clean run. Cheap, and it is the only signal that the enclosing
    // call names below it are trustworthy at all.
    expect($scan['unbalanced'])->toBe([]);

    // The raw-SQL bucket is judged as a LIST rather than as a verdict, because a WHERE and a SET
    // are indistinguishable here. A new entry reds and gets read by a human.
    $sqlFiles = array_values(array_unique(array_column($scan['rawSqlNamingVoid'], 'file')));
    sort($sqlFiles);
    expect($sqlFiles)->toBe(voidWriterPermittedRawSqlSites());

    // ── THE VERDICT. SET EQUALITY, NOT CONTAINMENT, AND THAT IS THE ANTI-VACUOUS HALF: a permitted
    // list satisfied by NOTHING is a green that proves nothing, and `⊆` would give exactly that if
    // the write were deleted. Equality also forbids a permitted entry that no longer writes, so the
    // list cannot rot into a wish.
    expect(voidWriterFilesWithWrites($scan))->toBe(voidWriterPermittedFiles());

    // AND THE IDENTITY BEHIND THE PATH. The rule is about a CLASS; the scanner works in files. This
    // ties the two, so moving or renaming the action reds instead of silently re-pointing the pin.
    foreach (voidWriterPermittedClasses() as $path => $fqcn) {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $namespace = substr($fqcn, 0, strrpos($fqcn, '\\'));
        $class = substr($fqcn, strrpos($fqcn, '\\') + 1);

        expect($source)->toContain('namespace '.$namespace.';')
            ->and($source)->toMatch('/\bclass '.preg_quote($class, '/').'\b/');
    }
});

it('does not count the value named in a comment, which is where it is explained', function () {
    // THE ARM THAT EARNS THE TOKENISER OVER A GREP — without it this file is an expensive substring
    // search. And it is not hypothetical: app/ carries the value in prose today, including the
    // sentence in ApproveVoidRequest explaining that flipping to 'void' releases the F7 slot.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    class Explains
    {
        /**
         * The approval writes `'status' => InvoiceStatus::Void` and posts the reversal.
         */
        public function noop(): void
        {
            // Flipping to 'void' also releases the F7 slot NOW (not before).
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['comment'])->toBe(2)
        ->and($scan['unrecognised'])->toBe([])
        ->and($scan['dynamicCase'])->toBe([]);
});

it('reds on a second producer written as an ORM update, which is the shape that exists', function () {
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance\Actions;

    use App\Finance\Enums\InvoiceStatus;

    class CorrectInvoice
    {
        public function handle($invoice): void
        {
            $invoice->update([
                'status' => InvoiceStatus::Void,
                'cancelled_at' => now(),
            ]);
        }
    }
    PHP);

    expect($scan['writes'])->toHaveCount(1)
        ->and($scan['writes'][0]['via'])->toBe('enum-case')
        ->and($scan['unrecognised'])->toBe([]);
});

it('reds on a producer that launders the value through a variable first', function () {
    // THE HOLE THE SIBLING HAS TO DECLARE, CLOSED HERE. `FinanceRefusalsNameNoInternalIdentifiers`
    // keys on an argument expression and so cannot see `$m = …; throw new X($m);`. Keying on the
    // PRODUCTION rather than on the call means the assignment itself is the trip.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance\Actions;

    use App\Finance\Enums\InvoiceStatus;

    class Launders
    {
        public function handle($invoice): void
        {
            $status = InvoiceStatus::Void;

            $invoice->update(['status' => $status]);
        }
    }
    PHP);

    expect($scan['writes'])->toHaveCount(1)
        ->and($scan['writes'][0]['via'])->toBe('enum-case')
        ->and($scan['unrecognised'])->toBe([]);
});

it('reds on a producer that spells the backing value as a string', function () {
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance\Actions;

    class SpellsIt
    {
        public function handle($invoice): void
        {
            $invoice->update(['status' => 'void']);
            $invoice->status = 'void';
        }
    }
    PHP);

    expect($scan['writes'])->toHaveCount(2)
        ->and(array_column($scan['writes'], 'via'))->toBe(['status-string', 'status-string'])
        ->and($scan['unrecognised'])->toBe([]);
});

it('does NOT red on the same literal under a different key, which the scanned tree carries', function () {
    // THE KNOWN-NEGATIVE FOR THE STRING MARKER. `VoidRequestResource` writes `'type' => 'void'` as
    // the discriminator of the unified approvals queue, and the enum itself declares
    // `case Void = 'void'`. A gate that refuses everything is indistinguishable from a strict one
    // right up until somebody disables it — so the free arm matters more here than the strict one.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    enum Probe: string
    {
        case Void = 'void';
    }

    class Resource
    {
        public function toArray(): array
        {
            return ['type' => 'void'];
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['literalVoidNotStatus'])->toHaveCount(2)
        ->and(array_column($scan['literalVoidNotStatus'], 'key'))->toBe(['void', 'type'])
        ->and($scan['unrecognised'])->toBe([]);
});

it('does NOT red on a comparison, which is five of the six occurrences in the tree', function () {
    // THE ARM THAT SAYS A WRITE IS NOT AN OCCURRENCE. All four spellings of comparison plus the
    // reader argument, in the shapes app/ actually carries.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Finance\Enums\InvoiceStatus;

    class Compares
    {
        public function isVoid($invoice): bool
        {
            return $invoice->status === InvoiceStatus::Void;
        }

        public function notVoid($invoice): bool
        {
            $flag = $invoice->status !== InvoiceStatus::Void;

            return $flag && $invoice->status != InvoiceStatus::Void && $invoice->status == InvoiceStatus::Void;
        }

        public function scopeExcludingVoid($query)
        {
            return $query->where('status', '!=', InvoiceStatus::Void->value);
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['comparison'])->toHaveCount(4)
        ->and($scan['readerArgument'])->toHaveCount(1)
        ->and($scan['readerArgument'][0]['call'])->toBe('where')
        ->and($scan['unlistedCall'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('routes a production position it cannot classify into unrecognised rather than into silence', function () {
    // THE THIRD BUCKET, PROVED BY THE SHAPE THAT MOTIVATES IT. A ternary IS a production and this
    // classifier has no rule for `?` — so rather than guess, it reds and costs a reviewed line.
    // Without an arm here, the first arm's `expect($scan['unrecognised'])->toBe([])` could be green
    // because the bucket never fills.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Finance\Enums\InvoiceStatus;

    class Ternary
    {
        public function handle($invoice, $flag): void
        {
            $invoice->update(['status' => $flag ? InvoiceStatus::Void : InvoiceStatus::Issued]);
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['unrecognised'])->toHaveCount(1)
        ->and($scan['unrecognised'][0]['role'])->toBe('(none)')
        ->and($scan['unrecognised'][0]['call'])->toBe('update');
});

it('reports a case resolved at runtime rather than skipping it', function () {
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Finance\Enums\InvoiceStatus;

    class Dynamic
    {
        public function handle($invoice, string $raw): void
        {
            $invoice->update(['status' => InvoiceStatus::from($raw)]);
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['dynamicCase'])->toHaveCount(1)
        ->and($scan['dynamicCase'][0]['text'])->toBe('InvoiceStatus::from');
});

it('does NOT report ::class or another case, which are knowably not it', function () {
    // THE KNOWN-NEGATIVE FOR dynamicCase. Its positive arm above proves the bucket fills; without
    // this one, a bucket that filled on EVERY `InvoiceStatus::` would satisfy that arm perfectly
    // and red the gate on correct code — and `app/` carries `InvoiceStatus::Issued` today.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Finance\Enums\InvoiceStatus;

    class Benign
    {
        public function handle($invoice): void
        {
            $invoice->update([
                'status' => InvoiceStatus::Issued,
                'kind' => InvoiceStatus::class,
            ]);
        }
    }
    PHP);

    expect($scan['dynamicCase'])->toBe([])
        ->and($scan['writes'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('catches a raw SQL string that sets the column, which no token walker can tell from a WHERE', function () {
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use Illuminate\Support\Facades\DB;

    class RawSql
    {
        public function handle(int $id): void
        {
            DB::statement("UPDATE finance_invoices SET status = 'void' WHERE id = ?", [$id]);
        }
    }
    PHP);

    // Not a `write` — the bucket is a LIST that a human reads, and that is the declared design.
    expect($scan['rawSqlNamingVoid'])->toHaveCount(1)
        ->and($scan['writes'])->toBe([]);
});

it('reds on the POSITIONAL setters that defeated the first version of this gate', function () {
    // THE REGRESSION ARM. A cold review defeated the shipped gate with three spellings, all three
    // reproduced on this tree before anything was changed: `setAttribute('status',
    // InvoiceStatus::Void)` landed in the argument bucket, `setAttribute('status', 'void')` in the
    // not-a-status-key bucket, and `data_set($i, 'status', InvoiceStatus::Void)` in the argument
    // bucket — three clean runs, `unrecognised` zero in every one.
    //
    // The common cause was ORDERING rather than any missing spelling: `,` claimed the occurrence as
    // "an argument, therefore benign" before anything asked what it was an argument TO. All three
    // are the same fix, which is why they are one arm.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance\Actions;

    use App\Finance\Enums\InvoiceStatus;

    class Positional
    {
        public function handle($invoice): void
        {
            $invoice->setAttribute('status', InvoiceStatus::Void);
            $invoice->setAttribute('status', 'void');
            data_set($invoice, 'status', InvoiceStatus::Void);
        }
    }
    PHP);

    expect($scan['writes'])->toHaveCount(3)
        ->and(array_column($scan['writes'], 'via'))->toBe(['enum-case', 'status-string', 'enum-case'])
        ->and(array_column($scan['writes'], 'call'))->toBe(['setAttribute', 'setAttribute', 'data_set'])
        ->and(array_column($scan['writes'], 'role'))->toBe(['separator', 'separator', 'separator'])
        ->and($scan['readerArgument'])->toBe([])
        ->and($scan['literalVoidNotStatus'])->toBe([])
        ->and($scan['unlistedCall'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('does NOT red on a READER in argument position, which is the false-red this fix could have shipped', function () {
    // THE FREE ARM FOR THE FIX ITSELF, AND IT IS NOT OPTIONAL. The change makes the enclosing call
    // decide, and the lazy version of that change — "an occurrence in an argument list is a
    // production" — would red on `Invoice::scopeExcludingVoid()`, which is CORRECT CODE that has
    // been in the tree since before this gate existed.
    //
    // The real-tree arm would also catch that, by failing. This arm catches it ON PURPOSE and says
    // which property broke, which is the difference between a gate and an accident: a fix that
    // replaces a false green with a false red has not improved anything, and a gate that reds on
    // correct code is one bad afternoon from being deleted.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Finance\Enums\InvoiceStatus;

    class Reads
    {
        public function scopeExcludingVoid($query)
        {
            return $query->where('status', '!=', InvoiceStatus::Void->value);
        }

        public function onlyVoid($query)
        {
            return $query->whereIn('status', [InvoiceStatus::Void->value])
                ->orWhere('status', InvoiceStatus::Void)
                ->whereNot('status', 'void');
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['readerArgument'])->toHaveCount(4)
        ->and(array_column($scan['readerArgument'], 'call'))->toBe(['where', 'whereIn', 'orWhere', 'whereNot'])
        ->and($scan['unlistedCall'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});

it('routes a call the vocabulary does not cover into unlistedCall rather than into either verdict', function () {
    // THE FOURTH BUCKET, PROVED BY INJECTION-FREE MEANS: a method that is in neither list, which is
    // the state every method is in until somebody classifies it.
    //
    // It must not land in `writes` — that would assert `frobnicate` writes the row, which is a
    // claim this classifier cannot make and which would send the reader hunting a bypass that is
    // not there. It must not land in `readerArgument` either — that is the silence this whole
    // revision exists to remove. It reds, and it reds saying what is actually wrong.
    $scan = voidWriterScanSource(<<<'PHP'
    <?php

    namespace App\Finance;

    use App\Finance\Enums\InvoiceStatus;

    class Unknown
    {
        public function handle($invoice): void
        {
            $invoice->frobnicate('status', InvoiceStatus::Void);
        }
    }
    PHP);

    expect($scan['writes'])->toBe([])
        ->and($scan['readerArgument'])->toBe([])
        ->and($scan['unlistedCall'])->toHaveCount(1)
        ->and($scan['unlistedCall'][0]['call'])->toBe('frobnicate')
        ->and($scan['unrecognised'])->toBe([]);
});

it('attributes the enclosing call through an attribute and an interpolation, which truncate a text-matched walker', function () {
    // THE SIBLING'S DEFECT, PRE-EMPTED. `#[Attr]` is T_ATTRIBUTE with text `#[` and `${k}` is
    // T_DOLLAR_OPEN_CURLY_BRACES with text `${`; both close with a bare `]`/`}`. A walker comparing
    // token TEXT to bracket characters takes a closer it never counted an opener for, the stack
    // runs one short, and every enclosing-call name after that point is wrong — silently, and in
    // the direction that turns a mutator into "no call at all".
    //
    // Built by concatenation because `${…}` is a PARSE ERROR on PHP 8.4: the source is assembled at
    // runtime so only the TOKENISER sees it, and on a version that no longer produces that token
    // the arm still passes, because `{$k}` and `${k}` are then the same stream.
    $body = '<?php'."\n\n"
        .'namespace App\Finance;'."\n\n"
        .'use App\Finance\Enums\InvoiceStatus;'."\n\n"
        .'class Brackets'."\n"
        .'{'."\n"
        .'    public function handle($invoice, $k): void'."\n"
        .'    {'."\n"
        .'        $label = (#[Foo] fn () => "a")();'."\n"
        .'        $note = "x ${k} y";'."\n"
        .'        $invoice->setAttribute(\'status\', InvoiceStatus::Void);'."\n"
        .'    }'."\n"
        .'}'."\n";

    $scan = voidWriterScanSource($body);

    expect($scan['writes'])->toHaveCount(1)
        ->and($scan['writes'][0]['call'])->toBe('setAttribute')
        ->and($scan['unbalanced'])->toBe([])
        ->and($scan['unlistedCall'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);
});
