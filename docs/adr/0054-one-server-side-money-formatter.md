# 0054 — One server-side money formatter, on the value object

**Status:** Accepted — 2026-08. **Deciders:** owner + advisor. Ships with the change that
implements it. Closes
[docs/handoff/tickets/server-side-money-has-no-single-formatter-and-no-lint.md](../handoff/tickets/server-side-money-has-no-single-formatter-and-no-lint.md).

## Context

`bin/ci-money-lint.php` enforced "all money is displayed through one formatter" — and walked
`resources/js` only. There was no server arm. The rule therefore looked stronger than it was:
the browser was held to one renderer while the server, which produces refusal messages,
approval summaries, ledger narrations, notification bodies and email bodies, was held to
nothing.

What accumulated in that unwatched half was **four** spellings of a naira figure:

| | Shape | Where |
| --- | --- | --- |
| A | `1500.00` — `Money::toNaira()` bare, no symbol, no grouping | opening-balance validator findings (4 sites) |
| B | `NGN 1500.00` — ISO code + `toNaira()` | allocation refusals (2), credit-note approval summary (1) |
| C | `₦3,476,400.00` — grouped, hand-rolled | `OpeningBalanceInterpretation::naira()` |
| D | `₦1,234.56` — grouped, `number_format` | a global `formatNaira()` helper with **no production callers** |

Plus two raw-integer spellings in prose: `%d minor units` in a 422 and in a ledger narration,
and `%d kobo` in validator findings, reconcile drift and the import console.

C and D produce byte-identical output. Each was written believing it was the first. D shipped
in the same commit as the value object itself (`946be7e`) and had no production callers — it was
exercised only by `MoneyTest`, whose five assertions this change rewrote onto `format()`. (An
earlier wording here said "never called", which this commit's own diff contradicted.) C's own
docblock explains that it exists because `toNaira()` was ungrouped — written by someone who
did not know D was three directories away, and who therefore solved the same problem twice.

The originating ticket's "sites known today" table listed three. A scan found fourteen. It was
incomplete by eleven, which is the ticket's own point made sharper: without a lint there is no
scan, and without a scan an inventory is a memory.

The concrete failure this produced: U10's allocation screen refuses an over-allocation with a
sentence rendered by the server, fifteen pixels from a table column rendering the same quantity
in the browser. `NGN 1500.00` beside `₦1,500.00`. On a real term bill that reads `NGN 125000.00`
— six unbroken digits in a sentence about money an operator is about to commit irreversibly.

## Decision

**1. `App\Support\Money::format()` is the one server-side renderer.** It lives on the value
object, not in a helper, because a Money already knows its currency and its exactness; a free
function is a second place to forget. It emits `₦1,234.56` / `-₦12.05` / `₦0.00` — the shape
`resources/js/lib/format.ts`'s `formatNaira` emits, so the two renderings of one figure cannot
disagree when they sit inches apart.

**2. Symbol, not ISO code.** `NGN 125000.00` was the server's habit. The code prefix bought
nothing a single-currency system needs, and the missing grouping cost the thing grouping is for:
a magnitude error is exactly what an unbroken digit run hides, and a refusal message is exactly
where one must not hide. **This is a user-visible copy change and was approved as one.**

Approval summaries are STORED, not rendered on read (`NotifiesApprovalCheckers` — a summary is
an immutable fact about a decision, and `rendered_fallback` exists to hold it). Rows written
before this ADR keep their `NGN 1500.00` text forever. That is correct: a stored fallback
records what was said at the time, and rewriting history to match a formatting decision would
be a worse fault than the inconsistency it tidied.

**3. Grouping is string surgery, never `number_format`.** `format()` takes the exact decimal
`toNaira()` produced, splits the sign, reverses, commas every three digits, reverses back. No
float appears anywhere in the path.

**The argument is about the declared type, and rests on nothing else:**

| | |
| --- | --- |
| `number_format`'s first parameter | declared `float` (`ReflectionFunction` confirms) |
| Largest naira-major this type can hold | `intdiv(PHP_INT_MAX, 100)` = 92,233,720,368,547,758 ≈ **9.22e16** |
| float's exact-integer limit | 2^53 = 9,007,199,254,740,992 ≈ **9.01e15** |

The domain's maximum exceeds float's exact range by an order of magnitude, so at the top of the
range the exactness of a grouped figure is one coercion away. Measured, that coercion costs real
digits: `number_format((float) 9007199254740993)` is `9,007,199,254,740,992`.

**The practical stake is small, and is named as small.** A term bill is around 1.25e7 kobo —
nine orders of magnitude below the boundary. Nothing this school system will ever bill comes
near it. What is not small is the principle: a formatter is the last thing that should be able
to alter the figure it displays, and the string technique has no float anywhere in its path, so
the property is structural rather than contingent on a range check nobody will re-run.

**On the claim this replaces, and on a claim of my own that did not survive review.** The
`OpeningBalanceInterpretation::naira()` docblock said `number_format` "casts to float and would
lose precision". An earlier draft of this ADR asserted the opposite mechanism — that
`number_format` has an integer fast-path on PHP 8.0+, so the original claim was simply false.
**That assertion was not supported by what had been measured.** What was measured is narrower:

```
$i = 9007199254740993;            // 2^53 + 1, not representable as a double
(float) $i                        => 9007199254740992.0
number_format($i)                 => 9,007,199,254,740,993
number_format((float) $i)         => 9,007,199,254,740,992
```

A coerce-then-format path could not emit `...993`, because the double for that value is `...992`
— so **no coercion is observable at this value on PHP 8.3.32**. That is an observation about one
build. It names no mechanism, says nothing about other versions, and the decision above is
written so that it does not matter which way it goes. `MoneyTest` pins the exactness property at
`PHP_INT_MAX` directly, by stripping the punctuation back off and comparing digits — which tests
the guarantee rather than the engine.

**4. NGN only; a non-NGN Money throws.** `₦` is a naira mark. Rendering USD through it would
MISLABEL the amount rather than merely mis-style it, and a mislabelled currency on a money
screen is the failure mode the whole `Money` currency field exists to make impossible. Single
currency here is a constraint, deliberately, not an omission — matching the throw the dead
helper already had, and the one the constructor raises on a malformed code.

**5. Kobo integers STAY in diagnostics.** `Δ %d kobo` in the opening-balance validator's
findings, `stored=%d ledger=%d (Δ=%d)` in `reconcile-accounts`, `L2 (kobo): …` in the import
console. These are machine figures whose entire purpose is to expose a mismatch, and the
mismatches worth catching are sub-naira: a one-kobo drift between a projected balance and
`SUM(ledger)` is a real defect, and `₦0.01` — or worse, a rounded `₦0.00` — is how you would
fail to notice it. They are not renders and are not migrated. The `%d minor units` figures in
the credit-note 422 and the payment ledger narration ARE renders — an operator reads them — and
were migrated.

**6. `bin/ci-money-lint.php` gets a PHP arm**, walking `app/`, exempting only
`app/Support/Money.php`:

- `money-render-outside-money-format` — `toNaira()` **consumed rather than bound**.
- `money-number-format-on-money` — `number_format(` on a line that also names money.

The first is token-based (`token_get_all`), not regex, because the renders it exists to catch
are written across several lines: a `$statedSum->toNaira(),` sitting alone on a line knows
nothing about the `sprintf(` three lines above it, and a line-local rule cannot tell a sprintf
argument from an ordinary parameter.

It is stated as **binding**, not as "string context", and that is a deliberate widening of the
brief. Concatenation, interpolation and sprintf-argument detection would have missed
`array_map(fn (Money $m) => $m->toNaira(), $stated)` — the shape the validator used to build its
`inconsistent_student_total` finding, which is a render by any honest reading and sits inside no
quote, no dot and no sprintf. The one shape that is not a render is a direct assignment
(`$exact = $money->toNaira();`), which keeps the machine decimal available to a machine consumer
in a form a reviewer can see.

**The kobo diagnostics are spared STRUCTURALLY, not by an exemption list.** They are `toKobo()`
— integers — and the rule never looks at `toKobo()`. An exemption list would have been a
standing invitation to add the next site to it, and the request that produced this ADR said so
explicitly: if the rule needs a list to spare the diagnostics, the rule is wrong.

The baseline is **empty on both arms** and is intended to stay that way. A money render is never
a reviewed exception.

### A known limit of `money-render-outside-money-format`

**Bind-then-interpolate passes, and always will:**

```php
$s = $money->toNaira();
// ... later, or elsewhere in the method
$message = "Total {$s}";        // NOT flagged
```

The first line is a legal binding; the second names a string, not a Money. Catching it needs
flow analysis — following a value from its binding to its uses, across branches and calls — and
that is a class of machinery this repository should not carry for one lint.

**This is stated as a limit, not as a gap to be closed later.** It is the most natural shape in
which a second spelling comes back: someone who reaches for `toNaira()`, is told no by the gate,
and assigns it to a variable first. A reader who believes the rule is airtight is worse off than
one who knows exactly where it ends — the first will trust a green run to mean something it does
not.

What the rule does buy is that the *casual* route is closed and the remaining route is
conspicuous: a bound `toNaira()` sitting next to string work is a thing a reviewer can see, and
`format()` is the obvious call to make instead. The gate raises the cost of the wrong path; it
does not make it impossible, and it should not be described as if it did.

## Consequences

- `OpeningBalanceInterpretation::naira()` is deleted; its four callers use `format()`. The
  existing oracle in `OpeningBalanceSingleColumnTest` asserts the full sentence byte-for-byte
  and passes unchanged — `format()` reproduces `naira()` exactly, which is the migration's own
  proof.
- The dead global `formatNaira()` is deleted from `app/Helpers/Helper.php`. The file keeps its
  four other functions and its `composer.json` `autoload.files` entry.
- Allocation refusals, the credit-note approval summary (and therefore the in-app approval feed
  and the notification email body that read `rendered_fallback`), the credit-note over-approval
  422, the payment ledger narration and all four opening-balance validator findings now read
  `₦1,500.00`.
- A fifth spelling cannot be added silently. That is the whole of the change that is not
  cosmetic.

## What this does not do

- It does not touch `resources/js`. The frontend money-input work is separate.
- It does not make the system multi-currency. `format()` refusing non-NGN is a guard on a
  single-currency decision, not the start of a currency layer.
- It does not give `Money` a `__toString`. Accidental interpolation of a Money stays a
  TypeError, which is the right answer — a render must be asked for by name.
