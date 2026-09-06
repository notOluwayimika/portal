# Implementation report — `feat/pin-the-single-writer-of-invoice-void`

**Branch:** `feat/pin-the-single-writer-of-invoice-void` off `staging` @ `064de707` (confirmed clean
at start; `git ls-remote origin` for this branch returns nothing, against a control —
`refs/heads/staging` — that resolves to `064de707`). **ONE commit** on top of `064de707`, carrying
the test, this report and the two tickets it raises. **Not pushed.**

## Headline

`tests/Arch/InvoiceVoidHasOneWriterTest.php` pins the producers of `InvoiceStatus::Void` to an
explicit permitted list holding one entry, `App\Finance\Actions\ApproveVoidRequest`. **Fifteen arms,
67 assertions, all green.**

**A cold review defeated the first version of this gate and the defeat is reproduced here before the
fix**: three spellings of a direct void write — two `setAttribute` forms and `data_set` — each
reported a clean run with `unrecognised` zero. The cause was ORDERING rather than a missing spelling:
a `,` meant *an argument, therefore benign*, decided before anything asked what it was an argument
**to**. The fix inverts that default, so benign must now be positively earned; a call in neither the
mutator nor the reader list lands in a fourth bucket asserted ZERO rather than passing.

**The report's own central claim was also false and has been withdrawn.** An earlier version said the
sibling gate's truncation defect was "absent by construction" here. Reading only adjacent tokens was
not a guarantee — it was the hole the three spellings walked through. No second structural guarantee
replaces it; the docblock and the report now state what the classifier positively recognises, what it
reds on, and what remains OPEN.

All five bite-proofs were run on the tree that is committed.

**No file under `app/` was touched.** No action, no migration, no route, no config.

## Tier — FULL, and I do not think it is TARGETED

The brief assigns FULL on the grounds that a new enforcement gate is on the always-full list. I
agree, and would argue it independently on three counts:

1. **A gate's first green is the least trustworthy green it will ever produce** (CLAUDE.md §
   gates) — nobody has yet established what the instrument cannot see. A TARGETED pass would report
   "11 passed" and stop, which is precisely the unfalsifiable clean run that entry is about. The
   three-number report and the declared-exclusion table below are the work that makes the green mean
   something, and they are FULL-tier work.
2. **The rule is stated as a NEGATIVE** — "nothing else writes this". CLAUDE.md's absence rule
   applies directly: an absence claim requires an exhaustive search, and this file IS that search,
   mechanised. Its denominator therefore has to be asserted, not assumed.
3. **It is a precondition of other people's work.** The correction mechanism will add the second
   entry to this list. If the gate is loose, that commit inherits the looseness with a green.

The one argument for TARGETED — "it is one test file, it touches no production code" — is a claim
about blast radius, not about how much can be wrong. Everything that can be wrong here is wrong
silently.

## Deviations from the brief and from the ticket, each argued

### 1. The rule judges PRODUCTION, which is wider than "writer". Declared, not slipped in.

The ticket says "writer" and the shape in the tree is an ORM write. This file judges every position
where the value is **produced into a slot** — an array value (`=>`), an assignment (`=`), or a
`return` — and the docblock's second section says so under the heading `WHAT IS PINNED: PRODUCTION,
NOT "THE WRITE"`.

The reason is not tidiness. A rule keyed on the `update()` call has the laundering hole the sibling
`FinanceRefusalsNameNoInternalIdentifiersTest` has to **declare open**: it cannot see
`$m = …; throw new X($m);`. Keying on production closes the analogous hole here —
`$status = InvoiceStatus::Void;` is itself a bare `=` and trips the gate one line earlier, before it
reaches any `update()`. There is a green arm for exactly this (`reds on a producer that launders the
value through a variable first`).

It costs nothing today: of the six occurrences under `app/`, one is a production and five are not, so
the wide rule and the narrow rule currently name the same single file.

**I have deliberately not called it "the write pin" anywhere in the file or the commit**, because a
claim wider than its artifact is the defect CLAUDE.md's acknowledgment-vs-staleness-gate entry is
about, and here the claim would be *narrower* than the artifact — the same error mirrored.

### 2. A SECOND MARKER the ticket does not ask for: the backing value spelled as a string.

`'status' => 'void'` and `$invoice->status = 'void'` write the same row and name no enum. The
ticket's rule would not see them. A bypass spelled this way would arrive unreviewed for exactly the
reason the ticket exists, so the marker is closed rather than declared.

It is keyed on **the key being `status`**, which is what keeps it from becoming a nuisance: the
scanned tree carries `'type' => 'void'` in `VoidRequestResource.php:34` (the discriminator for the
unified approvals queue) and `case Void = 'void';` in `InvoiceStatus.php:21`, and both must stay
green. There is a known-negative arm asserting exactly that, in addition to the positive one.

### 3. Raw SQL is judged as a PINNED LIST, not as a verdict.

`… status = 'void' …` inside a string cannot be told apart from `WHERE … status = 'void'` without a
SQL parser. Rather than guess in either direction, every such string is collected and the **list** is
pinned against `voidWriterPermittedRawSqlSites()`, which holds the one comparison that exists
(`AuditLedgerCoherence.php:216 (checkVoidHasOneMatchingReversal)`, the I4 query). An
`UPDATE finance_invoices SET status = 'void'` written tomorrow arrives as a new entry and reds, and a
human reads it. There is an arm planting exactly that statement.

### 4. Bite-proof 3 (zero producers) — the brief asked whether the ticket requests it. It does not; I added it.

The ticket asks for the planted-write arm and the comment arm and stops. The brief is right that a
permitted list satisfied by nothing is a vacuous green. **I did not add a third assertion for it** —
I chose the assertion shape that makes the arm unnecessary to bolt on: the verdict is **set equality**
between the files carrying a production and the permitted list, not containment. Containment (`⊆`)
is satisfied by the empty set; equality is not. The same choice also forbids a permitted entry that
no longer produces anything, so the list cannot rot into a wish. The arm was still run, as bite-proof
3 below.

### 5. Path is tied to FQCN.

The rule is about a *class*; a token scanner works in *files*. The first arm additionally asserts
that `app/Finance/Actions/ApproveVoidRequest.php` declares `namespace App\Finance\Actions;` and
`class ApproveVoidRequest`, so moving or renaming the action reds rather than silently re-pointing
the pin at whatever now occupies that path.

## The cold review defeated the first version, and the fix went past its stated minimum

**The finding, quoted:**

> `$invoice->setAttribute('status', InvoiceStatus::Void)`   -> bucket "passed"
> `$invoice->setAttribute('status', 'void')`                -> bucket "literalVoidNotStatus"
> `data_set($invoice, 'status', InvoiceStatus::Void)`       -> bucket "passed"

**All three reproduced on this tree before anything was changed**, each planted as production code
in a second file under `app/Finance/`, each run against the committed gate. Bucket deltas, and the
gate's verdict:

| planted spelling | gate | bucket that absorbed it | `unrecognised` |
| --- | --- | --- | --- |
| `$invoice->setAttribute('status', InvoiceStatus::Void);` | **PASSED** 11/11, 39 assertions | `passed` 1 → **2** | 0 |
| `$invoice->setAttribute('status', 'void');` | **PASSED** 11/11, 39 assertions | `literalVoidNotStatus` 2 → **3** | 0 |
| `data_set($invoice, 'status', InvoiceStatus::Void);` | **PASSED** 11/11, 39 assertions | `passed` 1 → **2** | 0 |

The review's evidence and this tree agree exactly, including the bucket names. Tokens examined moved
each time (361,240 → 361,253 / 361,251 / 361,254), so the planted code was read and classified; it
was not missed, it was **excused**.

### The cause was ORDERING, and that is why the fix is not three more spellings

The classifier read the ONE token before the occurrence and stopped. A `,` meant *an argument,
therefore benign* — decided before anything asked what it was an argument **to**. Adding
`setAttribute` and `data_set` to some list would have left the next positional setter exactly as
invisible, because the default was benign and the default is what was wrong.

**The fix inverts the default.** An occurrence is benign only if it can be POSITIVELY justified:

- an operand of `===` / `!==` / `==` / `!=`, which cannot write a row whatever encloses it; or
- an argument to a call on the **reader list**, every member of which is incapable of writing.

Everything else is a production judged against the permitted list, or one of four buckets asserted
ZERO. `voidWriterVerdict()` is the whole change; the two method lists are bookkeeping under it.

### Past the stated minimum, and why

The review named three spellings. Fixing exactly those three would have been a **partial fix to a
gate**, which CLAUDE.md records as *worse than the gap* — it converts a known blind spot into an
unknown one. So the commit goes further, in four places the review did not ask for:

1. **A bracket-character stack** (`voidWriterCallScopes()`) rather than a nearest-token guess, so
   the enclosing call is known for every occurrence at any nesting depth.
2. **A fourth bucket, `unlistedCall`**, so a method in neither list reds instead of falling to a
   default — this is what makes a list of method names safe to be a list at all.
3. **An `unbalanced` bucket**, so a stack that lost its place says so instead of mis-attributing
   every call after it.
4. **The `#[` / `${` arm**, pre-empting the sibling's own truncation defect in the walker this
   commit introduces, rather than waiting to be bitten by it a second time.

## The positional-setter family, measured

The review named three spellings. The family is larger, and the counts are live usage under `app/`
on `064de707`. **The measuring instrument was itself wrong first and is reported as such:** the
first pass used `grep -E '(->|::|\b)name\('`, and BSD `grep -E` does not support `\b`, so every
pattern in that form matched nothing and returned **0 for `where` as well as for `setAttribute`** —
a table of zeros that read as "none of this family exists here". It was caught by a positive control
(`->where(` must be > 0) and an absent control (`->zzzNotAMethod(` must be 0). The numbers below are
from the re-measurement.

| member | live under `app/` | state in the gate |
| --- | --- | --- |
| `->forceFill(` | **31** | mutator — production |
| `->setAttribute(` | **2** | mutator — production |
| `->fill(` | **1** | mutator — production |
| `->setRawAttributes(` | 0 | mutator — listed anyway |
| `->offsetSet(` | 0 | mutator — listed anyway |
| `->setAttributeValue(` | 0 | mutator — listed anyway |
| `data_set(` | 0 | mutator — listed anyway; it is one of the review's three |
| `data_fill(` | 0 | mutator — listed anyway |
| `Arr::set(` | 0 | mutator (`set`) — listed anyway |

The array-shaped write family, same measurement: `->update(` **142**, `->create(` **96**, `->save(`
**43**, `->firstOrCreate(` **38**, `->make(` **26**, `->updateOrCreate(` **13**, `->insert(` **5**,
`->insertOrIgnore(` **4**, `->updateQuietly(` **1**, `->upsert(` **1**; zero for `updateOrInsert`,
`forceCreate`, `firstOrNew`, `insertGetId`, `saveQuietly`, `increment`, `decrement` — all listed.

The reader family, which is the expensive list: `->where(` **867**, `->whereNull(` **87**,
`->whereIn(` **76**, `->whereHas(` **53**, `->orWhere(` **45**, `->whereNotNull(` **33**,
`->whereColumn(` **13**, `->whereRaw(` **10**, `->whereNotIn(` **8**, `->whereDoesntHave(` **6**,
`->orWhereIn(` **2**, `in_array(` **58**; zero for `whereNot`, `orWhereNot`, `orWhereNotIn`,
`having`, `orHaving`.

**`->where([` is ZERO under `app/`** — measured, because an array passed to a reader would have put
a `'status' => …` arrow inside a benign call, and the arrow rule would have called it a production.
It is handled by method awareness anyway; the measurement says the hazard is not live today.

## A method name in neither list: `unlistedCall`, asserted ZERO — not a violation

**The decision, and it is the substance of the fix rather than the lists.** A call in neither list
reds either way, so this is not a safety choice; it is a choice about what the failure **says**.

Calling it a violation would assert that `$x->frobnicate('status', InvoiceStatus::Void)` **writes
the row** — a claim the classifier does not have and which may be false. The reader then goes
hunting a void bypass that is not there, and the cheapest way to make the red go away is to argue
the method is harmless, which is the wrong conversation.

`unlistedCall` asserts only what is true: *the vocabulary does not cover this call*. The remedy it
implies is the correct one — put the name in one of the two lists, deliberately, which is the same
reviewed-line discipline as the permitted list itself.

It is also the repository's own standard applied one layer down. A gate reports **unrecognised** as
its own bucket rather than folding it into *skipped*; an absence must not be rendered as a value.
*"I cannot classify this"* and *"this is a violation"* are two states, and collapsing them destroys
the one that tells the next reader what to do.

**Over-inclusion is therefore the cheap direction for the MUTATOR list and the expensive one for the
READER list**, and the two are sized accordingly: a method wrongly in the mutator list produces a
red that names a file; a method wrongly in the reader list produces a **silent green**, which is the
defect this whole revision exists to remove.

### 6. METHOD AWARENESS, which neither the ticket nor the original brief asked for.

The ticket asks for a token-based pin on `InvoiceStatus::Void`. What ships also reads the ENCLOSING
CALL, from a bracket-character stack, and decides from role **and** call together. That is a larger
instrument than the ticket describes and it is here because the ticket's instrument was measured
losing to three ordinary spellings. The argument is in the review section above; the residual is in
the NOT-JUDGED table.

## Contradictions of the premise

**The brief and the ticket are both right about the substance; two of the ticket's numbers have
moved, and one of its labels is coarser than what I measure.** Nothing in either was found to be
false.

| claim | ticket (`ca8dbc45`) | brief | **measured here (`064de707`)** |
| --- | --- | --- | --- |
| `.php` files under `app/` | 632 | 634 | **634** — agrees with the brief |
| occurrences of `InvoiceStatus::Void` under `app/` | 6 | 6 | **6** |
| of those, productions | 1 | 1 | **1** |
| `ReturnInvoice.php` guard | `:174` | — | **`:175`** |
| `ApproveInvoice.php` guard | `:155` | — | **`:156`** |
| ledger post below the status write | "eight lines" | — | **six** (`:76` and `:82`) |
| `Invoice.php:217` | "comparison (`scopeExcludingVoid`)" | — | **argument** — it passes `->value` into `where()`, it is not an operand of a comparison operator. Same site, finer label. |

The ticket's other supporting citations were re-derived and hold: `checkVoidHasOneMatchingReversal`
is at `AuditLedgerCoherence.php:216` (the ticket's `215-216` window contains it), it is invoked at
`:106`, and it is scheduled at `routes/console.php:127`.

## What is counted as a production, and what is not

An occurrence is classified from **two** facts, not one: its POSITIONAL ROLE (the single significant
token before it) and its ENCLOSING CALL (the nearest call whose argument list it sits in, from a
bracket-character stack). Role alone was the first version's whole answer and is what the review
defeated.

| role | enclosing call | verdict |
| --- | --- | --- |
| `=` (bare), `return` | any | **production** — the value now exists in a slot |
| `===` `!==` `==` `!=` | any | benign: **comparison** |
| `=>`, `,` `(` `[` | on the **mutator** list | **production** |
| `=>`, `,` `(` `[` | on the **reader** list | benign: **readerArgument** |
| `=>`, `,` `(` `[` | in **neither** list | **`unlistedCall`**, asserted ZERO |
| `=>` | none (bare array literal) | **production** — last point the value is visible |
| `,` `(` `[` | none | **`unrecognised`**, asserted ZERO |
| anything else | any | **`unrecognised`**, asserted ZERO |

**Two exits do not refuse, and both must be earned:** an operand of an equality operator, or an
argument to a call that cannot write. There is no path on which an occurrence the classifier cannot
positively justify becomes silence. That inversion is the fix.

### WHAT IS NOT JUDGED — the table, rewritten, with the positional-setter family in it whatever its state

An undeclared blind spot is worse than a declared one. Each row is CLOSED, BUCKETED AND ASSERTED
ZERO, or NAMED OPEN. The setter family is listed **including the members now caught**, so this is a
map of the decision rather than of the leftovers.

| shape | state |
| --- | --- |
| **positional setters** — `setAttribute`, `setRawAttributes`, `setAttributeValue`, `offsetSet`, `fill`, `forceFill`, `data_set`, `data_fill`, `set`, in either spelling of the value | **CLOSED** — mutator list. The three defeats and their family |
| **array-shaped writes** — `update`, `create`, `save`, `firstOrCreate`, `make`, `updateOrCreate`, `insert`, `upsert` and siblings | **CLOSED** — same mechanism |
| `$s = InvoiceStatus::Void;` then written elsewhere | **CLOSED** — a bare `=` is itself a production |
| `'status' => 'void'`, `$i->status = 'void'`, `setAttribute('status', 'void')` | **CLOSED** — string marker, keyed on the key being `status` |
| a call in **neither** method list | **BUCKETED** `unlistedCall`, asserted ZERO |
| `InvoiceStatus::from(…)` / `tryFrom(…)` / `cases()` / `::{$x}` | **BUCKETED** `dynamicCase`, asserted ZERO. Zero today |
| raw SQL naming the column against the literal | **BUCKETED** as a pinned list; a new site reds |
| a role with no rule — `?:`, a `match` arm label, a spread | **BUCKETED** `unrecognised`, asserted ZERO |
| a bracket stack that underflowed | **BUCKETED** `unbalanced`, asserted ZERO |
| **the STRING marker laundered through a variable** — `$s = 'void'; $i->update(['status' => $s]);` | **OPEN**. The ENUM marker closes this (bare `=` is a production); the STRING one cannot, because `$s = 'void'` has key `s`. Asymmetric, and stated |
| an import **alias** — `use … InvoiceStatus as S; S::Void` | **OPEN**. All seven imports under `app/` are unaliased (measured) |
| **a call through a variable** — `$i->$m('status', …)`, `call_user_func([$i,'setAttribute'], …)` | **OPEN**, and MEASURED because the guess was wrong in one of three: at method top level it lands in `unrecognised`; inside another call it lands in `unlistedCall` naming the OUTER call (`transaction`, not `$m`); `call_user_func` lands in `unlistedCall` naming `call_user_func`. All three RED — but as unclassifiable, and one names a call that is not the culprit |
| `DB::statement()` assembled at runtime from fragments no string token carries whole | **OPEN**. Nothing under `app/` does this to `finance_invoices` |

**Scope is `app/` and only `app/`.** `tests/` carries eight occurrences outside the new file, five
of them planting a void row to test something else. `database/migrations/` declares the column.

## The range walker this file now has, and the claim that has been withdrawn

**An earlier version of this report and of the test docblock said the sibling's truncation defect
was "absent by construction" here.** That sentence was true about the mechanism and **false as a
safety claim**, and the review is right that it is the worst thing in the commit — a report that
tells the next author a defect class is impossible is worse than one that says nothing, because it
stops them looking.

It is worse than that, and the correction is kept visible rather than deleted: **reading only
adjacent tokens is precisely WHY the three positional setters walked through.** The absence of a
range walker was not a guarantee. It was the hole.

**No second structural guarantee replaces it.** What can be said positively is bounded, and this is
all of it:

- **RECOGNISED** — an occurrence's role, and the name of the nearest enclosing call, tracked by
  counting bracket CHARACTERS rather than by enumerating token names. That is the sibling's *shipped*
  fix, not its first one, so `#[` (T_ATTRIBUTE, text `#[`) and `${` (T_DOLLAR_OPEN_CURLY_BRACES, text
  `${`) are handled without either being named — and an arm proves it on both, with the `${` case
  assembled at runtime so the file still lexes after PHP 8.4 removes that form.
- **REDS ON** — any production not on the permitted list; `unrecognised`; `unlistedCall`;
  `dynamicCase`; `unbalanced`. All four buckets asserted ZERO.
- **OPEN** — the four rows marked OPEN above. They are holes, they are named, and nothing here
  claims a class of defect is impossible.

### Why a list of METHOD names is acceptable where a list of TOKEN names was not

The sibling rejected a list of token kinds because PHP's lexical vocabulary is not this repository's
to control — `${` is gone in 8.4, and whatever joins `#[` later is in nobody's list.

These lists are different in kind: they are the vocabulary of Eloquent and of this codebase, and
they change only when somebody writes a call. But the difference that actually carries the weight is
not provenance — **it is that omission from these lists is not silent.** A token kind left out of the
sibling's original list vanished into a range that closed early. A method name left out of both of
these lands in `unlistedCall`, which is asserted zero and reds. Same lesson, applied rather than
copied.

## Proof

Every command below was run. `bin/db-exclusive` is the authority on test-database exclusivity and was
used for every suite invocation; no ad-hoc `ps | grep` preflight was written. No command's exit code
passes through a pipe.

### Step 0 — confirm HEAD

```
feat/pin-the-single-writer-of-invoice-void
b2164a80 test(finance): pin the single producer of InvoiceStatus::Void
064de707 Merge pull request #418 from notOluwayimika/fix/refusals-name-the-bill-and-the-person
(status --porcelain: empty)
TARGET  ls-remote refs/heads/feat/pin-the-single-writer-of-invoice-void  -> no ref line, exit 0
CONTROL ls-remote refs/heads/staging  -> 064de707c5a42172d80f9d8de43f3dbf68b22fc2, exit 0
```

### Step 1 — the measurement, with denominators and controls

```
FILES EXAMINED      : 634
TOKENS EXAMINED     : 361240
writes               : 1
comparison           : 4
readerArgument       : 1
literalVoidNotStatus : 2
rawSqlNamingVoid     : 1
unlistedCall         : 0
dynamicCase          : 0
unrecognised         : 0
unbalanced           : 0
comment (prose)      : 2
```

**Positive control on the file list:** `find app -name '*.php' -type f | wc -l` = **634**, identical
to the scanner's own count. **Absent control:** the first arm asserts `count($files) > 500` before it
asserts any verdict, so a scan that read nothing cannot report a clean run.

**The per-site table, re-derived, now carrying the ROLE and the ENCLOSING CALL** — the two facts the
verdict is built from, so the table shows the reasoning and not just the answer:

| site | role | enclosing call | verdict |
| --- | --- | --- | --- |
| `app/Finance/Actions/ApproveVoidRequest.php:76` | `arrow` | `update` | **PRODUCTION** |
| `app/Finance/Actions/ApproveInvoice.php:156` | `comparison` | `transaction` | comparison |
| `app/Finance/Actions/ReturnInvoice.php:175` | `comparison` | `transaction` | comparison |
| `app/Finance/Models/Invoice.php:203` | `comparison` | *(none)* | comparison |
| `app/Finance/Services/InvoiceSettlement.php:65` | `comparison` | *(none)* | comparison |
| `app/Finance/Models/Invoice.php:217` | `separator` | `where` | **readerArgument** — benign because `where` is on the reader list, not because it follows a comma |

The last row is the whole difference. Under the first version it was benign *because a comma
preceded it*; under this one it is benign *because it is an argument to a call that cannot write*.

| site | bucket | why it does not red |
| --- | --- | --- |
| `app/Finance/Enums/InvoiceStatus.php:21` | `literalVoidNotStatus` | `'void'` under key `void` — the enum's own declaration |
| `app/Finance/Http/Resources/VoidRequestResource.php:34` | `literalVoidNotStatus` | `'void'` under key `type` — the approvals-queue discriminator |
| `app/Finance/Console/AuditLedgerCoherence.php:219` | `rawSqlNamingVoid` | on the pinned list; a `WHERE`, not a `SET`. The line is the STRING TOKEN's start; the text sits at `:230` |
| two comments | `comment` | prose cannot write a row |

### Step 2 — the arms

```
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":67}
```

EXAMINED: **15 arms, 67 assertions** (was 11 / 39). **Six are free arms** — a comparison must not
red, a reader in argument position must not red, `InvoiceStatus::Issued` and `::class` must not red,
`'type' => 'void'` must not red, a comment must not red. A gate needs the free arm more than a test
does, because refusing everything looks like strictness right up until somebody disables it.

### Step 3 — the DEFEAT, reproduced against the COMMITTED gate before anything was changed

Each of the review's three spellings, planted as production code in
`app/Finance/Services/InvoiceSettlement.php`, run against `b2164a80`:

| planted | gate | bucket | `unrecognised` | tokens |
| --- | --- | --- | --- | --- |
| `setAttribute('status', InvoiceStatus::Void)` | **PASSED** 11/11, 39 | `passed` 1 → 2 | 0 | 361,240 → 361,253 |
| `setAttribute('status', 'void')` | **PASSED** 11/11, 39 | `literalVoidNotStatus` 2 → 3 | 0 | 361,240 → 361,251 |
| `data_set($invoice, 'status', InvoiceStatus::Void)` | **PASSED** 11/11, 39 | `passed` 1 → 2 | 0 | 361,240 → 361,254 |

Restored byte-exact, sha `54a8c5b3…`. **The review's evidence and this tree agree exactly, bucket
names included.** The tokens moved every time, so the planted code was read and classified — it was
not missed, it was excused.

### Step 4 — the five bite-proofs, on the tree that is committed

Each mutation applied to the working tree, `php -l`-checked, run, then restored with
`git checkout --` and verified **byte-exact by sha256**. Control run first: 15/15, 67 assertions.

| # | mutation | gate | EXAMINED (delta from the clean run) |
| --- | --- | --- | --- |
| 1 | `->update(['status' => InvoiceStatus::Void])` in a second file | **RED** exit 1, 14/15 | `writes` 1 → **2**; tokens 361,240 → 361,256 |
| 2 | the same text in a `//` comment and a docblock | **GREEN** 15/15, 67 assertions | `comment` 2 → **4**; tokens → 361,244; `writes` **unchanged at 1** |
| 3 | `ApproveVoidRequest`'s write deleted entirely | **RED** exit 1, 14/15 | `writes` 1 → **0**; tokens → 361,231 |
| 4 | `setAttribute('status', InvoiceStatus::Void)` | **RED** exit 1, 14/15 | `writes` 1 → **2**; tokens → 361,253 |
| 5 | `->where('status', …->value)->whereIn('status', […->value])` | **GREEN** 15/15, 67 assertions | `readerArgument` 1 → **3**; tokens → 361,277; `writes` **unchanged at 1** |

**Bite-proof 4 is shown failing before the fix**: the identical plant is row 1 of step 3 above, where
the committed gate reported `passed, 11/11`. Same source text, same file, same line; the only thing
that changed between the two runs is `voidWriterVerdict()`.

**Bite-proof 5 is the arm that stops this fix being a different defect.** The lazy version of
"the enclosing call decides" — *an occurrence in an argument list is a production* — would red on
`Invoice::scopeExcludingVoid()`, which is correct code that predates this gate. The real-tree arm
would catch that by failing; arm 5 catches it **on purpose** and names which property broke. A fix
that replaces a false green with a false red has improved nothing.

**Bite-proof 2's comment count is the part that matters**, and a bare green would not have shown it.
"The comment did not red" is satisfied equally by *the scanner bucketed it as prose* and by *the
scanner never saw it*. The bucket going 2 → 4 and the token count rising by 4 is the evidence for the
first.

### The blast radius, which is part of the proof and not a footnote

**15 arms. Bite-proofs 1, 3 and 4 red exactly ONE — the real-tree verdict arm — and leave 14 green.
Bite-proofs 2 and 5 red none.**

The fourteen greens are **right** to stay green, and structurally so: every other arm scans a
throwaway probe source outside the repository, so each is independent of `app/` by construction. Had
a mutation of `app/` reded the whole file, the honest reading would be that the arms share a
dependency none of them names.

### Gates

| gate | result | what it examined |
| --- | --- | --- |
| `pint --test` | PASSED | **1 file**, array-guard form. **Positive control:** planted trailing blank lines → `fail` naming the file and the fixers, exit 1. **Absent control:** the guard refused to invoke pint on an empty list |
| `bin/ci-authz-lint.php` | OK, exit 0 | 0 known commented-out checks |
| `bin/ci-boundary-lint.php` | OK, exit 0 | **939 files** across `app/` and `tests/`, 8 known exceptions |
| `bin/ci-citation-lint.php` | OK, exit 0 | 181 citations, 164 baselined keys. **It reded twice during this pass** and both were real — a rewritten docblock line that split `path:LINE` from its `(symbol)` across two lines, and a bare basename `InvoiceSettlement.php:65`. Run directly, it localised each in one line; that contrast is the substance of the ticket this branch raises about it |
| `pest --group=arch` | PASSED — **168 tests, 826 assertions**, exit 0 | arch group. Measured baseline with the new file moved out: **153 tests, 759 assertions**. Delta 15 / 67 is exactly this file |
| `composer analyse` | passed, 0 errors — **and VACUOUS for this change** | `phpstan.neon:11-12` is `paths: - app`, so it examined **zero** of this branch's files. See the ticket |
| `phpstan` on the new file explicitly | passed, 0 errors | 1 file. **Positive control:** planted `strlen(new stdClass)` → `argument.type` at the planted line, exit non-zero |

### An instrument that was wrong, and the control that caught it

The first measurement of the setter family used `grep -E '(->|::|\b)name\('`. **BSD `grep -E` does
not support `\b`**, so every pattern in that form matched nothing, and the table came back **all
zeros — including `where`, which occurs 867 times.** Read at face value it said the positional-setter
family does not exist in this codebase, which would have made the review's finding look theoretical.

It was caught by pairing a **positive control** (`->where(` must be > 0) with an **absent control**
(`->zzzNotAMethod(` must be 0) rather than by noticing the zeros looked odd — the zeros looked
entirely plausible. This is the "unable to match" row of CLAUDE.md's four-shapes table, and it is
recorded because it nearly produced a confident wrong sentence in a report about a gate whose whole
subject is instruments that examine nothing.

## What this means for the next commit

**`CorrectReturnedInvoice` is the second producer this pin exists for.** It is the class the approved
correction mechanism adds — a pre-release correction that must not need Executive Director approval,
built as void-and-re-raise with the approval waived for a bill that is unreleased and unallocated.
Measured: the name appears **nowhere in the tree today** (zero occurrences across `app/`, `tests/`,
`docs/`), so this is the commit before it, not a description of something already present.

When it lands it adds one line to `voidWriterPermittedFiles()` with its reason beside it. That is the
mechanism working.

**And here is the concrete cost of the defect the review found, which is one commit away rather than
hypothetical.** Had that action been written with a positional setter —

```php
$invoice->setAttribute('status', InvoiceStatus::Void);
$invoice->save();
```

— the gate as committed at `b2164a80` would have reported **PASSED, 11 of 11, `unrecognised` zero**,
and the second void writer would have entered the codebase with the pin still claiming there was
one. The diff would have read as "add the correction path"; nothing would have said that the number
of unasserted void producers had gone from one to two. That is precisely the failure the ticket was
opened to prevent, reproduced by the gate written to prevent it.

## Findings raised, not fixed

**Both of the first two now have tickets, shipped in this commit.** A finding whose only home is a
report is not filed.

**1. `composer analyse` examines no test file — `docs/handoff/tickets/larastan-examines-no-test-file.md`.**
`phpstan.neon:11-12` is `paths: - app`. Measured: 634 `.php` under `app/` examined, **305 under
`tests/` examined — zero**, of which **13** are `tests/Arch/`, the files this project treats as its
durable enforcement mechanisms. Proved rather than inferred: a planted `strlen(new stdClass)` in a
`tests/Arch/` file leaves `composer analyse` reporting `passed, errors: 0, exit 0` while the same
file handed to phpstan directly reds with `argument.type`. The ticket states plainly that it would
have caught **nothing** on this branch — the explicit run found zero errors before the plant — and
that excluding `tests/` may well be correct; the defect is that `phpstan.neon` comments its `level`
and its `tmpDir` at length and says nothing about `paths`, so an unstated exclusion and an overlooked
one are indistinguishable. Three options recorded, none chosen.

**2. `ci-citation-lint` is unlisted and its failures misname themselves —
`docs/handoff/tickets/citation-lint-is-absent-from-the-gate-list-and-misnames-its-failures.md`.**
`CLAUDE.md:82-84` names **2** of the **14** `bin/ci-*.php` scripts, all 14 of which `bin/quality`
invokes on every push; **10** are named nowhere in `CLAUDE.md`, and `:722` still describes the hook
as running "four lints". And the diagnosability half, **re-measured for this ticket and different
from what an earlier draft of this report said**: the ratio depends on whether the offending file is
tracked. UNTRACKED — **10** reds, **2** name the file. TRACKED — **11** reds, **3** name it, the
extra being `it_n`, which by design only fires once the file is committed. The eight silent arms all
print `Failed asserting that 1 is identical to 0.` because they assert the exit code before
`$output`, and `$output` is the lint's own report and does carry the path. Proposal, not built:
assert the output first — eight one-line edits — with its costs stated.

**4. The gate I shipped was defeated by three spellings, and my own measuring instrument was
defeated by a `\b`.** The first is above at length. The second is worth its own line because it is
the same shape one layer out: `grep -E '(->|::|\b)name('` on BSD `grep -E` matches nothing, so the
first measurement of the setter family returned all zeros — including `where`, which occurs 867
times — and read as "this family does not exist here". Nothing about the output looked wrong. Only
pairing a positive control with an absent control caught it. Recorded because it was one sentence
away from making the review's finding look theoretical in a report about instruments that examine
nothing.

**3. THREE of my own numbers were wrong before they were final, and every one of them was CARRIED
rather than derived.** (i) The docblock said `tests/` held "eight occurrences, seven of which are
`update(…)`" — five, measured. (ii) The commit message repeated the ticket's "eight lines below" for
the ledger post — six, measured (`:76` and `:82`). (iii) An earlier draft of finding 2 above stated
the citation-lint ratio as a flat "eight of ten do not name the file"; re-measured for the ticket,
that is true only when the offending file is UNTRACKED, and becomes three of eleven once it is
committed — so the figure I stated was correct for the run I happened to do and was written as
though it were a property of the gate.

(iii) is the worst of the three and is the reason all three are listed together: it was not a
transcription slip but a **condition-blind measurement**, the same shape as CLAUDE.md's `SHOW
COLUMNS` entry — a genuine measurement of the wrong object, which is the most credible-looking wrong
claim available. All three were corrected before this commit, and **all three bite-proofs were
re-run on the squashed tree** rather than inherited from any earlier run. Recorded because the
failure is the one CLAUDE.md's rule 4 names, committed inside the very file whose subject is not
trusting recollection.

## What this commit does not prove

- **It does not prove the void path is correct.** It proves there is one producer of the value and
  that a second cannot arrive unremarked. Whether `ApproveVoidRequest` guards, posts and dates
  correctly is untouched by this file.
- **It does not stop a bypass at runtime.** It is an authorship-time gate. A `DB::table(…)->update(…)`
  from a shell, a manual SQL statement, or a migration is outside its reach.
- **It does not close the ticket**, which remains open for whoever adds the second producer.
- **The two OPEN holes in the exclusions table are open.** An import alias and a runtime-assembled
  SQL string both evade it. Neither exists today; both are stated in the file rather than left to be
  discovered.
- **`bin/quality` was not run**, so the full suite and the tsc ratchet are unmeasured on this branch.
  The change adds one file under `tests/Arch/` and touches no PHP under `app/` and no frontend, so
  the arch group plus the four lints plus an explicit phpstan run are the gates with anything to say
  about it. This is stated rather than implied.

## Not done

- Not pushed, per the brief.
- `app/` untouched — no action, migration, route or config was edited, and nothing in the work
  suggested one should be.
- No drive: this change reaches no screen.
