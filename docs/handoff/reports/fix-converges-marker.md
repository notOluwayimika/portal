# Report — `@converges` marker: exemption 3 stops reading prose

**Branch:** `fix/converges-marker` (2 commits) · **Base:** `staging` @ `8c354a5`
**Brief:** `docs/handoff/converges-marker-redesign-brief.md`
**Tier:** full review — this touches a gate, an RBAC lint, and two shipped migrations.
Subagent review attached; recommend a cold session before merge.

Shape: 1 lint + 1 lint test + 1 migration test + 2 migration docblocks (commit 1),
1 console command line (commit 2).

- `8122da3` fix(ci): grants-convergence exemption 3 reads a declaration, not prose
- `809e30e` fix(rbac): audit output prints user ids, never email addresses

---

## Deviations from the brief, at the top

**1. The brief's stated reason for `[ \t]` over `\s` is wrong, and I said so in the code
rather than repeating it.** The brief (§1) says a `\s*$` tail "would let the tail run onto
the next line and a trailing-prose smuggle would pass". It would not. `\s*` cannot cross
non-whitespace, so `@converges auditor activity_log.view and also bursar` fails to match
under *both* forms. Measured:

```
                                           tight=[ \t]   loose=\s
A: " * @converges auditor\n   activity_log.view\n"     -            activity_log.view
B: "// @converges auditor activity_log.view and also bursar\n"   -            -
C: "// @converges auditor activity_log.view\n\nbursar excluded\n" activity_log.view  activity_log.view
```

The character that blocks case B is the **`$` anchor**, not the character class. What `\s`
actually opens is case A — assembling one marker from two lines. Both facts are load-bearing,
for different reasons, so the pattern is unchanged from the brief; only the *explanation*
in the lint docblock and in the test comment differs, and both now say which character does
which job. I proved this by mutation, not by reading (§ Watched reds, mutation 2 vs 3).

**2. I did not run `./bin/quality origin/main` for step 0 — I ran the lint alone against
`origin/main`.** `bin/quality:141` invokes it as `php bin/ci-grants-convergence-lint.php
"$BASE"`, so the section the brief asked for is exactly this command's output. `bin/quality`
on HEAD (13/13) was run separately at the end.

**3. One extra edit the brief did not name.** `constMembers()`'s docblock carried a dangling
`{@see namesPermission}` cross-reference (`:168`) that would have pointed at a deleted
function. Repointed at `declaredConvergences`.

**4. I added the unrecognised-marker test arm (brief §7 said "unless it falls out easily").**
It did — one fixture, two halves. Without it, step 7's code is unexercised.

**5. `:147` (governed role missing) left unarmed**, as the brief directed. I agree: it
throws loudly and its sibling `:110` is now armed, which is the exit that could have gone
quiet.

---

## Step 0 — the release window

Prediction in the brief: `2026_08_05_100000_converge_finance_access_grants.php` is an ADDED
migration in the release-scoped diff but exempts nothing there, so tightening exemption 3
cannot turn the release gate red.

```
$ php bin/ci-grants-convergence-lint.php origin/main
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (1ee3d59..8c354a5; 0 exempted).
EXIT=0
```

**Matched.** `0 exempted` — no exempted line cites that migration, or anything else. Same
command after the change, byte-identical output:

```
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (1ee3d59..8c354a5; 0 exempted).
EXIT=0
```

The reason it is 0 rather than "exempt via the migration": the release-scoped diff of
`RbacSeeder.php` (`1ee3d59...8c354a5`) adds no grant line at all — that convergence fixed
historical drift and never touched `grantsMap()`. Nothing to exempt, so no path through
exemption 3 was ever taken.

---

## What changed in the lint

`bin/ci-grants-convergence-lint.php`:

- **`declaredConvergences(array $migrations)`** replaces `namesPermission()` and
  `namesRole()`. Line-anchored `preg_match_all` over each added migration's content, `/m`,
  returning `{path, role, permission}` triples unvalidated.
- **Extraction happens once**, next to `$addedMigrations`, into `$declared["role\0permission"]
  => path` (first declaration wins, matching the old `break`) plus `$unknownMarkers` for
  markers whose role is not in `RbacSeeder::ROLES` at head or whose permission is not an enum
  value at head. Membership of `$headRoles` is tested rather than `$headRoles ∪ $newRoles`
  because `$newRoles = array_diff($headRoles, $baseRoles)` is a subset of `$headRoles` by
  definition — noted in the code so nobody "fixes" it later.
- **Exemption 3 is now one array read.** The `$role !== null` guard is unchanged and still
  load-bearing.
- **Message** is now `migration [<path>] declares @converges <role> <permission>`.
- **Unrecognised markers are echoed on the failing path only**, under the exemption list,
  with `⚠ <path> declares @converges <role> <permission> — no such role|no such permission`.
  Not a gate, per brief §7.
- **The header docblock's exemption 3 and the failure heredoc** are rewritten. The heredoc
  now prints the marker syntax literally, names the three permitted lead-ins, says prose is
  no longer read *and why*, and keeps the `?`-role instruction.
- **The deleted predicates' derived facts are preserved** in `declaredConvergences`'s
  docblock — the 9 prefix / 0 suffix / 0 mid-string permission shape, the
  `admin ⊂ super_admin` / `teacher ⊂ form_teacher` role shape, and the point that both
  boundaries perfectly placed still cannot tell an assertion from a mention. That is the
  argument for the marker, so it stays in the file.

Backfilled markers (comment-only, in the docblock):

- `2026_08_03_100000_converge_finance_change_grants.php` — 3 pairs
  (`accounts_officer` × fee-schedule/discount-policy submit, `accounts_supervisor` ×
  fee-schedule submit).
- `2026_08_05_100000_converge_finance_access_grants.php` — 2 pairs
  (`head_of_school`, `principal` × `finance.access`).

**I declared only the pairs each migration actually converged, not every pair it governs.**
Both migrations force a target derived from `grantsMap()` across a wider governed set
(6 roles and 5 roles respectively), but the roles that were already aligned had nothing
converged for them. Over-declaring would create a false exemption; under-declaring creates
a false red. This repo's stated preference is the red, and the brief's own worked example
names exactly the two drifted roles. Stated in each docblock so the choice is visible.

---

## Existing arms updated

| Arm | Change | Message change |
| --- | --- | --- |
| `exemption 3 — exact vs longer sibling` | Fixture migrations became `// @converges auditor <permission>`; moved from the one-role to a new two-role base so both halves can also assert what is *not* exempt | `in this diff names it` → `declares @converges auditor activity_log.view` |
| `4c — role A does not exempt role B` | Fixture migration became `// @converges auditor activity_log.view` | `names it AND names role [auditor]` → `declares @converges auditor activity_log.view` |

Both arms keep their subject. The exemption-3 arm's comment now states explicitly that the
mechanism moved from a boundary regex to exact equality, and tells a future reader **not** to
re-derive the prefix-pair argument from it — that argument now lives in the lint's own
docblock, and what the arm pins is that equality is equality.

New shared test helpers: `gclTwoRoleBase()` / `gclTwoRoleWithGrant()` (a two-role fixture, so
"declares one, must not exempt the other" is expressible), and `gclSplit()` which splits a
failing run's output at `were EXEMPT` so an arm can assert on the failure block and the
exemption block separately. A bare `toContain('auditor')` over whole output cannot tell
"flagged" from "exempt", and that distinction is the entire subject of these arms.

---

## New arms — red before, green after

All six are in `tests/Feature/Rbac/GrantsConvergenceLintTest.php`.

| Arm | Asserts |
| --- | --- |
| MARKER 1 — prose is not a declaration | Migration declares `auditor` and documents in prose that `` `bursar` deliberately does NOT receive `activity_log.view` ``. Exit 1; failure block names `role: bursar` and not `role: auditor`; exemption block names auditor and not bursar |
| MARKER 2 — no marker at all | Migration names both in prose, no marker. Exit 1, exemption block empty |
| MARKER 3 — trailing prose does not smuggle | `@converges auditor activity_log.view and also bursar`. Exit 1 for **both** roles; nothing exempt; the migration path is not cited |
| MARKER 4 — multi-pair | Two marker lines exempt both pairs in one run. Exit 0, both cited |
| MARKER 5 — docblock lead-in | ` * @converges …` inside `/** */` exempts identically |
| MARKER 6 — unrecognised marker | Typo'd role → `— no such role`; typo'd permission → `— no such permission`; exit 1 both times |

### Watched red 1 — revert the mechanism, keep the message

I reinstated `namesPermission()`/`namesRole()` and the old `foreach ($addedMigrations …)` loop,
leaving the new exemption *message* in place so the arms are testing behaviour and not string
matching. Raw:

```
$ pest tests/Feature/Rbac/GrantsConvergenceLintTest.php --filter="MARKER"
{"result":"failed","tests":6,"passed":3,"failed":3,"failures":[
  "MARKER_1_—_PROSE_IS_NOT_A_DECLARATION…"  "Failed asserting that 0 is identical to 1.",
  "MARKER_2_—_NO_MARKER_AT_ALL…"            "Failed asserting that 0 is identical to 1.",
  "MARKER_3_—_TRAILING_PROSE_DOES_NOT_SMUGGLE…" "Failed asserting that 0 is identical to 1."]}
```

Exactly the three regression arms, each failing on `exit 0` where the fix gives `exit 1`.
MARKER 4/5/6 pass under the revert by design — the free-text predicate also exempts those
fixtures, so they pin the marker's *positive* behaviour and the echo, not the regression.
After restore: `18 passed, 92 assertions`.

**A correction I had to make mid-proof, recorded because it is the interesting part.** My
first MARKER 1 fixture said "The other seat deliberately does NOT receive…" instead of naming
`bursar`. It passed under the revert — the old `namesRole('bursar')` was false, so the arm was
green for the wrong reason and would have shipped as wallpaper. Naming `bursar` in the prose
is what makes the arm bite. This is the brief's fixture, verbatim; I had softened it and the
watched red is the only thing that caught it.

### Watched red 2 — loosen `[ \t]` to `\s`

```
$ pest … --filter="MARKER 3"
{"result":"passed","tests":1,"passed":1,"assertions":5}
```

**Still green.** This is the measurement behind deviation 1: `\s` does not open the
trailing-smuggle hole.

### Watched red 3 — drop the `$` anchor

```
$ pest … --filter="MARKER 3"
{"result":"failed","tests":1,"failed":1,
 "message":"Failed asserting that '…✗ activity_log.view @ …RbacSeeder.php:23\n
   role: bursar (INFERRED…)…' contains \"role: auditor\"."}
```

Red, and for the right reason: without the anchor the smuggled line declares `auditor`, so
only bursar is flagged. **The `$` is the load-bearing character**, and MARKER 3 pins it.

---

## The two unproven exits on `2026_08_05_100000`

Added to `tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php`:

- **ARM 5 — fresh install is a quiet green.** Delete every `finance.%` permission row, run
  `up()`. Asserts it returns without throwing, and that `role_has_permissions` count,
  `activity_log` count and `MAX(activity_log.id)` are all unmoved. Grant count is captured
  *after* the delete, because both permission pivots carry `ON DELETE CASCADE` on
  `permissions.id`.
- **ARM 6 — broken substrate aborts.** Delete only `finance.access`, assert the rest of the
  namespace is still present (so the guard has a real decision), then assert `up()` throws a
  `RuntimeException` whose message names `rbac:sync`.

### Watched red — narrow the guard to `finance.access` alone

The one-word edit that reads like a tightening:

```php
-  ->where('name', 'like', 'finance.%')
+  ->where('name', 'finance.access')
```

```
{"result":"failed","tests":6,"passed":5,"failed":1,"failures":[
  "ARM_6_—_broken_substrate…","Exception \"RuntimeException\" not thrown."]}
```

**ARM 6 red and nothing else** — a clean isolation of the exact failure mode: the migration
would return a quiet green with the grant never written, which is the one outcome nobody
would ever see. Restored: 6 passed, 38 assertions.

### Watched red — disable the guard entirely (`if (false && ! $financeSubstrate)`)

All six arms error, not just ARM 5:

```
converge-finance-access-grants ABORTED: target permission(s) absent from the permissions
table — run `php artisan rbac:sync` first, then re-migrate: finance.access
```

**Worth knowing and not something I expected:** `RefreshDatabase` runs this migration against
an empty database on every suite run, so the fresh-install guard is on the hot path of the
test suite itself, not just of a real `migrate:fresh`. ARM 5 goes red as intended, but the
mutation is too blunt to isolate it. ARM 6's mutation above is the surgical one.

---

## Third: the audit output line

`app/Console/Commands/AuditDutySeparation.php:55` printed `$user->email ?? ('user#'.$user->id)`
into `finance:audit-duty-separation`'s findings table. Now `'user#'.$user->id`
unconditionally, `??` dropped. Separate commit (`809e30e`), revertible independently.

Verified there is no other sink — the findings array feeds `$this->table()` at `:74-77` only;
no CSV, no export, no log write. No test asserts on that column
(`grep -rn "audit-duty-separation" tests app bin docs` → one comment reference in
`GrantsMapSeparationTest.php:7`, no assertion).

**One cosmetic consequence I did not fix**, because fixing it would widen a deliberately
one-line commit: `:73` sorts findings by `[$school_id, $user]` as strings, so ids now sort
lexically — `user#10` before `user#2`. Table ordering only; no correctness effect. Flagging
it rather than folding it in.

---

## Gates

```
$ ./bin/quality
quality gate — base 8c354a5
 [1/13] wayfinder:generate ✓   [2/13] lint-changed ✓        [3/13] tsc-ratchet ✓
 [4/13] build ✓                [5/13] authz-lint ✓          [6/13] boundary-lint ✓
 [7/13] grants-convergence-lint ✓                           [8/13] money-lint ✓
 [9/13] runtime-zero-lint ✓    [10/13] identifier-generation-lint ✓
 [11/13] arch ✓                [12/13] larastan ✓           [13/13] test-ratchet ✓
✓ quality: PASS — per-push floor.
```

Targeted: `GrantsConvergenceLintTest` 18 passed / 92 assertions;
`FinanceAccessGrantConvergenceTest` 6 passed / 38 assertions. Pint clean on all six changed
files.

---

## What I did not do, and what I am unsure about

- **CRLF residual, recorded in the lint docblock, not handled.** The anchor is LF-shaped: a
  CRLF-authored migration leaves `\r` before the line end, `[ \t]*$` does not match it, the
  marker declares nothing and the run goes red. Safe direction, and every file here is LF —
  but the author would get a red they cannot explain, and step 7's echo would not fire either
  (the line matches nothing at all, so there is no marker to report). If you want that closed,
  `\r?` before `$` costs nothing and opens no hole. I left it out because the brief said not to
  loosen the anchor and this is a judgement call about what counts as loosening.
- **Duplicate-pair markers across two added migrations** resolve to the first migration
  encountered, silently. That matches the old `break`, and I did not add a warning.
- **No arm covers a marker in an added migration that is *not* under `database/migrations/`.**
  `$addedMigrations` already filters on that prefix and that filter is unchanged, so nothing
  new is at risk — but it is untested, as it was before.
- **The backfilled markers are functionally inert today** (neither migration is an added
  migration in any diff that changes `grantsMap()`), so nothing in the suite exercises them.
  Their value is as a template. If you want them load-bearing, that needs a diff replay arm
  I did not write.
- **I did not verify the marker against a real future convergence migration**, because there
  isn't one. Everything above is fixtures plus the two shipped migrations' docblocks.
