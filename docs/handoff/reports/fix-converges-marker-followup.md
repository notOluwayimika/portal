# Report — `@converges` follow-up: the two fixes before merge

**Branch:** `fix/converges-marker` @ `cef0517` · **Base:** `staging` @ `8c354a5`
**Brief:** `plan_docs/task.md` (also at `docs/handoff/converges-marker-followup-brief.md`)
**Prior report:** `docs/handoff/reports/fix-converges-marker.md`

One commit, `cef0517`. Four backlog tickets from §"Do NOT do on this branch" untouched —
verified with `git diff 395af20..cef0517 --stat`: `bin/ci-grants-convergence-lint.php`,
`tests/Feature/Rbac/GrantsConvergenceLintTest.php`,
`database/migrations/2026_08_05_100000_converge_finance_access_grants.php`, `docs/testing.md`.
Neither `RbacDiffGrants.php` nor `FinanceAccessGrantConvergenceTest.php` nor
`2026_08_03_100000` is in it.

`bin/quality` 13/13.

---

## Step 0a — the `?`-role dead end

Shared-fragment fixture, built with the same unreferenced-object plumbing the test harness
uses: `$activityAdmin` defined **above** `return [`, spliced into two pre-existing roles;
head adds `ACTIVITY_LOG_VIEW` to the fragment and ships a migration carrying a valid
`@converges` line for **each** of those roles.

```
$ php bin/ci-grants-convergence-lint.php 0fa6da8 8778eb9

grants-convergence-lint: 1 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (0fa6da8..8778eb9):

  ✗ activity_log.view  @  database/seeders/RbacSeeder.php:18
      role: ? (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::ACTIVITY_LOG_VIEW->value,

  WHY THIS FAILS. …
  TO RESOLVE, pick the one that is true:
    · Ship a convergence migration … If the
      role above reads `?`, the addition is in a shared fragment — declare a line for every
      pre-existing role it spreads to.
    · The permission is genuinely new …
    · The role is genuinely new …
EXIT=1
```

**Matched your prediction exactly.** Exit 1, `role: ?`, and the two valid markers appear
**nowhere** — no `were EXEMPT` block, no `⚠` line, no mention of
`2099_01_01_000000_converge_fragment.php` anywhere in the 40 lines of output. The author
does precisely what the last line told them to do and gets this same page back.

## Step 0b — the silent-syntax class

```
1   * @converges auditor activity_log.view                matches=1
2  /** @converges auditor activity_log.view */            matches=0
3   * @converges auditor activity_log.view  (CRLF)        matches=0
4   * @converges auditor / * activity_log.view (wrap)     matches=0
5   * @converges auditor activity_log.view and bursar     matches=0
```

**1 / 0 / 0 / 0 / 0 — matched your prediction on all five.** (`declaredConvergences()`
extracted from the committed file and `eval`'d, so this is the shipped pattern and not a
transcription of it.)

---

## Fix 1 — the heredoc

```diff
       The marker may sit in a docblock (` * @converges …`), a `//` line or a `#` line.
       Naming the permission and the role in PROSE is NOT enough and is no longer read: a
-      migration that documents which roles it EXCLUDES would otherwise exempt them. If the
-      role above reads `?`, the addition is in a shared fragment — declare a line for every
-      pre-existing role it spreads to.
+      migration that documents which roles it EXCLUDES would otherwise exempt them.
+
+      If the role above reads `?`, a marker CANNOT clear this: exemption 3 is a lookup on
+      (role, permission) and there is no role to look up. The addition sits in a shared
+      fragment above `return [`, so it lands on every pre-existing role that splices it and
+      this lint cannot tell which. ATTRIBUTE IT — move the addition under a `'<role>' => [`
+      key, or regroup the fragments beneath one (see $inferRole in this file, which carries
+      the shape). Re-run; the findings then name real roles, and you declare a marker for
+      each.
```

**One wording deviation.** The brief's text was `{@see $inferRole}`. Inside a nowdoc that
renders literally to the operator as `{@see $inferRole}`, which is IDE syntax leaking into
console output — I wrote `see $inferRole in this file`. Same referent, no other change.

`:642-644` untouched, as instructed.

## Fix 2 — mentions counted against parses

`$allMarkers = declaredConvergences($addedMigrations)` hoisted; `$unparsedMarkers` built
after the validation loop; echoed on the failing path immediately after the
`$unknownMarkers` block. Not a gate, failing-path only, false positive on prose containing
the literal `@converges` accepted and recorded in the comment — all three per the brief.

Live output (MARKER 8's fixture):

```
  1 line(s) mention @converges but did not parse as a declaration (they exempt nothing):
  ⚠ database/migrations/2099_01_01_000000_converge.php — 1 line. Check for CRLF endings, a one-line
    /** @converges … */ (the closer needs its own line), a wrapped line, or text after
    the permission.
```

**One thing the brief did not anticipate, and it is a small joke at this change's expense.**
I first wrote the rejected shapes into `declaredConvergences()`'s docblock, including a
literal `/** @converges … */`. That closer **ended the docblock** and broke the file —
`syntax error, unexpected token "@"`. The one-line docblock marker is not merely unparseable
by the lint; it is unwriteable inside a PHP docblock at all. The docblock now says so and
points at the notice text and at MARKER 8, which carry the literal form. Caught by the
IDE diagnostic before any run, and `php -l` is clean.

I also rewrote that docblock's `KNOWN RESIDUAL: the anchor is LF-shaped` paragraph — it
disclosed CRLF as an accepted silent residual, which fix 2 makes false. It now enumerates
all four rejected shapes and says the notice, not a looser pattern, is the answer.

---

## MARKER 7 — the literal, and why that one

Asserted on **`ATTRIBUTE IT`**.

The alternatives are worse for the same reason. `shared fragment` appears in the *old*
wording too, so the arm would survive the exact regression it exists to stop. `'<role>' => [`
appears three times elsewhere in the failure text (the `INFERRED from the nearest preceding`
line on every finding), so it would pass on a heredoc that never mentioned attribution.
`ATTRIBUTE IT` exists only in the new remedy and only in the imperative. The arm's comment
says this and tells the next reader not to soften it.

Also asserted: exit 1, `role: ?`, no `were EXEMPT` block, and the migration filename absent
from the whole output — the last one being the invariant proper (two syntactically valid
markers exempt nothing and are not even reported).

**Watched red**, though the brief did not require one for a text change: reverting the
heredoc to the old wording fails MARKER 7 on
`… contains "ATTRIBUTE IT"` and nothing else. Restored, green.

## MARKER 8 — red before, green after

Fix 2's echo disabled (`if (false && $unparsedMarkers !== [])`):

```
{"result":"failed","tests":2,"passed":0,"failed":2,"failures":[
  "MARKER_3_—_TRAILING_PROSE_DOES_NOT_SMUGGLE…",
  "MARKER_8_—_a_marker_the_parser_cannot_READ_is_reported__not_swallowed"]}
```

Both fail on `… contains "did not parse as a declaration"`. Restored: 20 passed,
104 assertions.

**MARKER 3 changed and you should know why.** Its old assertion was
`not->toContain('2099_01_01_000000_converge.php')` — written when a smuggled tail was cited
nowhere. Fix 2 now cites it in the unparsed notice, so that assertion went red on the first
run. I did **not** weaken it: it became `not->toContain('declares @converges')` (the exemption
claim, which is what the arm was actually about) **plus** two new positive assertions that
the notice names the file. The arm is strictly stronger than before and now covers both
halves of the tail's behaviour. Flagging it because "an existing arm went red and I changed
it" is the shape of a weakened test even when it isn't one.

---

## §"What I got wrong" — the three carried items

1. **`:322` docblock** already carried the measured reason from `8122da3`, not the brief's —
   `$` anchor blocks the tail, `[ \t]` blocks two-line assembly, with the counter-example
   spelled out. Re-read and left alone. No change needed.
2. **`docs/testing.md`**, under the gate-audit section where watched-red is described:
   added a paragraph that a regression arm written after its fix defaults to passing under
   the revert, with the 2026-08-03 instance (the softened MARKER 1 fixture) named.
3. **The fresh-install guard's docblock** now records that `RefreshDatabase` migrates an
   empty database every suite run, so the guard is load-bearing for the whole suite and
   disabling it errors all six arms — not one.

---

## Your two questions

**Should the mention-count notice fire on the passing path too?** No, and I do not think
this is close. On a passing run every grant addition was exempted by *something*, and the
exemption list already names what. An unparsed marker on that run is either irrelevant
(the pair was exempted by 1, 2 or 4) or already covered (a second migration declared it),
so the notice would be printing about a file whose problem had no consequence. That is the
class of output that trains an operator to stop reading gate output, which is the specific
failure `:702-707` is written against. The asymmetry with the green path is not an
inconsistency to tidy up — it *is* the rule: this lint tells you things when they cost you
something.

The one case I can construct against myself: an author lands a migration with a
CRLF-mangled marker in a diff where the pair happened to be exempt for another reason, the
marker rots silently, and a **later** diff inherits it. But the later diff is red — a
declaration that never parsed cannot be found by lookup — and the notice fires there, on
the run where it matters. The signal is deferred to the moment it has a consequence, not
lost.

**Is the false positive worth suppressing?** No, and I would go further: it is not really a
false positive. A migration whose prose contains the literal `@converges` is a migration
explaining the convention, and on an already-red run the notice tells the reader "one of
these lines is decoration, not a declaration" — which is true and occasionally useful. Any
suppressor (skip lines that also match `.*@converges.*is.*`, skip the first N, skip lines
inside prose paragraphs) is a second parser making a judgement about intent from bytes,
which is the exact mechanism the marker replaced. Leave it.

---

## What I did not do, and what is still open

- **The four backlog tickets are untouched**, per the brief. All four are still true in the
  repo at `cef0517`; I re-checked `RbacDiffGrants.php:51` and `2026_08_03_100000:38-39`
  rather than trusting the review.
- **No arm for CRLF or the wrapped line**, per the brief — one arm per mechanism. Their
  behaviour is proven by step 0b only, which is a parser-level measurement rather than an
  end-to-end run.
- **The notice's count is per-file, not per-line.** It says "2 lines" without saying which
  two. Adding line numbers means re-scanning with a second, looser pattern to find the
  candidates — the road the brief and I both want closed. If you want line numbers later,
  the honest way is to have `declaredConvergences()` return the offsets of everything it
  *considered*, not to add a scanner.
- **`$unparsedMarkers` counts `@converges` with no boundary**, so `@convergesXYZ` would
  inflate it. I left it as the brief wrote it: a boundary here is a third pattern with its
  own edge cases, and over-counting only produces a noise line on a red run.
- **Nothing was driven against the dev database or a real app flow.** This change is a
  lint's console output and its arms; the migration edit is a comment.
