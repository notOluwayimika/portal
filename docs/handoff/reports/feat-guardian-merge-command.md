# Implementation report — `feat/guardian-merge-command`

**This is full-review tier** — it writes to an append-only audit trail, it moves
rows across a `school_id` boundary, it can end a parent's portal login, and it is
the engine slice 3's data migration will run against production data. Recommend a
cold session before merge.

**Revision 3.** Read the supersession section before anything else: the design
this branch shipped in revisions 1 and 2 has been replaced twice, both times
because the guard was keyed on the wrong signal. What is current is described
below; what is superseded is labelled superseded.

---

## Headline

Done. `GuardianService::merge()` with an opt-in `--consolidate-login` path, plus
`guardians:merge` and `guardians:find-duplicates`, and 30 arms in
`tests/Feature/Guardian/GuardianMergeTest.php`. Branch
`feat/guardian-merge-command`, cut from `staging` @ `e484a46` (`staging` and
`main` are the same commit today — re-derived). Three commits. Not pushed; no PR.

The one thing to carry away: **`can_login` is not the login.** Authentication
never reads it. Two revisions of this branch shipped a guard keyed on it, and
both were wrong in the same direction.

## Superseded decisions

Recorded in order, because the sequence is the finding.

**Revision 1 — "the pivot flag travels with the row."** `merge()` re-pointed
`can_login` onto the keeper's pivot without regard for which `users` row it had
been living on. Wrong: the password, the sign-in address and the reset link all
live on `users`, so moving the flag strands the account rather than moving the
login.

**Revision 2 — "refuse any cross-account `can_login`."** Correct in intent,
keyed on `guardian_student.can_login`. Authentication reads none of it:
`FortifyServiceProvider.php:50-51` resolves `User::where('email', …)` then checks
`isDisabled()` and the password hash. `disableLogin` writes `users.disabled_at`
(`GuardianService.php:672`) and never touches the pivot; `enableLogin` clears it
and re-issues a password, also never touching the pivot. So the guard asked a
question authentication does not ask. Measured against the 14 duplicate groups in
the production copy it refused **1** and waved through **13**, every one of which
would have left an enabled, deliverable account backing a soft-deleted guardian
row.

**Revision 2's "refuse, never migrate" ruling is also superseded**, and for a
reason worth stating plainly: keyed correctly, refuse-only refuses the entire
working set and the command does nothing for the population it was built for. A
guard that is right and useless gets switched off by whoever needs the work done.

**Current design.** The predicate is *can this account authenticate* — not
disabled (through `User::isDisabled()`, not an inlined `disabled_at`) and a
password set — asked of every absorbed record whose user is not the keeper's.
Refused by default; consolidated on explicit `--consolidate-login`, which ends
the donor account, re-enables and re-credentials the keeper's, and emails the
parent. Refused even with the flag when the keeper cannot be mailed.

The general rule, stated so it can be attacked: **a guard must be keyed on the
signal the system it is protecting actually reads.** Both wrong versions read
plausibly and were pinned green by arms that shared their premise.

## What this revision changed, against the ruling

**1. The predicate is re-keyed** — `planLoginDecision`
(`app/Services/GuardianService.php:957`), called at `:826` before the planner. A
donor "can authenticate" when it is a different `user_id` from the keeper's, the
user exists, `! $user->isDisabled()`, and the password is non-empty. Derived from
Fortify's own three checks, not from a second definition and not from the pivot.

**2. The deliverability invariant is untouched** and still fires on every write
that leaves `can_login` true (`:1303`, called from both branches of the pivot planner). It is
orthogonal: it asks whether the destination can receive mail, never what becomes
of the source account. Both guards have their own arms and their own reds.

**3. `--consolidate-login` is opt-in** (`assertLoginDecisionAllowed`, `:1028`).
Without it a donor that can authenticate refuses, and the message names the flag
as the remedy — **only** the flag. The two remedies revision 2's message
prescribed are deleted: "disable it on the absorbed record" set
`users.disabled_at` and left the pivot flag alone, so the check refused
identically on the re-run *and the parent was locked out for nothing*; "enable it
on the keeper" changed nothing the check read at all.

**4. Consolidation** (`applyLoginConsolidation`, `:1075`): the donor is ended
through the existing `disableLogin()` (so `users.disabled_at` plus a
`login_disabled` entry on the record that is going away), the keeper's user is
re-enabled, re-credentialled and given the `guardian` role if missing, and a
`login_enabled` entry is written on the keeper. No merge-specific events were
invented — the trail reads like any other login transition, because that is what
it is.

**5. Notification is required and fires after commit.** The password is written
*inside* the transaction (it is state and must roll back); the email is carried
out on a `&$deferred` variable and sent after `DB::transaction` returns
(`:814-842`), the same shape `StudentController::store` uses. A rollback leaves
no email in flight, and an arm plants a genuine mid-apply failure to prove it.

**6. Refused even with the flag when the keeper cannot be told** (`:1049`).
A disabled keeper account is fine — consolidation re-enables it — but an
undeliverable one is refused, because the notification is the entire reason the
consolidation is safe. Consolidating into an address nobody can receive is the
original defect wearing a flag.

**7. The dry run prints the decision as its own table, first**
(`app/Console/Commands/MergeGuardians.php:136-163`), above the pivot tables, in
both dry-run and applied modes: per absorbed record the `guardian#`, its `user#`,
whether it can sign in today, whether that is the keeper's account, and what the
merge will do to it; then the surviving `user#` with its deliverability and
enabled state; then whether the parent will be emailed.

**8. Both tickets.** `guardian-merge-dry-run-and-apply-are-two-plans.md` had its
false sentence corrected **in place and labelled**, not quietly deleted — it
claimed the pre-flight made a stranded login impossible on apply, which was a
second copy of the defect written in prose. It also now records the sharper
version of that gap: `--consolidate-login` is consent to *the accounts the dry run
showed*, and the apply re-derives the set.
`guardian-merge-audit-entries-have-no-causer.md` is new (reviewer finding 3),
including the note that it becomes a fix the moment the admin UI calls `merge()`.

**9. The nits.** `MergeGuardians.php` is **229** lines, not 205 or 206 — it grew
with the decision table. The pre-flight call is at `:826`. Both re-derived at the
moment of writing rather than carried.

## Deviations from the ruling

**One, and it is a limit rather than a choice.** Ruling item 7 says the dry run
must print the decision table "in both modes". It prints in both dry-run and
applied modes — but **not on the refusal path**, because the refusal throws
before a plan exists and `render()` takes a plan. What an operator sees on a
refusal is the message naming each `guardian#`/`user#` that can sign in, and the
flag. That is pinned by an arm
(`GuardianMergeTest.php:785`, `expectsOutputToContain('can sign in today')` on the
failed run). If the table is wanted on refusals too, that is a real change —
building the decision, rendering it, and only then throwing — and I did not make
it unasked.

**On the brief, one that survives from revision 1:**
`assertLoginRequiresDeliverableEmail` was never widened, because `merge()` is in
`GuardianService` itself and nothing needed widening. Untouched at `:318`.

**A consequence a reader should know about, not a deviation.** Correctly keyed,
the guard fires on *any* absorbed record with a live account — which is most
fixtures. Ten structural arms (pivot moves, collisions, back-fill, audit, dry
run, the multi-absorbed case) now build the absorbed side on a deliberately
**dormant** account via a `gm_dormantUser()` helper, so an arm about where a
pivot row lands is not silently testing the login guard instead. Every arm that
is about the guard uses a live account explicitly.

## Contradictions of the premise

**None outstanding.** The reviewer's finding 1 mechanism, verified in the tree
rather than taken from the review: `FortifyServiceProvider.php:50-51` is the
authentication predicate and reads email + `isDisabled()` + hash;
`GuardianService::disableLogin:672` writes `disabled_at` only;
`GuardianController::wards` returns whatever `forUserInActiveSchool` resolves,
with no `can_login` filter, so a soft-deleted guardian row yields an empty list
for an account that signs in fine. All three confirmed, and the third is
reproduced under **The watched red**.

The brief's original data premise still does not reproduce (0 certain groups, no
guardian appearing three times); per the lead's ruling the 14 phone-matched pairs
are the working set and nothing here is designed around the reported triplicate.

## What changed

Three commits. Cumulative shape:

| File | Size | What |
| --- | --- | --- |
| `app/Services/GuardianService.php` | +~790 | `merge()` and eleven private helpers: the mergeability and active-school refusals, the login decision and its two refusals, the consolidation, the plan simulation, the deliverability re-raise, the back-fill, the orphan report, the apply, and the per-link audit entries. One import added. Nothing pre-existing modified (`git diff --numstat` shows 0 deletions in this file). |
| `app/Console/Commands/MergeGuardians.php` | 229 | `guardians:merge --keep= --absorb=* [--apply] [--consolidate-login]`. |
| `app/Console/Commands/FindDuplicateGuardians.php` | 229 | `guardians:find-duplicates [--school=]`. |
| `tests/Feature/Guardian/GuardianMergeTest.php` | 863 | 30 arms. |
| `docs/handoff/tickets/guardian-merge-dry-run-and-apply-are-two-plans.md` | — | Corrected in place. |
| `docs/handoff/tickets/guardian-merge-audit-entries-have-no-causer.md` | — | New. |

## Proof

Raw output. This harness replaces Pest's stdout with a JSON summary line; that is
verbatim what the commands returned.

### Targeted file

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never
{"tool":"pest","result":"passed","tests":30,"passed":30,"assertions":138,"duration_ms":28907}
```

### The guardian directory

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian --colors=never
{"tool":"pest","result":"passed","tests":48,"passed":48,"assertions":153,"duration_ms":54082}
```

`GuardianLoginInvariantTest` is in that directory, unmodified, and green — which
matters this round, because consolidation now writes `users.disabled_at` and
passwords.

### Full suite + ratchet, on the final tree

```
$ DB_DATABASE=portal_testing php vendor/bin/pest --log-junit junit.xml; echo "PEST_EXIT=$?"; php bin/ci-test-ratchet.php junit.xml; echo "RATCHET_EXIT=$?"
PEST_EXIT=2

ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

`PEST_EXIT=2` is the 7 baselined failures. Counts read back out of the JUnit
report, because the harness swallowed the summary line on this invocation:

```
{'tests': '1699', 'assertions': '7090', 'failures': '6', 'errors': '1', 'skipped': '10', 'time': '1344.826170'}
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
1 tests/Feature/Auth/AuthenticationTest.php::users are rate limited
1 tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
1 tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
guardian merge cases: 30
```

### Gates

```
$ ./vendor/bin/pint app/Services/GuardianService.php app/Console/Commands/MergeGuardians.php tests/Feature/Guardian/GuardianMergeTest.php
{"tool":"pint","result":"passed"}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ DB_DATABASE=portal_testing php vendor/bin/pest --group=arch --colors=never
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":7666,"warnings":2,…ForcingMigrationsDoNotStripLaterGrantsTest… "Constant FORCING_MIGRATIONS already defined"…}

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

The two arch warnings are pre-existing and unrelated. Pint was invoked with an
explicit file list every time, never a bare path.

## The watched red

The reviewer's point was that finding 1's shape had no arm and therefore no red.
It now has both, and the red is the one that matters.

### Red 1 — the re-keyed guard, against the 13-of-14 shape

Mutation, in `merge()`:

```diff
-            $this->assertLoginDecisionAllowed($keeper, $decision);
+            // WATCHED RED: re-keyed login guard removed
```

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='refuses to absorb a record whose account can still sign in'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":26187,"failed":1,"failures":[{"test":"…it_refuses_to_absorb_a_record_whose_account_can_still_sign_in","file":"…/GuardianMergeTest.php","line":252,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

An assertion message is not the defect. With the guard still removed, planting
the shape directly — **no `can_login` row anywhere** — and then evaluating
Fortify's own predicate verbatim against the donor account after the merge:

```
can_login rows on donor guardian: 0
AFTER MERGE  donor user#3 authenticates: true
AFTER MERGE  forUserInActiveSchool(user#3) = NULL
AFTER MERGE  wards list for that account: []
AFTER MERGE  absorbed guardian#2 deleted_at='set'
```

That is finding 1 in five lines. Zero `can_login` rows — so the revision-2 guard
had nothing to look at — and the account still authenticates, resolves to no
guardian record, and gets an empty ward list. Restored, and the file is green at
30/30 above.

### Red 2 — the deliverability guard, both call sites

```diff
-            $this->assertLoginRequiresDeliverableEmail($keeper, true);
+            // WATCHED RED: invariant call removed
```

Reds both the move-side arm and the collision-side arm (`ValidationException` not
thrown, at `GuardianMergeTest.php:275` and `:302` as they stood then). Restored.

### Red 3 — the collision-side condition, including against its old narrowed form

Disabled outright (`if (false)`) → red. Then restored to revision 1's narrowed
condition `if ($after['can_login'] && ! $before['can_login'])` → **also red**,
which is what makes the revert load-bearing rather than cosmetic. Restored.

### Red 4 — the collision branch

`if (! isset($state[$studentId]))` → `if (true)` reds arm 2 with
`SQLSTATE[23000] … 1062 Duplicate entry … for key
'guardian_student.guardian_student_guardian_id_student_id_unique'` on the
`UPDATE … SET guardian_id`. Restored.

`grep -c "WATCHED RED" app/Services/GuardianService.php` returns `0`.

## The drive

No screen changed — nothing under `resources/js` is touched. No drive.

## Database observations

Local copy, read-only, ids and counts only. Unchanged and re-confirmed.

```
Live guardian records examined: 776 (all schools)
(1) CERTAIN — same user in the same school: 0
(2) LIKELY  — shared phone within a school: 14   (all school#1, all size 2)
```

**The operational consequence of this revision, which is the line the lead will
want:** all 14 groups are two *distinct* users — that is what makes them
invisible to grouping (1) — and the reviewer measured all 28 accounts as enabled
with a password and a deliverable address. So **every one of the 14 groups now
requires `--consolidate-login`**, and each one disables a parent's current
account and emails them a new password for the survivor. That is the correct
outcome and it is not a quiet one: 14 parents get a credentials email. The work
is a communications exercise as much as a data one, and it should be sequenced
with the school rather than run in a batch.

## Not done

- **The decision table does not render on the refusal path.** See Deviations.
- **`--consolidate-login` is not exercised end-to-end through the command in
  apply mode.** The dry run is (`GuardianMergeTest.php:785`), and the service-level
  consolidation is; the combination of the two is not.
- **No arm asserts the notification's contents**, only that it was sent once, to
  the keeper's user, and not to the donor.
- **No detector for which groups will need the flag.** `guardians:find-duplicates`
  does not report it, so an operator working the 14 groups discovers it one merge
  at a time. Raised below.
- **`--school` on `guardians:find-duplicates` is proven only in the narrow
  direction** — no arm proves two guardians in different schools sharing a phone
  are not grouped.
- **The dry-run/apply arm still compares only three plan keys** — ticketed, not
  fixed, per the earlier ruling.
- **`docs/handoff/briefs/feat-guardian-merge-command.md` remains untracked.**
- **Nothing pushed. No PR. No merge to `staging`.**

## Findings raised, not fixed

- **`guardians:find-duplicates` cannot tell an operator which groups will need
  `--consolidate-login`.** It knows every fact required — it already loads the
  guardians, and "can this account authenticate" is one join away. Without it the
  14 groups are a work list with an invisible column, and the discovery mechanism
  is a refused merge. **ticket**, and the one I would pick up next.
- `app/Services/GuardianService.php:275` — the creation-path defect itself, still
  live. Slice 2. **fix**.
- `app/Models/Guardian.php:88-94` — `applySchoolScope`'s OR branch.
  `Guardian::withoutGlobalScopes()` now stands at 9 call sites across 5 files
  (`grep -rn`, re-derived on this tree). **ticket**.
- `app/Services/GuardianService.php:391-397` and the pivot writers — single-primary
  is enforced in code only, at each writer, and the two-primary pre-state an arm
  plants is constructible directly through the pivot with nothing to detect it.
  **ticket**.
- `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43,48` — two
  constants redefined, warned on every `--group=arch` run. Pre-existing.
  **ticket**.
