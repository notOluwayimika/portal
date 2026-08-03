# Brief — `@converges` follow-up: the two fixes before merge

Continue on `fix/converges-marker`. One more commit (two if you split the test). Everything else
from the review is a backlog ticket and must NOT land here.

Verified at `395af20` against the repo, not against your report. Your three corrections are all
accepted — see §4.

---

## Step 0 — reproduce both defects first

Two runs, paste both.

**0a. The `?`-role dead end.** Rebuild the shared-fragment fixture the reviewer used (a permission
added to a fragment above `return [`, plus a migration carrying a valid `@converges` line for each
pre-existing role that splices it). Run the lint. I expect: exit 1, the finding reads `role: ?`, the
markers appear NOWHERE — not as exempt, not as unrecognised. If they appear anywhere, stop and tell
me; my model of `:642-644` is wrong.

**0b. The silent-syntax class.** Run `declaredConvergences()` against these five contents and paste
the match count for each:

```
 * @converges auditor activity_log.view          (expect 1)
/** @converges auditor activity_log.view */      (expect 0)
 * @converges auditor activity_log.view\r\n      (expect 0)
 * @converges auditor\n * activity_log.view      (expect 0)
 * @converges auditor activity_log.view and bursar   (expect 0)
```

Four of those five are an author writing a marker in good faith and getting no signal at all. That
is fix 2's whole subject; confirm the numbers before building against them.

---

## Fix 1 — the heredoc tells a `?`-role author to do something that cannot work

**The defect.** `bin/ci-grants-convergence-lint.php:642-644`:

```php
$migration = $role !== null
    ? ($declared[$role."\0".$permission] ?? null)
    : null;
```

A null role never reaches `$declared`. The heredoc at `:734-736` nonetheless says:

> If the role above reads `?`, the addition is in a shared fragment — declare a line for every
> pre-existing role it spreads to.

That instruction is mine, from the last brief, and it is wrong. You implemented it faithfully.

**Why it matters more than a wrong sentence.** For a null role on a PRE-EXISTING permission there is
no exemption at all: exemption 1 does not need a role but the permission is not new; exemption 4 is
line-scoped to `SUPER_ADMIN_PLATFORM`; 2 and 3 both require a role. So the author follows the
instruction, ships the markers, re-runs, and gets an identical red with zero feedback. A gate with no
exit is a gate that gets switched off — and `.githooks/pre-push` runs this one.

**The change.** Replace `:734-736` (from `If the role above reads` to the end of that bullet) with:

```
      If the role above reads `?`, a marker CANNOT clear this: exemption 3 is a lookup on
      (role, permission) and there is no role to look up. The addition sits in a shared
      fragment above `return [`, so it lands on every pre-existing role that splices it and
      this lint cannot tell which. ATTRIBUTE IT — move the addition under a `'<role>' => [`
      key, or regroup the fragments beneath one ({@see $inferRole}, which carries the shape).
      Re-run; the findings then name real roles, and you declare a marker for each.
```

`$inferRole`'s docblock (`:547-578`) already documents the regroup with a worked example. This makes
the heredoc point at it instead of contradicting it. Do not change `:642-644` — the null-role
refusal is correct and its reasoning at `:635-641` stands.

**Proof.** This is a text change; there is no behaviour to watch red. Instead land the step-0a
fixture as a permanent arm — see §3, MARKER 7. That arm pins the invariant (a `?` finding is not
exemptible by any marker), which is what actually needs protecting.

---

## Fix 2 — the `⚠` echo covers typo'd operands, not typo'd syntax

**The defect.** `$unknownMarkers` (`:534-545`, echoed `:708-715`) collects markers that PARSED and
then named a role or permission absent at head. A marker that fails the regex never becomes a marker,
so it is not in that array and produces no output whatsoever. Per step 0b, that covers CRLF line
endings, a one-line `/** @converges … */`, a wrapped line and a trailing tail — four shapes, all
silent, and all likelier author errors than a misspelt role.

Reviewer finding 6 named one of them. Do not fix the instance. Count the class.

**The change.** Hoist the parse at `:534` so the result is reusable:

```php
$all = declaredConvergences($addedMigrations);

foreach ($all as $marker) {
```

Then after that loop closes (`~:545`):

```php
// A marker the author WROTE but the parser could not READ produces no ⚠ above — it never became
// a marker, so it is not in $unknownMarkers. CRLF endings, a one-line `/** @converges … */`, a
// wrapped line and a tail after the permission all fail this way, and every one of them is an
// author who believes they declared the pair. Counting mentions against parses covers the class
// rather than chasing each shape into the regex, which is how the old prose predicates grew.
$unparsedMarkers = [];
foreach ($addedMigrations as $path => $content) {
    $mentions = preg_match_all('/@converges/', $content);
    $parsed = count(array_filter($all, fn (array $m): bool => $m['path'] === $path));
    if ($mentions > $parsed) {
        $unparsedMarkers[$path] = $mentions - $parsed;
    }
}
```

Echo it on the FAILING path only, directly after the `$unknownMarkers` block at `:715`, same shape:

```
  N line(s) mention @converges but did not parse as a declaration (they exempt nothing):
  ⚠ database/migrations/2099_…_converge.php — 1 line. Check for CRLF endings, a one-line
    /** @converges … */ (the closer needs its own line), a wrapped line, or text after the
    permission.
```

**Two decisions, so you do not re-litigate them.** It is NOT a gate, for the same reason
`$unknownMarkers` is not — see the comment at `:702-707`, it still holds. And it is failing-path
only, staying symmetric with `$unknownMarkers`; the green path already prints its exemptions.

**Known false positive, accepted:** prose containing the literal `@converges` (a migration explaining
the convention) inflates `$mentions` and fires the notice. Cost is one noise line on an already-red
run. Do not build a suppressor for it — a notice that is occasionally chatty is cheaper than a
second parser, and a second parser is exactly the road that produced the defect we just removed.

---

## Tests — `tests/Feature/Rbac/GrantsConvergenceLintTest.php`

**MARKER 7 — a `?` role is not exemptible by any marker.** Promote the step-0a fixture to a
permanent arm. Permission added to a shared fragment above `return [`; an added migration carrying a
syntactically valid `@converges <role> <permission>` for two pre-existing roles that splice it.
Assert exit 1, that the failure names `role: ?`, that the exemption block does not cite that
migration, and that the failure text contains the attribution remedy (`ATTRIBUTE IT` or the
`'<role>' => [` phrasing — pick one literal and use it). That last assertion is the only thing
stopping the wrong instruction from creeping back, so state that in the arm's comment.

**MARKER 8 — a one-line docblock marker is reported, not swallowed.** Migration containing
`/** @converges auditor activity_log.view */` and nothing else marker-shaped. Assert exit 1 (it
declares nothing), and that the output carries the unparsed notice naming that file. Reverting fix 2
must turn this arm red — prove it by actually reverting, not by reading.

No arm for CRLF or the wrapped line: same mechanism, and MARKER 3 already pins the tail. One arm per
mechanism, not one per shape.

---

## What I got wrong, and what you caught

Recorded here because the next brief should not repeat it.

1. **`[ \t]` vs `\s` — you are right, I was wrong.** I ran both: `\s*$` on a trailing tail gives 0
   matches, so the `$` anchor was doing that work all along; `\s` on a two-line split gives 1, which
   is the hole the strict class actually closes. Pattern stands, reason replaced. Make sure the
   docblock at `:322` carries YOUR reason and not the brief's — the brief's is false, and a false
   justification in a comment is worse than none, because the next author reasons from it.
2. **The soft MARKER 1 fixture.** A regression arm written after the fix defaults to passing under
   the revert; only the watched red catches it. That belongs in the runbook as a standing note, not
   as a one-off anecdote — add one line to the testing section wherever watched-red is described.
3. **The fresh-install guard breaks 6 arms, not 1**, because `RefreshDatabase` runs that migration
   against an empty DB every suite run. Put that sentence in the migration's docblock: it makes the
   guard load-bearing for the whole suite, not for one arm, which changes how carefully anyone may
   touch it.

---

## Do NOT do on this branch

Four backlog tickets, confirmed in the repo, none of them belonging to this commit:

- `app/Console/Commands/RbacDiffGrants.php:51` still says `AuditDutySeparation.php:55` "emits an
  email" — stale since `809e30e`.
- Every line reference in the new `FinanceAccessGrantConvergenceTest.php` comments is +9
  (`:10` cites `:147`→ should be `:156`; `:146` cites `:75-91`→ `:84-99`; `:170` cites `:75-84`).
- `2026_08_03_100000`'s docblock `:38` says "the other two governed roles"; `$governed` at `:72` has
  five, three carry no declared pair — `finance_lead` is missing from both the count and the list.
- The report's §Step 0 "after the change" paste is labelled branch head but ran at `8c354a5`
  (= staging, the parent of `8122da3`). Conclusion holds; relabel the artifact.

---

## Report

Run `bin/quality` (13/13) before reporting. Report as:

- step 0a and 0b output verbatim, and whether each matched my prediction
- the diff of the heredoc change
- MARKER 8: red-before / green-after, proven by actually reverting fix 2
- MARKER 7: what literal you asserted on and why
- anything here you think is wrong — specifically, whether the mention-count notice should fire on
  the passing path too, and whether the false positive above is worth suppressing after all