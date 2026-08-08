# Report — the Executive Director role, and stripping finance from Head of School

**Branch:** `feat/executive-director-role`, cut at `f299f40` (staging tip, promoted `cb71cb9` + your §6a edit).
**Brief:** `docs/handoff/executive-director-role-brief.md`

```
$ git rev-list --count $(git merge-base staging HEAD)..HEAD
2
```

**`bin/quality` is 12/13. Step 13 (`test-ratchet`) is RED on 6 arms, and I stopped rather than
fixing them.** They are not my tests and the fix is a decision about another change's contract —
§7 below, with three options and a recommendation. Everything else in the brief is done.

**One edit to the raw-output rule, declared once here.** `finance:check-staffing-readiness` prints
school display names in its first column. Under the ids-and-counts rule I have replaced that column
with `school#<id>` and marked it. That is the
only substitution in this report; every other block is verbatim.

---

## 1. `grantsMap()` and `ROLES`

`head_of_school` — all five finance grants deleted, replaced by the comment §6a asked for:

```php
                PermissionEnum::MANAGE_HEAD_OF_SCHOOL_COMMENTS->value,
                // NO FINANCE AT ALL — 2026-08-04, Brookstone: "The heads of school have never approved
                // any of the items listed — they initiate it for my approval" …
                //
                // `principal` KEEPS `finance.access` and that is deliberate, not a miss — answered
                // 2026-08-04: "The Principal role should be able to view finance." A secondary
                // Principal who also holds head_of_school therefore still sees the finance area.
                // `finance.access` alone is VIEW: no record, no generate, no approve.
                // Route access (C2)
```

`accounts_supervisor` — four checker sides deleted, down to two grants, with the `:362` "checker side
of the void instance" line gone and the consequence recorded:

```php
            'accounts_supervisor' => [
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value,
            ],
```

`executive_director` — new, nine grants, checker sides only, with the never-a-submit reason in the
block. Added to `ROLES` above the finance-seat block.

**`TWO_FACTOR_REQUIRED`: yes, ED belongs in it, and I put it there.** Derived, not taken from you:

- `EnsureTwoFactorEnrolled::requiresTwoFactor()` (`app/Http/Middleware/EnsureTwoFactorEnrolled.php:117-125`)
  matches the flag on a role held in **any** school or globally — team-agnostic, which is the right
  shape for a seat whose reach is assignment to every school.
- `config/rbac.php:32` — the master switch defaults **on in production**.
- `RbacSeeder.php:461-477` — the default applies at `firstOrCreate` and under `--fresh` only.
  Creation is the one cheap moment, exactly as you said.
- Cost to the intended holder is zero: the intended holder already holds `admin`, which is already flagged.

I also **corrected the constant's docblock, which was stale**: it said the finance roles were "not
seeded (step-0)". They have been in `ROLES` since the 2026-08-01 realignment, so their absence is a
decision, not a consequence. The new text says ED is deliberately the first finance seat to carry the
flag so nobody normalises the asymmetry away.

## 2. The migration

`database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php`.
House properties kept: target derived from `grantsMap()`, global rows only, school-scoped counted and
never written, fresh-install guard, diff-based revoke+give in one transaction (never
`syncPermissions`), idempotency short-circuit before any activity row, BEFORE/AFTER holder counts as
ids and counts, `down()` a deliberate no-op.

**The ED-may-not-exist branch: it ABORTS, and the reason is not stylistic.** `two_factor_required` is
applied only at role creation. A role row created by this migration would be created with the flag
**false**, and no later `rbac:sync` would ever correct it — silently stripping two-factor enrolment
from the one seat that can approve money leaving four different ways. Aborting costs an operator one
command; creating costs a permanent, invisible security downgrade. That asymmetry decided it.

**It bite-proved itself on the first run**, because the local copy was in exactly that state:

```
$ php artisan migrate     # ED role row does not exist yet — expecting the abort branch
 2026_08_06_100000_move_head_of_school_finance_to_executive_director  14.72ms FAIL

   RuntimeException

  move-hos-finance-to-ed ABORTED: the [executive_director] role row does not exist yet. It is new in RbacSeeder::ROLES, so run `php artisan rbac:sync` first and then re-migrate. This migration deliberately does NOT create the row: two_factor_required is applied only at role creation (RbacSeeder.php:461-477) and executive_director is in TWO_FACTOR_REQUIRED, so a row created here would carry the flag FALSE permanently.
```

And it aborted **before any write** — verified, not assumed:

```
head_of_school: finance.access, finance.discount-policy.change.approve, finance.discount-policy.change.reject, finance.fee-schedule.change.approve, finance.fee-schedule.change.reject
accounts_supervisor: finance.access, finance.credit-note.approve, finance.credit-note.reject, finance.fee-schedule.change.submit, finance.invoice.void-request.approve, finance.invoice.void-request.reject
principal: finance.access
executive_director row exists: NO
```

**It also carries a post-write duty-separation walk**, inside the transaction, filtered to
`enforcedPairs()`. `2026_08_05_100000` reasoned its way out of one because `finance.access` is in no
pair; this migration moves **checker sides of four enforced pairs**, so the walk is reachable in
principle and it is cheaper to run it than to argue it away.

Then `rbac:sync` created ED and the new-role exemption behaved exactly as your §3 said:

```
ED row: id=17
ED two_factor_required: true
ED grants (9):
 finance.access
 finance.credit-note.approve
 finance.credit-note.reject
 finance.discount-policy.change.approve
 finance.discount-policy.change.reject
 finance.fee-schedule.change.approve
 finance.fee-schedule.change.reject
 finance.invoice.void-request.approve
 finance.invoice.void-request.reject
ED holds any *.submit: NO
```

The migration then ran:

```
 2026_08_06_100000_move_head_of_school_finance_to_executive_director   move-hos-finance-to-ed: school-scoped role rows carrying finance.* (UNTOUCHED): 0
  move-hos-finance-to-ed [BEFORE] holders per school:
    school#1  finance.access  holders=9
    school#1  finance.fee-schedule.change.approve  holders=4
    school#1  finance.discount-policy.change.approve  holders=4
    school#1  finance.credit-note.approve  holders=1
    school#1  finance.invoice.void-request.approve  holders=1
    school#2  finance.access  holders=5
    school#2  finance.fee-schedule.change.approve  holders=1
    school#2  finance.discount-policy.change.approve  holders=1
    school#2  finance.credit-note.approve  holders=1
    school#2  finance.invoice.void-request.approve  holders=1
  move-hos-finance-to-ed [AFTER] holders per school:
    school#1  finance.access  holders=5
    school#1  finance.fee-schedule.change.approve  holders=0
    school#1  finance.discount-policy.change.approve  holders=0
    school#1  finance.credit-note.approve  holders=0
    school#1  finance.invoice.void-request.approve  holders=0
    school#2  finance.access  holders=5
    school#2  finance.fee-schedule.change.approve  holders=0
    school#2  finance.discount-policy.change.approve  holders=0
    school#2  finance.credit-note.approve  holders=0
    school#2  finance.invoice.void-request.approve  holders=0
 48s DONE
```

Every checker count is now **0 in both schools**. That is §5's predicted, correct outcome.

## 3. The convergence lint on a removal-only diff — two findings

Raw, on the map edit alone (before the migration existed):

```
grants-convergence-lint: 1 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (f299f40..3915229):

  ✗ finance.access  @  database/seeders/RbacSeeder.php:414
      role: accounts_supervisor (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::FINANCE_ACCESS->value,

  4 addition(s) in the same diff were EXEMPT:
  ✓ finance.fee-schedule.change.approve  @  database/seeders/RbacSeeder.php:394 — role [executive_director] is NEW in this diff (takes the full $permissions array)
  ✓ finance.fee-schedule.change.reject  @  database/seeders/RbacSeeder.php:395 — role [executive_director] is NEW in this diff (takes the full $permissions array)
  ✓ finance.discount-policy.change.approve  @  database/seeders/RbacSeeder.php:396 — role [executive_director] is NEW in this diff (takes the full $permissions array)
  ✓ finance.discount-policy.change.reject  @  database/seeders/RbacSeeder.php:397 — role [executive_director] is NEW in this diff (takes the full $permissions array)
```

**Finding A — the lint is structurally blind to removals.** It said nothing whatever about the nine
removals, which are the entire point of this change. It walks *added* lines in `grantsMap()` and has
no removal path at all. A removal-only convergence passes that gate with zero findings. Your §3 said
this would be a finding about the lint rather than a licence to skip the migration; it is, and I wrote
the migration anyway. `rbac:diff-grants` **does** have the mirror diagnosis
(`RbacDiffGrants.php:72`, `MAP_REMOVAL_GAP`) — so the capability exists in the reconciler and not in
the gate.

**Finding B — the one thing it did fire on is a false positive, because it diffs LINES not GRANTS.**
`accounts_supervisor` already held `finance.access`, in the map at merge-base and in the live
database:

```
=== does accounts_supervisor hold finance.access BEFORE this branch? (map at merge-base) ===
            'accounts_supervisor' => [
                PermissionEnum::FINANCE_ACCESS->value,
                …

=== and in the live DB right now (pre-migration) ===
accounts_supervisor holds finance.access: YES
```

Nothing about that pair drifted. The new `executive_director` block contains an identical
`PermissionEnum::FINANCE_ACCESS->value,` line, git pairs the old AS line with it, and AS's unchanged
grant renders as an addition.

**How I resolved it, and the honest caveat.** The migration governs `accounts_supervisor` and forces
its finance namespace to match `grantsMap()`, which includes `finance.access` — so
`@converges accounts_supervisor finance.access` is a **true statement about what the migration does**,
which is the only reason I wrote it rather than arguing with the gate. It is not evidence that AS lost
and regained the grant, and the migration's docblock says so at length. Green after:

```
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (f299f40..0243406; 5 exempted).
  · finance.fee-schedule.change.approve @ …:394 — exempt: role [executive_director] is NEW in this diff (takes the full $permissions array)
  · finance.fee-schedule.change.reject @ …:395 — exempt: role [executive_director] is NEW in this diff (takes the full $permissions array)
  · finance.discount-policy.change.approve @ …:396 — exempt: role [executive_director] is NEW in this diff (takes the full $permissions array)
  · finance.discount-policy.change.reject @ …:397 — exempt: role [executive_director] is NEW in this diff (takes the full $permissions array)
  · finance.access @ …:414 — exempt: migration [database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php] declares @converges accounts_supervisor finance.access
```

## 4. Pre-flights, BEFORE and AFTER

### a. `finance:check-staffing-readiness`

**BEFORE** — school column substituted per the note at the top; everything else verbatim:

```
| school#1 | result.approve                         | 68 | 6 | OK |
| school#1 | result.reject                          | 68 | 6 | OK |
| school#1 | finance.credit-note.approve            |  2 | 1 | OK |
| school#1 | finance.credit-note.reject             |  2 | 1 | OK |
| school#1 | finance.invoice.void-request.approve   |  2 | 1 | OK |
| school#1 | finance.invoice.void-request.reject    |  2 | 1 | OK |
| school#1 | finance.discount-policy.change.approve |  2 | 4 | OK |
| school#1 | finance.discount-policy.change.reject  |  2 | 4 | OK |
| school#1 | finance.fee-schedule.change.approve    |  3 | 4 | OK |
| school#1 | finance.fee-schedule.change.reject     |  3 | 4 | OK |
| school#2 | result.approve                         | 44 | 4 | OK |
| school#2 | result.reject                          | 44 | 4 | OK |
| school#2 | finance.credit-note.approve            |  1 | 1 | OK |
| school#2 | finance.credit-note.reject             |  1 | 1 | OK |
| school#2 | finance.invoice.void-request.approve   |  1 | 1 | OK |
| school#2 | finance.invoice.void-request.reject    |  1 | 1 | OK |
| school#2 | finance.discount-policy.change.approve |  1 | 1 | OK |
| school#2 | finance.discount-policy.change.reject  |  1 | 1 | OK |
| school#2 | finance.fee-schedule.change.approve    |  2 | 1 | OK |
| school#2 | finance.fee-schedule.change.reject     |  2 | 1 | OK |
Every school covers every maker-checker pair with two distinct users.
```

**AFTER:**

```
| school#1 | result.approve                         | 68 | 6 | OK  |
| school#1 | result.reject                          | 68 | 6 | OK  |
| school#1 | finance.credit-note.approve            |  2 | 0 | GAP |
| school#1 | finance.credit-note.reject             |  2 | 0 | GAP |
| school#1 | finance.invoice.void-request.approve   |  2 | 0 | GAP |
| school#1 | finance.invoice.void-request.reject    |  2 | 0 | GAP |
| school#1 | finance.discount-policy.change.approve |  2 | 0 | GAP |
| school#1 | finance.discount-policy.change.reject  |  2 | 0 | GAP |
| school#1 | finance.fee-schedule.change.approve    |  3 | 0 | GAP |
| school#1 | finance.fee-schedule.change.reject     |  3 | 0 | GAP |
| school#2 | result.approve                         | 44 | 4 | OK  |
| school#2 | result.reject                          | 44 | 4 | OK  |
| school#2 | finance.credit-note.approve            |  1 | 0 | GAP |
| school#2 | finance.credit-note.reject             |  1 | 0 | GAP |
| school#2 | finance.invoice.void-request.approve   |  1 | 0 | GAP |
| school#2 | finance.invoice.void-request.reject    |  1 | 0 | GAP |
| school#2 | finance.discount-policy.change.approve |  1 | 0 | GAP |
| school#2 | finance.discount-policy.change.reject  |  1 | 0 | GAP |
| school#2 | finance.fee-schedule.change.approve    |  2 | 0 | GAP |
| school#2 | finance.fee-schedule.change.reject     |  2 | 0 | GAP |
Staffing GAP: at least one school/pair lacks two distinct users to run the two-person flow. That module cannot approve there until staffed.
```

**16 gaps: 8 finance pairs × 2 schools, every one `#checkers = 0`.** Every credit note, invoice
cancellation, fee-schedule change and discount-policy change in both schools is unapprovable until
someone is assigned `executive_director` in **each** school. **I assigned nobody** — per §8. The
`result.*` pairs are untouched.

### b. Duty separation — `admin` proven clean

**BEFORE and AFTER are byte-identical:**

```
Auditing 10 maker-checker pair(s) for both-sides users (effective ability, per school)…
10 both-sides finding(s) — a user holding a checker AND its matching maker in one school:
| 1 | user#35   | result.approve | result.submit |
| 1 | user#35   | result.reject  | result.submit |
| 1 | user#4    | result.approve | result.submit |
| 1 | user#4    | result.reject  | result.submit |
| 1 | user#51   | result.approve | result.submit |
| 1 | user#51   | result.reject  | result.submit |
| 1 | user#6    | result.approve | result.submit |
| 1 | user#6    | result.reject  | result.submit |
| 2 | user#3199 | result.approve | result.submit |
| 2 | user#3199 | result.reject  | result.submit |
DETECTION ONLY — nothing was revoked.
```

**Zero finance findings, before and after.** Your §5b reading of `admin` holds, proven rather than
believed: its four finance grants (`finance.access`, `finance.invoice.generate`,
`finance.invoice.reduction.apply`, `finance.fee-schedule.manage`) are none of them a pair maker side,
so **ED + admin on one user is clean**. That matters because the intended ED holder already holds `admin`.

### c. Pending approvals — nothing is stranded

```
finance_credit_notes:
 (no rows)
finance_void_requests:
 (no rows)
finance_fee_schedule_changes:
 (no rows)
finance_discount_policy_changes:
 (no rows)
```

All four approval tables are empty on the local copy, so **no in-flight request changes clearing seat
underneath it**. (Note the table is `finance_void_requests`, not `finance_invoice_void_requests`.)

## 5. `rbac:diff-grants`, and the `principal` confirmation

```
rbac:diff-grants — RbacSeeder::grantsMap() vs the live grants
 env=local db=portaa10_portal guard=web
 scope: global role rows only (roles.school_id IS NULL)

SECTION A — permission catalog (enum vs `permissions` rows)
 clean — the enum and the permission rows agree.

SECTION B/C — grants per global role, with the diagnosis for each difference
 clean — every role in grantsMap() holds exactly its mapped grants.

FOOTER — school-scoped `web` role rows (school_id IS NOT NULL), counted, NEVER diffed: 0

TOTALS catalog: 0 missing row(s), 0 extra row(s) | grants: 0 missing, 0 extra across 0 role(s) | roles: 0 mapped-without-row, 0 unmapped
 CLEAN — grantsMap() and the live grants agree.
```

**No `MAP_REMOVAL_GAP` is reported, and that is the migration having worked** — the nine removals were
already applied when this ran. The diagnosis exists (`RbacDiffGrants.php:72`) and would have fired had
the migration not run; I did not stage that counterfactual because it would mean reverting grants on
the ground-truth copy.

**`principal` confirmed from the database, not the map** (§6a), alongside the full final state:

```
head_of_school        : (no finance grant)
accounts_supervisor   : finance.access, finance.fee-schedule.change.submit
executive_director    : finance.access, finance.credit-note.approve, finance.credit-note.reject, finance.discount-policy.change.approve, finance.discount-policy.change.reject, finance.fee-schedule.change.approve, finance.fee-schedule.change.reject, finance.invoice.void-request.approve, finance.invoice.void-request.reject
principal             : finance.access
admin                 : finance.access, finance.fee-schedule.manage, finance.invoice.generate, finance.invoice.reduction.apply
accounts_officer      : finance.access, finance.credit-note.submit, finance.discount-policy.change.submit, finance.fee-schedule.change.submit, finance.fee-schedule.manage, finance.invoice.generate, finance.invoice.reduction.apply, finance.invoice.void-request.submit, finance.payment.record
finance_lead          : finance.access, finance.credit-note.submit, finance.discount-policy.change.submit
```

## 6. The new arm — red before, green after

In `FinanceRoleRealignmentTest`. It pins the **seeded map**, not the migration: the migration converges
an already-seeded database, the arm asserts what a fresh seed produces, and both must agree or the
drift is back. The `no submit` half is asserted **separately** from the exact-list equality, because an
equality assertion silently stops being about submits the moment someone edits the expected array.

**Red**, with the `grantsMap()` edit reverted to merge-base:

```
=== GREEN (map edit in place) ===
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":20,"duration_ms":11424}
=== RED (grantsMap() edit reverted) ===
failed 3 passed / 2 failed / 0 errors
  RED: the_role_set_matches_the_realigned_seats_—_old_roles_gone__new_roles_p
        Failed asserting that a traversable contains 'executive_director'.
  RED: _HoS_holds_no_finance_at_all__AS_is_maker_viewer
        Expecting null not to be null 'global role [executive_direct…ed map'.
=== GREEN again (restored) ===
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":20,"duration_ms":11687}
```

My first version used `firstOrFail()` and went red as a `ModelNotFoundException` — a red that names
nothing. I changed it to assert the row first, so the revert produces a readable failure. Recording it
because the first version would have passed review and been worse.

## 7. STOPPED: 6 arms red, and the fix is not mine to choose

`bin/quality` step 13:

```
ratchet: 6 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php::it ARM 1 — converges the drift, leaves the four aligned governed roles byte-identical
  ✗ tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php::it ARM 2 — idempotent: a second up() changes no grant and writes no activity row
  ✗ tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php::it ARM 4 (bite-proof, runs first) — the planted drift is real: without the migration the roles genuinely lack the grant
  ✗ tests/Feature/Rbac/FinanceChangeGrantConvergenceTest.php::it ARM 1 — converges the drift, leaves principal/head_of_school untouched, closes the GAP
  ✗ tests/Feature/Rbac/FinanceChangeGrantConvergenceTest.php::it ARM 2 — idempotent: a second up() changes no grant and writes no activity row
  ✗ tests/Feature/Rbac/FinanceChangeGrantConvergenceTest.php::it ARM 4 — user-scoped pre-flight bites: a user holding accounts_supervisor + head_of_school aborts the convergence, then converges once resolved
```

**Two causes, and the second is the one that stopped me.**

**Cause 1 — the offender pre-flights now fire on ED, correctly.** Both prior migrations abort when a
global role outside their frozen governed set holds the governed permissions:

```
converge-finance-access-grants ABORTED: unexpected global role(s) grant [finance.access]: executive_director (holders=0). internal_auditor holding it is a DECIDED-but-UNIMPLEMENTED grant…
converge-finance-change-grants ABORTED: unexpected global role(s) grant the governed permissions: executive_director (holders=0). Investigate before widening this migration.
```

That is the pre-flight **working**: telling a human an unexpected role now holds the permission, and to
investigate before widening. I am that human and the investigation concludes ED is expected. Note this
is a test-only path in practice — both migrations are on `main`, so they never re-run in production, and
a new environment hits the fresh-install guard.

**Cause 2 — those migrations DERIVE their target from `grantsMap()`, which I changed.** Replaying
`2026_08_05_100000` today converges `head_of_school` to **empty**, because that is what the map now says.
Its ARM 4 asserts HoS *starts* holding `finance.access`; it no longer does. So the arms are not merely
seeing an extra role — their premise has moved.

**I tried the obvious fix and backed it out.** I patched both files to strip ED's grants before invoking
`up()` (reconstructing the 2026-08-05 substrate) and it did not work: reconstructing the substrate also
requires reconstructing the **map**, which would mean stubbing a static method. I reverted those patches
rather than leave a half-fix in the diff.

**Three options, and what I would do:**

1. **Edit the two shipped migrations** to allow ED in their offender checks. Rejected: their `$governed`
   is documented as frozen precisely so a migration does not re-shape itself when the map moves.
2. **Rewrite both arms against the current map** — assert what those migrations now do. Legitimate, but
   it is a substantial rewrite of another change's suite and it changes what those arms prove.
3. **Recommended: pin those arms against a frozen fixture map rather than the live `grantsMap()`.**
   The root cause is that a map-deriving migration's behaviour moves with the map, so its replay tests
   were always coupled to a moving target — this change is just the first time the target moved. That is
   a design fix worth its own brief, and it protects every future convergence migration, not this one.

Until then the gate is red and **this branch must not be pushed**.

## 8. §7 fixtures, oracles and docs

| File | What changed |
| --- | --- |
| `tests/fixtures/rbac-grants-baseline.json` | Regenerated from a fresh seed using byte-for-byte the expression `PermissionEnumTest` builds `$actual` with, so the fixture cannot drift from its own assertion. Diff is exactly the seat move: AS −4, HoS −5, ED +9. 15 roles. |
| `tests/fixtures/route-access-map.json` | `php artisan rbac:derive-access`, after `rbac:sync` and `migrate`. **Removals audited**: the only deletions are `head_of_school` (15) and `accounts_supervisor` (7) — no unrelated regression regenerated away. Additions include ED plus the notification routes that landed with the rebase. |
| `DutySeparationEnforcementTest` | Header docblock + 7 role references: the CHECKER seat is ED. Stated why — AS now holds only maker-or-viewer grants, so AS + AO would no longer throw and the arms would pass for the wrong reason. |
| `MakerCheckerSeparationTest` | `plantBothSidesFinanceUser` plants ED, not AS. |
| `FeeScheduleChangeTest`, `DiscountPolicyTest` | `fscChecker` / `dpChecker` grant ED. **Neither was in your §7 list** — 14 of the finance failures were theirs. |
| `ApprovalsPageGateTest` | Full-checker arm grants ED. |
| `DriveCastSeeder` + `SeedDriveFixture` | `checker@drive.test` holds ED, so the drive can actually approve. |
| `docs/rbac/finance-seat-realignment.md` | SUPERSEDED-IN-PART banner. Body left intact as the dated 2026-08-01 record. |
| `docs/finance/segregation-of-duties.md` | Dated seat note at the top; the example naming AS left as written. |
| `app/Enums/Permission.php` | **No change needed** — grepped it; the only `head_of_school` reference is `MANAGE_HEAD_OF_SCHOOL_COMMENTS`, and no docblock names a checker seat. §7's expectation was wrong here. |
| `tests/fixtures/route-middleware-baseline.json` | Unchanged, as predicted. |

**`FinanceApiAcceptanceTest` needed no change and did not go red** — your prediction held. All 248
`tests/Feature/Finance` tests pass.

`docs/Finance Module — Implementation Master Plan - v10.md` — **not touched**, and I am flagging rather
than silently skipping: it is a 160KB planning document and the seat move touches its authority matrix
in several places. That is a documentation pass of its own, not a rider here.

## 9. Things in the brief I think are wrong, and other findings

- **§6b, confirmed and worth repeating loudly:** "sees every school" is assignment. There is no combined
  all-schools approvals view. After this ships, ED must be assigned in **every** school separately or that
  school's finance approvals are dead — the 16 GAPs above are exactly that, today.
- **§5's table names**: it is `finance_void_requests`, not `finance_invoice_void_requests`.
- **§7's list was incomplete** (FeeScheduleChangeTest, DiscountPolicyTest) and wrong about
  `app/Enums/Permission.php`. Neither cost anything; recording so the next §7 list is derived by running
  the suite rather than by memory.
- **The convergence lint's blindness to removals (§3, Finding A) deserves its own brief.** This change is
  the first removal-only convergence and it sailed through the gate. The reconciler already has the
  diagnosis (`MAP_REMOVAL_GAP`); the gate does not. That is the same shape as every other hole this arc has
  closed — the capability exists, the enforcement does not.
- **Not done, deliberately:** no user assigned to any role (§8); rows 14/17/18/19 not built; nothing near
  `ISOLATION_CROSSING` or the `Gate::before`.
- **Not verified:** I did not drive the app UI. The migration and the grant state are proven from the
  database and the suite, not from a browser.

---

# Addendum — FIX 2, 3 and 4 (post-review round)

Reports only what changed since `c176586`. The §2–§5 evidence set above is unchanged and not re-run.

History restructured as instructed: the two identically-subjected `feat(rbac)` commits (`3915229`,
`0243406`) are squashed into one; the report commit stays separate. **Not pushed** — the convergence
fix lands first and this branch rebases onto it.

## FIX 3 — ED is assignable, super-admin only

`SyncUserRolesRequest::withValidator` now appends `executive_director` on the same footing as
`admin`, and `SchoolRbacOverview::assignableRoles` mirrors it so the UI offers exactly what the write
accepts. **Not added to `SCHOOL_ROLES`**, for the reason you gave: that list is gated on
`rbac.manage_users`, which a school admin holds, so listing ED there would let a school admin grant
themselves the top financial approver.

**`DutySeparation::assertAssignmentAllowed` DOES run on this path — traced, not assumed.**
`SchoolUserController::syncRoles` calls spatie's `HasRoles::syncRoles`, which ends in
`return $this->assignRole($roles);` (`vendor/spatie/laravel-permission/src/Traits/HasRoles.php:313`),
and `assignRole` is overridden at `app/Models/User.php:412` where the guard fires. So widening this
allowlist widens **who may assign**, never **which combinations are legal**. Nothing to wire; I did
not touch the guard.

Two arms in `SchoolUserModuleTest`, both red under the revert (ED append removed from both places):

```
failed 12 passed / 2 failed / 0 errors
  RED: D2_—__executive__director__is_refused_to_an_rbac_manage__users
        Failed asserting that false is true.
  RED: offers__executive__director__in_assignableRoles_only_to_a_supe
        Property [assignableRoles] was marked as invalid using a closure. Failed asserting that false is true.
```

The refusal arm asserts up front that `$this->admin` really does hold `rbac.manage_users` (under the
school's team context), so it cannot pass because the actor happened to lack the permission.

## FIX 4 — `two_factor_required` pinned

Two arms in `TwoFactorEnrollmentTest`. The existing seeded-defaults arm gains
`executive_director` = true and the four operational finance seats = false, named individually so the
asymmetry is visible in one place; its title lost the now-false "(Finance roles held for I6)". A
second arm asserts the **constant** directly, because the row arm alone is not sufficient: the flag
applies only at creation, so on any environment where the ED row already exists, deleting the
constant entry changes nothing observable.

Red under deleting `'executive_director'` from `RbacSeeder::TWO_FACTOR_REQUIRED`:

```
failed 9 passed / 2 failed / 0 errors
  RED: seeds_super__admin__admin_and_executive__director_as_2FA_requi
        Failed asserting that false is true.
  RED: pins_executive__director_in_RbacSeeder__TWO__FACTOR__REQUIRED_
        Failed asserting that an array contains 'executive_director'.
```

## FIX 2 — `MoveHosFinanceToEdConvergenceTest`, eight arms

| Arm | Property | Mutation that turns it red | Result |
| --- | --- | --- | --- |
| 0 | the planted pre-move state is real (a fresh seed writes the POST-move map, so without the plant every arm below passes vacuously) | — bite-proof for the rest | n/a |
| 1 | converges: HoS → none, AS → two, ED → nine, no `*.submit`, `principal` unchanged | delete the revoke loop | **RED** |
| 2 | idempotent: second `up()` moves no grant, no `activity_log` row, `MAX(id)` unmoved | see below | **RED** (double) |
| 3 | the audit choice: revoke+give emits **9** `permission_detached` events | replace with `syncPermissions` | **RED** `0 is identical to 9` |
| 4 | school-scoped rows untouched, byte-identical | drop `whereNull('school_id')` from the role lookup | **RED** |
| 5 | ED row missing ⇒ aborts naming `rbac:sync` **and writes nothing** | create-if-missing instead of throwing | **RED** |
| 6 | fresh install ⇒ quiet green, not a throw | disable the substrate guard | **RED** |
| 7 | duty-separation finding rolls the whole transaction back | remove the throw | **RED** |

**Two arms did not bite on the first attempt, and both are worth recording.**

**ARM 3 was wallpaper as first written.** It asserted `$written > 0` plus a substring search for
"detach" in `description`, over a window computed from a query I got wrong. Under the
`syncPermissions` mutant it stayed **green**. I probed the listener directly rather than adjust the
assertion by guesswork:

```
rows written: 10
 event=permission_detached  desc=permission_detached: finance.access
 …(9 detached)…
 event=permission_attached  desc=permission_attached: finance.access, finance.credit-note.approve, …
```

and under the mutant:

```
rows written: 3
 event=permission_attached  (×3, ZERO detached)
```

That confirms the CLAUDE.md claim empirically — `HasPermissions::syncPermissions` does
`$this->permissions()->detach()` (`vendor/…/HasPermissions.php:446`), raw, no event — and the nine
removals would have vanished from the audit trail. The arm now asserts an **exact count of 9 on the
`event` column**, which is unambiguous and fails on the right thing.

**ARM 2 cannot be killed by any single mutation, and that is a fact about the migration.**
Idempotency is guaranteed twice over: disabling the `$needsWork` short-circuit alone leaves it green
(the diff is empty on a second run), and making the writes unconditional alone leaves it green (the
short-circuit returns first). Only killing **both** produces the regression:

```
ARM2 -> RED    Failed asserting that 41 is identical to 28.
```

Belt and braces rather than a weak arm — but the arm's comment now says so, because if someone
removes one of the two mechanisms this arm will no longer notice.

## Gate

`bin/quality` 12/13. The only red is `test-ratchet`, on **the same 6 arms as before** — the two
shipped convergence migrations you are briefing separately. Every arm added in this round is green,
and nothing in the previously-passing suite regressed.

## Ticket, recorded not worked

`finance:check-staffing-readiness` returns `FAILURE` with 16 GAPs and **no `bin/quality` step runs
it**. The detector exists; the enforcement does not — the same shape as the convergence lint's
blindness to removals, and now the same shape twice in one branch.

---

# Addendum 2 — rebased onto the target freeze; four debt items worked

`feat/executive-director-role` rebased onto `staging` @ `8e8a92d` (the `--no-ff` merge of
`fix/convergence-migration-target-freeze`). **`bin/quality` 13/13.** Neither branch pushed.

```
$ git rev-list --count $(git merge-base staging HEAD)..HEAD
2
```

**The new gate did its job on arrival.** Before any edit, on the freshly rebased branch:

```
RED    0p/1r
    these migrations read the seeder grants map at run time; freeze their target as a literal instead — see ADR 0052
```

That is the mechanism working as designed: ED could not go green until `2026_08_06` was frozen too,
and nobody had to remember to do it.

## Debt 1 — `2026_08_06` frozen; both aborts KEPT

Target frozen as a `private const TARGET` of plain strings — role set and grants together, which is
the split its four predecessors got wrong. `head_of_school => []`,
`accounts_supervisor => ['finance.access', 'finance.fee-schedule.change.submit']`,
`executive_director =>` its nine. `grantsMap` hits in the file: **0**. The docblock line that
advertised *"target DERIVED from `grantsMap()` and never hardcoded"* as a virtue is gone.

**Its two aborts do not convert**, and the reasoning is now written into the file rather than left to
the next reader's judgement:

```
 * WHY THIS FILE'S ABORTS SURVIVED THE CONVERSION ITS FOUR PREDECESSORS DID NOT (ADR 0052's two-part
 * test). … the difference is not seniority — it is the shape of the act:
 *
 *   1. WOULD CONTINUING LEAVE A HOLE THIS MIGRATION'S OWN WRITES DUG? YES. Its predecessors each
 *      converge ONE role toward ITS OWN frozen slice… This one is a TRANSFER… Half-applying it —
 *      the strip running, the grant skipped — leaves the fee-schedule and discount-policy
 *      approve/reject sides and both credit-note/void checker pairs held by NOBODY. With the
 *      `Gate::before` maker-checker exclusion (ADR 0040), no seat on the platform, `super_admin`
 *      included, could then approve anything financial.
 *   2. DOES THE MESSAGE NAME A COMMAND THAT CLEARS IT AND LETS THE MIGRATION PASS? YES, both times:
 *      `php artisan rbac:sync`.
```

Three throws survive in the file: the missing-ED-row abort, the missing-target-permission abort, and
the duty-separation walk's rollback.

## Debt 2 — the convergence arms

The rebase left **four** red, not the six the brief predicted — two of them mine from debt 3.

| arm | why it was red | what it says now |
| --- | --- | --- |
| `FinanceChangeGrantConvergenceTest` ARM 1 | asserted principal/HoS came out *"untouched"* — a comparison against whatever the live map had put there, false once the seat move emptied HoS's slice | asserts **every** governed role's namespace slice equals the frozen literal, written out in the test as literals. Strictly stronger and map-independent |
| `FinanceAccessGrantConvergenceTest` ARM 4 | asserted a fresh seed leaves HoS holding `finance.access`. It does not and will not again | proves **both drift shapes at once**: `principal` still holds it after a seed so the drift is PLANTED; `head_of_school` does not, so it is a LIVE divergence between the frozen 2026-08-03 target and today's map, needing no plant. ARM 1 converges both |

## Debt 3 — my own map coupling, removed

`RealignFinanceGovernanceGrantsTest` ARM 0 asserted the fresh seed's **absolute** content. That is a
live-map read wearing a test's clothes, in the test file written to prove live-map reads were removed
— and it went red on a migration whose behaviour had not changed at all. Rewritten the way
bite-proofing actually needs: capture the slice, plant, assert it **changed** and now equals the
planted shape. Survives every future map edit.

ARM 1 carried the same coupling in a second place (`expect(HoS)->hasPermissionTo('finance.access')`).
Now a before/after comparison of the grants **outside** the governed namespaces, via a new
`rfgOtherFinanceGrants()` helper — "this migration moved nothing it does not govern" without asserting
what the map happens to say today.

## Debt 4 — the ED hazard, and the census you asked for

Reviewer 4's hazard is recorded and **not fixed**, per your ticket: `2026_08_03`'s surviving walk
filters to `enforcedPairs()` but never to what that migration wrote, so after this rebase a user
holding `executive_director` plus any `*.change.submit` maker role is a violation the 2026-08-02
migration did not create and would roll back for. It cannot bite today — no user holds ED, and
`assertAssignmentAllowed` refuses the pairing at assignment.

**On your standing instruction — I found more than three, and I have frozen nothing.** Full census,
every `DutySeparation` consumer outside the class itself:

```
$ grep -rn "DutySeparation::violations\|DutySeparation::enforcedPairs\|DutySeparation::pairs\|::violationsFromRolePermissionSync\|::assertAssignmentAllowed" app database routes bin | grep -v "^app/Support/DutySeparation.php"
app/Models/User.php:412:            DutySeparation::assertAssignmentAllowed($this, (int) $teamId, $roles);
app/Support/SchoolRbacOverview.php:95:            'sodPairs' => DutySeparation::pairs(),
app/Support/SchoolRbacOverview.php:308:        foreach (DutySeparation::pairs() as $pair) {
app/Support/RbacOverview.php:67:            'sodPairs' => DutySeparation::pairs(),
app/Support/RbacOverview.php:207:        foreach (DutySeparation::pairs() as $pair) {
app/Http/Requests/SyncRolePermissionsRequest.php:112:            foreach (DutySeparation::violationsFromRolePermissionSync($roleName, $requested) as $violation) {
app/Console/Commands/CheckStaffingReadiness.php:37:        $pairs = DutySeparation::pairs();
app/Console/Commands/AuditDutySeparation.php:34:        $pairs = DutySeparation::pairs();
app/Console/Commands/AuditDutySeparation.php:52:            foreach (DutySeparation::violations($user, (int) $row->school_id) as $pair) {
database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php:269:            $enforced = collect(DutySeparation::enforcedPairs())
database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php:280:                    foreach (DutySeparation::violations($user, (int) $school->id) as $pair) {
database/migrations/2026_08_03_100000_converge_finance_change_grants.php:255:            $enforced = collect(DutySeparation::enforcedPairs());
database/migrations/2026_08_03_100000_converge_finance_change_grants.php:262:                    foreach (DutySeparation::violations($user, (int) $school->id) as $pair) {
```

**Nine call sites, not three** — and `AuditDutySeparation` calls `violations()` live, which your list
did not name.

**But the census answers your open question rather than widening it, and I think it decides it.** Sort
those nine by *what kind of thing is doing the calling*:

- **Runtime guards and readers** — `User::assignRole`, `SyncRolePermissionsRequest`, both RBAC
  overviews, `CheckStaffingReadiness`, `AuditDutySeparation`. Seven of the nine. Every one is a
  question asked *now* about the state *now*: may this assignment proceed, is this school staffed,
  who currently holds both sides. **A live answer is the only correct answer for all of them.**
  Freezing any would be the defect in reverse.
- **Dated acts** — `2026_08_03:255,262` and `2026_08_06:269,280`. Exactly two, and both are the same
  post-write walk in the same shape.

So the boundary that predicts the set is not *which primitive is called* but **whether the caller is a
dated act or a runtime question**. That is the same boundary ADR 0052 already draws for the grants
map, and it says the same thing about duty separation: a migration must not read live authority state
to decide whether to abort; everything else must.

Which leaves the real question narrower than "are duty-separation guards correctly live". It is: **the
two migration walks read live pair definitions AND live user-role assignments to decide whether to
roll back a dated act.** `2026_08_03`'s is the one reviewer 4 found overstated. `2026_08_06`'s has the
same shape and I did not touch it either.

I have frozen nothing and am not proposing wording. Two shapes a decision could take, so the choice is
concrete:

1. **Scope each walk to what its own run wrote** — keeps the abort, removes the reach-forward, and
   makes ADR `:56-58` true as written. Behaviour change; needs its own arms.
2. **Accept both walks as deliberately broader** and say so in the ADR — they are the last line before
   a both-sides state reaches production, and firing on a violation the migration did not create is a
   false positive that costs a rollback, not a hole.

I have no strong preference and deliberately did not act. Your call, once.

## Not done

- Neither branch pushed. `staging` is 6 commits ahead of `origin/staging`.
- Nothing driven against the dev database on this pass; the four migrations already applied there, and
  every arm ran against `portal_testing`.
- `finance:check-staffing-readiness` still FAILURE with 16 GAPs, correct and expected.

---

# Addendum 3 — both walks scoped to what their own run wrote

`bin/quality` 13/13. One commit on this branch; the ADR change rides with it because the taxonomy it
records is the reasoning for the code.

## The narrowing

`2026_08_03` and `2026_08_06` now accumulate `$grantedThisRun` — the permissions the diff actually
granted on this run, not the frozen target and not the revokes — and the walk flags a pair only when
at least one of its sides is in that set. The population is unchanged: every user, every school. What
narrowed is which findings roll the migration back.

Out-of-scope findings are echoed as `user#<id> @ school#<id>`, counted, and the count is repeated in
the `AFTER` report line. The echo names their owner:

```
  converge-finance-change-grants REPORT: 1 both-sides finding(s) this run did NOT create — not blocked on:
    user#<id> @ school#<id> finance.credit-note.submit<>finance.credit-note.approve
    These are real and they matter. They belong to `php artisan finance:audit-duty-separation`, not to a migration.
```

For `2026_08_06` the scope is the granted side of the transfer only, exactly as the file's own comment
already said about revokes. Inside scope, both throws and both rollbacks are untouched.
`2026_08_03:61-76`'s argument is unchanged — the narrowing made none of it false.

## Arm 1 — `FinanceChangeGrantConvergenceTest` ARM 4, green and unchanged

```
{"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":...}
```

Its planted user holds `accounts_supervisor` + `head_of_school`, and the violation is created **by
this run's own grant** of the fee-schedule maker — squarely in scope, so it still throws and still
rolls back. Not edited, not adjusted.

## Arm 2 — one new arm per walk, mutation-checked

**`FinanceChangeGrantConvergenceTest` ARM 7.** A user in a both-sides state on the CREDIT-NOTE pair —
neither side is one of the three `*.change.submit` this run grants.

**One thing I had to correct mid-build, and it is the interesting part.** My first plant gave the user
`accounts_officer`, which holds *both* granted submits — so the user was in scope through a second
pair and the arm errored with the throw. That would have been a false red suggesting the filter was
wrong; the filter was right and the fixture was. Replaced with a bespoke role holding **only**
`finance.credit-note.submit`, which is the pair this migration touches neither side of. Neither
`accounts_officer` nor `finance_lead` can serve — both hold a `*.change.submit` this run grants.

**`MoveHosFinanceToEdConvergenceTest` ARM 8.** The cleanest possible statement of the rule: ED and AS
are put at their frozen target first, so the run grants **nothing** and only strips `head_of_school`.
Every finding is then out of scope by construction, which demonstrates the revokes-cannot-create rule
directly rather than by argument.

Both assert the migration **committed** — the grants landed / HoS was stripped — because a rollback is
the regression they exist to catch, not just a missing message.

**Mutation-checked**, `$thisRunWroteASide = true` (scope widened back to all enforced pairs):

```
=== GREEN (scoped) ===
{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":16}
=== RED expected (scope widened back) ===
RED    1p/2r
    ARM_7… :: converge-finance-change-grants ABORTED (rolled back): 2 user(s) would hold both sides of a finance maker-check
    ARM_8… :: move-hos-finance-to-ed ABORTED and ROLLED BACK: the new seat assignment would leave 8 user(s) holding both sid
=== restored ===
{"tool":"pest","result":"passed","tests":16,"passed":16,"assertions":73}
```

## ADR 0052 — the taxonomy

New section, *"The same boundary, applied to `DutySeparation`"*, recording all three populations —
RUNTIME (7, leave live), DATED ACTS THAT REPORT (4 `holdsViaGrant` calls in the `report()` methods,
leave live), DATED ACTS THAT DECIDE (2, scoped) — so the next call site does not re-litigate it. It
states that this answers part 1 of the two-part test rather than amending it: a violation the run did
not create is not a hole the run's own writes dug.

The attribution line is in the ADR as you asked: the finding is recorded as the implementing agent's,
after the freeze shipped, with the note that the advisor specified the preservation of that abort and
never looked inside it.
