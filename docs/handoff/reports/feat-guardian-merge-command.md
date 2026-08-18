# Implementation report — `feat/guardian-merge-command`

**This is full-review tier** — it writes to an append-only audit trail, it moves
rows across a `school_id` boundary, it can end a parent's portal login, and it is
the engine slice 3's data migration will run against production data.

**Revision 4.** Three guards have been shipped on this branch and two of them
were wrong. The sequence is in **Superseded decisions** and is the most useful
thing here; what is current is described below it.

---

## Headline

Done. `GuardianService::merge()` with an opt-in `--consolidate-login` path and
three login refusals, plus `guardians:merge` and `guardians:find-duplicates`, and
33 arms in `tests/Feature/Guardian/GuardianMergeTest.php`. Branch
`feat/guardian-merge-command`, cut from `staging` @ `e484a46` (`staging` and
`main` are the same commit today — re-derived). Four commits. Not pushed; no PR.

This round closed one correctness gap (a consolidation could revoke a parent's
access at a school the merge had nothing to do with), ticketed three, and
corrected two of my own reporting errors.

## Superseded decisions

**Revision 1 — the pivot flag travels with the row.** `merge()` re-pointed
`can_login` onto the keeper's pivot regardless of which `users` row it had been
on. The password, sign-in address and reset link all live on `users`, so this
stranded the account rather than moving the login.

**Revision 2 — refuse any cross-account `can_login`.** Right intent, wrong key.
Authentication reads none of the pivot: `FortifyServiceProvider.php:50-51`
resolves `User::where('email', …)` then checks `isDisabled()` and the password
hash. Measured against the 14 duplicate groups in the production copy this
refused **1** and waved through **13**.

**Revision 3 — re-keyed to "can this account authenticate", refuse or
consolidate.** Correct as far as it went, and it missed that
`users.disabled_at` is a property of the **account**, not of a school: one human
is a parent at more than one school on one `users` row (§6.2), so consolidating
in school A revoked their access at school B, with a credentials email that
mentions only school A.

**Current.** Three refusals, in order: a live donor account refuses unless
`--consolidate-login`; consolidation refuses into a keeper that cannot be mailed;
and **consolidation refuses when the donor account still backs a live guardian
record anywhere else.** Only a school-exclusive donor account can be ended here.

The pattern across all three, stated so it can be attacked: **each wrong version
was scoped to the object in front of it — the pivot row, then the account's
sign-in state — while the thing it was protecting had wider scope.** The guard's
scope has to match the blast radius of the write, not the shape of the record
being edited.

## What this revision changed, against the ruling

**1. The third refusal** — `assertLoginDecisionAllowed`
(`app/Services/GuardianService.php:1092`), gate at `:1122`. Ordered with the
other pre-flights, before the planner and before any write (called at `:827`,
after `planLoginDecision` at `:826`).

**2. It consults the fact the branch already computed**, as ruled.
`planLoginDecision` (`:957`) calls `orphanedUserIdsAfterMerge` — the same list the
plan reports — and a donor absent from it is exactly one that still backs a live
record. `school_exclusive` is derived from that list, not from a second
predicate. `remainingGuardianSchoolIdsFor` (`:1044`) exists only to name the
schools in the message and the table.

**3. The message names ids and says what can actually be done.** Donor
`user#<id>`, the `school#<id>`s where it still backs live records, and then a
remedy that is stated **only when it is real**: reversing `--keep`/`--absorb`
works when the keeper's account is itself school-exclusive, so the multi-school
account survives and the disposable one is ended. When neither account is
exclusive the message says plainly that this pair cannot be collapsed by this
command, leave it and raise it — rather than inventing a step. The previous
round shipped two remedies that did not clear the check, one of which locked a
parent out on the way; that is what this clause is guarding against.

**4. The decision table carries it in both modes** — a new `only this school`
column (`app/Console/Commands/MergeGuardians.php:138`) reading `yes` or
`NO — also school#<id>, …`, and `merge will` now distinguishes
`REFUSE — cannot be disabled here` from the flag-missing refusal.

**5. The test comment is corrected.** `GuardianMergeTest.php:680-687` said a live
donor "is refused before any of this is reached", which stopped being true the
moment `--consolidate-login` existed. It now says why the arm uses a dormant
account (to isolate the `users`-row question) and points at the live+shared arm
that covers what it used to wave away.

**6. Three arms**, not two: refusal on live+shared+consolidate with nothing
written and the other school's row and the account's `disabled_at` both untouched
(`:339`); the plan carrying `remaining_school_ids` wherever a plan is reachable
(`:370`); and consolidation still working when the donor is school-exclusive even
though a second school exists (`:394`) — so the refusal cannot pass by refusing
everything.

**7. Findings 2, 3 and 4 ticketed, not built:**
`guardian-merge-consolidation-rests-on-a-best-effort-email.md` (carrying the
reviewer's distinction explicitly: the refusal checks an address **exists**, the
safety argument needs it **delivered**, and only the first is mechanised),
`guardian-merge-offers-no-basis-for-choosing-the-keeper.md` (recording the **0 of
28 activated** figure as the reason it is low-stakes today and the trigger that
ends that), and
`guardian-merge-rotates-the-keeper-password-unconditionally.md`. For the last, I
also annotated the pinning assertion in the arm itself so the next reader sees it
pins a trade-off rather than an invariant.

**8. My two errors, corrected.** The stale `tests/Feature/Guardian` block is
re-run and re-pasted below (not hand-edited to the reviewer's numbers — that is
carrying a number, which is what produced the error); `login_state` →
`login_decision` in the dry-run ticket. **I then re-read both tickets against the
code rather than against my memory of writing them** — the arm's three compared
keys, the plan key names, `notifyGuardian`'s swallow, and `causedBy(auth()->user())`
all check out as written.

## Deviations from the ruling

**One, and it is a limit I could not remove without exceeding scope.** Ruling
item 4 says to print the school-exclusivity in the decision table in both modes.
It is printed in both dry-run and applied modes — but a donor that is **both live
and non-exclusive** is refused in *every* mode, so its table row never renders;
that operator gets the schools through the refusal message instead. The column
does render for donors that are non-exclusive but dormant, which is what the arm
at `:370` pins. Making the table render on the refusal path is the same change I
flagged last round (build the decision, render it, then throw) and I did not make
it unasked.

**One inherited from the brief:** `assertLoginRequiresDeliverableEmail` was never
widened, because `merge()` is in `GuardianService` itself. Untouched at `:318`.

## Contradictions of the premise

**None.** The reviewer's finding-1 mechanism, verified in the tree:
`disableLogin` at `:672` writes `users.disabled_at` and nothing school-scoped;
`orphanedUserIdsAfterMerge` computes precisely the negation needed; and the
live+shared+consolidate combination had neither arm nor guard, as stated.

## What changed

Four commits. Re-derived at the moment of writing:

| File | Size | What |
| --- | --- | --- |
| `app/Services/GuardianService.php` | **+854, −0** (`git diff --numstat e484a46`) | `merge()` and thirteen private helpers. Nothing pre-existing modified. |
| `app/Console/Commands/MergeGuardians.php` | **240** | `guardians:merge --keep= --absorb=* [--apply] [--consolidate-login]`. |
| `app/Console/Commands/FindDuplicateGuardians.php` | **229** | `guardians:find-duplicates [--school=]`. |
| `tests/Feature/Guardian/GuardianMergeTest.php` | **958** | **33** arms (`grep -c "^it("`). |
| `docs/handoff/tickets/` | 5 files | Two corrected in place, three new. |

## Proof

Raw output. This harness replaces Pest's stdout with a JSON summary line.

### Targeted file

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never
{"tool":"pest","result":"passed","tests":33,"passed":33,"assertions":152,"duration_ms":32105}
```

### The guardian directory — **re-run, not carried**

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian --colors=never
{"tool":"pest","result":"passed","tests":52,"passed":52,"assertions":175,"duration_ms":37281}
```

Revision 3 pasted `48/153` here, which was a run from before its own final tree —
the reviewer caught it and was right. This is 52 now because this round added
three arms to a directory that was 49. `GuardianLoginInvariantTest` and
`DeliverableEmailPredicateTest` are in it, unmodified, and green.

### Full suite + ratchet, on the final tree

```
$ DB_DATABASE=portal_testing php vendor/bin/pest --log-junit junit.xml; echo "PEST_EXIT=$?"; php bin/ci-test-ratchet.php junit.xml; echo "RATCHET_EXIT=$?"
PEST_EXIT=2

ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

Counts read back out of the JUnit report, because the harness swallowed the
summary line on this invocation:

```
{'tests': '1702', 'assertions': '7104', 'failures': '6', 'errors': '1', 'skipped': '10', 'time': '1400.422313'}
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
1 tests/Feature/Auth/AuthenticationTest.php::users are rate limited
1 tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
1 tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
guardian merge cases: 33
```

Those seven are `tests/ratchet-baseline.txt` exactly. The 33 merge cases are in
this run, so the suite number and the file number are the same tree.

### Gates

```
$ ./vendor/bin/pint app/Services/GuardianService.php app/Console/Commands/MergeGuardians.php tests/Feature/Guardian/GuardianMergeTest.php
{"tool":"pint","result":"passed"}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ DB_DATABASE=portal_testing php vendor/bin/pest --group=arch --colors=never
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":6486,"warnings":2,…ForcingMigrationsDoNotStripLaterGrantsTest…}

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

## The watched red

### Red 1 — the cross-school account refusal (this round's)

**The mutation is two lines, not one, and that is itself a finding.** Removing
only the refusal at `:1122` reds the arm on "exception not thrown" but does **not**
produce the defect: `consolidating` is also gated on
`$notExclusive === []` (`:1029`), so the consolidation never runs and
`disabled_at` stays null. I checked, found the demonstration inert, and removed
both halves of the guard — which is the honest mutation, since both halves landed
in this round.

Arm, with the refusal removed:

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='still backs a live guardian in another school'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":27779,"failed":1,"failures":[{"test":"…it_refuses_to_consolidate_an_account_that_still_backs_a_live_guardian_in_another_school","file":"…/GuardianMergeTest.php","line":351,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

First demonstration attempt, refusal removed but `consolidating` intact — **the
defect does not reproduce**, which is why this is recorded rather than dropped:

```
BEFORE  user#3 authenticates: true | school#2 wards: [1]
AFTER   user#3 authenticates: true | disabled_at=null
```

Both halves removed, same fixture — one human, two schools, one account, a child
at school#2:

```
BEFORE  user#3 authenticates: true | school#2 wards for that account: [1]
AFTER   user#3 authenticates: false | disabled_at=SET
AFTER   school#2 guardian#3 still live: true | wards row still present: [1]
AFTER   school#2 access is unreachable: the record exists, the account cannot sign in.
```

That is the finding: school#2's guardian record and its ward link are both intact
and completely unreachable, because a school#1 cleanup disabled the account. The
parent was emailed about a school#1 record. Restored; `grep -c "WATCHED RED"`
returns `0` and the file is green at 33/33 above.

### Reds carried from earlier rounds, re-verified this round by the file staying green

- **The re-keyed guard** (`assertLoginDecisionAllowed` removed): arm red, then
  the five-line probe with **zero `can_login` rows** — `donor authenticates: true`,
  `forUserInActiveSchool = NULL`, `wards: []`.
- **The deliverability invariant call removed:** reds both the move-side and
  collision-side arms.
- **The collision-side condition**, disabled outright *and* restored to revision
  1's narrowed `$after && ! $before` form: red in both, which is what makes the
  un-narrowing load-bearing.
- **The collision branch** forced to always re-point: `1062` on
  `guardian_student_guardian_id_student_id_unique`.

## The drive

No screen changed — nothing under `resources/js` is touched. No drive.

## Database observations

Local copy, read-only, ids and counts only. Re-confirmed: 776 live guardian rows,
776 distinct users, **0** certain `(user_id, school_id)` groups, **14** likely
groups (all `school#1`, all size 2, all two distinct users).

**No user in this copy backs live guardian rows in more than one school**
(`users_with_live_guardian_rows_in_more_than_one_school=0`), so the refusal added
this round cannot fire on today's data. That is not an argument for it being
unnecessary: the enrolment path produces multi-school parents by design —
`resolveOrCreateGuardianForUserInSchool` exists for exactly that — and slice 3
runs this engine unattended.

The operational picture for the working set is unchanged: all 14 groups need
`--consolidate-login`, each disables a parent's account and emails them new
credentials. Fourteen parents get an unexpected password email. Sequence it with
the school.

## Not done

- **The decision table does not render on the refusal path.** See Deviations.
- **`--consolidate-login` is not exercised end-to-end through the command in
  apply mode.** The dry run is (`GuardianMergeTest.php:880`); the service-level
  consolidation is; the combination is not.
- **No arm asserts the notification's contents**, only that it was sent once, to
  the keeper's user, and not to the donor.
- **`guardians:find-duplicates` still cannot tell an operator which groups will
  need the flag, or which will now be refused for non-exclusivity.**
- **`--school` on `guardians:find-duplicates` is proven only in the narrow
  direction.**
- **The dry-run/apply arm still compares only three plan keys** — ticketed.
- **`bin/quality` has not been run end to end**, only the individual gates. The
  reviewer noted this last round and it is still true.
- **`docs/handoff/briefs/feat-guardian-merge-command.md` remains untracked.**
- **Nothing pushed. No PR. No merge to `staging`.**

## Findings raised, not fixed

- **`guardians:find-duplicates` reports duplicate groups but not their login
  disposition** — which will consolidate, which will be refused as
  non-exclusive, which are inert. It already loads every guardian and both facts
  are one join away. Without it the 14 groups are a work list with two invisible
  columns and the discovery mechanism is a refused merge. **ticket**, and still
  the one I would pick up next.
- `app/Services/GuardianService.php:275` — the creation-path defect. Slice 2. **fix**.
- `app/Models/Guardian.php:88-94` — `applySchoolScope`'s OR branch.
  `Guardian::withoutGlobalScopes()` now at 10 call sites across 5 files
  (re-derived on this tree). **ticket**.
- `app/Services/GuardianService.php:391-397` and the pivot writers — single-primary
  enforced in code only, at each writer, with no detector. **ticket**.
- `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43,48` — two
  constants redefined, warned on every arch run. Pre-existing. **ticket**.
