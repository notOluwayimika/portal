# Brief — the Executive Director role, and stripping finance from Head of School

Business decision, 4 August 2026. Supersedes the approver column of
`docs/finance/authority-matrix-decisions-2026-08-03.md` (banner-marked SUPERSEDED) and replaces
`docs/handoff/credit-note-approver-move-brief.md` (banner-marked HALTED — do not implement it).

**Sequencing: this is NOT next.** Finish `docs/handoff/staging-integration-decision.md` steps 1–6
first (commit docs → `composer install` → rebase → `bin/quality` → push → `quality-promote`). This
brief lands on a clean, promoted `staging` or not at all.

Verified against the repo at the current tip, not against any report. Every line number below I read
myself; where I have not run something I say so.

---

## 0. The decision

Brookstone, 4 August:

> *"The executive director approves scholarships and discounts, concessions, refunds, write offs and
> other high impact financial decisions. The finance lead and supervisors cannot process refunds,
> write-offs, invoice cancelations, opening balance adjustments without approval from the ED. The
> executive director also has access across schools. The heads of school have never approved any of
> the items listed — they initiate it for my approval."*

Then, confirmed the same day: **"nothing changed except switching every permission and ability held
by HoS to ED. HoS doesn't have access to finance."**

Four answers taken on the open points:

| # | Question | Answer |
|---|---|---|
| 1 | What does "access across schools" mean | ED **sees every school and approves in every school by being assigned to each one**. Not a new cross-school passage. |
| 2 | Fee-schedule (row 2) and discount-policy (row 20) approvals | **ED takes both. HoS approves nothing anywhere** — and holds no finance access at all. |
| 3 | Rows 14 and 19 (reverse a receipt, correct a posted transaction) | **ED.** Neither row exists in code, so this is a design input only. |
| 4 | Is ED one person | **One person — Segun.** Built as a role, assigned to one user, assignable to more later at zero cost. |

**Answer 1 is the important one and it is the cheap one.** A role that acts across schools without
being assigned to them has no precedent below `super_admin`: `Permission::ISOLATION_CROSSING`
(`app/Enums/Permission.php:195-197`) is a pinned forbidden set, and the only passage through the
school boundary is the `Gate::before` at `app/Providers/AppServiceProvider.php:89`, documented at
`:72` as existing for *"the team-less `super_admin` role"*. **We are not touching any of that.** ED
gets its reach by being assigned to every school, which is a user-assignment fact, not an
architecture change. Nothing in this brief goes near the isolation boundary.

**One tension on the record, not blocking.** Answer 3 gives rows 14 and 19 to ED; the *"nothing
changed except HoS → ED"* sentence would leave them with the Accounts Supervisor, where the 3 August
decision put them. Neither row exists in code — no permission case, no table — so nothing is built
either way and nothing needs deciding today. I have written ED. One line from Segun flips it.

---

## 1. What actually moves

Head of School's finance block today, verified at `database/seeders/RbacSeeder.php:216-232`, is
exactly five grants:

```
finance.access
finance.fee-schedule.change.approve
finance.fee-schedule.change.reject
finance.discount-policy.change.approve
finance.discount-policy.change.reject
```

All five move to `executive_director`. HoS keeps everything non-finance — `guardianFull`,
`studentSubjectFull`, `enrollmentAdmin`, `assessments`, `activityAdmin`, `resultChecker`,
`MANAGE_HEAD_OF_SCHOOL_COMMENTS`, and the C2 route-access tail. Touch none of those.

**Plus the credit-note pair, re-pointed.** The 3 August matrix gave rows 15 and 16 (cancel an
invoice, issue a credit note) to HoS; running code gives them to `accounts_supervisor`
(`:358-368`). With the HoS column now reading ED, those four go to **ED, not HoS** — which is the
whole reason `credit-note-approver-move-brief.md` is halted rather than edited:

```
finance.credit-note.approve
finance.credit-note.reject
finance.invoice.void-request.approve
finance.invoice.void-request.reject
```

`accounts_supervisor` is then left holding `finance.access` + `finance.fee-schedule.change.submit`
only. Say so in its comment, replacing the *"checker side of the void instance"* line at `:362`
which becomes false in the same commit. **Record without re-litigating: the Accounts Supervisor now
approves nothing that is built.** Rows 14 and 19 were its checker side and answer 3 took them too.
That is Brookstone's call to make and they have made it; the consequence is that AS is a maker-and-
viewer seat, and if that reads wrong to them it is a business correction, not a code one.

## 2. The new role

`executive_director`, added to `RbacSeeder::ROLES` (`:65-85`) with a comment in the register of the
finance-seat block above it. Nine grants:

```
finance.access
finance.fee-schedule.change.approve      finance.fee-schedule.change.reject
finance.discount-policy.change.approve   finance.discount-policy.change.reject
finance.credit-note.approve              finance.credit-note.reject
finance.invoice.void-request.approve     finance.invoice.void-request.reject
```

**Checker sides only. ED must never hold a submit.** Four maker-checker pairs now terminate on one
role, so a single stray `*.submit` grant makes ED a both-sides holder and
`DutySeparation::assertRoleSetAllowed()` throws at grant time. Write that reason into the block —
the next author adding "just view access to raise a credit note" needs to hit it before they type it.

**No new permission enum case.** All nine already exist (`app/Enums/Permission.php:84-141`). That
matters for §3.

**Two-factor:** check `RbacSeeder::TWO_FACTOR_REQUIRED` and say in your report whether ED belongs in
it. My view is yes — it is the only seat that can approve money leaving in four different ways — but
`two_factor_required` is a runtime-editable matrix toggle whose default only applies at creation
(`:463-470`), so getting it right at creation is the only cheap moment. I have not read that constant
myself; derive it, do not trust me.

## 3. What `rbac:sync` will and will not do — this is where the trap is

I read the grant loop at `RbacSeeder.php:490-500`:

```php
$toGrant = in_array($roleName, $existingRoles, true)
    ? array_values(array_intersect($permissions, $newPermissions))
    : $permissions;
```

- **The ED adds land.** `executive_director` is not in `$existingRoles` on any seeded environment, so
  it takes the `: $permissions` branch and receives **all nine**, even though none of the permissions
  is new. This is the new-role exemption, and it is real — I read it, it is not inferred.
- **The HoS removals land nowhere.** `rbac:sync` revokes nothing, ever. Deleting five lines from
  `grantsMap()['head_of_school']` changes no seeded database. Same for the four removed from
  `accounts_supervisor`.

**So: a convergence migration is required, and it is a REMOVAL-ONLY migration.** That is a shape we
have not built before — every prior one was add-or-move. Do not copy
`2026_08_02_100000_realign_finance_governance_grants.php` blindly and leave an add half that does
nothing.

**Derive, do not assume, what the convergence lint demands of a removal-only change.** I have not run
`bin/ci-grants-convergence-lint.php` against a removal-only diff and I will not tell you what it
says. Run it, paste it raw, and if it wants `@converges` markers, write them for what it names. If it
is silent on removals, say so — that is a finding about the lint, not a licence to skip the migration.

## 4. The migration

`2026_08_06_100000_move_head_of_school_finance_to_executive_director.php`

Keep the house properties from `2026_08_02_100000_realign_finance_governance_grants.php` — they are
good and they are why these migrations are auditable:

- governed roles `head_of_school`, `accounts_supervisor`, `executive_director`; namespaces
  `finance.`
- target grants **derived from `RbacSeeder::grantsMap()`**, never a second hardcoded list
- global rows only (`school_id IS NULL`); school-scoped rows counted and reported, never written
- fresh-install guard keyed on the permission substrate, returning a no-op
- diff-based `revokePermissionTo` / `givePermissionTo` in one transaction — **never
  `syncPermissions`**, whose raw detach fires no event and is invisible to `LogRbacChange`
- idempotency check: a second run writes no activity rows
- `down()` a deliberate no-op, reason in the docblock
- BEFORE/AFTER holder counts per school — **ids and counts only, no names, no emails**

**One thing this migration must do that none before it has:** `executive_director` may not exist as a
role row yet when the migration runs, depending on whether `rbac:sync` has run on that environment.
Handle both orders explicitly — create-if-missing, or abort with a message naming `rbac:sync` — and
say in your report which you chose and why. A migration that half-runs against a missing role and
leaves HoS stripped with nothing to approve is the worst outcome available here.

## 5. Two pre-flights that are about people, not code

Run each BEFORE and AFTER on the local copy. Paste all four outputs raw and unedited.

**a. `php artisan finance:check-staffing-readiness`.** It derives pairs from
`DutySeparation::pairs()` and demands, per school, a maker and a checker who are **distinct users**.
After this change the checker on **four** finance pairs is whoever holds `executive_director`.

**This will go red, and that is the point, not a bug.** ED is one person and holds the role in zero
schools until someone assigns him. **Every school needs an ED assignment or every credit note,
invoice cancellation, fee-schedule change and discount-policy change in that school becomes
unapprovable.** Report the gap with school ids and counts, and stop — the assignment is Segun's to
make, not yours, and it should be made deliberately rather than folded into a migration.

**b. Users who would become duty-separation violations.** Count, per school, users who hold
`executive_director` alongside any role carrying a matching maker grant. From `:335-354`,
`accounts_officer` holds `finance.credit-note.submit`, `finance.invoice.void-request.submit`,
`finance.fee-schedule.change.submit` and `finance.discount-policy.change.submit`; `finance_lead`
(`:372-376`) holds two; `accounts_supervisor` keeps `finance.fee-schedule.change.submit`.

`admin` (`:176-215`) holds `finance.access`, `finance.invoice.generate`,
`finance.invoice.reduction.apply` and `finance.fee-schedule.manage` — **none of which is a pair maker
side**, so ED + admin on one user should be clean. That is my reading of the four names against
`ApprovalAbility::matchingMakerFor()`; **prove it with `php artisan finance:audit-duty-separation`
rather than believing me**, because Segun holds admin and will hold ED.

Also count credit notes and invoice-void requests currently in `pending_approval`, per school. Those
were raised expecting an Accounts Supervisor to clear them and the clearing seat changes underneath
them. Counts only.

## 6. What I found that the decision does not cover — raise these, do not solve them

**a. The Principal keeps finance view — ANSWERED 4 August, do not change it.** `principal`
(`RbacSeeder.php:281-298`) holds `finance.access` and **keeps it**. Segun: *"The Principal role should
be able to view finance."*

This was worth asking because Brookstone told us the principal is also the HoS in secondary — *"it
can just have the two roles"* — so a secondary Principal will still see the finance area after HoS is
stripped. That is now the intended behaviour, not a leak. **`head_of_school` is the only role losing
`finance.access`; `principal` is untouched.**

Say so explicitly in the `head_of_school` comment you write, in one line, naming `principal` — the
next author who greps for who can still see finance after this migration will otherwise read it as a
miss and "fix" it. `finance.access` alone is view: no record, no generate, no approve.

**b. "Sees every school" is assignment, not a screen.** Assigning ED to all schools gives him every
school one at a time, through whatever school-switching the app already does. A single combined
view — all schools' pending approvals in one list — is a feature that does not exist, is not in this
brief, and is not free. Say so in the report so nobody discovers it in a demo.

**c. Row 17, opening balance, now needs approval and has nowhere to put it.** The ED statement names
opening-balance adjustments as requiring ED approval. There is no opening-balance table, column,
permission or screen. This is a design input for when that row is built, and it reverses the
3 August decision that it needed no second signature. Nothing to do here; recorded so it is not lost.

## 7. Oracles, fixtures and docs that carry the old seat

These go red because the seat moved. Update them for that reason, not to make them pass:

- `tests/fixtures/rbac-grants-baseline.json` — `head_of_school`, `accounts_supervisor`, and a new
  `executive_director` block
- `tests/fixtures/route-access-map.json`
- `tests/Feature/Rbac/DutySeparationEnforcementTest.php` — the docblock naming AS as CHECKER
- `tests/Feature/Rbac/MakerCheckerSeparationTest.php`
- `tests/Feature/Rbac/FinanceRoleRealignmentTest.php`
- `tests/Feature/Finance/ApprovalsPageGateTest.php`
- `app/Console/Commands/SeedDriveFixture.php` — the fixture must staff an ED who can approve
- `docs/finance/segregation-of-duties.md`
- `docs/rbac/finance-seat-realignment.md` — the 2026-08-01 narrative predates this
- `docs/Finance Module — Implementation Master Plan - v10.md`
- `app/Enums/Permission.php` — the credit-note and invoice-void docblocks describe who checks

`tests/Feature/Finance/FinanceApiAcceptanceTest.php` builds ad-hoc roles from permission sets and is
seat-agnostic. It should need **no** change. If it goes red, stop and report — something seat-shaped
leaked into it and I want to see it before it is edited.

`tests/fixtures/route-middleware-baseline.json` gates on permissions, not seats. Unchanged.

**New arm**, in `FinanceRoleRealignmentTest` or wherever the seeded-map assertions live: assert
`executive_director` holds all nine and **no** `*.submit`; `head_of_school` holds **no** permission
whose value starts `finance.`; `accounts_supervisor` holds exactly `finance.access` and
`finance.fee-schedule.change.submit`. Prove it red first by reverting the map edit — actually revert
it, do not reason about it.

## 8. Do NOT

- Do not touch `Permission::ISOLATION_CROSSING`, the `Gate::before`, or anything else on the
  school-isolation boundary. ED's reach is assignment.
- Do not grant ED any `*.submit`.
- Do not assign any user to the ED role. This branch moves grants between roles, never roles between
  people.
- Do not remove `finance.access` from `principal`. Answered 4 August: the Principal keeps finance
  view. See §6a.
- Do not build rows 14, 17, 18 or 19.
- Do not touch discount-policy eligibility / guest lists. Still being sized separately.
- Do not add a `down()` that restores the old grants.
- Do not start this before `staging-integration-decision.md` steps 1–6 are done and green.

## 9. Report

`bin/quality` 13/13 before reporting. Command output pasted raw and unedited, per the standing rule.

- the `grantsMap()` diff for all three roles, and the `ROLES` addition
- the migration in full, including the ED-role-may-not-exist branch and why you chose it
- the convergence lint output on this diff, raw, and what it demands of a removal-only change
- staffing readiness BEFORE and AFTER, both schools, plus the §5b duty-separation and pending-
  approval counts — ids and counts only
- `rbac:diff-grants` after migrating, specifically whether `MAP_REMOVAL_GAP` is reported for the nine
  removals
- the new arm red-before / green-after, proven by reverting the map edit
- confirmation that `principal` still holds `finance.access` after the migration (§6a), derived from
  the DB, not from the map
- whether ED belongs in `TWO_FACTOR_REQUIRED`, derived
- commit count as the output of `git rev-list --count $(git merge-base staging HEAD)..HEAD`, command
  pasted beside the number
- anything here you think is wrong
