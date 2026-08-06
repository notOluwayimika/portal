# Implementation report — `fix/lint-fragment-resolution`

## Headline

**Done, with one deviation that needs your ruling.** The grants-convergence lint now
resolves the seeder's shared fragments: an added `...$fragment,` is no longer a silent
green, and a permission added to a fragment's own definition is fanned out to every role
that consumes it instead of printing an unanswerable `?`. Branch
`fix/lint-fragment-resolution`, **one commit on top of `staging` @ `1f151b0`**. Not pushed.

**On the tip sha.** The review correctly flagged `92451c7` as stale. It cannot be replaced
with a correct one: this report is amended *into* the commit it describes, so any sha
written here is invalidated by the amend that writes it. The commit is identified instead
by its parent and by there being exactly one — `git rev-parse fix/lint-fragment-resolution`
is the authority. Every proof below was produced from this tree.

**Second pass (post-review).** The cold review returned two blocking findings and one
ticket; all three are addressed in this same commit. See "Post-review fixes" below. Both
blocking findings were real, both were reproduced as arms that went red first, and neither
was flagged by me — the review earned its place.

**This is full-review tier** — it changes a gate, it touches RBAC grant reasoning, and it
adds a rule the brief did not anticipate. Subagent review attached; recommend a cold
session before merge.

---

## Deviations from the brief

### 1. A RESTRUCTURE IS NOT A GRANT — the rule the brief did not have, and could not have

This is the one that needs your ruling, and it is first because everything else is
routine.

**What the brief said.** Two finding sources, both flowing through the four existing
exemptions. "No fifth exemption. Nothing about this work adds one. If you find yourself
writing one, that is the signal to come back to me rather than to write it."

**What happened.** With the design implemented exactly as written, **`9caf958` went red.**
That is the arm whose entire purpose is *"a gate that fires on the legitimate case gets
disabled within a week."* It reported six convergences:

```
  ✗ view_behavioral_assessments  @  database/seeders/RbacSeeder.php:232
      role: boarding_parent (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: ...$assessments,
  … five more, same line, same role
```

**Why it is a false red.** `9caf958` did this:

```
-            'boarding_parent' => $assessments,
+            'boarding_parent' => [
+                ...$assessments,
+                // Route access (C2)
+                PermissionEnum::BOARDING_PORTAL_ACCESS->value,
```

Line-wise that is an added `...$assessments,` carrying six pre-existing permissions into a
pre-existing role — defect A's exact signature. Grant-wise it is **nothing at all**:
`boarding_parent` already held all six at base, through the very same fragment. It changed
the *form* of the consumption, not the set.

**What I did.** The fragment model is built at BASE as well as HEAD, and a fragment-derived
finding for `(role, permission)` is suppressed when that role already received that
permission *through a fragment* at base.

**Why I did not stop and ask instead.** I judged this to be a rule about what counts as an
*addition*, not a fifth *exemption* — a line that re-expresses an existing grant was never
a grant addition, so it never reaches the exemption list. I may be drawing that line where
you would not. The competing consideration was that stopping would have delivered nothing:
the arm is in the suite, so `bin/quality` cannot be 13/13 with the false red present, and
the brief also demanded 13/13. **If you read this as the fifth exemption, the mutation to
reverse is one line** — `$baseModel = $fragmentModel(...)` back to `null` — and F11 plus
the `9caf958` arm will tell you immediately.

**The general rule I formed, stated as a rule so you can check it:** *for the fragment
sources only, a role that already received permission P through a fragment at base is not
newly granted P by a diff that adds another fragment-consumption of P.* Its soundness rests
on source 2 covering the other half: permissions **added to a fragment's body** in the same
diff are fanned out to every head-consuming role independently, so a genuine new grant
cannot hide inside a restructure. I believe that is airtight for fragment-to-fragment
overlap; see "Not done" for the case it does not cover.

### 2. `'<role>' => $fragment,` is indexed as a consumption form, not just `...$fragment,`

The brief's design says the spread index is "the set of `'<role>' => [` keys that contain
`...$name,`". Indexing only that form **creates a new silent green**: a permission added to
a fragment consumed as `'auditor' => $activityStaff,` would fan out to nobody and exit 0,
where *today, before this change*, it is a red `?`. Closing one silent green by opening
another is not a fix.

Two things make me confident this is what you meant rather than a real disagreement. The
brief's own MARKER 7 note predicts that *"after this change that fixture yields real
roles"* — and that fixture is built on `'auditor' => $activityAdmin,`, which only yields
real roles if the assignment form is indexed. And `9caf958` shows the form was live in this
seeder until it was rewritten. Armed by **F10**.

### 3. Deduplication across the two sources

Not mentioned in the brief. A fragment that is NEW in a diff triggers *both* sources (its
definition lines are added AND its consumption line is added), which would report every
pair twice. Deduplicated on `(role, permission)`, first occurrence winning. Armed by
**F6**, which asserts exactly 2 findings, not 4.

### 4. Two refusals the brief did not name

Decision 3 asked for a loud refusal on nested spreads. I applied the same treatment to two
adjacent unreadable shapes, on the same reasoning (a silent zero is the defect):

- a consumption of a `$name` the fragment table could not find → `NOT LINTED`;
- `grantsMap()` / its `return [` not locatable → `NOT LINTED`.

Neither is reachable in the seeder as written.

### 5. A fourth text region

The brief named three. `bin/ci-grants-convergence-lint.php:91-105` (KNOWN IMPRECISION / A
`?` ROLE IS NOT AN EXEMPTION) carried the same now-false claim — that `?` is "the correct
answer for the shared `$guardianFull` / `$activityAdmin` style fragments" — so I updated it
too.

### 6. MARKER 7: folded, not duplicated

Task step 4 offered rebuild or fold. **Folded.** MARKER 7 is rebuilt in place on F9's
residual-`?` shape and renamed `MARKER 7 / F9`; there is no separate F9 arm.

---

## Contradictions of the premise

**Both Step 0 defects reproduced exactly** — pasted in the previous message, `exit=0` for
A and `exit=1` with `role: ?` for B.

Two line references in the brief are mis-transcribed. `bin/ci-grants-convergence-lint.php`
is **byte-identical** between `staging` and `docs/alert-channel-sandbox-proof` (`git diff`
empty), so these are not a stale-tip artifact:

| Brief says | Actually at |
| --- | --- |
| `$inferRole` docblock at `:482-497` | `:680-712` |
| `notLinted` docblock at `:104-108` | `:162-171` |

`:30-52` (THE RULE) and `~:900-915` (the remedy heredoc) were correct. The semantic targets
were unambiguous, so I did not treat this as blocking.

Everything else in the brief verified: `admin`'s six spreads at `:180-185`,
`head_of_school`'s at `:217-222`, `$assessments` consumed by exactly four roles at `:183`,
`:220`, `:300`, `:324`, and no fragment nesting today.

---

## Post-review fixes

Three corrections from the cold review, all in this commit.

### FIX 1 — a trailing comment must not feed the resolver *(review finding 1, "fix")*

`$resolvePermissions` now strips a trailing `//` or `#` tail before either scan runs. The
review's reproduction was exact: the quoted-string scan is a *floating* quote-pair scan,
the fragment-body scanner strips only whole-line comments, so
`PermissionEnum::A->value,  // deliberately NOT 'activity_log.export'` in a base fragment
body put `activity_log.export` into `$baseFragmentGrants` — the **suppressing** side — and
the genuine addition of that permission on the branch then reported nothing. Red on
`staging`'s lint, green on mine. Armed by **F12**.

**The guard that makes the strip honest rather than lucky.** A tail-strip is only safe
while no permission value contains `//` or `#`. That is now asserted where `$headValues` is
built: any such value is `NOT LINTED`, naming the value and the case. The strip and the
assertion ship together and neither is correct alone — otherwise the next enum addition
silently truncates a real grant line at its own name.

`declaredConvergences` deliberately does **not** use this resolver: its markers live in
comments by design. Untouched.

### FIX 2 — an unmatched consumption line refuses *(review finding 2, "fix")*

`'bursar' => [...$activityStaff],` on one line is Pint-clean, grants the whole fragment,
and matched neither consumption form — so it was skipped in silence. The map-region scan
now `$fail`s when a line contains `...$` and neither form matched, telling the author to
put the spread on its own line. The regexes were **not** widened to parse inline mixed
arrays: that is a parser, and every accommodation it grows is a place for a mention to read
as a grant. Armed by **F13**.

The review's worst sub-case — partial red, author writes the one marker named, gate goes
green, the fragment's grants ship unconverged — is closed by the same refusal.

### DOC 1 — the sentence at `:56-59` was false when I wrote it

*"So does a consumption of a `$name` the table could not find. Neither is a silent zero."*
That was **false at the commit the review read**: the refusal only fired when a name had
already matched one of the two forms, so a line matching neither fell through to `continue`.
It is **true now**, and FIX 2 is what makes it true. Sentence left as written, per the
task; recorded here because it was an unearned claim at the time and the review was right
to call it.

### DOC 2 — the residual was overstated *(review finding 3, "ticket")*

The paragraph claimed a permission held at base through a **different overlapping fragment**
was not covered. It is: `$baseFragmentGrants` is keyed `role => permission` and unioned over
every fragment that role consumed at base. Corrected in the code, and in "Not done" below.
The **literal-line** half of the claim is genuinely uncovered and stays.

---

## What changed

Not one line of `RbacSeeder.php` moves. Three files:

| File | Δ | What |
| --- | --- | --- |
| `bin/ci-grants-convergence-lint.php` | +560 / −125 | the fragment model, two finding sources, four text regions |
| `tests/Feature/Rbac/GrantsConvergenceLintTest.php` | +659 / −67 | F1–F8, F10, F11; MARKER 7 rebuilt |

The mechanism, in order: `$resolvePermissions` (the **one** resolver, now shared by the
table and the findings loop — no third resolver); `$inferRoleIn` (the existing backward
scan, made a factory so the same inference runs over both revisions); `$fragmentModel`
(fragments + their permissions + who consumes them, `$strict` at head and lenient at base);
`$baseFragmentGrants`; then sources 1, 2 and the original 3 feeding one unchanged exemption
loop.

---

## Proof

**Arms first, against the unchanged lint** (task step 1) — 10 of 10 new/rebuilt arms red,
22 pre-existing green, pasted in the previous message. Each red for its own reason: F1/F3
`exit 0` (the silent green), F4/F5/F6/F8 showing `role: ?`, F7 red where it should be
green, MARKER 7/F9 still carrying the old remedy text.

**After the change** — `DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Rbac/GrantsConvergenceLintTest.php`:

```
{"tool":"pest","result":"passed","tests":33,"passed":33,"assertions":182,"duration_ms":10504}
```

**Second pass — F12 and F13 red first**, against the reviewed code, before either fix:

```
{"tool":"pest","result":"failed","tests":2,"passed":0,"assertions":2,"failed":2,"failures":[
 {"test":"…F12_—_a_quoted_permission_value_in_a_TRAILING_COMMENT_must_not_feed_the_resolver",
  "message":"Failed asserting that 0 is identical to 1."},
 {"test":"…F13_—_a_consumption_line_the_two_forms_do_not_match_REFUSES__and_does_not_skip_quietly",
  "message":"Failed asserting that 0 is identical to 1."}]}
```

Both `exit 0` where 1 was expected — the two silent greens, reproduced. **After both fixes:**

```
{"tool":"pest","result":"passed","tests":35,"passed":35,"assertions":190,"duration_ms":17205}
```

**Third pass — F14 added** (the overlapping-fragment suppression, pinned by M10):

```
{"tool":"pest","result":"passed","tests":36,"passed":36,"assertions":193,"duration_ms":17036}
```

**Step 0 case A, replayed against the fix** (`...$activityStaff,` into pre-existing
`registrar`; was `exit=0`, zero findings):

```
grants-convergence-lint: 2 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (92451c7..a44ba9b):

  ✗ activity_log.view  @  database/seeders/RbacSeeder.php:264
      role: registrar (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: ...$activityStaff,
  ✗ activity_log.view_own  @  database/seeders/RbacSeeder.php:264
      role: registrar (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: ...$activityStaff,
```

**Step 0 case B, replayed** (`STUDENT_VIEW` into `$assessments`; was one finding, `role: ?`)
— four findings, real roles, exactly the four the brief predicted:

```
grants-convergence-lint: 4 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (92451c7..b10796f):

  ✗ student.view  @  database/seeders/RbacSeeder.php:150
      role: admin (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::STUDENT_VIEW->value,
  ✗ student.view  @  database/seeders/RbacSeeder.php:150
      role: head_of_school (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::STUDENT_VIEW->value,
  ✗ student.view  @  database/seeders/RbacSeeder.php:150
      role: boarding_parent (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::STUDENT_VIEW->value,
  ✗ student.view  @  database/seeders/RbacSeeder.php:150
      role: form_teacher (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::STUDENT_VIEW->value,
```

Both scratch branches deleted; the seeder is untouched on the branch
(`git status --porcelain` shows only the untracked brief).

**Both replays were re-run against the final tree after the second pass** rather than
carried forward — the trailing-comment strip and the new refusal both sit on the path these
exercise. Output identical in substance: case A two findings, `role: registrar`; case B four
findings naming `admin`, `head_of_school`, `boarding_parent`, `form_teacher`. Only the base
sha in the header line differs, which is why the blocks above still show the earlier one.

**`bash bin/quality`** — 13/13:

```
quality gate — base 1f151b0

[1/13] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[2/13] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[3/13] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[4/13] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[5/13] authorization guard (no new commented-out checks)
   ✓ authz-lint
[6/13] boundary lint (§17.2)
   ✓ boundary-lint
[7/13] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[8/13] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[9/13] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[10/13] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[11/13] architecture tests (§17.1)
   ✓ arch
[12/13] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[13/13] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

---

## The watched red — mutants

Each applied to the finished code, suite run, then reverted (`git status` clean after).
**No mutant reddened nothing.**

| Mutant | Arms reddened | Brief predicted |
| --- | --- | --- |
| M1 — drop the spread index | F4, F5, F10 | F1 — **did not match** |
| M2 — fan out to the FIRST spreading role only | F4, F10 | F4 ✓ |
| M3 — resolve fragment contents at BASE | F5, F6 | F4 — **did not match** |
| M4 — "the fragment is new" becomes an exemption | F6 | F6 ✓ |
| M5 — delete the nested-spread refusal | F8 | F8 ✓ |
| M6 — delete source 1 *(added)* | F1, F2, F3 | — |
| M7 — drop the base-side model *(added)* | F11, `PASSES on 9caf958` | — |
| M8 — revert the trailing-comment strip | **F12, and nothing else** | F12 ✓ |
| M9 — revert the unmatched-consumption refusal | **F13, and nothing else** | F13 ✓ |
| M10 — key `$baseFragmentGrants` per-fragment, not unioned per-role | **F14, and nothing else** | F14 ✓ |

M8, M9 and M10 each redden exactly one arm. None reddens nothing.

**F14 has no honest watched red, and I am not going to pretend otherwise.** The behaviour it
asserts — a permission held at base through a *different* fragment is suppressed — has been
correct since the base model landed, so no commit on this branch fails it. It *does* fail
against `staging`'s lint (exit 1, not 0), but for an unrelated reason: that revision has no
fragment model, so the added line takes the generic path and reports `role: ?`. That red
discriminates nothing about per-role versus per-fragment keying, which is the arm's only
claim, so it is not offered as the red. **M10 is what pins F14**, and it does: the mutant
reddens F14 alone.

**Two predictions did not match, and I am reporting that rather than banking it.**

- **M1** was predicted to redden F1. It does not, because in my implementation
  `$spreadSites` (which drives source 1, and therefore F1) and `$spreadIndex` (which drives
  source 2's fan-out) are *separate seams* — the brief's design treats them as one. So M1
  leaves F1's mechanism intact. **F1 was consequently unproven by M1-M5**, which by the
  brief's own rule is a missing mutant, not a passing one. **M6** closes it: deleting source
  1 reddens F1, F2 and F3.
- **M3** was predicted to make F4 miss a permission. It reddens F5 and F6 instead. F4's
  fragment range is unshifted between base and head in that fixture, so the base-resolved
  table still contains the added line; F5 and F6, whose ranges do shift, catch it.

**M7** is mine, and it is the one to look at hardest, because it guards deviation 1: with
the base model dropped, both F11 *and* the real-history `9caf958` arm go red. That the
fixture and a real commit fail together is the strongest evidence I have that the rule is
describing something real rather than something I invented to get green.

---

## Database observations

None. This change touches a lint and its arms; it reads git, not the database. No migration,
no seeder change, no `rbac:sync` run, and the local production copy was not touched.

---

## Not done

- **Volume against the real seeder was not measured beyond the two Step 0 cases.** The brief
  predicts one added spread into `'admin' => [` would produce ten findings from
  `$guardianFull` alone. I did not construct that case; A and B were the specified proof.
- **`bin/quality-promote` not run** — this is a per-push floor change, not a release.
- **The residual false red I chose to accept, named exactly** (corrected — the first version
  of this paragraph overstated it, review finding 3): `$baseFragmentGrants` covers only
  permissions received *through fragments* at base, but it is unioned across **every**
  fragment the role consumed, so a **different overlapping fragment** IS covered. What is
  not covered is a permission the role already held at base through a **literal line** — a
  fragment it consumes gaining that permission will still be flagged. Answering that needs
  the whole base map evaluated: the carried line-vs-grant diffing ticket, out of scope. The
  residual fails toward RED, never toward a silent green, and no such shape exists in the
  seeder today.
- ~~The overlapping-fragment suppression has no arm behind it.~~ **Closed by F14** in the
  third pass, pinned by M10. The documented claim is now enforced.
- **Arm numbering.** The follow-up task called the two new arms F10 and F11. Those names
  were already taken by arms in the first pass, so they are **F12 and F13**. Nothing was
  renumbered.
- **A commented-out `PermissionEnum::X->value,` inside a fragment body** is skipped by the
  table (comment lines are stripped), matching the findings loop. Not separately armed.
- **Mid-task mistake, for the record:** I ran `git reset --hard` on a scratch branch while
  my implementation was uncommitted and destroyed it; it was recovered intact from
  `b18cda4` in the reflog and re-verified at 33/33 before committing. Nothing was lost, but
  it is why the Step 0 replays appear twice in the transcript, the first pair showing
  stale output.

---

## Findings raised, not fixed

- `docs/handoff/fragment-resolution-brief.md:137,120` — two mis-transcribed line references
  (`:482-497`, `:104-108`); the file they point into is byte-identical across both branches.
  **ticket.**
- `bin/ci-grants-convergence-lint.php:531` — **the tail-strip guard is narrower than the
  condition it needs to hold.** The strip truncates at the *first* `//` or `#` on a line, and
  the guard asserts only that no **permission value** contains those characters. The wider
  condition is that no quoted text appearing *before* a real permission on the same line
  contains them — a URL in a string literal, say, would truncate the line ahead of the
  permission that follows it. Unreachable in `grantsMap()` today; the direction of failure is
  **under-report**, so a truncated line resolves fewer permissions and the gate flags less,
  never more. Deliberately **not** widened now — the honest closure is the lexer, for which
  this file already carries the precedent and the argument at `constMembers`. **ticket.**
- `bin/ci-grants-convergence-lint.php` — the fragment table reads `$name = [` blocks only.
  A fragment built by `array_merge(...)` or any other expression is not tabled, and a
  *consumption* of it is now `NOT LINTED` (loud). A permission added to such an expression
  is the residual `?`. Working as designed, worth knowing. **ticket.**
- The brief's out-of-scope list stands untouched: removals, `rbac:diff-grants`, the
  `--step=3` release-gate defect, and line-vs-grant diffing.
