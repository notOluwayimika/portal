# Implementation report — `feat/guardian-merge-command`

**Full-review tier** — it writes to an append-only audit trail, it moves rows
across a `school_id` boundary, and it is the engine slice 3's data migration will
run against production data.

**Revision 7 — documentation only.** Revision 6 removed `--consolidate-login`
from this branch entirely; account consolidation becomes its own branch. This
revision commits the follow-up brief, supersedes a ticket whose mechanism has
since been falsified, and corrects what this report says about the ratchet. **No
code changed.**

⚠️ **The ratchet was green on the final run after being red on the two before
it, and I changed nothing that bears on it.** A dedicated investigation has since
reported: **86 executions of the file, 3,096 arm-executions, zero failures**, and
my own gc explanation falsified outright. Read *Full suite + ratchet*.

---

## Headline

Done. `GuardianService::merge()` with **one unconditional, terminal** account
refusal, plus `guardians:merge` and `guardians:find-duplicates`, and 28 arms in
`tests/Feature/Guardian/GuardianMergeTest.php`. Branch
`feat/guardian-merge-command`, cut from `staging` @ `e484a46` (`staging` and
`main` are the same commit today — re-derived). Eight commits, the last of them
documentation only. Not pushed; no PR.

## Why consolidation was removed, which is not "the lead asked"

Five review rounds. Each one found a defect. **Every one of them was in the
account-migration code, and not one was in the merge engine.**

| Round | Defect | Where |
| --- | --- | --- |
| 1 | `can_login` re-pointed onto the keeper's pivot regardless of which `users` row it lived on | account migration |
| 2 | The cross-account guard keyed on `can_login`, which authentication never reads — refused 1 of 14 real groups, waved 13 through | account migration |
| 3 | `users.disabled_at` is account-global: consolidating in school A revoked access at school B | account migration |
| 4 | The same write's **keeper** half — clearing `disabled_at` — left ungated, with the gating fact computed and used only to word a message | account migration |
| 5 | Both gates measured an account's reach by counting live **guardian rows**, while `school_user` + team roles are a second access path guardian rows cannot see | account migration |

One shape, five times: **a guard scoped to the record in front of it while the
write reached further.** The merge engine — pivot moves and collisions, the
OR-merge, single-primary re-assertion, back-fill, the cross-school and
active-school refusals, the audit trail, soft-delete, the orphan report — was
attacked in all five rounds and held in all five.

That is the argument for removal rather than a sixth fix. Consolidation is a
different feature that was wearing a flag on this one, and the flag is what kept
putting its blast radius inside the merge's review. It needs its own branch, its
own arms and its own review, and it now has a committed brief carrying those
findings — plus a sixth the review added after the removal — as its
specification.

## What was removed

- The `--consolidate-login` flag and every path reachable only through it: the
  donor disable, the keeper re-enable, the credential re-issue, the parent
  notification, and the `&$deferred` drain that existed to serve it.
- `applyLoginConsolidation()` and `remainingGuardianSchoolIdsFor()`, deleted
  outright.
- `orphanedUserIdsAfterMerge()` **as a gate**. It survives as what it always
  was — a *fact* reported in the plan (`orphaned_user_ids`) and printed by the
  command. Removing its gate role and keeping its reporting role was the one part
  of this removal that needed reading rather than deleting.
- Every consolidation-shaped plan key: `school_exclusive`, `remaining_school_ids`,
  `keeper_school_exclusive`, `keeper_other_school_ids`, `keeper_re_enable_blocked`,
  `consolidate_requested`, `consolidating`, `will_notify`,
  `not_school_exclusive_guardian_ids`.
- The decision table's `only this school` and `merge will` columns, the
  surviving-account line's disabled/exclusive clauses, and the "the parent WILL
  be emailed" line.
- Eight arms, and five tickets whose subject no longer exists.

## What replaced it

**One refusal, unconditional and terminal** (`GuardianService.php:1020`): if any
absorbed record's account can authenticate — enabled and password set, derived
from Fortify's own checks, never from `can_login` — and it is not the keeper's
account, the merge aborts. There is no flag that proceeds past it.

The message names the ids, says this command collapses duplicate **records** and
does not consolidate **accounts**, says the records can be merged once the
accounts have been consolidated deliberately with the parent told, and stops.
**It prescribes nothing it cannot deliver** — twice on this branch a refusal
named a remedy that did not clear the check, one of which locked a parent out on
the way, and that is why this one describes the situation instead.

`planLoginDecision` (`:947`) is now a detector, not a decision: it reports which
donor accounts can sign in and stops. `keeper_deliverable` is retained because the
pre-existing `can_login` deliverability invariant still reads it when a pivot
carrying that flag lands on the keeper; it gates nothing about accounts.

## The follow-up brief — now committed

`docs/handoff/briefs/feat-guardian-consolidate-login.md`. Revision 6 left it
untracked on instruction; the cold review then pointed out that two committed
tickets and this report all dereference to a path that exists in no commit, so it
is committed now and the references resolve on any clone.

It carries what consolidation must do, **six** findings with their mechanisms as
the specification, both of the reviewer's probes verbatim as arms that branch must
pass, the two decisions nobody ever made (unconditional password rotation; no
basis for choosing `--keep`, with the `0 of 28 activated` figure and the
instruction to re-derive it), and a plain statement that **guardian rows are the
wrong measure of an account's reach**, naming `school_user` + `model_has_roles`
as the right one.

The sixth finding was added this revision: **"can this account authenticate" is a
snapshot of two account-global facts, and both can be cleared from outside this
school** — another school's `enableLogin` clears `disabled_at` globally, and an
empty password is not a lock because the reset broker resolves by email as an
identity key. It is the branch's own rule applied to *time* rather than to rows,
and it is a genuine fork (accept the snapshot as the contract, or widen the
predicate to "could be made to authenticate"). It belongs to the consolidation
branch as a specification point, not to this one as a loose end.

### A convention I am introducing, said plainly rather than left ambiguous

Existing briefs in this repository live at `docs/handoff/<name>-brief.md` —
`c6-brief.md`, `slice-2-brief.md`. **This file does not follow that convention;
it sits in a new `docs/handoff/briefs/` subdirectory**, which was created by the
brief-writing side rather than by me, and which already holds a second slice's
brief. I kept it there rather than renaming: moving this one file would split the
set across two conventions, which is worse than either convention consistently.
Somebody should pick one and migrate the rest — this note exists so that decision
is made deliberately instead of inherited.

`docs/handoff/briefs/fix-guardian-create-duplicates.md` belongs to a different
slice. It is **not** in this commit and I have never opened it.

## Deviations

**None on the ruling.** Everything named for removal is removed; everything named
to survive survives.

**One mistake made and corrected inside this round, recorded because the tree
briefly held it.** My first deletion pass took `buildMergePlan()` with it —
`remainingGuardianSchoolIdsFor` and the refusal block are not contiguous, and I
deleted a span rather than the two methods. `php -l` still passed, because the
result was syntactically valid and semantically gutted. I restored the file from
`HEAD` and redid the removal by locating each method and its docblock
individually. Nothing broken was committed, and the method list was re-derived
afterwards to confirm.

**The commit-hygiene deviation from two rounds ago still stands as recorded:**
`git add -A docs/handoff/` swept in two untracked briefs including one I had never
opened; caught on the status output and reset before the commit stood. Every
commit since has staged explicit paths, this one included.

## Superseded decisions

All four earlier designs are gone with the feature they guarded, and the table
under *Why consolidation was removed* is now the record of them. The one general
rule worth carrying forward, because it survived being right four times and
insufficient four times:

**A guard must be keyed on the signal the system it protects actually reads, and
scoped to the full reach of the write — not to the record in front of it.**

`can_login` is not the login; `users.disabled_at` is not per-school; and a live
guardian row is not the measure of an account's access. Each of those was learned
by shipping the opposite.

## What this revision changed (revision 7 — documentation only)

- **The follow-up brief is committed**, with a sixth finding added to it.
- **`grants-convergence-lint-test-is-self-poisoning-against-git-auto-gc.md` is
  superseded in place** — kept at its path so committed references resolve,
  banner-marked, pointing at the investigation, with the surviving parts
  preserved and the original text left visible underneath rather than rewritten.
- **`grants-convergence-lint-nondeterminism.md` is committed** — the
  investigation's own report.
- **This report's ratchet section is corrected** against that investigation, and
  records that one of the contaminating conditions it observed was my own tree
  edits during revision 6.
- **The `docs/handoff/briefs/` convention is named** rather than left ambiguous.

### And, from revision 6

- the docblocks on `merge()`, `planLoginDecision` and the refusal rewritten so
  none of them describes behaviour that no longer exists — the report's own rule
  from an earlier round, applied to a deletion rather than an ungated write;
- the surviving tickets cleaned of consolidation: the dry-run/apply ticket had its
  `--consolidate-login` paragraphs replaced with a labelled note that the feature
  is gone (**that ticket has now carried a stale sentence twice, and the note says
  so**), and the causer ticket's mention of consolidation events rewritten to
  point at the follow-up branch that inherits it.

## Contradictions of the premise

**None.** The removal was verified against the tree rather than assumed:
`grep -c "consolidat"` over `app/Services/GuardianService.php` and
`app/Console/Commands/MergeGuardians.php` returns only deliberate prose (the
docblock explaining why the flag is gone, and the refusal message), and the
method list re-derived after the deletion shows `applyLoginConsolidation` and
`remainingGuardianSchoolIdsFor` absent with `buildMergePlan` present.

## What changed

Eight commits, the last documentation only. Re-derived at the moment of writing:

| File | Size | What |
| --- | --- | --- |
| `app/Services/GuardianService.php` | **+664, −0** against `e484a46` (`git diff --numstat`); this round alone **+45, −308** | `merge()` and its helpers. Nothing pre-existing modified. |
| `app/Console/Commands/MergeGuardians.php` | **221** (was 257) | `guardians:merge --keep= --absorb=* [--apply]`. |
| `app/Console/Commands/FindDuplicateGuardians.php` | **229** | `guardians:find-duplicates [--school=]`. |
| `tests/Feature/Guardian/GuardianMergeTest.php` | **802** (was 1048) | **28** arms (`grep -c "^it("`), down from 36. |
| `docs/handoff/tickets/` | **4 files** (was 8) | Five deleted with the feature; two cleaned of it; the gc ticket superseded in place; the nondeterminism investigation added. |
| `docs/handoff/briefs/feat-guardian-consolidate-login.md` | new, **committed** | The follow-up, with six findings as its specification. |

## Proof

Raw output. This harness replaces Pest's stdout with a JSON summary line.

### Targeted file

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never
{"tool":"pest","result":"passed","tests":28,"passed":28,"assertions":121,"duration_ms":17276}
```

### The guardian directory

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian --colors=never
{"tool":"pest","result":"passed","tests":47,"passed":47,"assertions":144,"duration_ms":15055}
```

`GuardianLoginInvariantTest` and `DeliverableEmailPredicateTest` are in it,
unmodified, green.

### Gates

```
$ files=$(git diff --name-only HEAD -- '*.php' | tr '\n' ' ')
$ echo "FILES: $files"
FILES: app/Console/Commands/MergeGuardians.php app/Services/GuardianService.php tests/Feature/Guardian/GuardianMergeTest.php 
$ if [ -n "$files" ]; then eval "./vendor/bin/pint $files"; else echo "SKIPPED — empty list guard"; fi
{"tool":"pint","result":"fixed","files":[{"path":"tests\/Feature\/Guardian\/GuardianMergeTest.php","fixers":["no_extra_blank_lines"]}]}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}

$ DB_DATABASE=portal_testing php vendor/bin/pest --group=arch --colors=never
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":5733,"warnings":2,…ForcingMigrationsDoNotStripLaterGrantsTest…}
```

### Full suite + ratchet, on the final tree

**GREEN this run — and I did nothing to make it so.** That sentence is the whole
point of this section, so it comes before the output.

```
$ DB_DATABASE=portal_testing php vendor/bin/pest --log-junit junit.xml; echo "PEST_EXIT=$?"; php bin/ci-test-ratchet.php junit.xml; echo "RATCHET_EXIT=$?"
PEST_EXIT=2

ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

```
{'tests': '1697', 'assertions': '7073', 'failures': '6', 'errors': '1', 'skipped': '10', 'time': '479.042069'}
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
1 tests/Feature/Auth/AuthenticationTest.php::users are rate limited
1 tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
1 tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
merge cases: 28
gcl cases: 36
```

`PEST_EXIT=2` is the seven baselined failures, which is `tests/ratchet-baseline.txt`
exactly. All 36 `GrantsConvergenceLintTest` cases ran and passed.

**Do not read that as the red being fixed.** The honest record across this branch:

| Run | `GrantsConvergenceLintTest` | Wall time |
| --- | --- | --- |
| 1–4 | clean | ~1400–1900 s |
| 5 | **12 arms red** | ~1745 s |
| 6 | **9 arms red**, a different subset | ~3101 s |
| 7 (this one) | **clean, all 36 green** | **479 s** |

I touched nothing between runs 6 and 7 that could bear on it — no `git gc`, no
baseline edit, no change to that file or to `bin/ci-grants-convergence-lint.php`.

### What a dedicated investigation has since established

Reported in `docs/handoff/tickets/grants-convergence-lint-nondeterminism.md`,
committed with this revision. It is worth reading in full; the parts that change
what this report may claim:

- **86 executions of that file — 3,096 arm-executions — across this branch, solo
  runs, and `staging` in a separate clone: zero failures.** Against 2 red in 6
  consecutive runs on 2026-08-17. The failure depends on a condition that was
  present that day and is not present now.
- **My gc-pruning mechanism is falsified outright**, and by the evidence I myself
  cited. A pruned commit produces `could not resolve base '…' to a commit`; the
  real failure said `RbacSeeder.php is unreadable at head 4af879e` — a sha that
  **resolved**. The commit existed and its tree was empty. Pruning cannot produce
  that message.
- **The loose-object threshold is not the cause either**: 86 green runs at 7,294 →
  7,594 loose objects with the same stray `tmp_pack_*` present throughout.
- **The cold reviewer's alternative is correct and bite-proved.** `gclBlob:93` and
  `gclCommit:112-135` discard the exit status of every git call; poisoning
  `hash-object` at 1-in-12 in an isolated clone reproduces the exact failure text
  *and* the random-subset-per-run property. That is what *hid* the cause.
- **The strongest remaining lead is machine contention, stated as correlation.**
  The two failing runs took 1745 s and 3101 s against a 524–623 s norm, and one
  failure shape is a 60 s `ProcessTimedOutException` on a subprocess that normally
  costs 0.3 s — a ~200× slowdown, which is thrash rather than CPU contention. Not
  proven; 86 runs at load 10–15 with swap 93 % full stayed green.

**And one of the contaminating conditions it observed was me.** Its runs 1–3 saw
the suite's test count change mid-run (1705 → 1697) and `GuardianMergeTest`
failing on `--consolidate-login option does not exist` and `Undefined array key
"consolidating"`. Those are exactly the states this working tree passed through
while I was performing revision 6's removal. It could not have caused *their*
red — the four load-bearing files were untouched — but a shared working tree does
make "two runs, different failure subsets" an expected outcome, and I was the
other writer.

So the green in run 7 remains evidence about the machine, not about the test, and
I claim nothing from it. What has changed since revision 6 is that the *cause* is
better bounded, not that it is known. My own ticket is superseded in place rather
than deleted or quietly rewritten: the file keeps its path so the committed
references still resolve, carries a ⛔ banner pointing at the investigation, and
retains what survived — the swallowed exit statuses, and the instruction not to
baseline and not to retry for green. **This branch has twice shipped a document
carrying a false statement about the code; superseding with the history visible is
the correction that does not repeat the error.**

## The watched red

**The point of this one is that the removal did not take the guard with it.**
Deleting a feature is exactly the change most likely to quietly delete a check
that was sitting inside it.

Mutation — the terminal refusal, in `assertLoginDecisionAllowed`:

```diff
-        if ($decision['cross_account_login_guardian_ids'] === []) {
+        if (true) { // WATCHED RED: terminal account refusal removed
             return;
         }
```

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='refuses to absorb a record whose account can still sign in'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":11379,"failed":1,"failures":[{"test":"…it_refuses_to_absorb_a_record_whose_account_can_still_sign_in","file":"…/GuardianMergeTest.php","line":253,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

An assertion message is not the defect, so with the refusal still removed I
planted the shape directly — a donor account with **no `can_login` row anywhere**,
which is the 13-of-14 production shape — and asked what happens to it:

```
BEFORE  donor user#3 authenticates: true
merge: COMPLETED
AFTER   absorbed guardian#2 deleted: true
AFTER   donor user#3 authenticates: true, its guardian record resolves to: NULL
AFTER   that account signs in and its ward list is: []
```

The account still signs in, its guardian record is gone, and
`forUserInActiveSchool` resolves nothing — so the parent reaches an empty portal
with no email and no error. Restored; `grep -c "WATCHED RED"` returns `0` and the
file is green at 28/28.

**I checked this mutation was not inert before writing it up**, because twice on
this branch a single-half mutation left the demonstration inert while a second
condition covered the write. Here there is only one condition left, and removing
it does reproduce the defect.

### Reds from earlier rounds

They exercised code that no longer exists (the donor disable, the keeper
re-enable, the consolidation gates) and are not carried forward as evidence for
anything. Two that still apply to surviving code stand: the deliverability
invariant on both its call sites, and the collision branch — `1062` on
`guardian_student_guardian_id_student_id_unique` when a colliding pivot is forced
to take the plain re-point.

## The drive

No screen changed — nothing under `resources/js` is touched. No drive.

## Database observations

Local copy, read-only, ids and counts only. Re-confirmed: 776 live guardian rows,
776 distinct users, **0** certain `(user_id, school_id)` groups, **14** likely
groups (all `school#1`, size 2, **two distinct users each**).

**The operational consequence of the removal, and it is the line the lead needs.**
All 14 groups are two distinct accounts, and the reviewer measured all 28 of those
accounts as enabled with a password set. So **every one of the 14 groups is now
refused by this command.** With consolidation gone, `guardians:merge` cannot
collapse a single group in the current working set.

That is not the command failing — it is the command declining to strand fourteen
parents' logins — but it does mean the remediation the school is waiting on is
blocked behind `feat/guardian-consolidate-login`, and this branch's immediate
value is the refusal, the detector and the engine rather than throughput. If that
is not acceptable, the lever is the follow-up branch's priority, not a flag here.

What this branch *can* collapse today: any group whose absorbed account cannot
authenticate (disabled or no password), and any certain duplicate where both
records share one account — of which the copy currently holds **0**.

## Not done

- **The decision table does not render on the refusal path.** A refusal throws
  before a plan exists, so an operator whose merge is refused gets the message
  naming the ids rather than the table. The table renders whenever a merge is
  actually on offer, which an arm pins.
- **`guardians:find-duplicates` still cannot tell an operator which groups will
  be refused for a live donor account.** It already loads every guardian and the
  fact is one join away. The discovery mechanism is currently a refused merge.
  Raised below.
- **`--school` on `guardians:find-duplicates` is proven only in the narrow
  direction** — no arm proves two guardians in different schools sharing a phone
  are not grouped.
- **The dry-run/apply arm still compares only three plan keys** — ticketed.
- **`bin/quality` has not been run end to end**, only the individual gates.
- **I did nothing about the `GrantsConvergenceLintTest` failures** — not a retry,
  not a baseline, not a `git gc`, and no change to the test file or its exit-status
  handling, which is a separate `fix/` branch. The final run came back green; that
  is not a fix and I have not treated it as one. What I did do is supersede my own
  ticket in place, visibly, once its mechanism was falsified.
- **The follow-up brief is now committed**, one explicit path, staged and
  verified before the commit rather than after.
  `docs/handoff/briefs/fix-guardian-create-duplicates.md` belongs to another slice,
  is not in any commit of mine, and remains unread.
- **Review findings 1, 2 and 3 are left as tickets, not built** — the silent
  `is_primary` demotion trail, the `can_authenticate` snapshot (moved into the
  consolidation brief where it is a specification point), and the class-scoped
  `can_login` cardinality pin whose docblock count is now false in the tree.
- **Nothing pushed. No PR. No merge to `staging`.**

## Findings raised, not fixed

- **`guardians:find-duplicates` does not report which groups the merge will
  refuse.** With consolidation gone, every group whose absorbed account can sign
  in is now blocked until that separate work lands — so the census that is meant
  to be the work list silently contains rows that cannot be worked. One join.
  **ticket**, and the first thing I would pick up.
- **`tests/Feature/Rbac/GrantsConvergenceLintTest.php` fails nondeterministically
  and the trigger is still unknown**, though the field is now much narrower: gc
  pruning and the loose-object threshold are both ruled out, and the swallowed
  exit statuses in `gclBlob`/`gclCommit` are bite-proved as the mechanism that
  converts any transient git failure into this exact red. **fix** — a gate that
  passes and fails on the same tree is not a gate — and it is somebody else's
  branch. Do not baseline the arms.
- `app/Services/GuardianService.php` — the creation-path defect that produces the
  duplicates. Slice 2. **fix**.
- `app/Models/Guardian.php:88-94` — `applySchoolScope`'s OR branch; every query in
  this change works around it with `withoutGlobalScopes()`. **ticket**.
- **Single-primary is enforced in code only**, at each pivot writer, and the
  two-primary pre-state an arm plants is constructible directly through the pivot
  with nothing to detect it. **ticket**.
- `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43,48` — two
  constants redefined, warned on every arch run. Pre-existing. **ticket**.
