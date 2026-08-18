# Implementation report — `feat/guardian-merge-command`

**This is full-review tier** — it writes to an append-only audit trail, it moves
rows across a `school_id` boundary, it can end and restore a parent's portal
login, and it is the engine slice 3's data migration will run against production
data.

**Revision 5.** Four guards have been shipped on this branch and three of them
were wrong on first writing. The sequence is in **Superseded decisions** and is
still the most useful thing in this document.

⚠️ **THE RATCHET IS RED ON THE FINAL TREE, and not on anything this branch
touches.** Two full-suite runs, two different subsets of
`tests/Feature/Rbac/GrantsConvergenceLintTest.php` failing. I have a mechanism
for it, evidence for the mechanism, and a ticket; I have **not** retried until
green and I have **not** baselined it. Read *Full suite + ratchet* before
anything else, because it is the reason this report cannot say the branch is
clean.

---

## Headline

Done. `GuardianService::merge()` with an opt-in `--consolidate-login` path and
four login refusals, plus `guardians:merge` and `guardians:find-duplicates`, and
36 arms in `tests/Feature/Guardian/GuardianMergeTest.php`. Branch
`feat/guardian-merge-command`, cut from `staging` @ `e484a46` (`staging` and
`main` are the same commit today — re-derived). Five commits. Not pushed; no PR.

This round closed the mirror of last round's defect — consolidation could clear
`disabled_at` on a keeper account another school had deliberately revoked — and
ticketed three.

## Deviations, and one of them is mine to own

**Commit hygiene.** Last round I ran `git add -A docs/handoff/`, which swept two
untracked brief files into the commit, one of which I had never opened. I caught
it on the status output, reset soft, unstaged them and re-committed. Nothing was
published and the branch history carries no trace of it. For the rest of this
branch I have staged explicit paths only. `docs/handoff/briefs/` remains
untracked and unread.

**On the ruling: none.** All four items of finding 1 are implemented as written,
and findings 2–4 are ticketed.

**One interruption to declare.** This session was cut off mid-round by a
server-side error, after `6713889` was committed and while the clean suite re-run
was in flight. On resuming I re-derived the tree rather than trusting memory —
36 arms present, the gate and both plan values present, the decision-table column
present, zero `WATCHED RED` residue — and only then finished this report. The
interruption changed nothing in the commit; it is recorded because a reader
comparing timestamps would otherwise find a gap.

**One judgement inside item 2, stated because it is the difference between a
guard and noise.** The ruling said to refuse "on the WRITE being reachable", not
on exclusivity alone. Getting that right needed *two* facts about the keeper's
account, not one, and they are deliberately separate values
(`app/Services/GuardianService.php:1029-1046`):

- `keeper_school_exclusive` — the **full** sense: nothing else lives on this
  account anywhere, this school included. It words the "reverse `--keep` and
  `--absorb`" remedy, which has to match what the donor gate asks.
- `keeper_other_school_ids` — the **narrow** sense: live records outside the
  school this merge is running in. That is what the re-enable gate uses, because
  the hazard is *another* school's revocation being undone; a sibling row in this
  school is this merge's own business.

Refusing on the full sense would have fired where nothing was at stake. That is
how a guard gets switched off by the next person, and it is also exactly the
conflation the reviewer found on the donor side — see finding 4's ticket, which
records this pair as the worked precedent for fixing it there.

**Item 4 of findings 2–4: checked whether the same-school exclusion was the
one-line change the ruling would have had me do inline. It is not.** Making the
donor *message* right while keeping the *gate* right needs a split, not a filter:
the gate is `orphanedUserIdsAfterMerge` and must keep counting same-school rows,
so the fix is a new plan value carrying the sibling guardian **ids** (the remedy
must name the rows to absorb), a narrowed `remaining_school_ids`, a third remedy
branch, a table change and an arm per branch. Ticketed, with that shape written
out.

## Superseded decisions

**Revision 1 — the pivot flag travels with the row.** `can_login` was re-pointed
onto the keeper regardless of which `users` row it had been on. The login lives
on `users`; moving the flag strands the account.

**Revision 2 — refuse any cross-account `can_login`.** Right intent, wrong key.
Authentication reads none of the pivot (`FortifyServiceProvider.php:50-51`).
Against the 14 duplicate groups in the production copy this refused **1** and
waved through **13**.

**Revision 3 — re-keyed to "can this account authenticate".** Correct as far as
it went, and it missed that `users.disabled_at` is a property of the **account**:
consolidating in school A revoked access at school B.

**Revision 4 — guarded the donor side of that write only.** The same function
also clears `disabled_at` on the **keeper**, and that half went unguarded, while
the fact needed to guard it was computed and used only to word a message.

**Current.** Four refusals, in order: a live donor account refuses unless
`--consolidate-login`; consolidation refuses into a keeper that cannot be mailed;
consolidation refuses when a **disabled** keeper account still serves another
school; consolidation refuses when a donor account is not school-exclusive.

The pattern, now four for four and worth stating as a rule rather than a story:
**each wrong version was scoped to the record in front of it while the write
reached further.** Revision 4 is the sharpest instance, because by then the rule
was written down in this very report and still got applied to only one half of a
two-sided write.

## What this revision changed, against the ruling

**1. The keeper-side re-enable is gated.** `keeper_re_enable_blocked`
(`app/Services/GuardianService.php:1046`, in the plan at `:1059`) and the fourth
refusal at `:1168`. It fires only when the keeper account is disabled **and**
still backs live guardian records outside this school — so an already-enabled
keeper is never refused, because there is no `disabled_at` to clear and nothing
another school could lose.

**2. `keeper_school_exclusive` is a gate's input, not prose.** It and
`keeper_other_school_ids` both feed the decision; the refusal message names the
`school#<id>`s, says the account may have been disabled there deliberately, and
says the only trail would be a `login_enabled` on a guardian in *this* school —
"a trail they cannot see" — then gives the two real actions: confirm with the
other school and re-enable it there first, or keep a guardian record whose
account is not shared.

**3. Both facts print in the decision table, in both modes**
(`app/Console/Commands/MergeGuardians.php:168-185`). The surviving-account line
now reads `used by this school only` / `also backs another guardian record in
this school` / `ALSO SERVES school#<id>, …`, and a `⚠` line appears when the
re-enable is blocked. A fact computed, used to word a message and never shown was
the shape of the defect being fixed.

**4. Three arms** (`GuardianMergeTest.php:394`, `:425`, `:457`): shared **and
disabled** keeper + `--consolidate-login` → refused with `disabled_at` unchanged
and the other school's row untouched; shared but **already enabled** → proceeds,
because there is no write to gate; **exclusive and disabled** → proceeds and
re-enables.

**5. Findings 2, 3 and 4 ticketed.** The rotation ticket is **amended, not
replaced**: a new *cross-school case* section, and its "Not this ticket" section
is rewritten to say the parked question — what the email says — is now the
**first** thing to decide, because a password email naming one school's children
for a credential governing two schools is a message the parent cannot act on.
`guardian-merge-notification-takes-its-school-from-users-school-id.md` is new and
states the legacy-versus-fresh distinction explicitly: the helper is old, but
`merge()` is a new caller that *has* the right value in scope and does not pass
it, which is what ADR 0042's expiries exist to stop being added to.
`guardian-merge-non-exclusivity-refusal-counts-its-own-school.md` is new and
records that it falsifies a property this report made load-bearing last round.

## Contradictions of the premise

**None.** The reviewer's finding-1 mechanism verified in the tree:
`applyLoginConsolidation` clears `disabled_at` unconditionally on `$keeper->user`;
`keeper_school_exclusive` existed and gated nothing; `render()` printed neither
it nor the other-school list.

## What changed

Five commits. Re-derived at the moment of writing:

| File | Size | What |
| --- | --- | --- |
| `app/Services/GuardianService.php` | **+917, −0** (`git diff --numstat e484a46`) | `merge()` and fourteen private helpers. Nothing pre-existing modified. |
| `app/Console/Commands/MergeGuardians.php` | **257** | `guardians:merge --keep= --absorb=* [--apply] [--consolidate-login]`. |
| `app/Console/Commands/FindDuplicateGuardians.php` | **229** | `guardians:find-duplicates [--school=]`. |
| `tests/Feature/Guardian/GuardianMergeTest.php` | **1048** | **36** arms (`grep -c "^it("`). |
| `docs/handoff/tickets/` | 8 files | Two corrected in place, one amended, five new across the rounds — including the git auto-gc ticket raised this round. |

## Proof

Raw output. This harness replaces Pest's stdout with a JSON summary line.

### Targeted file

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never
{"tool":"pest","result":"passed","tests":36,"passed":36,"assertions":170,"duration_ms":34206}
```

### The guardian directory — re-run on this tree

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian --colors=never
{"tool":"pest","result":"passed","tests":55,"passed":55,"assertions":193,"duration_ms":20573}
```

`GuardianLoginInvariantTest` and `DeliverableEmailPredicateTest` are in it,
unmodified, green.

### Full suite + ratchet, on the final tree

**RED, twice, on a file this branch does not touch.** Raw, from the clean re-run
on the committed tree:

```
$ DB_DATABASE=portal_testing php vendor/bin/pest --log-junit junit.xml; echo "PEST_EXIT=$?"; php bin/ci-test-ratchet.php junit.xml; echo "RATCHET_EXIT=$?"
PEST_EXIT=2

ratchet: 9 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it 4a EXPLOITED — rewording an apostrophe-bearing comment in ROLES must not manufacture a new role
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it 4b — an unreadable enum or seeder at either revision is NOT LINTED, never a pass
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it 4c — a migration converging role A does NOT exempt the same permission added to role B
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it 4d — the SUPER_ADMIN_PLATFORM range is bounded at both ends
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it F10 — a fragment consumed as `'<role>' => $fragment,` is indexed too, not read as spread by nobody
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it F13 — a consumption line the two forms do not match REFUSES, and does not skip quietly
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it F5 — F4 where one spreading role is NEW in the diff: that one exempt by 2, the other flagged
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it F7 — a permission added to a fragment NO role spreads yields zero findings
  ✗ tests/Feature/Rbac/GrantsConvergenceLintTest.php::it MARKER 1 — PROSE IS NOT A DECLARATION: naming a role it EXCLUDES must not exempt that role

Fix the regression, or — if the failure is intentional — add it to tests/ratchet-baseline.txt.
RATCHET_EXIT=1
```

Counts from that run's JUnit:

```
{'tests': '1705', 'assertions': '7085', 'failures': '10', 'errors': '6', 'skipped': '10', 'time': '3101.076584'}
… the 7 baselined entries …
9 × tests/Feature/Rbac/GrantsConvergenceLintTest.php
merge cases: 36
```

**What I did and did not do about it.** I did not re-run hoping for green, and I
did not baseline it. Both are the move CLAUDE.md names as indistinguishable from
fixing. What I did was look for a mechanism, and there is one.

The run before it was also red on the same file with **12** arms — a *different
subset*. Four earlier full-suite runs on this branch were clean with exactly the
seven baselined failures. The failures come in two shapes:

```
Illuminate\Process\Exceptions\ProcessTimedOutException: The process
"'php' 'bin/ci-grants-convergence-lint.php' '3129c7c…' 'e4df044…'" exceeded the timeout of 60 seconds

Failed asserting that 'grants-convergence-lint: NOT LINTED — database/seeders/RbacSeeder.php is
unreadable at head 4af879e. Either the file moved or the revision is unreachable…'
```

Those arms build throwaway commits with `git commit-tree` against a scratch
index. Nothing references them — they are loose objects. And the repository is
over git's auto-gc threshold:

```
$ git count-objects -v
warning: garbage found: .git/objects/pack/tmp_pack_rA7yBY
count: 7286
…
$ git config --get gc.auto   →  unset, so the default 6700
```

7286 loose objects against 6700. Git starts `gc --auto` opportunistically, and a
gc explains both shapes at once: it is slow enough to blow a 60 s subprocess
timeout (`commit-tree` measures 0.03 s in isolation), and it **prunes
unreferenced loose objects** — which is precisely what those fixture commits are,
hence "revision is unreachable". The stray `tmp_pack_*` is the residue of one
such gc being killed part-way.

The test file is also a contributor to the condition: every run writes fresh
unreferenced objects, so it walks the repo toward the threshold that then breaks
it.

Written up as `docs/handoff/tickets/grants-convergence-lint-test-is-self-poisoning-against-git-auto-gc.md`,
with the fix shapes (anchor the fixtures behind a temp ref, `-c gc.auto=0`, raise
the timeout, clean up after) and an explicit instruction **not** to baseline the
arms.

**I am not asking anyone to take this on my word.** It is a hypothesis with
evidence, not a proof: I have not demonstrated a gc running concurrently with a
failing arm. What is certain is that the failures are in a file this branch does
not touch, that the failing subset is not stable between runs, and that the
branch's own 36 arms are in both runs and green in both.

### Gates

Pint through an explicit changed-file list built from the diff, with the
empty-list guard:

```
$ files=$(git diff --name-only e484a46...HEAD -- '*.php' | tr '\n' ' ')
$ echo "FILES: $files"
FILES: app/Console/Commands/FindDuplicateGuardians.php app/Console/Commands/MergeGuardians.php app/Services/GuardianService.php tests/Feature/Guardian/GuardianMergeTest.php
$ if [ -n "$files" ]; then eval "./vendor/bin/pint --test $files"; else echo "SKIPPED — empty list guard"; fi
{"tool":"pint","result":"passed"}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).
authz exit=0

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).
boundary exit=0

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}

$ DB_DATABASE=portal_testing php vendor/bin/pest --group=arch --colors=never
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":5467,"warnings":2,…ForcingMigrationsDoNotStripLaterGrantsTest…}
```

## The watched red

**The mutation was checked for inertness before it was written up, as instructed
— and it was inert.** Removing only the fourth refusal at `:1168` reds the arm on
"exception not thrown" but does **not** produce the defect, because `consolidating`
is independently gated on `! $reEnableBlocked` (`:1046-1052`). Planting the shape
with only that half removed:

```
BEFORE  school#2 has revoked user#2: disabled_at=SET, authenticates=false, its school#2 records still exist: [1]
merge: COMPLETED
AFTER   user#2 disabled_at=SET, authenticates=false
AFTER   school#2 access RESTORED without school#2 asking: false
```

The merge ran and the revocation held. So both halves came out — the honest
mutation, and the same shape as last round, which is now twice in a row on this
guard family. Same fixture, both halves removed:

```
BEFORE  school#2 has revoked user#2: disabled_at=SET, authenticates=false, its school#2 records still exist: [1]
merge: COMPLETED
AFTER   user#2 disabled_at=NULL, authenticates=false
AFTER   school#2 access RESTORED without school#2 asking: true (account enabled=true, its school#2 wards [1], parent holds the password this merge just emailed)
```

`disabled_at` transitions `SET → NULL` and school#2's access is live again — its
guardian record and ward link were never touched, they were simply unreachable
while the account was disabled, and a school#1 cleanup handed them back. The
`authenticates=false` on both lines is the probe testing the *old* password,
which the consolidation rotated; the parent holds the new one from the email this
merge sent, which is why the last line reads on the account's enabled state and
the ward payload rather than on a password check.

Arm, with the gate removed:

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='still serves another school'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":23324,"failed":1,"failures":[{"test":"…it_refuses_to_re_enable_a_disabled_keeper_account_that_still_serves_another_school","file":"…/GuardianMergeTest.php","line":415,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

Restored; `grep -c "WATCHED RED"` returns `0` and the file is green at 36/36.

### Reds from earlier rounds

The donor-side cross-school refusal (`disabled_at=SET`, school#2 unreachable);
the re-keyed account guard (zero `can_login` rows, donor still authenticates,
`wards: []`); the deliverability invariant on both call sites; the collision-side
condition both disabled and restored to its old narrowed form; and the collision
branch (`1062` on the pivot unique index). All restored, all still green.

## The drive

No screen changed — nothing under `resources/js` is touched. No drive.

## Database observations

Local copy, read-only, ids and counts only. Re-confirmed: 776 live guardian rows,
776 distinct users, **0** certain `(user_id, school_id)` groups, **14** likely
groups (all `school#1`, size 2, two distinct users each), and **0** users backing
live guardian rows in more than one school.

That last zero means neither cross-school refusal — donor-side or keeper-side —
can fire on today's data. It is not an argument that they are unnecessary: the
enrolment path produces multi-school parents by design, and slice 3 runs this
engine unattended. It is also the argument the reviewer used against me, correctly:
reachability cannot excuse one half of a write after being waved away for the
other.

Operationally unchanged: all 14 groups need `--consolidate-login`, each disables a
parent's account and emails them new credentials. Sequence it with the school.

## Not done

- **The decision table does not render on the refusal path.** A refusal throws
  before a plan exists. Operators see the refusal message, which names the ids.
- **`--consolidate-login` is not exercised end-to-end through the command in apply
  mode.** The dry run is; the service-level consolidation is; the combination is
  not.
- **No arm asserts the notification's contents** — only that it was sent once, to
  the right user, and not to the donor.
- **`guardians:find-duplicates` still cannot tell an operator which groups will
  consolidate, which will be refused, and which are inert.**
- **`--school` on `guardians:find-duplicates` is proven only in the narrow
  direction.**
- **The dry-run/apply arm still compares only three plan keys** — ticketed.
- **`bin/quality` has not been run end to end**, only the individual gates.
- **I did not clear the loose-object condition** (`git gc --prune=now`) and then
  re-run the suite. That would very likely have turned the ratchet green, and it
  would have been retrying-until-green wearing a diagnosis. The repository's git
  housekeeping is the project lead's call, not something to do silently inside a
  feature branch.
- **The gc hypothesis is not proven**, only evidenced. See the suite section.
- **`docs/handoff/briefs/` remains untracked and unread.**
- **Nothing pushed. No PR. No merge to `staging`.**

## Findings raised, not fixed

- **`tests/Feature/Rbac/GrantsConvergenceLintTest.php` is self-poisoning against
  git's auto-gc** — it creates unreferenced loose objects every run, walks the
  repository past `gc.auto`, and then fails intermittently when a gc prunes its
  own fixtures or blocks its subprocesses. It lands on whoever holds the branch
  when the threshold is crossed, naming a file they did not touch. **fix**, and
  it is blocking this branch's ratchet right now. Ticketed with the mechanism and
  four fix shapes; explicitly **not** to be baselined.
- **`guardians:find-duplicates` reports duplicate groups but not their login
  disposition.** It already loads every guardian; both facts are one join away.
  Without it the 14 groups are a work list with three invisible columns and the
  discovery mechanism is a refused merge. **ticket**, and still the one I would
  pick up next.
- `app/Services/GuardianService.php:275` — the creation-path defect. Slice 2. **fix**.
- `app/Models/Guardian.php:88-94` — `applySchoolScope`'s OR branch;
  `Guardian::withoutGlobalScopes()` now at 10 call sites across 5 files. **ticket**.
- `app/Services/GuardianService.php:391-397` and the pivot writers — single-primary
  enforced in code only, with no detector. **ticket**.
- `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43,48` — two
  constants redefined, warned on every arch run. Pre-existing. **ticket**.
