# §9 step 4c — the opening-balance approval gate

**Branch** `feat/finance-ob-approval-gate` · **Base** `origin/staging` @ `6890edb` ·
**Commit** `8e9e79e` · one commit, 17 files.

**This is full-review tier** — money, a migration, a new Permission triple, grants, a
`school_id`-scoped Action, two lints and three fixture oracles. Subagent review attached;
recommend a cold session before merge.

---

## 0. Deviations from the brief — read these first

**D1. `PostOpeningBalanceBatch`'s entry state moved from `validated` to `submitted`, and 4b's
test file moved with it.** The brief said "do not re-implement any of 4b's guards" in
`ApproveOpeningBalanceBatch`, and I have not — but the two Actions could not both be right with
Post still requiring `validated`: Approve hands Post a batch that is `submitted`. Leaving Post
accepting `validated` as well would have left the pre-gate door open — anything holding a
`validated` batch could post it without a second signature, which is the one thing 4c exists to
prevent. So `PostOpeningBalanceBatch` now refuses anything but `submitted`
([PostOpeningBalanceBatch.php:159-166](../../../app/Finance/Actions/PostOpeningBalanceBatch.php#L159)),
and `OpeningBalancePostingTest`'s `obpBatch()` helper stages `submitted` instead of `validated`.
Three assertions in that file moved from `Validated` to `Submitted`, and its
"refuses to post a batch that is not validated" case now plants a `validated` batch **as the
refused state** — a strictly stronger claim than it made before. No assertion was weakened.

**D2. The brief's step 4 said "update the command's docblock". I also changed the refusal
STRING and the test that asserts it.** The old message read *"the approval gate is §9 step 4c —
not built"*, and the test demanded the words `the approval gate is §9 step 4c`
([OpeningBalanceImportTest.php:829](../../../tests/Feature/Finance/OpeningBalanceImportTest.php#L829)).
After 4c both would have been assertions that the feature is still unbuilt. The refusal itself
is untouched — same position (before any option is read), same `self::FAILURE`, no `--post`
flag. What changed is that it now points at the approval rather than at a milestone.

**D3. `tests/Unit/Finance/ApprovalRequirementTest.php`'s maker list is now derived, not typed.**
It hard-listed four maker abilities; 4c makes five. Rather than adding a fifth name to a list
that will go stale again, it derives from the enum with the same predicate the lint uses, and
asserts the count equals the number of `Submit*.php` files so it cannot pass vacuously.

**D4. I did NOT regenerate `tests/fixtures/route-access-map.json`.** See §6 — regenerating it
folds 153 lines of pre-existing, unrelated drift into this commit.

---

## 1. The four pre-edit verifications

All four confirmed. Three were corrections to the original scoping and each holds.

| Check | What the file actually says |
|---|---|
| `bin/ci-grants-convergence-lint.php` exemption 1 | *"THE PERMISSION IS NEW — the same diff adds its `case` to `app/Enums/Permission.php`. It then lands in `$newPermissions` and `rbac:sync` grants it. **No migration needed**."* Confirmed **by running the lint against the commit** — see §5. |
| `DutySeparation::pairs()` | Walks `Permission::cases()`, keeps those `ApprovalAbility::isExcludedFromSuperAdminBypass()` accepts, and derives the maker via `matchingMakerFor()`. Nothing is registered anywhere; the catalog **is** the source. |
| `ApprovalAbility` | The convention is the **terminal segment only** — `terminalSegment()` is `substr` after the last dot, and `CHECKER_SEGMENTS = ['approve','reject']`. Not a prefix, not a substring. `matchingMakerFor()` swaps that last segment for `submit`. |
| `bin/ci-boundary-lint.php` 150-201 | `approval-seam-missing` enumerates `app/Finance/Actions/Submit*.php` from the **filesystem** and requires `ApprovalRequirement::for(` on a **live** (non-comment) line. `approval-seam-count` greps `case FINANCE_[A-Z_]*SUBMIT =` from the enum and requires that count to equal the number of `Submit*.php` files. Both **zero baseline entries**. |

The count rule is what makes 4c indivisible: `FINANCE_OPENING_BALANCE_SUBMIT` matches that regex,
so the case and `SubmitOpeningBalanceBatch.php` had to land in the same commit.

---

## 2. What was built

**Permissions** — `finance.opening-balance.submit` / `.approve` / `.reject`, added to
`app/Enums/Permission.php` and to `PermissionGroup::FINANCE` (`group()` has no default;
`PermissionGroupTest` asserts the groups partition the enum exactly, so an unfiled case fails).

**Grants** — read off `RbacSeeder::grantsMap()`, not chosen. §3 below.

**State** — `Submitted` only. No `approved`: `ApproveOpeningBalanceBatch` posts inside the same
transaction, so `approved` would be a value no row ever holds between two commits.

**Migration** `2026_08_09_100000_opening_balance_approval_gate.php` — `submitted_by_user_id`,
`submitted_at`, `decided_by_user_id`, `decided_at`, `rejection_reason`, plus the
`..._maker_ne_checker` CHECK the other four approval tables carry. No CHECK on `status` had to be
widened: that column is a plain `string` with no constraint behind it
(`2026_08_06_100000:89`), so the legal set is the enum and nothing else.

Two notes a reviewer should push on:

- The two user columns are `*_user_id` **lookups with no FK**, unlike the other four request
  tables' `submitted_by` / `decided_by` FKs. That follows **this table's own** convention
  (`uploaded_by_user_id`, `posted_by_user_id`, both lookups, for the reason 2026_08_08_110000
  records: attribution must survive a deleted user). Two columns on one table under one
  convention and two under another looked worse than the divergence.
- `rejected` is now reached two ways — the validator's structural rejection and a checker's
  governance rejection. They are distinguished by `rejection_reason` + `decided_by_user_id`,
  non-null only on the governance path. This reuse is what the brief specified
  ("batch → rejected"); I have recorded the ambiguity in the enum, the migration and the Action
  rather than resolving it by coining a sixth state.

**Three Actions** — verbatim in §8.

**The command's refusal stays.** Same position, same exit code, no flag. Docblock and message now
say it is permanent.

---

## 3. Which roles, and where I read that from

Read from `database/seeders/RbacSeeder.php` by locating every existing
`FINANCE_FEE_SCHEDULE_CHANGE_*` grant and following it:

| Ability | Roles | Source line I read |
|---|---|---|
| `finance.opening-balance.submit` | `accounts_officer`, `accounts_supervisor` | `RbacSeeder.php:352` (AO) and `:367` (AS) both hold `FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT` |
| `finance.opening-balance.approve` / `.reject` | `head_of_school` | `RbacSeeder.php:231-232` holds `FINANCE_FEE_SCHEDULE_CHANGE_APPROVE` / `_REJECT` |

`finance_lead` does **not** get it, because `finance_lead` does not hold
`fee-schedule.change.submit` either — that role holds only the credit-note and discount-policy
maker sides.

This is pinned by a test written as a **comparison, not a name list**
(`OpeningBalanceApprovalGateTest.php`, last case): the holders of each opening-balance ability
must equal the holders of the corresponding fee-schedule-change ability, with a non-vacuity
assertion so an ability nobody holds cannot make all three comparisons trivially true. A future
seat realignment that moves one triple therefore moves this one or fails loudly.

**No convergence migration exists in this diff, and none is needed — exemption 1.** The lint
says so itself, by name, per pair. Raw output in §5. A reviewer should not go looking for one.

**One thing that disagrees with the grant, recorded rather than resolved.**
`docs/finance/authority-matrix-decisions-2026-08-03.md:83` row 17 — *"Change an opening
balance"* — is `D | D | D | V | V`: **no approver at all**, and HoS is a viewer. That row is
about *changing* an opening balance as an ongoing transaction, not about the cutover import,
and spec §8 is explicit that the import is maker-checker with the batch as the unit of approval.
I followed §8 and the brief. But the two documents use the same words for different acts, and
whoever owns the matrix should say so out loud before row 17 is ever built.

---

## 4. §8 / U16 — the queue does NOT pick the new type up

§8 claims 4c "makes **six** on the approvals queue (U16)". **It does not, and it could not.**
How I checked, in three steps:

1. **The page ROUTE does pick it up.** `routes/web.php:167-172` derives the middleware string
   from the `ApprovalAbility` convention over the catalog, so the new checker abilities joined
   it with no route edit. Evidence: `tests/fixtures/route-middleware-baseline.json` gained
   `|finance.opening-balance.approve|finance.opening-balance.reject` on the
   `GET /finance/approvals` entry — a one-line diff, regenerated by `rbac:derive-map`, not
   hand-edited. So a holder of the new checker ability can open the queue.

2. **The page CONTENT does not.** `resources/js/pages/admin/finance/approvals.tsx:70-77` fetches
   exactly **two** feeds — `CreditNoteController@pending` and `VoidRequestController@pending` —
   merges them, and discriminates on `type: 'credit_note' | 'void'`
   (`resources/js/types/finance.ts:100`). There is no third fetch and no third discriminator.

3. **There is no opening-balance feed to fetch.** `routes/endpoints/finance.php` has four
   `…/pending` routes (credit-notes, void-requests, fee-schedule-changes,
   discount-policy-changes). 4c adds no controller, no Resource and no route, because the brief
   scopes 4c to the domain and puts the operator screen at step 5.

So the honest count today is: **four pending feeds at the API, two of them rendered in the
queue, and five request types in the domain.** `docs/handoff/finance-mvp-cut-brief.md:140`
records U16 as *"approvals.tsx exists, covers four"* — that is wrong at the UI layer; it covers
two. U16 remains open and is now two types further from done, not one.

---

## 5. Gates — raw

```
$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-grants-convergence-lint.php origin/staging
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (6890edb..8e9e79e; 4 exempted).
  · finance.opening-balance.approve @ database/seeders/RbacSeeder.php:240 — exempt: permission is NEW in this diff (lands in $newPermissions)
  · finance.opening-balance.reject @ database/seeders/RbacSeeder.php:241 — exempt: permission is NEW in this diff (lands in $newPermissions)
  · finance.opening-balance.submit @ database/seeders/RbacSeeder.php:365 — exempt: permission is NEW in this diff (lands in $newPermissions)
  · finance.opening-balance.submit @ database/seeders/RbacSeeder.php:383 — exempt: permission is NEW in this diff (lands in $newPermissions)

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}

$ ./vendor/bin/pest --group=arch
{"tool":"pest","result":"passed","tests":23,"passed":23,"assertions":140}

$ ./vendor/bin/pint --test --dirty
{"tool":"pint","result":"passed"}
```

`bin/quality` raw output is in §9.

---

## 6. The fixture oracles

Regenerated in the required order — `rbac:sync` (via `migrate:fresh --seed` on
`portal_testing`) → `rbac:derive-access` → the baselines.

- **`rbac-grants-baseline.json`** — regenerated by re-running the exact computation
  `PermissionEnumTest` performs against a freshly migrated + seeded `portal_testing`. Diff is
  **+5 −1**: `finance.opening-balance.submit` into `accounts_officer` and
  `accounts_supervisor`, `.approve` + `.reject` into `head_of_school`. Nothing else moved.
- **`route-middleware-baseline.json`** — regenerated by `rbac:derive-map`. Diff is **one line**,
  the derived approvals-route middleware string (§4).
- **`route-access-map.json` — REGENERATED, THEN REVERTED, DELIBERATELY.** `rbac:derive-access`
  produced **+153 lines and 0 removals**, all of them route keys absent from the committed
  fixture: `GET /api/notifications`, `/api/notifications/unread-count`,
  `/api/notifications-queue-health`, `/api/parent/wards`, `/notifications/queue-health`,
  `PATCH /api/notifications/{uuid}/read`, `POST /api/notifications/read-all`, `/seen`,
  `/ses-events`, `/{notification}/actions/{action}` — ten routes, none of them mine. The
  `GET /finance/approvals` entry did not change at all. Folding a 153-line correction of
  someone else's drift into this commit would hide it, so I reverted the file and
  `RouteAccessParityTest` still passes against the committed version.

  **That is itself the finding: the map is stale by ten routes and the test that reads it does
  not notice.** `RouteAccessParityTest` passes both before and after, so whatever it asserts, it
  is not route-set completeness. A fixture that can fall ten routes behind without any gate
  going red is wallpaper for exactly the thing it was written to oracle. I have not fixed it —
  it belongs in its own commit, with someone deciding whether the parity test should be
  tightened.

---

## 7. The watched reds — mutation, raw failure, restore

Every one was watched red **before** it was watched green. All five restored; the working tree
after restoration is byte-identical to the committed state.

### Red 1 — the maker approving their own batch

Mutation: delete the `submitted_by_user_id === $checker->id` refusal from
`ApproveOpeningBalanceBatch::handle`.

```
{"result":"failed","tests":3,"passed":2,"failed":1,"failures":[{
 "test":"...it_PROOF_1_—_the_MAKER_who_submitted_a_batch_cannot_approve_it__refused__and_NOTHING_posts",
 "message":"Failed asserting that 'SQLSTATE[HY000]: General error: 3819 Check constraint
 'finance_opening_balance_batches_maker_ne_checker' is violated. (Connection: mysql, ...,
 SQL: update `finance_opening_balance_batches` set `decided_by_user_id` = 2, ... where `id` = 1)'
 contains \"maker ≠ checker\"."}]}
```

Worth reading closely: with the PHP guard removed, **the CHECK constraint caught it** — the test
went red on the *wrong exception type*, not on a successful self-approval. The two layers are
genuinely independent, which is what `PROOF 1b` asserts directly (3819 by driver code).

### Red 2 — super_admin's checker exclusion

Mutation: the historical denylist-drift bug — an early
`if (str_starts_with($ability, 'finance.opening-balance.')) return false;` in
`ApprovalAbility::isExcludedFromSuperAdminBypass()`.

```
{"result":"failed","tests":2,"passed":0,"failed":2,"failures":[
 {"test":"...PROOF_1c_—_the_pair_is_what_the_CONVENTION_derives__not_a_list_anyone_maintains",
  "message":"Failed asserting that null is identical to Array &0 [
    'checker' => 'finance.opening-balance.approve', 'maker' => 'finance.opening-balance.submit']."},
 {"test":"...PROOF_2_—_super__admin_CANNOT_approve_or_reject_a_cutover__and_CAN_still_hold_the_maker_side",
  "message":"Failed asserting that true is false."}]}
```

Both arms fire: the pair stops being derived **and** the bypass reaches the checker ability.

### Red 3 — approval must post

Mutation: `ApproveOpeningBalanceBatch` records the decision and returns without calling the poster.

```
{"result":"failed","tests":2,"passed":0,"failed":2,"failures":[
 {"test":"...PROOF_3_—_APPROVE_posts_the_batch...",
  "message":"Failed asserting that two variables reference the same object.\n
  -App\\Finance\\Enums\\OpeningBalanceBatchStatus Enum #7316 (Posted, 'posted')\n
  +App\\Finance\\Enums\\OpeningBalanceBatchStatus Enum #7249 (Submitted, 'submitted')"},
 {"test":"...PROOF_3b_—_approval_is_ONE_transaction...",
  "message":"Failed asserting that 0 is identical to 1062."}]}
```

### Red 4 — rejection must move no money

Mutation: the copy-paste-from-`Approve` hazard — `RejectOpeningBalanceBatch` calls
`PostOpeningBalanceBatch` before writing the rejection.

```
{"result":"failed","tests":3,"passed":2,"errors":1,"error_details":[{
 "test":"...PROOF_4_—_REJECT_leaves_the_batch_rejected_with_ZERO_ledger_rows_and_ZERO_payments",
 "message":"SQLSTATE[45000]: <<Unknown error>>: 1644 A posted opening-balance batch is terminal
 (G1b): neither its status nor its School can move. (..., SQL: update
 `finance_opening_balance_batches` set `status` = rejected, `decided_by_user_id` = 3, ...)"}]}
```

Again the database got there first: G1b refused the `posted → rejected` move with 1644 before the
test could reach its zero-rows assertion. The mutation is caught; it is caught one layer below
where I aimed it.

### Red 5 — the two lints, and what each would have caught

**5a — `approval-seam-count`.** Mutation: the triple lands **without** the Submit action
(`SubmitOpeningBalanceBatch.php` moved out of the tree — exactly the split the brief said is
impossible).

```
boundary-lint: 1 NEW boundary violation(s):
  ✗ approval-seam-count  app/Enums/Permission.php  finance *_SUBMIT abilities (5) != Submit* actions (4) — ADR 0051 seam-coverage drift
```

**That is the answer to "what would it have caught":** had I added the Permission triple and the
grants and shipped the Actions in a later commit, this lint would have failed the push on the
count 5 ≠ 4. It is why 4c is one commit.

**5b — `approval-seam-missing`.** Mutation: comment out the `ApprovalRequirement::for(…)` call in
`SubmitOpeningBalanceBatch` (the authz-rule-15 shape — leaving the `use` in place).

```
boundary-lint: 1 NEW boundary violation(s):
  ✗ approval-seam-missing  app/Finance/Actions/SubmitOpeningBalanceBatch.php  does not call ApprovalRequirement::for() — the maker-checker seam (ADR 0051)
```

It would have caught a Submit action that hard-wires "always needs a checker" at its own call
site instead of routing the decision through the one configurable seam — which is what makes the
comment-out, not just the deletion, a violation.

Restored, both green:

```
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).
```

---

## 8. The three Actions, verbatim

See `git show 8e9e79e -- app/Finance/Actions/SubmitOpeningBalanceBatch.php
app/Finance/Actions/ApproveOpeningBalanceBatch.php
app/Finance/Actions/RejectOpeningBalanceBatch.php` — reproduced in the chat transcript
accompanying this report.

Shape, for a reader who wants the summary before the source:

- **`SubmitOpeningBalanceBatch`** — school-context refusal, `validated`-only refusal,
  `ApprovalRequirement::for(FINANCE_OPENING_BALANCE_SUBMIT)` on a live line, then a transaction
  that re-reads under lock, re-checks `validated`, and writes `submitted` +
  `submitted_by_user_id` + `submitted_at`. `notifyApprovalCheckers` fires **after** the commit,
  outside the closure.
- **`ApproveOpeningBalanceBatch`** — one transaction: lock, refuse unless `submitted`, refuse if
  submitter == checker, write `decided_by_user_id` / `decided_at`, then
  `PostOpeningBalanceBatch::handle` inside the same transaction. It re-implements none of 4b's
  guards; the decision is written *before* the post so a failed post rolls it back with it.
- **`RejectOpeningBalanceBatch`** — reason required (trimmed, non-empty), lock, refuse unless
  `submitted`, refuse if submitter == checker, write `rejected` + `decided_by_user_id` +
  `decided_at` + `rejection_reason`. Nothing posts.

---

## 9. `bin/quality`

See §10 of the chat transcript — pasted raw there in full.

---

## 10. What I did NOT do, and what I could not verify

- **No controller, route, Resource or UI.** 4c is domain-only; the operator screen is step 5.
  Consequence stated in §4: the new type cannot appear on the approvals queue yet.
- **No `open_key`-style "one open submission per school".** The other four request types have
  one; this table does not. Reasoning is in `SubmitOpeningBalanceBatch`'s docblock — G1 already
  permits at most one *posted* batch per school ever and G1b makes it irreversible, so a second
  submitted batch cannot become a second post; its approval fails at 1062, which `PROOF 3b`
  exercises. **This is the weakest argument in the change** and the thing I would attack first:
  it means two makers can each have a submission pending on one school and a checker sees no
  signal that approving one kills the other.
- **`route-access-map.json` staleness** (§6) — found, reported, not fixed.
- **The authority-matrix row 17 conflict** (§3) — found, reported, not resolved. Not mine to
  resolve.
- **Not driven in the running app.** There is no UI to drive: no route reaches these Actions.
  The dev database (`brookstone_portal_db`) was **not** migrated or seeded — everything here was
  derived on `portal_testing`. `rbac:sync` has not been run against any production-derived copy,
  and must be (the catalog diff will be `missing_rows` only, three of them, which is the safe
  case) before these grants exist anywhere but a fresh install.
- **No severity calls on my own work**, and nothing here is nominated as contentious on my own
  authority. §4, §6 and the `open_key` note in this section are the three places I would send a
  reviewer first.
