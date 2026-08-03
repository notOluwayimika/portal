# Brief — move credit-note and invoice-void approval from `accounts_supervisor` to `head_of_school`

Business decision, 3 August 2026: **the Head of School approves a credit note and an invoice
cancellation.** Recorded in `docs/finance/authority-matrix-decisions-2026-08-03.md` §4, which was
written against the open question this brief closes. Matrix rows 15 and 16, HoS = `A`.

Today `accounts_supervisor` holds all four checker grants and `head_of_school` holds none. Both
workflows are live, so this is not a design input — it moves authority on running code.

**Sequencing: merge `fix/converges-marker` FIRST.** This branch adds permissions to a pre-existing
role and therefore lands on exemption 3 of `bin/ci-grants-convergence-lint.php`. Do not start it on
top of an unmerged lint change.

One branch, one commit. Verified against the repo, not against any report.

---

## 1. The grant move

Four permissions, `accounts_supervisor` → `head_of_school`:

```
finance.credit-note.approve
finance.credit-note.reject
finance.invoice.void-request.approve
finance.invoice.void-request.reject
```

### `database/seeders/RbacSeeder.php`

Remove all four from `'accounts_supervisor'`. That block then reads `FINANCE_ACCESS` +
`FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT` only. Replace its comment — the current one says "checker side
of the void instance", which becomes false in the same commit:

> Maker-only as of 2026-08-03. The credit-note and invoice-void checker sides moved to
> `head_of_school` (Brookstone matrix rows 15 and 16, HoS=A). AS proposes the fee-schedule change
> (row 2, AS=P) and approves nothing that is built today; rows 14 and 19 (receipt reversal, posted-
> transaction correction) give it a checker side again when those are built.

Add all four to `'head_of_school'`, beside the four change-approve grants already there. Extend that
block's existing comment rather than adding a second one — it currently enumerates the two pairs HoS
approves and will be wrong by omission.

**Check before you write it:** HoS must hold NEITHER `finance.credit-note.submit` NOR
`finance.invoice.void-request.submit`. It holds neither today. If that changes, stop —
`DutySeparation::assertRoleSetAllowed()` throws on a both-sides role and `bin/quality` will catch
it, but a both-sides map is a design error, not a test failure.

### The migration

`rbac:sync` revokes nothing and grants only permissions created in the same run, so **neither half of
this move happens on any seeded environment without a migration.** Copy the shape of
`database/migrations/2026_08_02_100000_realign_finance_governance_grants.php` exactly — it is the
house pattern for this and it is good. New file:

`2026_08_06_100000_move_credit_note_and_void_approval_to_head_of_school.php`

Keep all of its properties, and do not invent a lighter version:

- namespaces `finance.credit-note.` and `finance.invoice.void-request.`; governed roles
  `accounts_supervisor` and `head_of_school`
- target grants **derived from `RbacSeeder::grantsMap()`**, sliced to those namespaces — never a
  second hardcoded list
- global rows only (`school_id IS NULL`); school-scoped rows counted and reported, never written
- fresh-install guard keyed on the permission substrate, returning a no-op
- diff-based `revokePermissionTo` / `givePermissionTo` inside one transaction — **never
  `syncPermissions`**, whose raw detach fires no event and is invisible to `LogRbacChange`
- idempotency check so a second run writes no activity rows
- `down()` a deliberate no-op with the reason in the docblock
- BEFORE/AFTER holder counts per school, ids and counts only

Adapt the pre-flights to this move rather than copying them blindly. Pre-flight 1's `$allowed` list
must be the roles that may legitimately hold anything in these two namespaces:
`admin`, `accounts_officer`, `accounts_supervisor`, `head_of_school`, `finance_lead`. Verify that
list against `grantsMap()` before you write it — do not trust mine.

Declare the exemption-3 markers in this migration, one per line, nothing else on the line:

```
 * @converges head_of_school finance.credit-note.approve
 * @converges head_of_school finance.credit-note.reject
 * @converges head_of_school finance.invoice.void-request.approve
 * @converges head_of_school finance.invoice.void-request.reject
```

Only the ADD side needs a marker. The removals from `accounts_supervisor` are what stops
`rbac:diff-grants` reporting `MAP_REMOVAL_GAP` — confirm that it does, in your report.

## 2. Two pre-flights that are about people, not code

Both are runnable on the local copy. Run each BEFORE and AFTER and paste all four outputs.

**a. `php artisan finance:check-staffing-readiness`.** It derives
pairs from `DutySeparation::pairs()` and demands, per school, a maker and a checker who are
*distinct users*. After this move the checker for both pairs is whoever holds `head_of_school`. **A
school with no `head_of_school` user goes from OK to GAP and the command exits FAILURE** — every
credit note and invoice cancellation in that school becomes unapprovable. If it goes red, do not
work around it: report it, with school ids and counts only, and stop.

**b. Users who would become duty-separation violations.** `head_of_school` becomes a checker on two
new pairs, so any user holding `head_of_school` together with `accounts_officer` or `finance_lead`
in the same school is now a both-sides holder. That user is not refused retroactively, but the next
edit to their roles throws and `finance:audit-duty-separation` will report them. Count them per
school before you migrate. If the count is non-zero, report and stop — it is a business decision who
loses which hat, not yours.

Also count credit notes and invoice-void requests currently sitting in `pending_approval`, per
school. Those were raised expecting an Accounts Supervisor to clear them. Counts only.

## 3. Oracles, fixtures and tests

These carry the old seat and will go red. Update them because the seat moved, not to make them pass:

- `tests/fixtures/rbac-grants-baseline.json` — the `accounts_supervisor` block
- `tests/fixtures/route-access-map.json`
- `tests/Feature/Rbac/DutySeparationEnforcementTest.php:25-28` — the docblock names
  `accounts_supervisor` as CHECKER
- `tests/Feature/Rbac/MakerCheckerSeparationTest.php`
- `tests/Feature/Rbac/FinanceRoleRealignmentTest.php`
- `tests/Feature/Finance/ApprovalsPageGateTest.php`
- `app/Console/Commands/SeedDriveFixture.php` — the drive fixture must staff a HoS who can approve

`tests/Feature/Finance/FinanceApiAcceptanceTest.php` builds ad-hoc roles from permission sets and is
seat-agnostic. It should need **no** change. If it goes red, say so and stop — that means something
seat-shaped leaked into it and I want to see it before it is edited.

`tests/fixtures/route-middleware-baseline.json` gates on permissions, not seats. Unchanged.

**New arm.** In `FinanceRoleRealignmentTest` (or wherever the seeded-map assertions live): assert
`head_of_school` holds the four checker grants, `accounts_supervisor` holds none of them, and
`head_of_school` holds neither matching submit. Prove it red first by reverting the map edit.

## 4. Docs carrying the old seat

Correct these in the same commit. A stale rationale is worse than none, because the next author
reasons from it.

- `docs/finance/segregation-of-duties.md`
- `docs/rbac/finance-seat-realignment.md` — the 2026-08-01 realignment narrative predates this
- `docs/Finance Module — Implementation Master Plan - v10.md`
- `app/Enums/Permission.php` — the credit-note maker-checker docblock (`:93` area) and the invoice-
  void pair note describe who checks

`docs/finance/authority-matrix-decisions-2026-08-03.md` is already updated. Do not touch it.

## 5. Do NOT do on this branch

- Do not build rows 14, 17, 18 or 19. Receipt reversal, opening balance, payment transfer and
  posted-transaction correction do not exist and are not in scope here.
- Do not touch discount-policy eligibility / guest lists. Being sized separately.
- Do not reassign any user's roles. This branch moves grants between roles, never roles between
  people.
- Do not add a `down()` that restores the old grants.

## 6. Report

`bin/quality` 13/13 before reporting. Report as:

- the `grantsMap()` diff for both roles
- the migration in full
- staffing readiness BEFORE and AFTER, both schools, and the duty-separation and pending-approval
  counts from §2
- `rbac:diff-grants` output after migrating — specifically that no `MAP_REMOVAL_GAP` is reported for
  the four removed grants
- the new arm red-before / green-after, proven by reverting the map edit — actually revert, do not
  reason about it
- the convergence lint result, and confirmation that the four `@converges` markers appear in the
  exemption block rather than in `$unparsedMarkers`
- anything here you think is wrong. Specifically: whether pre-flight 1's `$allowed` list is right,
  and whether moving the checker seat while `accounts_supervisor` keeps
  `finance.fee-schedule.change.submit` leaves that role coherent.
