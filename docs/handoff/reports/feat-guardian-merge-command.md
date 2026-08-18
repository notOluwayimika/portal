# Implementation report — `feat/guardian-merge-command`

**This is full-review tier** — it writes to an append-only audit trail, it moves
rows across a `school_id` boundary, it decides who can sign in to a portal, and
it is the engine slice 3's data migration will run against production data.
Recommend a cold session before merge.

**Revision 2.** The first round of this work shipped a defect: it treated
`can_login` as a pivot boolean and re-pointed it onto the keeper's row without
regard for which `users` account it had been living on. Review caught it, the
project lead ruled, and this revision implements the ruling. Where this report
describes a decision, it describes the current one — the superseded reasoning is
recorded below as superseded, not left standing as design.

---

## Headline

Done. `GuardianService::merge()` plus `guardians:merge` and
`guardians:find-duplicates`, with 24 arms in
`tests/Feature/Guardian/GuardianMergeTest.php`. Branch
`feat/guardian-merge-command`, cut from `staging` @ `e484a46` (`staging` and
`main` are the same commit today — re-derived). Two commits. Not pushed; no PR.

The thing to read first is **Superseded decisions**: a merge can no longer move a
portal login between two `users` rows at all. It refuses. That is a behaviour
change from revision 1, not a hardening of it.

## Superseded decisions

**Revision 1's deviation #2 is reverted and its premise was wrong.** It narrowed
the deliverability guard to fire only when the merge *introduced* `can_login` on
the keeper, on the argument that a keeper row already carrying the flag against
an undeliverable address is a pre-existing violation the merge did not create.
The argument is true about the flag and false about the outcome: in the collision
where the keeper is undeliverable and already true, and the absorbed record holds
the same student on a **deliverable** account, the merge deleted the working
login and kept the dead one — and the narrowed condition had nothing to say,
because nothing was raised. The guard now fires on **every** write that leaves
the flag true (`app/Services/GuardianService.php:1062`, and the move side at
`:1021`).

The general lesson, stated so it can be attacked: *a guard scoped to "did this
write make things worse" cannot see a write that makes a bad state permanent.*
That is what the narrow condition was, and the shape is not specific to this
method.

**Revision 1 had no answer at all to the account question**, which is the larger
half. See finding 1 below.

## What this revision changed, against the ruling

**1. `merge()` refuses to relocate a portal login between accounts**
(`assertNoCrossAccountLogin`, `app/Services/GuardianService.php:946`, called at
`:820` — before the planner, not merely before the apply).

It refuses when an absorbed guardian has any pivot with `can_login = true` and
its `user_id` differs from the keeper's. **Unconditional on deliverability**: a
deliverable keeper does not make it safe, and believing it did was the hole. The
deliverability invariant asks whether the *destination* can receive mail; it
never asks what becomes of the *source* account, which is the one the parent's
password belongs to. Same `user_id` on both sides is the only case that proceeds
— and that case needs no relocation, which is the point.

The message names `guardian#<id>`, its `user#<id>`, the affected `student#<id>`s,
the keeper's `user#<id>`, and says what to do instead (consolidate the login
first through the existing enable/disable flows, then re-run). The command exits
non-zero on it.

Refusal rather than migration, per the ruling: re-issuing credentials would email
a parent a new password during what an operator ran as a cleanup, and disabling
the donor would revoke access nobody asked to revoke.

**2. The deliverability guard is un-narrowed.** See above.

**3. `MergeGuardians::render` prints the login state it is deciding on**
(`app/Console/Commands/MergeGuardians.php:130`), in both dry run and apply, and
prints it **first**, above the pivot tables: per absorbed record, the
`guardian#<id>`, its `user#<id>`, which students it carries login for, and
whether that account is the keeper's (`yes` / `NO`). The keeper's own account is
on the header line (`:119`). A refused merge never reaches this output; the table
is what makes the near-miss legible and shows the safe same-account case for what
it is.

**4. The audit trail records links, not counts** (finding 2). The `merged` entry
now carries `moved_student_ids` and `collision_student_ids`
(`app/Services/GuardianService.php:1328-1329`), and `logMergedLinks` (`:1355`)
emits the per-link events the rest of the service already emits — `attached` on
the keeper for each re-pointed link, `detached` on the absorbed record for each
colliding row deleted, each carrying the relationship and both booleans **as the
deleted row held them**. That last part needed a plan change: the collision entry
previously recorded only the keeper's before/after state, so the absorbed row's
own values were nowhere. A colliding pivot is hard-deleted; these entries are the
only surviving record of it.

**5. `merge()` asserts the keeper is in the ACTIVE school** (finding 4, done not
ticketed) — `app/Services/GuardianService.php:853-882`. Keeper-matches-absorbed
and keeper-is-in-my-context are different claims and only the second was being
made. Read through `ActiveSchool::id()` rather than `getOrFail()`, because
`getOrFail` raises a 403 abort — right for a request, useless in a console — and
absent context is refused explicitly instead. Every test call now establishes
context from the keeper's own `school_id`, the way the command does.

**6. Finding 5 is a ticket, not a build** —
`docs/handoff/tickets/guardian-merge-dry-run-and-apply-are-two-plans.md`. It
records that the "one plan" claim is true of the method and false of the
procedure the command mandates (two invocations, no lock, no plan hash), and that
the dry-run arm compares only `array_keys`, `pivot_moves` and `backfilled`.

**7. Finding 3** — every citation in this document was re-derived against the
committed tree at the moment of writing, not carried from revision 1.

**8. A multi-absorbed arm exists** (`GuardianMergeTest.php:412`), which revision 1
flagged as its own biggest untested gap.

## Deviations from the brief and the ruling

**None on the ruling.** Every numbered item is implemented as written.

**Against the original brief, one that survives from revision 1 and is now
load-bearing in a new way:** `assertLoginRequiresDeliverableEmail` was never
widened. The brief said "widen it only as far as needed"; needed was nothing,
because `merge()` is in `GuardianService` itself. It is untouched at `:318`.

**One arm changed shape as a consequence of the ruling, and a reader should know
why.** The collision arm (`GuardianMergeTest.php:136`) originally put keeper and
absorbed on two different accounts with `can_login` on the absorbed side — which
the new pre-flight now refuses outright. It is rebuilt on **one** account, which
is the certain-duplicate shape, and only there can the `can_login` OR-merge be
exercised at all. That is the rule made concrete: a `can_login` that changes rows
without changing accounts strands nobody.

## Contradictions of the premise

**None in the code.** Re-read and confirmed against this tree, not against
revision 1's list: `createGuardianWithUser` at `:226-288` with the unconditional
`Guardian::create()` at `:275` and `User::where('email', $userEmail)->first()` at
`:258`; `assertLoginRequiresDeliverableEmail` at `:318-332`;
`forUserInActiveSchool` at `:754-768` with its docblock recording the missing
unique key; `attachToStudent`'s credential re-issue at `:386-388` and its
code-only single-primary enforcement at `:391-397`; `logPivotEvent` at `:1404`.
`Guardian::applySchoolScope` at `app/Models/Guardian.php:88-94` and `$fillable` at
`:49-71`. `guardians.user_id` NOT NULL + `cascadeOnDelete` at
`database/migrations/2026_05_13_132246_create_guardians_table.php:18`;
`unique(['guardian_id','student_id'])` at
`database/migrations/2026_05_13_140000_create_guardian_student_table.php:20`; the
same-school triggers signalling `45000` at
`database/migrations/2026_07_16_000003_add_guardian_student_same_school_constraint.php:17-30`.

**The data half of the brief's finding does not reproduce** — unchanged from
revision 1 and re-confirmed below. Per the lead's ruling the 14 phone-matched
pairs are the working set and the reported triplicate is being chased separately;
nothing in this slice is designed around it.

## What changed

Two commits on the branch. Cumulative shape:

| File | Δ | What |
| --- | --- | --- |
| `app/Services/GuardianService.php` | +~590 | `merge()` and eight private helpers: refusals (including the active-school and cross-account-login pre-flights), the plan simulation, the login-state summary, the deliverability re-raise, the blank back-fill, the orphan report, the apply, and the per-link audit entries. One import added (`Illuminate\Support\Collection`). Nothing pre-existing in the file is modified. |
| `app/Console/Commands/MergeGuardians.php` | 205 (new) | `guardians:merge --keep= --absorb=* [--apply]`. Dry run by default; context from the keeper's own `school_id`; login state printed first; ids-only output; non-zero on refusal. |
| `app/Console/Commands/FindDuplicateGuardians.php` | 229 (new) | `guardians:find-duplicates [--school=]`. Certain groups (same `user_id`+`school_id`) and likely groups (shared normalised phone, connected components). Non-zero while any certain group exists. |
| `tests/Feature/Guardian/GuardianMergeTest.php` | 680 (new) | 24 arms. |
| `docs/handoff/tickets/guardian-merge-dry-run-and-apply-are-two-plans.md` | new | Finding 5. |

Design notes worth attacking directly:

- **Two login guards, and neither subsumes the other.** `assertNoCrossAccountLogin`
  asks what happens to the account the parent already signs in with;
  `assertMergedLoginIsDeliverable` asks whether the destination account can be
  reached. Both are pinned by arms that go red independently (below).
- **The plan is a simulation and it is sequential on purpose.** Two absorbed
  guardians linked to the same student make the first a move and the second a
  collision; classifying both against the original keeper rows would call both
  moves and hit the unique index. Now proven by `GuardianMergeTest.php:412`
  rather than argued.
- **The apply writes the simulated FINAL pivot state**, so a student touched by
  two absorbed rows lands on one value rather than the last one written.
- **No hard delete of a guardian and no `users` write anywhere.** Absorbed
  guardians are soft-deleted; orphaned users are reported, never acted on.
- **Every Guardian query drops the global scopes** and pins `school_id` /
  `deleted_at`; every pivot query is `DB::table('guardian_student')`.

## Proof

Raw output. **A note the reader needs:** this harness replaces Pest's stdout with
a JSON summary line — the per-test output is not available to me in any
invocation I tried. What follows is verbatim what the commands returned.

### Targeted file

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never
{"tool":"pest","result":"passed","tests":24,"passed":24,"assertions":104,"duration_ms":31055}
```

### The guardian directory, including the invariant test the ruling could have broken

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian --colors=never
{"tool":"pest","result":"passed","tests":43,"passed":43,"assertions":127,"duration_ms":56563}
```

`GuardianLoginInvariantTest` is in that directory and is green; it was not
modified.

### Full suite + ratchet, on the final tree

```
$ DB_DATABASE=portal_testing php vendor/bin/pest --log-junit junit.xml; echo "PEST_EXIT=$?"; php bin/ci-test-ratchet.php junit.xml; echo "RATCHET_EXIT=$?"
PEST_EXIT=2

ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

`PEST_EXIT=2` is the 7 baselined failures, which is what the ratchet exists to
distinguish. Counts read back out of the JUnit report, because the harness
swallowed the summary line on this invocation:

```
{'tests': '1693', 'assertions': '7056', 'failures': '6', 'errors': '1', 'skipped': '10', 'time': '1783.768657'}
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
1 tests/Feature/Auth/AuthenticationTest.php::users are rate limited
1 tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
1 tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
guardian merge cases: 24
```

Those seven are `tests/ratchet-baseline.txt` exactly — same seven lines, no more,
no fewer. Two are `GuardianProfileTest` and therefore guardian-adjacent, so worth
naming rather than glossing: both were in the baseline before this branch and
neither changed state. The 24 merge cases are present in this run, so the suite
number and the file number are the same tree.

### Gates

```
$ ./vendor/bin/pint app/Services/GuardianService.php app/Console/Commands/MergeGuardians.php app/Console/Commands/FindDuplicateGuardians.php tests/Feature/Guardian/GuardianMergeTest.php
{"tool":"pint","result":"fixed","files":[{"path":"app\/Services\/GuardianService.php","fixers":["single_quote","unary_operator_spaces","braces_position","not_operator_with_successor_space","single_line_empty_body"]}]}

$ php bin/ci-authz-lint.php
authz-lint: 1 NEW commented-out authorization check(s) — do not comment out authorization:
  ✗ app/Services/GuardianService.php  // abort(403)s, which is a sensible HTTP response and a useless console
```

**That red is worth reading rather than skipping.** It is a false positive on
prose — a comment explaining why I read `ActiveSchool::id()` instead of
`getOrFail()` contained the literal `// abort(403)` and the lint cannot tell an
explanation from a disabled check. I reworded the comment rather than baseline
it: the lint is doing exactly its job, and teaching it to ignore a shape is
worth more than one sentence's phrasing. After the rewording:

```
$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).
exit=0

$ ./vendor/bin/pint app/Services/GuardianService.php
{"tool":"pint","result":"passed"}

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ DB_DATABASE=portal_testing php vendor/bin/pest --group=arch --colors=never
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":10087,"warnings":2,"warning_details":[{"file":"…/ForcingMigrationsDoNotStripLaterGrantsTest.php","line":43,"message":"Constant FORCING_MIGRATIONS already defined"},{"file":"…/ForcingMigrationsDoNotStripLaterGrantsTest.php","line":48,"message":"Constant CONVERGES_MARKER already defined"}]}

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

The two arch warnings are pre-existing and unrelated. Pint was invoked with an
explicit file list every time, never a bare path.

## The watched red

Four mutations, four reds, all restored. `grep -c "WATCHED RED"` on the service
returns `0` and the file is green at 24/24 above.

### Red 1 — the cross-account pre-flight (the one that matters)

Mutation, in `merge()`:

```diff
-            $this->assertNoCrossAccountLogin($keeper, $absorbed);
+            // WATCHED RED: cross-account login pre-flight removed
```

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='refuses to relocate a portal login'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":29531,"failed":1,"failures":[{"test":"…it_refuses_to_relocate_a_portal_login_onto_a_different_user_account","file":"…/GuardianMergeTest.php","line":224,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

An assertion message is not the same as *seeing the defect*, and the ruling asked
for the relocation shown. With the pre-flight still removed, planting the shape
directly against `portal_testing` and printing where the `can_login` link lives
before and after:

```
BEFORE can_login link -> guardian#2 on user#3 (deleted_at=NULL)
keeper is guardian#1 on user#2
AFTER  can_login link -> guardian#1 on user#2
DONOR  user#3 still exists, disabled_at=NULL, backs live guardians: 0
```

That is the whole defect in four lines: the login moved from `user#3` to
`user#2`, and `user#3` — the account whose password the parent has — is still
enabled, still holding school access, and now backs zero live guardian rows. That
user signs in successfully and sees an empty ward list. Restored:

```
$ DB_DATABASE=portal_testing php vendor/bin/pest … --filter='refuses to relocate a portal login'
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":6,"duration_ms":35297}
```

### Red 2 — the collision-side deliverability condition, in both directions

The reviewer's point was that this condition had no red **and no arm in either
direction**. It now has an arm (`GuardianMergeTest.php:286`) and two reds.

Disabled outright:

```diff
-                if ($after['can_login']) {
+                if (false) { // WATCHED RED: collision-side invariant disabled
```

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":25204,"failed":1,"failures":[{"test":"…it_aborts_on_a_collision_that_leaves_an_already_true_flag_on_an_undeliverable_keeper","file":"…/GuardianMergeTest.php","line":302,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

And — the one that matters for the revert — restored to **revision 1's narrowed
condition**, which is what the ruling ordered removed:

```diff
-                if ($after['can_login']) {
+                if ($after['can_login'] && ! $before['can_login']) { // WATCHED RED: the OLD narrowed condition
```

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":27238,"failed":1,"failures":[{"test":"…it_aborts_on_a_collision_that_leaves_an_already_true_flag_on_an_undeliverable_keeper","file":"…/GuardianMergeTest.php","line":302,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

So the revert is not cosmetic: the old condition is red against this arm.
Restored.

### Red 3 — the deliverability invariant call itself

```diff
-            $this->assertLoginRequiresDeliverableEmail($keeper, true);
+            // WATCHED RED: invariant call removed
```

```
{"tool":"pest","result":"failed","tests":2,"passed":0,"assertions":2,"duration_ms":22028,"failed":2,"failures":[{"test":"…it_aborts_the_whole_merge_rather_than_move_login_access_onto_an_undeliverable_keeper","file":"…/GuardianMergeTest.php","line":275,…},{"test":"…it_aborts_on_a_collision_that_leaves_an_already_true_flag_on_an_undeliverable_keeper","file":"…/GuardianMergeTest.php","line":302,…}]}
```

Both arms, move side and collision side. Restored; the pair is green at 2/2.

### Red 4 — the collision branch (carried from revision 1, re-verified)

```diff
-                if (! isset($state[$studentId])) {
+                if (true) { // WATCHED RED: collision branch disabled
```

```
{"tool":"pest","result":"failed",…,"message":"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1' for key 'guardian_student.guardian_student_guardian_id_student_id_unique' (Connection: mysql, …, SQL: update `guardian_student` set `guardian_id` = 1, `updated_at` = … where `id` = 2)"}
```

1062, on the right unique index, on the re-point. Restored.

## The drive

No screen changed — nothing under `resources/js` is touched. No drive.

## Database observations

Local copy, read-only, ids and counts only. Unchanged from revision 1 and
re-confirmed on this tree.

```
$ php artisan guardians:find-duplicates
Live guardian records examined: 776 (all schools)

(1) CERTAIN — more than one live guardian row for the same user in the same school: 0

(2) LIKELY — distinct live guardian rows sharing a phone number within a school: 14
```

The 14 groups are all `school#1`, all of size 2:
`guardian#2428/#3121`, `#2478/#3126`, `#2484/#3124`, `#2687/#3118`,
`#2730/#3105`, `#2767/#3123`, `#2900/#2947`, `#2953/#3109`, `#2955/#3091`,
`#3022/#3116`, `#3023/#3101`, `#3029/#3103`, `#3042/#3111`, `#3055/#3099`.

Corroborated independently of the command:

```
guardians_rows_total=776
guardians_rows_live=776
same_user_school_groups_including_soft_deleted=0
users_with_live_guardian_rows_in_more_than_one_school=0
schools=2
name_groups_size_ge_2=4
name_groups_size_ge_3=0
max_group_size=2
```

**What this means for the new refusal, and it is the operationally important
line in this report:** every one of the 14 groups is two *distinct* users — that
is what makes them invisible to grouping (1). So the cross-account refusal
applies to the entire working set the lead has approved, and any group in it that
carries a portal login will be **refused** until an operator consolidates the
login first. That is the intended behaviour and it is not a small operational
fact: the remediation is now two steps for those groups, not one. The reviewer
identified `guardian#2900 / guardian#2947` as the group carrying login; a merge
of that pair is refused by this branch.

That the detector works is proven separately by an arm that plants a certain
duplicate and watches the command exit non-zero, so the `0` above is a fact about
the data rather than a silent no-op.

## Not done

- **No authorization on `merge()` beyond the active-school assertion.** Out of
  scope per the ruling. The admin merge UI must add one; the school assertion is
  an isolation boundary, not an authority check.
- **`--school` on `guardians:find-duplicates` is proven only in the narrow
  direction.** No arm proves two guardians in *different* schools sharing a phone
  are not grouped. The union-find keys on `school_id.':'.$number` so they cannot
  be, but that is an argument, not a test.
- **The dry-run/apply arm still compares only three keys.** Recorded in the
  finding-5 ticket rather than fixed, per the ruling.
- **No arm drives the new login table's rendered output.** The `login_state` plan
  entry is asserted (`GuardianMergeTest.php:309`); what `render` prints from it
  is not.
- **`docs/handoff/briefs/feat-guardian-merge-command.md` remains untracked.** It
  arrived that way; it is not mine to commit.
- **Nothing pushed. No PR. No merge to `staging`.**

## Findings raised, not fixed

- `app/Services/GuardianService.php:275` — the creation-path defect itself, still
  live. Slice 2. **fix**, and the reason this slice exists.
- `app/Models/Guardian.php:88-94` — `applySchoolScope`'s OR branch makes another
  school's guardian rows visible under the default scope.
  `Guardian::withoutGlobalScopes()` now stands at 9 call sites across 5 files
  (`grep -rn 'Guardian::withoutGlobalScopes' app/`, re-derived on this tree; this
  branch adds 4). **ticket**.
- `app/Services/GuardianService.php:391-397`, `:496-501` — single-primary is
  enforced in code only, at each writer. The pre-state in the third-guardian arm
  (two primaries for one student) is constructible directly through the pivot and
  nothing detects it. A `guardians:audit-single-primary` in the shape of
  `guardians:audit-login-invariant` would make it a fact rather than a
  convention. **ticket**.
- **The cross-account refusal has no detector.** `guardians:find-duplicates`
  reports duplicate groups; it does not report which of them will be refused for
  carrying a login on a foreign account. An operator working the 14 groups will
  discover it one merge at a time. A column on the likely/certain tables would
  turn that into a work list. **ticket**, and it is the one I would pick up next.
- `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43,48` — two
  constants redefined, warned on every `--group=arch` run. Pre-existing.
  **ticket**.
