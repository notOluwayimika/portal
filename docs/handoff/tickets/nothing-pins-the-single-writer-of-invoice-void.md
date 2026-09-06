# Nothing pins the single writer of `InvoiceStatus::Void`

**Status:** THE PIN EXISTS; the ticket stays open for the second producer · **Opened:** 2026-09-05 ·
**Pinned:** 2026-09-06 on `feat/pin-the-single-writer-of-invoice-void` · **Found by:** the
void-approval investigation of 2026-09-05 · **Severity:** fix, and it is a **precondition** of the
correction mechanism now being designed — see "Why this is urgent rather than tidy"

> **2026-09-06 — the precondition is met.** `tests/Arch/InvoiceVoidHasOneWriterTest.php` pins the
> producers of `InvoiceStatus::Void` to `voidWriterPermittedFiles()`, which holds one entry:
> `App\Finance\Actions\ApproveVoidRequest`. The literal claim this ticket opened with — *no arch
> test, no lint, no database constraint* — is now false in its first clause and unchanged in the
> other two.
>
> **The rest of this ticket stands, and it is not decoration.** The four request-table guards are
> still blind to a bypass; I4 is still detective-only and still sees only the money limb; there is
> still no database constraint. The arch test makes a second producer VISIBLE at authorship time,
> which is what the ticket asked for — it does not make one impossible.
>
> **What is left, and it is the whole reason this stays open:** the correction mechanism adds the
> second legitimate producer. Whoever writes it adds one line to `voidWriterPermittedFiles()` with
> the reason beside it, and that line is the reviewed artifact this ticket exists to force.
>
> Two figures in the table below were measured on `ca8dbc45` and have moved: `ReturnInvoice.php`'s
> guard is now `:175` (not `:174`) and `ApproveInvoice.php`'s is `:156` (not `:155`); the denominator
> is **634** `.php` files under `app/`, not 632. The counts — 6 occurrences, 1 of them a write — are
> unchanged. `Invoice.php:217` is more precisely an ARGUMENT (`->value` passed into `where()`) than a
> comparison. Re-derived on `064de707`; see
> `docs/handoff/reports/feat-pin-the-single-writer-of-invoice-void.md`.
>
> The report also records what the pin deliberately does NOT judge, so a reader of this ticket does
> not come away believing the hole is fully shut — four rows are stated OPEN, including an import
> alias, a call made through a variable, and the string marker laundered through a variable.
>
> **AND THE FIRST VERSION OF THE PIN WAS DEFEATED BEFORE IT MERGED.** A cold review beat it with
> three spellings of a direct void write — `setAttribute('status', InvoiceStatus::Void)`,
> `setAttribute('status', 'void')` and `data_set($i, 'status', InvoiceStatus::Void)` — each of which
> reported a clean run with `unrecognised` zero. All three were reproduced on the tree before the
> fix. The cause was ORDERING: a `,` meant "an argument, therefore benign", decided before anything
> asked what it was an argument to. The shipped pin inverts that default — benign must be positively
> earned, and a call in neither the mutator nor the reader list lands in a bucket asserted ZERO.

## What is true

`app/Finance/Actions/ApproveVoidRequest.php:76` is the only place in the application that writes
`InvoiceStatus::Void`:

```php
$invoice->update([
    'status' => InvoiceStatus::Void,
    …
]);
```

**Nothing asserts that.** No arch test, no lint, no database constraint.

Re-derived on `ca8dbc45`, with the denominator stated: **632** `.php` files under `app/` searched;
**6** occurrences of `InvoiceStatus::Void` in total; **1** of them a write.

| site | kind |
| --- | --- |
| `app/Finance/Actions/ApproveVoidRequest.php:76` | **the write** |
| `app/Finance/Models/Invoice.php:203` | comparison (`isVoid()`) |
| `app/Finance/Models/Invoice.php:217` | comparison (`scopeExcludingVoid`) |
| `app/Finance/Actions/ReturnInvoice.php:174` | comparison (guard) |
| `app/Finance/Actions/ApproveInvoice.php:155` | comparison (guard) |
| `app/Finance/Services/InvoiceSettlement.php:65` | comparison |

## The four guards, and why none of them sees a bypass

The void act is heavily guarded — and every guard is on the **request table**:

| guard | what it checks |
| --- | --- |
| `tests/Feature/Rbac/MakerCheckerSeparationTest.php:358` | *"SoD — no seeded role holds a checker ability together with its matching maker"* |
| `tests/Feature/Rbac/DutySeparationBaselineTest.php:268` | *"ARM 8 — every ENFORCED pair is in the namespace the baseline can never amnesty"* |
| `tests/Feature/Finance/SchemaConventionsTest.php:336` | *"SCHEMA INVARIANT — every approval table carries a maker≠checker TRIGGER pair (not a CHECK)"* |
| DB triggers `finance_void_requests_maker_ne_checker_bi` / `_bu` | reject maker = checker on raw INSERT and UPDATE |

A new action that sets `status = Void` directly — no `VoidRequest` row, no submitter, no checker —
**would compile, pass every gate, and be invisible to all four of them, because a void with no
request never touches the table they guard.**

**The safety here is structural rather than asserted, and a bypass would be caught by review or by
one partial detector — not by any gate.**

**THE DETECTOR, NAMED PRECISELY, BECAUSE AN EARLIER DRAFT SAID "OR NOT AT ALL" AND THAT WAS TOO
STRONG.** `app/Finance/Console/AuditLedgerCoherence.php:215-216` — check **I4**,
`checkVoidHasOneMatchingReversal` — asserts that every invoice with `status = 'void'` has exactly one
reversal row whose amount negates the charge sum, and raises a finding when `rev_count !== 1`
(`app/Finance/Console/AuditLedgerCoherence.php:236-238`). It is invoked at `app/Finance/Console/AuditLedgerCoherence.php:106`, and it **is** scheduled: `routes/console.php:127`,
`Schedule::command('finance:audit-ledger-coherence')->daily()`.

Three qualifications, and they matter more than the concession:

- **It is DETECTIVE, not preventive.** A daily console command that reports after the fact is not a
  gate. It is in neither `bin/quality` nor `.githooks/pre-push` — measured, zero occurrences in
  each — so nothing stops the bypass being written, merged and run; I4 only notices afterwards, up
  to a day later, and only if somebody reads its output.
- **It sees ONLY the money limb.** A bypass that sets `status = Void` **and posts a correct
  reversal** produces `rev_count = 1` and a matching sum, and is completely invisible to it. That is
  the LIKELIER bypass, because anyone writing a second void path would copy
  `ApproveVoidRequest`'s ledger posting — it sits eight lines below the status write.
- **Every one of the four request-table guards remains blind either way.** I4 knows nothing about
  `finance_void_requests`, so it cannot tell a void that skipped maker-checker from one that did
  not. The argument for the arch test is unchanged.

The ticket's literal claim — no arch test, no lint, no database constraint — still holds exactly; a
scheduled console audit is none of the three.

## Why it matters

Voiding is the only act in the system that reverses a whole charge. `app/Finance/Actions/ApproveVoidRequest.php:82-102`
posts the reversal **dated to the original charge's period**, deliberately, on the reasoning that a
void says the invoice should never have existed. A second writer that skipped the request table
would also skip the argument attached to it — and it would skip it silently, in a codebase whose
every other financial control is asserted somewhere.

**Environment:** every environment. It is a missing test, so it bites at authorship time and only
for whoever writes the next void path.

## Why this is urgent rather than tidy

**The correction mechanism now being designed for Brookstone adds a SECOND legitimate writer.**
Their requirement is that a pre-release correction must not need Executive Director approval; the
proposal on the table keeps void-and-re-raise as the mechanism and waives the approval for a bill
that is unreleased and unallocated. However that is built, it puts a second thing in the codebase
that sets `status = Void`.

**Without this pin, that commit widens an unguarded hole while looking like it closes a
requirement.** The diff will read as "add the correction path"; what it also does is take the
number of unasserted void writers from one to two, and nothing in the repository will say so. The
arch test is a **precondition of that work, not a follow-up to it** — written first, it makes the
new writer an explicit, argued line in a permitted list; written after, it baptises whatever
shipped.

## What would close it

A source-level pin in `tests/Arch/`, on the model of
`tests/Arch/ReleasedToPayersHasOneDefinitionTest.php`, which already solves this exact problem for
`reviewed_at`. Its shape, and each part earns its place:

- **Token-based, via `token_get_all()`** — not `grep`. A substring matcher over `app/` cannot tell
  a write from a comparison, from a docblock naming the constant, from a string literal.
- **Comment-blind by BUCKET, not by skip.** `tests/Arch/ReleasedToPayersHasOneDefinitionTest.php:19-21` makes the
  point: comments are *"a bucket with a stated reason, not an invisible skip"*, so a docblock cannot
  quietly satisfy or trip the rule.
- **Three numbers reported** — EXAMINED (`tests/Arch/ReleasedToPayersHasOneDefinitionTest.php:25`, every token of every `.php` under `app/`),
  EXCLUDED with a stated reason, and **UNRECOGNISED asserted ZERO** (`tests/Arch/ReleasedToPayersHasOneDefinitionTest.php:27-28`), so a spelling in a
  token kind nobody anticipated **reds instead of vanishing into a skip**.
- **The permitted writers named explicitly**, one entry today —
  `App\Finance\Actions\ApproveVoidRequest` — so adding a second is a reviewed line in a test rather
  than an unremarked line in an action.

The bite-proof it needs is the one that distinguishes it from a vacuous green: plant a
`status => InvoiceStatus::Void` write in a second file, watch it red, restore. And the inverse, which
is the arm that actually earns the tokeniser: put the same text in a **comment** and confirm it does
**not** red.

**DONE, 2026-09-06 — and with a third bite-proof this ticket did not ask for.** Both of the above
were run and behaved as specified. The third: delete `ApproveVoidRequest`'s write entirely and
confirm the test reds on ZERO producers too, because a permitted list satisfied by nothing is a
vacuous green in the other direction. The shipped assertion is SET EQUALITY against the permitted
list rather than containment, so the empty set cannot satisfy it and a permitted entry that no longer
produces anything cannot rot in the list unnoticed.

The shipped rule is also **wider than this section asks for**, deliberately and with the widening
declared in the file's docblock: it judges every position where the value is PRODUCED INTO A SLOT
(`=>`, `=`, `return`), not only an ORM write, which closes the launder-through-a-variable hole; and a
second marker catches the backing value spelled as the string `'void'` under a `status` key.
