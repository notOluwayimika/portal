# U2 — the discount-policies screen

**Branch:** `feat/discount-policies-page` (off `origin/staging` @ `9d4dcda`)
**Date:** 2026-08-13

---

## Headline

Done, with **two deviations** — one of them a change to an API endpoint the brief said the page would
simply consume. The screen exists, all four acts were driven end to end in the browser (including the
ED approving each proposal, which is not part of this commit but is what makes the outcomes visible),
and the drive fixture now seeds one active policy per school so amend and retire have a target.

**This is full-review tier** — it touches money rendering, an API contract, RBAC derivation, a fixture
oracle and the drive fixture. Recommend a cold session against this file before merge.

---

## Deviations from the brief

### 1. The catalog endpoint changed. The brief said the page needed no backend work; it did.

The brief's §2a requires retired and superseded rows to stay visible, and §1 says "everything the page
uses comes from `/api/v1/finance/discount-policies`". Those two cannot both hold against the endpoint
as it stood: `DiscountPolicyController::index()` hard-filtered to `status = active`
(pre-change body: `->where('status', DiscountPolicyStatus::Active->value)`) and took no parameters, so
a superseded or retired policy was unreachable by any caller.

I changed `index()` to the shape its sibling `FeeScheduleController::index()` already uses — an
optional `status` filter where **absent means unfiltered** — rather than inventing a `?status=all`
sentinel. The page passes nothing and gets the whole catalog; a caller that wants the choosable set
now asks for `?status=active`, which is what U8 will want at invoice time.

**Why the default flipped rather than being preserved.** I checked for consumers before changing it:
repo-wide search for the path across `resources/`, `tests/` and `app/` returned only the
wayfinder-generated client (`resources/js/actions/…`) and the two route fixtures — **no functional
test and no page called this endpoint at all**. The old behaviour was therefore an unstated default
nothing depended on, and an unstated default that silently hides rows is worse than an explicit
filter. A test now pins both directions (§Proof, arm 3), including that an unknown status is a 422
rather than a silently empty list.

If the reviewer disagrees, the minimal alternative is to keep `active` as the default and have the
page pass an explicit `?status=` for each state it wants — three requests instead of one, and a
default that still lies to the next caller.

### 2. A test file the brief did not ask for.

`tests/Feature/Finance/DiscountPoliciesScreenTest.php` (252 lines, 8 arms). The brief asked for the
screen and named the two gates that fire; it did not ask for tests. I wrote them because the endpoint
change above is a behaviour change with no coverage at all, and because the claim "the U1 split cannot
happen here" is a fact about the permission catalog that should fail a build if it stops being true
rather than sit in a route comment. Two of the arms have a watched red pasted below.

---

## Contradictions of the premise

**None on the controls.** Everything the brief says about the governance path checks out:
`ApproveDiscountPolicyChange` is the only writer of `finance_discount_policies` (pinned by an existing
arch arm, `DiscountPolicyTest.php:305`), the endpoints are all built
(`routes/endpoints/finance.php:134-142`), and the approvals queue already renders this type
(`DiscountPolicyChangeResource` carries `type => 'discount_policy_change'`).

**Two premises the brief asked me to verify, both confirmed:**

- **No second ability exists.** `App\Enums\Permission` carries exactly three discount-policy cases —
  `finance.discount-policy.change.submit` / `.approve` / `.reject` (`Permission.php:178-180`). There is
  no `manage` twin, so the page gate and the button gate are the same permission by construction and
  the U1 split is unrepresentable here. Pinned rather than asserted in prose: an arm fails if a fourth
  maker-side case is ever coined.
- **The drive fixture seeded no policy.** Confirmed by reading both halves before changing them —
  `DriveCastSeeder` touches no Finance table, and `DriveFinanceStates` created invoices, payments,
  credit notes, voids and one bank account, and nothing in `finance_discount_policies`.

**One thing the brief did not mention, which the drive exposed:** this screen cannot show a proposal it
has just sent. The pending queue is `GET /api/v1/finance/discount-policy-changes/pending`, gated on
`…change.approve` — an ability the proposing seat must never hold (they are a maker–checker pair). So
after sending a create, the list is unchanged and nothing on the page says a proposal is in flight. The
operator finds out only by trying a second change on the same policy and getting *"A change for this
policy is already awaiting approval."* (driven; screenshot `maker-07`). Fixing this needs a
maker-visible feed endpoint, which is a bigger change than the screen and is not in this commit. Raised
below as a ticket.

---

## What changed

| File | ± | What |
|---|---|---|
| `resources/js/pages/admin/finance/discount-policies.tsx` | +785 (new) | The screen: list (active + a "No longer in use" section), propose / amend / retire, all three posting `discount-policy-changes`. |
| `tests/Feature/Finance/DiscountPoliciesScreenTest.php` | +252 (new) | 8 arms: route gate, refusal, catalog states, isolation, the grants coupling, the one-ability fact, the nav item, the unfiltered fetch. |
| `routes/web.php` | +25 | The route, gated on `permission:finance.discount-policy.change.submit` inside the existing `auth`/`tenant`/`finance.access` group. No props. |
| `app/Finance/Http/Controllers/DiscountPolicyController.php` | +31 −7 | Deviation 1 — the optional `status` filter; absent means unfiltered. |
| `resources/js/components/app-sidebar.tsx` | +13 | The nav item beside Fee schedules, keyed on the route's ability. |
| `app/Finance/Console/DriveFinanceStates.php` | +76 | `ensureDiscountPolicy()` (submit → approve, through the real Actions) and `discountPolicyCount()`. |
| `app/Console/Commands/SeedDriveFixture.php` | +21 −6 | Seeds one policy per school before the states run; adds the count column to the report table. |
| `database/seeders/DriveCastSeeder.php` | +11 −1 | Exposes `schoolBMaker` so School B's proposal is made by School B's own bursar. |
| `tests/fixtures/route-access-map.json` | +8 | Regenerated (not hand-edited). One route added, nothing else moved. |
| `docs/handoff/drives/2026-08-13-discount-policies/` | 14 PNGs | The drive. |

No permission was coined. `Permission.php`, `PermissionGroup.php`, `grantsMap()` and
`rbac-grants-baseline.json` are untouched — the only edit to `RbacSeeder.php` in this session was a
watched-red mutation, reverted and verified (below).

---

## Grants derivation for the page's ability

Derived from `RbacSeeder::grantsMap()` at the moment of writing, not carried:

```
SUBMIT: accounts_officer (finance.access: yes)
SUBMIT: finance_lead     (finance.access: yes)
APPROVE: executive_director
```

Both holders also hold `finance.access`, which the page needs because the route sits inside that
group and because the list is a fetch of the catalog endpoint, which carries only that ability. That
coupling is what makes "no props" a decision rather than a hole, so it is asserted (arm 5) rather than
assumed.

The regenerated access map derives the same set from the live route plus the seeded grants, and adds
`super_admin`:

```json
"GET /finance/discount-policies": {
    "auth": true,
    "roles": ["accounts_officer", "finance_lead", "super_admin"]
}
```

`super_admin` is there through the `Gate::before` bypass and is correct: `…change.submit` is a MAKER
ability, so ADR 0040's checker exclusion does not apply to it — the same reason `super_admin` appears
on `/finance/fee-schedules` and not on `/finance/approvals`. Not driven (see §Not done).

---

## Proof

### The oracles

```
$ DB_DATABASE=portal_testing php artisan migrate:fresh --seed --force
 INFO Seeding database.
 Database\Seeders\ArmsDatabaseSeeder .. RUNNING
 Database\Seeders\RbacSeeder .. RUNNING
 Database\Seeders\RbacSeeder .. 425 ms DONE
 Database\Seeders\ArmsDatabaseSeeder .. 426 ms DONE

$ DB_DATABASE=portal_testing php artisan rbac:sync
rbac:sync — roles/permissions synced; existing grants preserved (non-destructive).

$ DB_DATABASE=portal_testing php artisan rbac:derive-access
route-access-map.json written (379 routes).

$ git diff --stat tests/fixtures/
 tests/fixtures/route-access-map.json | 8 ++++++++
 1 file changed, 8 insertions(+)
```

**Both commands were run against `portal_testing`, NOT against the production copy.** That is a
deliberate choice and worth checking: `rbac:derive-access` reads grants from the connected database,
and the fixture is compared by `RouteAccessParityTest` against a tree seeded with `DatabaseSeeder`.
Deriving it from the production copy would bake that copy's runtime matrix edits into an oracle the
suite re-derives from the seeder — drift by construction. The diff is the eight lines above and
nothing else, which is the evidence that the choice did not move anything.

`route-middleware-baseline.json` was **not** regenerated: neither `/finance/fee-schedules` nor
`/finance/bank-accounts` is in it (checked), so a new guarded web route is not a baselined entry, and
that test tolerates new routes carrying auth by design (its docblock says so).

### The suite arms

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/DiscountPoliciesScreenTest.php \
    tests/Feature/Finance/FinanceNavCoverageTest.php tests/Feature/Finance/FeeSchedulesScreenTest.php \
    tests/Feature/Finance/DiscountPolicyTest.php tests/Feature/Rbac/RouteAccessParityTest.php
{"result":"passed","tests":55,"passed":55,"assertions":144,"duration_ms":36919,"risky":1}
```

(The `risky: 1` is pre-existing and not in the new file.)

### Money lint

```
$ php bin/ci-money-lint.php
money-lint: OK — no money-rule violations (0 known exception(s)).
```

The page renders the amount basis through `formatNaira`, prefills through `minorToNairaInput` and
converts input through `nairaToMinor`. The percent basis goes near none of them: it is an integer
1..100, matching `unsignedTinyInteger` + the `BETWEEN 1 AND 100` CHECK in
`2026_07_26_140000_create_finance_discount_policies.php:42,59`.

### Changed-file lint

```
$ ./vendor/bin/pint <6 explicit files>          → {"result":"passed"}
$ npx eslint <2 frontend files>                 → ESLint: No issues found (exit 0)
$ npx prettier --list-different <2 files>       → (clean, after one --write)
$ npx tsc --noEmit | grep discount-policies     → (no diagnostics in the new page)
```

`tsc` does report `app-sidebar.tsx(528,61): Property 'uuid' does not exist on type 'Teacher'` — a
pre-existing diagnostic on a line this change does not touch, and the tsc ratchet step passed. See the
correction in §Remediation: `tsc-baseline` is a single project-wide COUNT (`42`), not a per-file
number, and an earlier version of this line described it as the file's own ratchet.

### `bin/quality`

**15 steps on this branch**, re-derived rather than carried (`grep -c '^\s*step "' bin/quality` → 15;
the `[%d/15]` literal is at `bin/quality:59`). The finance-context note saying 14 is stale.

**Run 1 — before the commit. PASS 15/15, and step 3 proved nothing.**

```
quality gate — base 9d4dcda

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form                                     ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)          ✓ lint-changed
       Pint: no changed PHP files
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/15] types (tsc ratchet vs tsc-baseline)                                ✓ tsc-ratchet
[5/15] frontend build (vite)                                              ✓ build
[6/15] authorization guard (no new commented-out checks)                  ✓ authz-lint
[7/15] boundary lint (§17.2)                                              ✓ boundary-lint
[8/15] grants-convergence lint                                            ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)           ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)                      ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)                         ✓ identifier-generation-lint
[12/15] sql-clock lint                                                    ✓ sql-clock-lint
[13/15] architecture tests (§17.1)                                        ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)                    ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)             ✓ test-ratchet

✓ quality: PASS — per-push floor.
bin/quality  361.91s user 75.51s system 57% cpu 12:36.15 total
```

**Read step 3 before reading the tick.** `lint-changed` saw *no changed files at all*, because the
work was still uncommitted — the known limitation recorded in
`docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md` and in CLAUDE.md. A green there was
a green over an empty list, which is exactly the shape this project calls wallpaper. The
changed-file lint above (Pint / ESLint / Prettier / tsc, run by hand on explicit files) is what
actually covered it — and then the gate was run a **second** time after the commit so the step had
something to look at:

**Run 2 — after the commit, `[3/15]` non-vacuous.**

```
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)          ✓ lint-changed
       Pint (check) on 6 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
```

```
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)             ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**Two runs, both PASS, no red in either** — so there is no re-run-until-green story here. Run 2 is not
a retry of a failure; it exists because run 1's step 3 was vacuous, and it is the run whose lint step
actually read this change's files.

---

## The watched red

Three mutations, each restored and each re-verified green afterwards.

### 1. The endpoint's old behaviour, restored

Mutation — put the hard filter back in `DiscountPolicyController::index()`:

```php
->where('status', DiscountPolicyStatus::Active->value)
```

```
{"result":"failed","tests":1,"failed":1,
 "test":"it_lists_superseded_and_retired_policies__not_only_the_active_ones",
 "message":"Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
-    0 => 'Covid relief',
-    1 => 'Old sibling discount',
-    2 => 'Sibling discount',
+    0 => 'Sibling discount',
 ]"}
```

The failure names the two hidden states, which is the defect. Restored; arm green.

### 2. The grants coupling

Mutation — removed `PermissionEnum::FINANCE_ACCESS` from the `finance_lead` row of `grantsMap()`:

```
{"result":"failed","tests":1,"failed":1,
 "test":"it_every_role_that_may_open_this_screen_may_also_read_the_catalog_it_fetches",
 "message":"Role(s) [finance_lead] hold finance.discount-policy.change.submit but not finance.access.
 /finance/discount-policies sits inside the finance.access route group and its list is a fetch of the
 catalog endpoint, so that seat is offered a nav item onto a screen that 403s before it renders."}
```

It names the role and says what to do. Restored; `grantsMap()` is byte-identical to `staging`
(`git diff staging -- database/seeders/RbacSeeder.php` is empty).

### 3. The nav coverage gate

Mutation — changed the sidebar href to `/finance/discount-policies-REGRESSION`:

```
{"result":"failed","tests":2,"failed":2,
 "test":"it_every_finance_page_is_reachable_from_the_sidebar…",
 "message":"A finance page is registered, permission-gated and reachable from NO menu:
 /finance/discount-policies. Add it to the Finance group in resources/js/components/app-sidebar.tsx,
 or — if it genuinely cannot be a menu item — add it to FNC_NOT_NAV in this file WITH THE REASON…"}
```

Both `FinanceNavCoverageTest` and my sidebar arm fired. **The second one taught me something and I
changed the test because of it:** my arm used `expect($sidebar)->toContain(…)`, which printed the
entire 18 kB sidebar instead of a sentence — the exact trap `FeeSchedulesScreenTest:216-220` records
(Pest's `toContain` is variadic, so a message argument is read as a second needle). Rewritten as
`expect(str_contains(...))->toBeTrue($message)`. Restored; both green.

**A fourth red I did not plant and should record:** the first version of the "fetches the catalog
unfiltered" arm asserted the literal `axios.get('/api/v1/finance/discount-policies')`, and
`prettier --write` broke that call across three lines and turned the arm red. A needle a formatter can
break is a test that fails for the wrong reason; the arm now asserts the URL and the *absence* of
`?status=` instead.

---

## The drive

`APP_ENV=drive php artisan finance:seed-drive-fixture` then `php artisan serve --port=8001`, driven
headlessly in Chromium. 14 screenshots in `docs/handoff/drives/2026-08-13-discount-policies/`.

### Fixture counts, from the command's own report table

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account;
the discount-policies screen amends and retires a policy:
+--------------+-------------------+-------+--------------+---------------+-------------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies |
+--------------+-------------------+-------+--------------+---------------+-------------------+
| A (school#1) | 1                 | 1     | 2            | 1             | 1                 |
| B (school#2) | 1                 | 1     | 2            | 1             | 1                 |
+--------------+-------------------+-------+--------------+---------------+-------------------+
```

Counted from the database through the scoped model inside `ActiveSchool::runFor`, not from the
seeder's own variables. Both policies were produced by `SubmitDiscountPolicyChange` →
`ApproveDiscountPolicyChange` — the real path, so the fixture contains no state the system cannot
reach. School B's was proposed by `school-b@drive.test` (`user` in `school#2`), not by School A's
bursar.

### Seat 1 — `maker@drive.test` (`accounts_officer`, `school#1`)

What the screen actually contained, read back from the DOM at each step:

```
ROWS initial:
 "Sibling discount | Second and subsequent children… | Percentage | 10% of discountable charges
  | A bursar applies it directly on the invoice | Active | Amend | Retire"
```

- **`01`** — the nav item is present ("Discount policies", beside Fee schedules) and the one seeded
  policy renders with its basis, its value and the plain-English `requires_approval` line.
- **`02`, `03` — the inline amount error.** Typing `250,000` (with the comma `nairaToMinor` rejects)
  blocks the send and marks that field: *"Enter the amount taken off, in naira — for example 25000 or
  2500.50."* No request is made. The scholarship radio is selected in the same shot, so the
  `requires_approval` copy is legible in place: *"The discount cannot go on an invoice at all. Each
  award is raised as a credit note and the ED approves it one student at a time."*
- **`04`** — `250000` sends. Toast: *"New policy sent to the executive director."* The list is
  unchanged, which is correct and is the limitation named above.
- **`05` — the amend modal is prefilled** from the policy being superseded: name `Sibling discount`,
  basis `percent`, percent `10` (read back from the inputs, printed in the drive log). Changed to 15.
- **`06`** — amendment sent.
- **`07` — the one-open-request refusal.** Clicking Retire on the same policy and sending returns 422
  *"A change for this policy is already awaiting approval."*, rendered both as a form-level message
  inside the modal and as a toast. That is `SubmitDiscountPolicyChange`'s friendly check (the
  `open_key` UNIQUE is the concurrency backstop) reaching the operator in words.

### The ED's decisions (`checker@drive.test`, `executive_director`) — not this commit, but what makes the outcomes visible

`08`, `11` show the catalog after approval:

```
ROWS after approval:
 "Scholarship — full fees | … | Fixed amount | ₦250,000.00 | Each award needs the ED's sign-off —
  raised as a credit note, never as an invoice line | Active | Amend | Retire"
 "Sibling discount | … | Percentage | 15% of discountable charges | A bursar applies it directly… | Active"
 "Sibling discount | … | Percentage | 10% of discountable charges | A bursar applies it directly… | Superseded"

ROWS final (after the retirement was approved):
 "Scholarship — full fees | … | ₦250,000.00 | … | Active"
 "Sibling discount | … | 10% … | Superseded"
 "Sibling discount | … | 15% … | Retired"
```

- **The amount policy renders `₦250,000.00`** — through `formatNaira`, from `value_minor` +
  `value_currency` paired back into the wire shape. Nothing on the page computed it.
- **An amendment supersedes rather than edits.** Two rows, both readable, the old one at its old rate.
- **A retired policy stays on the list**, in the "No longer in use" section, with "Kept for the
  invoices it priced" where its controls were.

### Seat 2 — `school-b@drive.test` (isolation, `school#2`)

```
ROWS school B:
 "Sibling discount | … | Percentage | 10% of discountable charges | … | Active | Amend | Retire"
```

One row: School B's own policy. None of School A's three appear — not the scholarship, not either
sibling row, and not the superseded or retired ones. Screenshot `isolation-01`.

---

## Not done

- **`super_admin` on this screen.** In the access map through the bypass; not driven. The drive
  fixture's `super@drive.test` exists, but the seat adds nothing this screen's controls do not already
  show, and the point it would prove (that a maker ability is not bypass-excluded) is a property of
  ADR 0040, not of this page.
- **A rejection.** The ED approved all three proposals; nothing was rejected, so the page has not been
  seen after a `rejected` change. It renders identically — a rejected change never touches the catalog
  — but that is reasoning, not an observation.
- **A server-side 422 on the terms.** Every field error seen in the drive was the client-side one. The
  server bag path (`value_minor` `prohibited_if`, the currency regex) is rendered by the same code that
  renders the client bag, and no drive produced a real one: the form cannot post a cross combo, which
  is the point of it.
- **The suite's non-determinism.** ADR 0053 records byte-identical code producing both a pass and a
  red. Whatever `bin/quality` returned below is one observation.
- **Anything about the production database.** No finding here was derived from the production copy; the
  grant facts come from `grantsMap()` and from `portal_testing`.

---

## Findings raised, not fixed

- **`ticket` — a maker cannot see their own pending proposal.**
  `resources/js/pages/admin/finance/discount-policies.tsx` shows no in-flight state, because
  `GET /api/v1/finance/discount-policy-changes/pending` is gated on `…change.approve`
  (`routes/endpoints/finance.php:137-138`) — an ability the proposing seat must never hold. The
  operator's only signal is the 422 on a second attempt. The same gap exists on the fee-schedules
  screen for the same reason. A maker-visible feed (`?mine=1`, or a submitter-scoped endpoint) would
  close both.
- **`ticket` — `/dashboard` 403s for the drive's finance seats.** `maker@drive.test` and
  `school-b@drive.test` log in successfully and are then bounced from `/dashboard` back to `/login`
  (403 on `GET /dashboard`, observed in the drive). Every finance page is reachable directly, so it did
  not block this drive, but a real bursar landing on a 403 after signing in is a poor first screen.
  Pre-existing and unrelated to this change.
- **`ticket` — `DiscountPolicyResource` does not expose `supersedes_policy_id`.** The screen can show
  that a policy is superseded but not *by which one*; on a school with several amendments of the same
  policy the "No longer in use" list becomes a set of same-named rows the operator must date-order by
  eye. Cheap to add when someone wants it.

---

## Commit

One commit on `feat/discount-policies-page`. Not pushed; no PR opened.

---

# Remediation — 2026-08-14 (cold review, amended into the same commit)

Two findings came back. One fix, one ticket, and two corrections to this report. Both findings were
confirmed against the repo before acting on either.

## Fix 1 — the one-ability arm guarded a prefix, not the property

**The finding is right and the arm was weaker than its own docblock claimed.** It filtered
`str_starts_with($ability, 'finance.discount-policy.')`, so the only maker abilities it could ever see
were ones already spelled the way today's are. `finance.discount.manage` — the most likely spelling
for a future "may author the catalog directly" ability — is invisible to that filter:

```
$ php -r 'var_dump(str_starts_with("finance.discount.manage", "finance.discount-policy."));'
bool(false)
```

So the arm would have stayed green through exactly the divergence it exists to catch. A prefix is a
guess about how the next person will name the thing.

**The widening, and the trap in the obvious version.** `str_contains($ability, 'discount')` also
matches `…change.approve` and `…change.reject`, and the arm asserts there is exactly ONE maker
ability. Checker actions are therefore excluded by the terminal-segment convention —
`ApprovalAbility::isExcludedFromSuperAdminBypass()` (`app/Support/ApprovalAbility.php:45-48`), the same
predicate the sidebar's checker item and ADR 0040's bypass exclusion already use. No third copy of
"ends with approve or reject" was written.

The arm now carries two expectations rather than one:

1. every discount-shaped ability that is NOT a checker action is exactly
   `['finance.discount-policy.change.submit']`;
2. every discount-shaped ability, checkers included, is still the known three — so a RENAME (to
   `concession`, say) fails loudly instead of making arm 1 pass by matching nothing.

### The watched red

Mutation — a maker-side case coined **outside** the old prefix, in `app/Enums/Permission.php`:

```php
case FINANCE_DISCOUNT_MANAGE_REGRESSION = 'finance.discount.manage';
```

```
{"result":"failed","tests":1,"failed":1,
 "test":"it_has_ONE_MAKER_ability__so_the_page_gate_and_the_button_gate_cannot_disagree",
 "message":"A second MAKER-side discount ability now exists.
 resources/js/pages/admin/finance/discount-policies.tsx gates the whole page on
 finance.discount-policy.change.submit and asks nothing else… the page gate and the button gate can
 now disagree — the U1 defect — and the page needs an in-page can() on whatever those controls
 actually post.
--- Expected
+++ Actual
 Array &0 [
     0 => 'finance.discount-policy.change.submit',
+    1 => 'finance.discount.manage',
 ]"}
```

The failure names the offending ability. **The pre-remediation arm would not have fired on this
mutation at all** — that is what the `str_starts_with` check above shows, and it is the point of
choosing a case outside the prefix rather than a fourth `finance.discount-policy.*` one.

Restored, and the enum is byte-identical to staging:

```
$ git diff origin/staging -- app/Enums/Permission.php | wc -l
0
$ pest --filter="ONE MAKER ability"
{"result":"passed","tests":1,"passed":1,"assertions":2}
```

## Ticket 1 — filed as U8's opening scope

`docs/handoff/tickets/discount-policy-status-unguarded-at-the-edge.md`.

Confirmed against the repo, not accepted from the review: `GenerateInvoiceRequest.php:113` validates
`lines.*.discount_policy_id` as `['sometimes','nullable','integer']`, `GenerateInvoice.php:221` passes
it through, and the reduction trigger's *"The referenced discount policy is not active."*
(`2026_07_26_140002_add_discount_policy_to_finance_lines.php:80-82`) is the only refusal. 1644 is
deliberately unmapped in `bootstrap/app.php:201`, so that refusal is a 500.

The ticket is written as **U8's precondition**, in the same handling as U1's drive-fixture seeding: it
opens by saying it is the first item in that commit's scope rather than a ticket U8 might read. It
records the internal inconsistency verbatim in both directions — `GenerateInvoiceRequest.php:110-112`
("the DB reduction_guard is the authority") against `SubmitDiscountPolicyChangeRequest.php:33-34`
("refused here so a cross combo is a 422, not the DB terms_shape CHECK's 3819 → 500") — because that
asymmetry, one feature and one repository apart, is the argument. Remedy named as either a
status-bearing `exists` rule or a pre-check in `GenerateInvoice`, with the second preferred: two of the
trigger's three refusal branches are operator-facing and only a resolved policy tells them apart.

Not fixed here for the reason the ticket states: no consumer posts the field, the money invariant
holds (the trigger refuses the write), and the guard's shape depends on what U8's picker sends.

## The two report corrections

**1. The assertion count was wrong, and the way it was wrong is worth naming.** This report said 142;
the reviewer measured 143 on the identical five files. The reviewer is right about the committed tree
— **142 was a number I took from a run made before the last two edits to the test file** (splitting the
sidebar assertion into two `expect()` calls, and replacing the formatter-fragile fetch needle). It was
a carried number, which is the failure mode this project's method names explicitly, and it was carried
across only twenty minutes.

Re-derived just now, after the widened arm above, which adds one more:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest <the same five files>
{"result":"passed","tests":55,"passed":55,"assertions":144,"duration_ms":36919,"risky":1}
```

The §Proof block now reads 144. The count is only meaningful against a stated tree state: 143 was the
committed `942ccce`, 144 is this amendment.

**2. `tsc-baseline` is not a per-file count.** It is a single project-wide number — the file contains
exactly `42` — and describing it as "the file's committed ratchet" for `app-sidebar.tsx` was wrong
about the mechanism. The claim it was supporting (this change introduces no new diagnostic; the
`Teacher.uuid` error is pre-existing and on a line the change does not touch) is unaffected, and the
`[4/15] tsc-ratchet` step passing is the actual evidence for it. Corrected in place in §Proof.

## Gate, after the remediation

Run on the AMENDED tree (`git status --porcelain` empty first, so `[3/15]` reads this change's files
rather than an empty list — the run-1 lesson above):

```
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
       Pint (check) on 6 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
…
✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
exit=0
```

**One earlier invocation shows a non-zero exit and it was not a red.** I started a gate run before
amending, realised `[3/15]` would again be reading a stale tree, and killed it — exit 144 is the
signal, not a failure. It was replaced by the run above rather than retried after a failure. Recording
it because the difference between "aborted" and "red, re-run until green" is invisible in a shell
history, and this project treats that ambiguity as the problem.

Three gate runs total on this branch, all green, none re-run after a red:

| Run | Tree | Why |
|---|---|---|
| 1 | uncommitted | PASS 15/15; `[3/15]` saw no files — vacuous |
| 2 | committed `942ccce` | PASS 15/15; `[3/15]` non-vacuous |
| 3 | amended `9796edd` | PASS 15/15; the remediation |

## What the remediation did NOT do

- **No drive was re-run.** The remediation touches a test filter, a ticket and this report — no
  runtime code, no page, no route, no fixture. The 14 screenshots still describe the shipped screen.
- **The ticket's failure mode is predicted, not observed.** Nobody posted a retired policy id at an
  invoice endpoint to watch the 500; there is no caller to do it through, and provoking it by hand
  would have tested the provocation. The citations are verified; the 500 is derived from `1644` being
  unmapped at `bootstrap/app.php:201`.
