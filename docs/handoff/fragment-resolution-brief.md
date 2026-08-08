# Fragment-resolution brief — `bin/ci-grants-convergence-lint.php`

Successor to the blind spot disclosed in `converges-marker-followup-2-brief.md` (Finding 1). That
brief landed the disclosure and said the resolution would be its own brief. This is it.

Everything below was read at `docs/alert-channel-sandbox-proof` before it was written. Line numbers
are from that tip.

---

## What this closes — two defects, one cause

**A. The silent green.** An added `...$fragment,` line resolves ZERO permissions. Permission
resolution (`:740-758`) accepts exactly two forms: `PermissionEnum::X->value`, and a quoted string
that is a real enum value at head. A spread line is neither. So it produces no finding and the run
exits 0 — while granting every permission in that fragment to a pre-existing role, which
`rbac:sync` will not apply.

This is not an exotic shape. `grantsMap()`'s `'admin' => [` opens with SIX consecutive spreads
before its first literal permission (`RbacSeeder.php:180-185`), and `'head_of_school' => [` repeats
the same six (`:217-222`). It is how the map is written.

**B. The dead end.** A permission added to a fragment's own DEFINITION (above `return [`) resolves
fine, but `$inferRole` (`:713-726`) scans backwards, hits `return [`, and returns null. The role
prints `?`. A null role disables exemption 2 and exemption 3 — so the addition is flagged with no
path to green. No marker can clear it: exemption 3 is a lookup on (role, permission) and there is
no role to look up.

The lint's own remedy text tells the author to restructure the seeder — "ATTRIBUTE IT — move the
addition under a `'<role>' => [` key, or regroup the fragments beneath one". **That advice is worse
than the defect it answers, and the file already knows it.** The `$inferRole` docblock at `:482-497`
records that regrouping fragments under a role key silently attributes every later fragment
addition to that key, and becomes a SILENT GREEN when the key lands in `$newRoles`.

So the gate is silent where it should speak (A), speaks where it cannot be answered (B), and its
published fix for B manufactures a fresh instance of A's failure mode.

---

## Step 0 — prove both, before changing one line

Scratch branch off `staging`. Two commits, thrown away after. Replay with the documented two-arg
form (`php bin/ci-grants-convergence-lint.php <base> <head>`), not through `bin/quality`.

```
git switch -c scratch/fragment-blindspot staging

# --- A: an added spread into a PRE-EXISTING role, with PRE-EXISTING permissions ---
# In RbacSeeder::grantsMap(), add `...$activityStaff,` inside 'registrar' => [.
# registrar is pre-existing; activity_log.view and activity_log.view_own are pre-existing.
git commit -am "scratch: spread activityStaff into registrar"
php bin/ci-grants-convergence-lint.php staging HEAD; echo "exit=$?"

# --- B: a permission added to a fragment DEFINITION ---
git revert --no-edit HEAD
# Add PermissionEnum::STUDENT_VIEW->value to $assessments (:143-150). Pre-existing permission.
git commit -am "scratch: add a pre-existing permission to the assessments fragment"
php bin/ci-grants-convergence-lint.php staging HEAD; echo "exit=$?"
```

Expected TODAY, and paste both raw:

- **A → `exit=0`.** Zero findings. This is the silent green.
- **B → `exit=1`,** one finding, `role: ?`, and the failure text recommending the regroup.

If either does not reproduce, **stop and tell me.** The design below rests on both, and a design
built on a defect that isn't there is worse than no design.

Then `git switch staging && git branch -D scratch/fragment-blindspot`. Nothing from step 0 is kept
except the pasted output.

---

## The design

One new structure, built from the HEAD seeder only, over the region between `function grantsMap()`
and its `return [`:

**The fragment table.** For each `$name = [` … `];` in that region: the fragment's name, its line
range, and its permissions — resolved by the SAME two forms the lint already uses. No third
resolver. A form the existing resolver cannot read is a permission the fragment does not carry, and
that is already the rule everywhere else in this file.

**The spread index.** For each fragment, the set of `'<role>' => [` keys that contain `...$name,` at
HEAD, using the existing `$inferRole` to attribute each spread line. Those roles are real — a spread
sits inside a role key, not above `return [`, so the null case does not arise here.

Then two new finding sources, both flowing through the existing four exemptions unchanged:

1. **An ADDED `...$name,` line at line L.** `$inferRole(L)` gives role R. Emit one finding per
   permission in fragment `$name`, each attributed to R.
2. **An ADDED permission line inside `$name`'s definition.** Emit one finding per (role in the
   spread index for `$name`) × that permission.

**No fifth exemption.** Nothing about this work adds one. If you find yourself writing one, that is
the signal to come back to me rather than to write it.

Concrete effect: adding one permission to `$assessments` today produces one finding with `?` and no
way to answer it. After this change it produces four — `admin`, `head_of_school`, `boarding_parent`,
`form_teacher` (`:183`, `:220`, `:300`, `:324`) — each attributable, each markable, each honestly
requiring its own convergence.

---

## Five decisions, already made, with the reason attached

**1. A NEW fragment is not an exemption.** A fragment added in this diff, spread into a pre-existing
role, carrying pre-existing permissions, is the defect in full. The fragment being new says nothing
about the permissions. This is the tempting fifth exemption; arm F6 exists to prove it stays absent.

**2. No fragment-level marker.** `@converges admin ...$guardianFull` is rejected. The fragment's
contents change after the migration is written, so such a marker would exempt permissions the
migration never granted — silently, and later. Exemption 3 is per-pair or it is nothing. Ten grants
means ten marker lines, and that is the honest cost of ten grants.

**3. A nested spread refuses loudly.** If a fragment definition contains its own `...$other,` line,
call `notLinted()` and name the fragment. Do not resolve transitively and do not skip quietly. No
fragment nests today (verified at `:109-169`), so this costs nothing until someone writes one — at
which point the gate says so instead of guessing. Use the existing `notLinted()`; do not invent a
second not-looked message, for the reason its own docblock gives at `:104-108`.

**4. Removals stay out of scope.** The lint sees additions. A REMOVED spread is the carried
"blindness to removals" ticket and is not this work.

**5. `?` gets rarer, not gone — and its remedy text must change.** After this, a `?` means the
addition is above `return [` and inside no fragment the table resolved: a parser gap, not a fragment.
It must still flag. The failure text must stop recommending the regroup, because following that
advice is now strictly worse than leaving the code alone.

---

## Text that must change in the same commit

- **`:30-52`, THE RULE.** followup-2's disclosure says the lint does NOT resolve `...$fragment,`.
  It will. Replace it — do not delete it — with what it now resolves and what it still does not
  (nested spreads, which refuse rather than pass).
- **`:482-497`, the `$inferRole` docblock.** The regroup stops being a recommendation. Keep the
  hazard on the record; drop the sentence that reads as advice.
- **The remedy paragraph in the final heredoc (`~:900-915`).** "ATTRIBUTE IT — move the addition
  under a `'<role>' => [` key, or regroup the fragments beneath one" is now wrong advice pointing at
  a known silent-green. Replace with the marker instruction, since after this change there IS a role
  to declare.

---

## Arms

Follow the existing harness in `tests/Feature/Rbac/GrantsConvergenceLintTest.php` — `gclCommit`,
`gclBlob`, `gclFixtureBase`, `gclTwoRoleBase`. If that harness cannot express a fragment fixture,
say so in your report BEFORE writing arms; do not quietly build a second harness beside it.

- **F1** — added spread into a pre-existing role → N findings, real role name, exit 1.
- **F2** — F1 plus a migration declaring all N pairs → exit 0, N exempt.
- **F3** — F1 plus a migration declaring all but ONE pair → exit 1 naming exactly that pair.
- **F4** — permission added to a fragment definition → one finding per spreading role, no `?`.
- **F5** — F4 where one spreading role is NEW in the diff → that one exempt by 2, the others flagged.
- **F6** — fragment NEW in this diff, spread into a pre-existing role, pre-existing permissions →
  still flagged. This is decision 1, proven.
- **F7** — permission added to a fragment spread by NO role → zero findings, exit 0. Correct: nothing
  is granted.
- **F8** — a fragment containing a nested spread → `NOT LINTED`, exit 1.
- **F9** — the residual `?`: a shape above `return [` that is inside no resolved fragment.

**MARKER 7 goes vacuous if you leave it.** `it('MARKER 7 — a ? role is NOT exemptible by any marker')`
at `:900` is built on the fragment case. After this change that fixture yields real roles and the arm
stops asserting what its name says. Rebuild it on F9's shape, or fold the two. Either is fine;
leaving it green and meaningless is not.

---

## Mutants

Each one, applied to the finished code, then the suite:

- **M1** — drop the spread index (fan out to nothing). Expect F1 green.
- **M2** — attribute the fan-out to the FIRST spreading role only. Expect F4 partially green.
- **M3** — resolve fragment contents at BASE rather than HEAD. Expect F4 to miss a permission added
  in the same diff.
- **M4** — make "the fragment is new in this diff" an exemption. Expect F6 green.
- **M5** — delete the nested-spread refusal, resolve one level and stop. Expect F8 green.

Report which arms redden for each. **A mutant that reddens nothing is a missing arm, not a passing
mutant** — say so plainly rather than banking it, the way you did on commit 6's L and N.

---

## What does NOT land here

Not one line of `RbacSeeder.php` moves in this work. No regrouping, no reordering, no new fragments.
The whole point is that the lint learns to read the seeder as written. If the fix requires touching
the seeder, the fix is wrong.

Also out: removals, `rbac:diff-grants`, the `--step=3` release-gate defect, and the convergence
lint's line-vs-grant diffing ticket.

---

## Severity and expected volume

**fix, not stop.** The silent green is real and it is in this gate's own defect class, but it fires
only on a diff that adds a spread — and the last one of those is already merged and already
converged.

Expect finding volume to rise sharply on any diff that touches a fragment: one added spread into
`'admin' => [` would produce ten findings from `$guardianFull` alone. That is not noise. Ten grants
that `rbac:sync` will not apply need ten convergences, and the whole reason this lint exists is that
nobody could see them.
