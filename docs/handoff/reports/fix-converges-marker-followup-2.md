# Report — `@converges` follow-up 2: disclose one hole, extend one notice

**Branch:** `fix/converges-marker` · **This report covers `e6600eb..4ba9125`** (one commit).
**Branch base:** `staging` @ `8c354a5`; the branch carries five commits over it. Earlier commits
are covered by `fix-converges-marker.md` and `fix-converges-marker-followup.md`. (Header states
the range explicitly — ticket 4 from the last review, fixed in the reporting rather than deferred.)

**Brief:** `docs/handoff/converges-marker-followup-2-brief.md`

`bin/quality` 13/13. `GrantsConvergenceLintTest` 21 passed / 110 assertions.

Nothing from either "Do NOT do" list is in this commit — `git show --stat 4ba9125`:
`bin/ci-grants-convergence-lint.php`, `tests/Feature/Rbac/GrantsConvergenceLintTest.php`, and
the two convergence migrations. No `RbacDiffGrants.php`, no `FinanceAccessGrantConvergenceTest.php`.

---

## Finding 1 — the disclosure

Placed at `THE RULE`, which is what an author reads first. Diff:

```diff
- * THE RULE. Fail when the diff `<base>..<head>` adds a permission to `grantsMap()` and NONE of these
- * four exemptions holds:
+ * THE RULE. Fail when the diff `<base>..<head>` adds a permission to `grantsMap()` and NONE of these
+ * four exemptions holds.
+ *
+ * READ THIS QUALIFICATION BEFORE TRUSTING THAT SENTENCE. Coverage is per-pair for permissions this
+ * lint can RESOLVE from an added line, and it resolves exactly two forms: `PermissionEnum::X->value`,
+ * and a quoted string that is a real enum value at head (see the resolution block at the findings
+ * loop). It does NOT resolve `...$fragment,`. So an added spread of a PRE-EXISTING fragment into a
+ * PRE-EXISTING role grants every permission in that fragment, `rbac:sync` grants none of them, and
+ * this lint produces no finding and exits 0.
+ *
+ * That is a LIVE BLIND SPOT inside this gate's own defect class — not a shape outside it — and it is
+ * reached by the map's most common line: `grantsMap()['admin']` opens with five consecutive spreads
+ * before its first literal permission. Resolving `...$fragment,` means locating `$fragment = [...]`
+ * in the head seeder and extracting its enum values; that is a behaviour change with its own finding
+ * volume and its own arms, so it is a dated successor to this file rather than a rider on it. It is
+ * disclosed here because the alternative is a reader taking the sentence above at face value, and a
+ * false justification in a comment is worse than none — the next author reasons from it.
+ *
+ * (`$inferRole` resolves a REAL role at a spread line, since the spread sits inside `'<role>' => [`.
+ * Findings from that future work will be attributable and exemption 3 will apply to them normally.
+ * The `?` case is a permission added to the fragment's own DEFINITION, above `return [`.)
+ *
+ * The four exemptions:
```

`:44`'s "by construction" untouched, per your correction — it is a true, narrow claim about the
marker.

**The successor brief is owed now, not backlogged.** Resolving `...$fragment,`: locate
`$fragment = [...]` in the head seeder, extract its enum values, attribute each to the role whose
`'<role>' => [` block carries the spread. Needs its own step 0 (how many findings does the current
`RbacSeeder` diff history produce under it?) and its own arms, red first — an arm asserting today's
silence would pin the blind spot in place. Say the word and I will work it.

## The `--diff-filter=A` rule comment

```diff
  // Migrations ADDED in this diff, with their content — exemption 3.
+ //
+ // `A` ONLY, AND THAT IS A RULE RATHER THAN A CONVENIENCE. A migration already present on the base
+ // has already RUN on every seeded environment; a marker added to it now declares a convergence that
+ // never happened. Collecting `M` here would exempt on a promise nothing kept — a false green of
+ // exactly the kind this gate exists to stop. (The range is `base...head`, so a migration added in an
+ // EARLIER COMMIT of the same branch is still `A`: the ordinary workflow — lint goes red, author adds
+ // the marker to the migration they committed an hour ago — works. The gap needs the migration to
+ // predate the base, and that case is reported by $markersOnModified below, never exempted.)
  $addedMigrations = [];
```

`$markersOnModified` follows it, collected from `--diff-filter=M`, echoed on the failing path only
beside the other two notices. Never reaches `$addedMigrations` or `$declared`.

Live output (MARKER 9's fixture):

```
  1 @converges line(s) sit on a migration that is not new in this diff (they exempt nothing):
  ⚠ database/migrations/2098_01_01_000000_already_shipped.php — 1 line. This migration is already on the base,
    so it has already run; a marker added to it declares a convergence nothing
    performed. Write a NEW convergence migration and declare the pair there.
```

---

## MARKER 9 — red before, green after

Notice disabled (`if (false && $markersOnModified !== [])`):

```
failed  20 passed / 1 failed
  MARKER_9_—_a_marker_added_to_a_migration_that_is_NOT_new_in_the_diff_e ::
    … contains "sit on a migration that is not new in this diff"
```

**Exactly one arm, failing on exactly the right string.** Restored: 21 passed, 110 assertions.

The fixture is the real workflow rather than the abstract shape: the migration exists on the base
**without** a marker and the branch **edits it** to add one — which is what puts it in
`--diff-filter=M`. A migration that carries a marker on base and is untouched at head is not in the
`M` list either and produces no notice; that case is not armed, and I flag it below.

Both halves are asserted separately: `$exemptions` is `''` and the output does not contain
`declares @converges` (the rule), and the notice names the file and carries
`Write a NEW convergence migration` (the fix).

---

## MARKER 3's assertion block, before and after

You asked for this because "an existing arm went red and I edited it" is a shape that needs eyes.

**Before** (`395af20`):

```php
    expect($r['exit'])->toBe(1)
        // BOTH roles flagged — declaring nothing, not declaring auditor.
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->toContain('role: bursar')
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('2099_01_01_000000_converge.php');
```

**After** (`cef0517`, unchanged in this commit):

```php
    expect($r['exit'])->toBe(1)
        // BOTH roles flagged — declaring nothing, not declaring auditor.
        ->and($failures)->toContain('role: auditor')
        ->and($failures)->toContain('role: bursar')
        ->and($exemptions)->toBe('')
        ->and($r['output'])->not->toContain('declares @converges')
        // The line is not silently dropped either: it mentions @converges and parsed as nothing, so
        // the unparsed-marker notice names it. See MARKER 8 for the mechanism.
        ->and($r['output'])->toContain('did not parse as a declaration')
        ->and($r['output'])->toContain('2099_01_01_000000_converge.php');
```

Four assertions kept verbatim. One replaced: `not->toContain('<path>')` →
`not->toContain('declares @converges')`. The old form was a proxy for "not exempted" that happened
to work only while nothing else in the run cited the path; the new form negates the exemption claim
directly, which is what the arm was always about. Two assertions added, and the path assertion is
now **positive** — the same string, opposite polarity, because the correct behaviour inverted.

---

## `2026_08_03_100000`'s marker prose — and `2026_08_05_100000`'s

**Both needed the change, not one.** `git cat-file -e staging:<path>` succeeds for both, and
`--diff-filter=A` over `merge-base(origin/staging, HEAD)...HEAD` returns **zero** migrations. Both
sets of markers are permanently inert. Your brief named `2026_08_03_100000`; I changed both and am
flagging it rather than leaving the second worked example intact.

**`2026_08_03_100000` before:**

```
 * The pairs this migration converges, declared for `bin/ci-grants-convergence-lint.php`'s exemption 3.
 * The lint reads THESE LINES ONLY, never the prose — one line per pair, nothing else on the line.
 * These are the three ADD-side gaps named above; the other two governed roles (principal,
 * head_of_school) were already aligned by 2026_08_02_100000 and this migration converges nothing for
 * them.
```

**After:**

```
 * The pairs this migration converges, in `bin/ci-grants-convergence-lint.php`'s `@converges` syntax.
 *
 * RECORDED FOR THE READER, UNREADABLE BY THE GATE — and permanently so, not pending. Exemption 3
 * reads markers only on migrations the diff ADDS (`--diff-filter=A`), because a migration already on
 * the base has already run and a marker on it would declare a convergence nothing performed. This
 * file predates the lint and is on `staging`, so no future `base...head` will ever mark it `A`. From
 * here on, a pair needing exemption gets a NEW convergence migration and declares it there; do not
 * copy this file expecting these lines to do work.
 *
 * They are kept because they record which pairs the author actually converged, which the prose alone
 * does not state precisely: the three ADD-side gaps named above; the other two governed roles
 * (principal, head_of_school) were already aligned by 2026_08_02_100000 and this migration converges
 * nothing for them.
```

**Note on the sentence I did not fix.** "the other two governed roles (principal, head_of_school)"
is wrong — `$governed` has five members and three carry no declared pair (`finance_lead` is missing
from both the count and the list). It is on your deferred list, so I carried it through the rewrite
**verbatim** rather than quietly correcting it inside a paragraph I was already touching. Fixing a
deferred ticket by stealth is worse than the ticket.

`2026_08_05_100000` got the same lead-in, with its second paragraph kept specific to that file:
its prose names roles it EXCLUDES (`internal_auditor`) and roles it merely mentions (`registrar
cache flushed after`), which is the measurement that motivated the whole marker.

Both edits are comment-only.

---

## Your question: should the modified-migration notice be a gate?

**No — and the premise that separates it from the other two does not survive contact with the
range semantics.**

Your case for it is real: unlike the other two notices, this one has a known-wrong author action
behind it. Nobody edits a shipped migration to add a marker by accident.

But "the marker is on a migration not added in this diff" is a property of the **range**, not of
the tree, and the range moves. Three concrete cases where gating fires on something correct:

1. **The branch's own future.** A convergence migration added on a feature branch is `A` against
   the branch base. Once merged, every later `base...head` sees it as absent-from-the-diff or `M`.
   If a subsequent commit touches it for any reason — a typo, a Pint pass, a comment — a gate fires
   on a migration that did exactly the right thing when it mattered.
2. **`bin/quality-promote`.** It runs `./bin/quality origin/main`, a much wider range than the
   per-push one. A migration is `A` there and `M` (or absent) in the day-to-day range, so the same
   tree gates differently depending on which script invoked it. A gate whose verdict depends on the
   caller's base is a gate people learn to route around.
3. **Replay.** `php bin/ci-grants-convergence-lint.php <base> <head>` is documented in the usage
   block as "replay any range". Gating makes historical replay unreliable.

More decisively: **the red is already there and it is already correct.** The pair is unexempted, so
the run exits 1 and the finding names the permission and the role. Gating would add a second failure
for the same underlying fact, and the operator would then have to distinguish "the grant needs a
convergence migration" from "your marker is in the wrong file" as two separate reds when they are
one problem with one fix. The notice attaches the explanation to the red that already exists, which
is what the author needs and where they are already looking.

The one case gating would catch and the notice does not: an author who adds a marker to a shipped
migration in a diff where the pair is exempt for some **other** reason, and the misplaced marker
rots. That run is green and prints nothing. But the marker is inert, so nothing depends on it, and
the next diff that actually needs it is red — with the notice. Deferred to the moment it costs
something, not lost. Same shape as the answer I gave last round about the unparsed notice on the
passing path.

---

## What I did not do, and what is still open

- **No arm for finding 1**, per the brief — an arm asserting today's silence would pin the blind
  spot in place. It belongs to the resolution brief, red first.
- **The spread blind spot is disclosed, not closed.** `bin/quality` step 7's step-name still reads
  "a pre-existing permission added to grantsMap() ships a migration", which carries the same
  over-claim as the sentence I qualified. I left it: changing a `bin/quality` step name is outside
  a lint-comment commit, and it is one line for the successor brief to take with the fix.
- **MARKER 9 covers `M` only.** A migration carrying a marker on base and *untouched* at head is in
  neither the `A` list nor the `M` list, so it produces no notice at all. That is the same silence
  the fix removed, in a narrower case — but it is also a case with no author action behind it
  (nobody wrote a marker in that diff), so I judged it out of scope rather than fixing it silently.
  Say if you disagree.
- **`$markersOnModified` counts `@converges` with no boundary**, matching `$unparsedMarkers`. Over-
  counting produces a noise line on an already-red run.
- **Nothing driven against the dev database or a real app flow.** This commit is a lint's console
  output, its arms, and two comment-only migration edits.
- **Merge not performed.** The brief says "Then merge"; the branch is green at `4ba9125` and
  `git merge --ff-only fix/converges-marker` from `staging` is clean (no divergence — `staging` is
  still at `8c354a5`, the branch's base). I stopped short of running it and of pushing: merging into
  a shared branch and publishing are outward-facing and yours. One word and I will do it.
