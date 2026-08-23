# TICKET — single-capture in the subledger poster has no test, and the suite cannot see it break

**Status:** open, not implemented. Raised by `fix/release-gate-static-analysis-composer-timeout`,
which was on the gate rather than on Finance and measured this in passing. Deliberately NOT fixed
there: the closure is a new lint, and adding a `bin/quality` step is a gate decision, not something a
branch about a Composer timeout gets to settle on the way past.

## The claim under test

`SubledgerPoster::post()` captures the instant ONCE and binds it into both writes — the ledger row's
`posted_at` (`app/Finance/Services/SubledgerPoster.php:103`, `:113`) and the account projection's
`created_at`/`updated_at`, which receive it as an argument (`:117`, `:175`, `:197`). The docblock at
`:76` is explicit that this property is **not** proven by the arm that guards the method:

> Single-capture is instead structural: the instant is a local in post() and is passed down, so there
> is no second call to drift from. Do not read the arm as proof of it.

That paragraph was **honest but assumed**. It is now measured, and the measurement agrees with it —
which is the point of writing this down. The docblock said the suite could not see a second clock
read; nobody had made the suite fail to see one.

## The measurement

Method: revert the shared capture to two independent `now()` calls — the minimal form of the
regression the docblock describes — by changing `applyToAccount()`'s stamp at
`app/Finance/Services/SubledgerPoster.php:197` from

```php
$stamp = $postedAt->toDateTimeString();     // the instant post() captured
```

to

```php
$stamp = now()->toDateTimeString();         // a second, independent clock read
```

and then running the arm both as it stands and with its clock freeze
(`tests/Feature/Finance/SubledgerClockFrameTest.php:102`) commented out.

```
                                                                   tests  assertions  result
control, tree as shipped                                             2/2          19  PASS
two independent now(), clock freeze in place                         2/2          19  PASS
two independent now(), clock freeze REMOVED                          2/2          19  PASS
two independent now(), wider set (see below)                       31/31          91  PASS
```

Commands, so the counts are re-derivable rather than remembered:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/SubledgerClockFrameTest.php
$ DB_DATABASE=portal_testing ./vendor/bin/pest \
    tests/Feature/Finance/SubledgerClockFrameTest.php \
    tests/Feature/Finance/LedgerCoherenceTest.php \
    tests/Feature/Finance/CaptureColumnsTest.php
```

The wider set is the three Finance files that touch the ledger row and its projected timestamps; it
was run because two tests is a small net, and 31 passing is a stronger statement of the same gap.

**The shipped clock lint does not see it either.** With the regression in place:

```
$ php bin/ci-sql-clock-lint.php ; echo $?
0
```

That is correct behaviour, not a lint defect: `bin/ci-sql-clock-lint.php` (`bin/quality:235`, step 12)
forbids **MySQL** clock functions inside raw SQL. A second **PHP** `now()` is a different rule, and
nothing holds it.

So: the property is invisible to the arm written for it, invisible to the wider Finance clock tests,
and invisible to the lint nearest to it. A commit reintroducing the two-clock write lands green on
staging.

**An earlier figure of 8/8 for this experiment could not be reproduced and should not be quoted.**
`SubledgerClockFrameTest` contains two `it(` blocks, not eight (`grep -c '^it(' ` on the file). The
counts above are what the runner printed on `0057842`. Re-derive rather than carry the number.

## Why the clock freeze turned out not to matter

The freeze at `:102` was added by `fix/subledger-clock-frame-test-race` (`719d002`) to stop
`travel(90)` racing a still-moving clock — a flake fix, and it worked. It was reasonable to wonder
whether freezing had also blinded the arm to a second clock read: with time frozen, two `now()` calls
return the identical instant by construction.

It had not, because the arm was already blind. Unfrozen, the two reads are microseconds apart, both
`toDateTimeString()` to the same second, and the assertion compares seconds. The docblock at `:76`
predicted exactly this — "a second now() inside applyToAccount would still pass it whenever the two
calls fall in the same second" — and the unfrozen run confirms it. **The freeze costs no coverage
here**, which is worth recording because it is the obvious suspicion and it is wrong.

## The candidate closure

A lint forbidding `now()` inside `SubledgerPoster` anywhere except the single capture point at
`:103` — **the same shape as the ₦ rule on the server side**, which is
`bin/ci-money-lint.php`'s PHP arm: a line-regex over a named tree holding one sanctioned call site
and banning the rest (`Money::format()` as the sole server-side renderer; `number_format(` on a line
naming money is a violation). Both are textual rules about *which call is allowed where*, which is
precisely the class of property that a behavioural test cannot reach and a lint can.

Sketch, not a specification:

- **Scope:** `app/Finance/Services/SubledgerPoster.php`. One file, because the rule is about this
  method's single-frame invariant, not a platform-wide ban on `now()`.
- **Rule:** at most one `now()` in the file, and it must be the assignment at the top of `post()`.
- **Exemption:** the capture line itself, named the way `MONEY_HOME` names `app/Support/Money.php`.
- **Baseline:** none — the file is at zero violations today, so it ships hard like the sql-clock lint
  did, not baselined like the citation lint.

**What it would catch:** a second clock read added to this file, which is the regression measured
above. **What it would not catch:** the same defect committed by a *new* poster elsewhere, or a
`Carbon::now()`/`SchoolDay::now()` spelling if the matcher only watches `now(`. Both are reasons to
write the matcher carefully, not reasons to prefer a test that has been shown not to work.

## A stale claim in the same docblock, found while measuring

`app/Finance/Services/SubledgerPoster.php:87` still reads:

> NOTHING ENFORCES THE RULE YET. "No MySQL clock function in raw SQL" is a rule on trust here: the
> gate that would hold it is docs/handoff/tickets/sql-clock-lint.md, not shipped.

It **is** shipped — `6e694ca`, running as `bin/quality` step 12 (`bin/quality:235`), and
`docs/handoff/tickets/sql-clock-lint.md` is still sitting in this directory as though open. Not
corrected here because this branch ships tickets only, and the correction is two files this ticket
does not otherwise touch. Same failure mode as the ticket beside it: a written claim outliving the
thing it described.

## Not proposed here

Whether the lint becomes a `bin/quality` step, whether it is scoped to one file or to
`app/Finance/Services/`, and what the matcher spells are open. The one claim this ticket makes is
that the current answer — nothing checks it — is measured, not suspected.
