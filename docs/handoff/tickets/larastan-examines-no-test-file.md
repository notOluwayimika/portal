# Larastan examines no test file, and `composer analyse` does not say so

**Status:** open · **Opened:** 2026-09-06 · **Found by:** the void-producer pin
(`feat/pin-the-single-writer-of-invoice-void`), when its only changed file turned out to be outside
the analyser's scope · **Severity:** fix — but the fix may be a SENTENCE rather than a config change;
see "Three options, recorded not chosen"

## What is true

`phpstan.neon:11-12`:

```neon
    paths:
        - app
```

That is the whole scope declaration. **Re-derived on `064de707`**, with denominators:

| population | count | Larastan examines |
| --- | --- | --- |
| `.php` under `app/` | 634 | 634 |
| `.php` under `tests/` | 305 | **0** |
| of those, `.php` under `tests/Arch/` | 13 | **0** |

`tests/Arch/` is where this project puts its **durable enforcement mechanisms** — thirteen files
today, including four lint-coverage tests, the two single-definition pins and the refusals gate. Not
one of them is type-checked by anything in the enforcement floor.

## The proof, not the inference

`paths: - app` implies the consequence, but implication is not measurement, and a scope claim
satisfied by reading a config file is exactly the shape CLAUDE.md's gates entry warns about. So it
was measured, with a positive control, both halves on the same tree in the same minute:

**Planted** `$planted = strlen(new stdClass);` into `tests/Arch/InvoiceVoidHasOneWriterTest.php` —
a live, unambiguous level-5 type error.

```
(1) composer analyse                       -> EXIT=0   {"tool":"phpstan","result":"passed","errors":0}
(2) phpstan analyse <that same file>       ->          {"tool":"phpstan","result":"failed","errors":1,
      "tests/Arch/InvoiceVoidHasOneWriterTest.php": line 220,
      "Parameter #1 $string of function strlen expects string, stdClass given.", "argument.type"}
```

The plant was then reverted and the file verified byte-exact by sha256.

**(1) is the finding.** The analyser reports `passed`, `errors: 0`, exit 0, while a type error it is
perfectly capable of finding sits in the working tree — because it was never handed the file. This
is the *handed no input* row of CLAUDE.md's four-shapes table, at the scale of a whole directory:
an instrument that examined nothing must not report success, and this one does.

## What it would have caught on this branch: NOTHING. Stated, because a ticket that hides this is weaker.

The branch that found this adds exactly one file, `tests/Arch/InvoiceVoidHasOneWriterTest.php`, and
phpstan was run against it explicitly before commit. **It found zero errors.** The positive control
above is the only error that run has ever reported, and it was planted deliberately.

So this ticket is **not** "coverage would have caught a bug we shipped". It is: *the green in
`bin/quality` did not mean what the person reading it would take it to mean*, and on this branch the
person reading it was the author, who only discovered the gap by checking. The next author may not
check.

## The defect is the SILENCE, not the scope

**Excluding `tests/` may well be correct.** Test files legitimately do things a level-5 analyser
dislikes — Pest's `$this` binding inside closures, `expect()` chains, fixtures constructed loosely on
purpose — and a `tests/` baseline could be large enough that it never shrinks and so becomes a
permanent frozen list rather than a ratchet. That is a real position and this ticket does not argue
against it.

**The defect is that nobody decided it, and nothing says so.** `phpstan.neon` carries a nine-line
comment about `level` (`:6-9`, "Level 5 is the FIXED Phase-1 enforcement level… Do not raise it in
this milestone") and a twelve-line comment about `tmpDir` (`:14-25`, the whole result-cache scar). It
says **nothing at all** about `paths`. The single word `tests` appears once in the file, at `:8`, and
it is part of the phrase "tests/tsc/authz/boundary" describing the ratchet pattern — not a scope
decision.

A reader of `phpstan.neon` therefore comes away knowing why the level is 5 and why the cache is
repo-local, and with no reason to think the scope was chosen at all. That is the same class as this
repository's own rule about a gate reporting **unrecognised** as its own bucket rather than folding
it into *skipped*: an unstated exclusion and an overlooked one are indistinguishable from outside,
and only one of them is safe.

## Why it matters more than it looks

1. **`bin/quality` is the permanent enforcement floor** (ADR 0053 — Actions is disabled and is not
   coming back). There is no second layer that covers what this one misses.
2. **The files it misses are the files that DO the enforcing.** A defect in an arch test does not
   announce itself: it reports a clean run. A gate that is broken-open looks exactly like a codebase
   with no violations — which is the failure mode `tests/Arch/` exists to prevent, now applied one
   level up to `tests/Arch/` itself.
3. **The gap is invisible in every diff.** `phpstan.neon` is not edited when someone adds a test, so
   nothing ever surfaces the scope at the moment it matters.

## Three options, recorded not chosen

| option | what it costs |
| --- | --- |
| **A — extend `paths` to `tests/`, behind its own baseline entry** | 305 files enter the analyser at once. The baseline that results is unmeasured; if it is large it freezes rather than ratchets, and CLAUDE.md already records that a permanently-failing baselined item is the anti-pattern the ratchet's own message invites. Someone must measure the opening count before this can be judged. |
| **B — extend `paths` to `tests/Arch/` only** | 13 files, the ones that are enforcement mechanisms rather than fixtures. Much smaller blast radius, and it targets the population the argument above is actually about. But it draws a line that will need re-arguing the first time somebody wants a Feature test checked, and a partial fix to a gate converts a known blind spot into an unknown one unless the boundary is stated as loudly as the coverage. |
| **C — leave the scope, and STATE it in `phpstan.neon`** | Nothing but the sentence. Closes the silence and not the gap: the next reader knows `tests/` is deliberately out, and `composer analyse`'s green still means less than it reads as. |

**Not chosen here.** A, B and C are three different claims about what the floor should cover, and
that is a decision for whoever owns the floor. What this ticket asserts is only that the current
state is none of the three: it is B's blast radius with C's silence and A's appearance.

## Related

- `docs/handoff/reports/feat-pin-the-single-writer-of-invoice-void.md` — the branch this was found on;
  its gates table reports `composer analyse` as **vacuous for that change** rather than as green.
- `docs/handoff/tickets/citation-lint-is-absent-from-the-gate-list-and-misnames-its-failures.md` —
  the sibling found on the same branch. Both are the same shape: a gate whose output does not tell
  the reader what it examined.
