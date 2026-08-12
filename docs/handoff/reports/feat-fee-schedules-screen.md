# U1 commit 2 — the fee-schedules screen

**Branch** `feat/fee-schedules-screen` · **base** `origin/staging` @ `5a19442` (Merge PR #236 —
the commit-1 data surface) · **brief** `docs/handoff/u1-fee-schedules-brief.md` Block 5, appended
verbatim by this commit.

**Full-review tier.** This change touches money (a new summed `total` on a wire contract), RBAC
grants (a permission row created on the local copy by `rbac:sync`), `school_id` isolation (two
School-scoped props), a fixture oracle (`route-access-map.json`) and two gates. Recommend a cold
session started from this file before merge.

---

## 1. Two premises in the brief that the code contradicts

Both are named first because the brief's own instructions rest on them, and following either
literally would have produced a change that looks finished and is wrong.

### 1a. `whenLoaded('items', …)` does NOT keep `total` off prefill — prefill loads items

Block 5 §2 says:

> `FeeScheduleResource` gains `total` … Under `whenLoaded('items', …)` — the same treatment the
> items themselves get, so prefill's payload does not grow a key.

and two lines later requires an arm proving `total` is **absent** on prefill. Those two cannot both
hold. `prefill()` builds the resource with items **loaded**:

```php
// app/Finance/Http/Controllers/FeeScheduleController.php:183 (pre-change numbering)
'schedule' => new FeeScheduleResource($schedule->loadMissing('items.bankAccount')),
```

and the pre-existing prefill arm asserts `items` is present in that payload
(`tests/Feature/Finance/FeeScheduleTest.php`, the key list
`['id','term_id','class_level_id','label','status','items']`). So `whenLoaded('items')` is
**satisfied** on the billing read path and a total hung off it appears there.

Proven, not reasoned — this is watched red **R2** below: swapping the implemented
`when($this->withTotal, …)` for the brief's `whenLoaded('items', …)` makes the prefill key list fail
with `+ 6 => 'total'`.

**What was built instead.** An explicit opt-in on the resource — `FeeScheduleResource::withTotal()`,
plus a `::catalog(Collection)` helper for the list — asked for by name at the four catalog responses
(`index`, `store`, `editDraft`, `supersede`) and not by `prefill`. Rejected alternatives, with the
reason each was rejected, are in the method's docblock: keying off `term` or `classLevel` (relations
prefill happens not to load) would give the right shape today for a reason that has nothing to do
with totals, and would move silently the day an eager-load changed.

**The brief's requirement survives its reasoning.** `total` must stay off prefill regardless: that
payload hands the bursar's generate form a `lines` array to confirm and post, and a `schedule.total`
beside it reads as "what to charge this student" on the one payload where that is wrong — a schedule
prices a slot, an invoice prices a student.

### 1b. The second of the "two gates that fire" does not fire

Block 5 §4 says `tests/fixtures/route-access-map.json` "gains `GET /finance/fee-schedules`" under the
heading **THE TWO GATES THAT FIRE THE MOMENT THE ROUTE REGISTERS**. Gate (a) — `FinanceNavCoverageTest`
— does fire; watched red **R7**. Gate (b) does not. Both consumers of that fixture assert
**fixture → live** only, and both say so:

```
tests/Feature/Rbac/RouteAccessParityTest.php:19-22
 * Deliberate asymmetry (same as RouteMiddlewareBaselineTest): only fixture
 * routes are asserted, so NEW routes — Finance additions included — are never
 * blocked here.
```

`grep -rn route-access-map app tests bin` returns only that test, the deriving command,
`app/Support/RouteAccessMap.php` and a docblock in `app/Enums/Permission.php`. Nothing asserts the
map is complete. The fixture was regenerated anyway (§5) because the brief asked and because a stale
oracle is worth avoiding — but a reader should not believe a red would have caught its omission.

`route-middleware-baseline.json` needed no regeneration for the same documented asymmetry: a new
route carrying auth middleware passes freely (`RouteMiddlewareBaselineTest.php:14-18`).

---

## 2. Deviations and additions beyond the brief

### 2a. A third coupling the brief did not name: `manage` does not imply `change.submit`

Block 5 §1 turns the bank-accounts fetch coupling into a checked one. The same shape exists one
ability over and is **already broken**, and the brief does not mention it.

The page is gated on `finance.fee-schedule.manage`. Acts (d) and (e) — submit for approval, retire —
`POST /api/v1/finance/fee-schedule-changes`, gated on `finance.fee-schedule.change.submit`
(`routes/endpoints/finance.php:117-118`). In `RbacSeeder::grantsMap()` the `admin` role holds the
first and **not** the second (`database/seeders/RbacSeeder.php:235` vs `:389`, where
`accounts_officer` holds both). So an `admin` authoring a draft would be shown a "Submit for
approval" button that 403s — the defect the nav gate exists to prevent, one layer in.

Two things were added for it, neither in the brief:

1. The page gates both controls on `can('finance.fee-schedule.change.submit')`
   (`resources/js/pages/admin/finance/fee-schedules.tsx`, `canPropose`).
2. An arm asserting the gate is in the file, with the holder set derived from `grantsMap()` rather
   than listed (`FeeSchedulesScreenTest.php`, "gates the submit/retire controls on the CHANGE
   ability"). Watched red **R6**.

The map is **not** treated as wrong and was not edited — `admin` is deliberately not a finance maker
seat. The arm also fails loudly if that ever changes, so the gate cannot silently become decoration.

### 2b. A fourth helper in `resources/js/lib/format.ts`

Prefilling the edit modal needs minor units rendered back into the plain string an amount input
holds. `formatNaira` returns `₦2,500.75`, which `nairaToMinor` then rejects; computing it at the call
site is `amount_minor / 100`, which is the arithmetic `bin/ci-money-lint.php` exists to refuse.

`minorToNairaInput(money): string` was added to `format.ts` — the one file exempt from both money-lint
rules and the file whose own docblock says all money conversion lives there. It mirrors the backend
`Money::toNaira()` and is float-free the same way (`Math.trunc` + `%`, no `toFixed`). Round-trip
confirmed in the drive: an amount stored from `250000.50` came back into the input as `250000.50`
(§6).

This widens the money boundary by one function and is flagged rather than buried.

### 2c. `sumMinor` exists, so "the frontend performs NO monetary arithmetic" is not literally true

Block 5 §2 states the rule absolutely. `format.ts:64` already exports `sumMinor`, described there as
"the third and last sanctioned money op". The page therefore **could** have summed the items itself.
It does not, and the brief's conclusion is still the right one — the API returning the figure means
the list and the write responses cannot disagree about it, and `sumMinor` cannot see the
currency-mismatch case §3 below is about. Recorded because the stated premise is stronger than the
code.

### 2d. Types declared in the page, not in `types/finance.ts`

`resources/js/types/finance.ts` says it mirrors the backend Resources. The catalog shape is declared
in `fee-schedules.tsx` instead, following `bank-accounts.tsx`, which declares its `BankAccount` type
inline. One consumer, one declaration. If a second screen reads fee schedules, it should move.

---

## 3. The currency-mismatch decision (Block 5 §2 asked for one)

**`total` is `null` when the schedule's items disagree on a currency.** The key is present; the row
renders "Mixed currencies".

The condition is reachable, not theoretical. `items.*.currency` accepts any `/^[A-Z]{3}$/`
(`HasFeeScheduleItemRules`), and the database CHECK on `finance_fee_items.amount_currency` is a
**shape** check, deliberately not `= 'NGN'` — its migration says so in as many words
(`2026_08_01_120000_add_currency_shape_checks.php`, "SHAPE ONLY, deliberately not `= 'NGN'`"). So two
items in one schedule may legally be NGN and USD through the ordinary write path.

`Money::plus` throws on a mismatch. The three candidate behaviours:

| Behaviour                      | What the School gets                                                                                                                               |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sum with `plus`, unguarded     | One malformed schedule **500s the whole list** — uncaught `InvalidArgumentException` inside `index()`. Watched red **R3** reproduces exactly this. |
| Add the minor units            | A number that is not an amount of anything, rendered as naira.                                                                                     |
| **`null` + a named condition** | 200, the other rows readable, and the offending row says what is wrong with it.                                                                    |

The sum is still performed through `Money::plus` rather than by adding `toKobo()` ints, because
integer addition is precisely the operation that cannot see the mismatch. An item-less schedule (raw
insert only; `items` is `required|min:1` on both write requests) totals zero.

---

## 4. The slot collision, provoked rather than assumed (Block 5 §3)

`finance_fee_schedules_pending_unique` permits one draft-or-pending schedule per (school, term, class
level). **It is a 422 with a `message` and no `errors` bag** — not a 500, and not a validation bag.
`CreateFeeSchedule` catches the 1062 and rethrows a `BusinessRuleException`, which
`FeeScheduleController::store` returns as `['message' => …]`.

Verified twice. As an HTTP arm (`FeeSchedulesScreenTest.php`, "answers a second draft for an occupied
slot with a 422 the page can render"), asserting the key list is exactly `['message']`; and in the
browser (§6, `maker-05-slot-collision.png`), where the operator sees:

> A draft or pending schedule already exists for this term and class level. Edit that draft instead,
> or submit it for approval; if it is already awaiting approval, await the decision.

Nothing was papered over. The page's `parseErrorBag` forks on the two 422 shapes deliberately: a page
reading only `response.data.errors` would have shown a failed save and said nothing.

---

## 5. What was built

| File                                                     | What                                                                                                                                                                                                                 |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `routes/web.php`                                         | `GET /finance/fee-schedules`, inside the `auth`+`tenant`+`finance.access` group, `+permission:finance.fee-schedule.manage`. Terms and class levels as props, both School-scoped off `ActiveSchool::getOrFail()->id`. |
| `resources/js/pages/admin/finance/fee-schedules.tsx`     | The screen. Five acts, no approve/reject.                                                                                                                                                                            |
| `resources/js/components/app-sidebar.tsx`                | Finance group item, gated on the route's ability.                                                                                                                                                                    |
| `app/Finance/Http/Resources/FeeScheduleResource.php`     | `total` (opt-in), `withTotal()`, `catalog()`, `scheduleTotal()`.                                                                                                                                                     |
| `app/Finance/Http/Controllers/FeeScheduleController.php` | Four catalog responses ask for the total; `prefill` does not.                                                                                                                                                        |
| `resources/js/lib/format.ts`                             | `minorToNairaInput`.                                                                                                                                                                                                 |
| `tests/Feature/Finance/FeeSchedulesScreenTest.php`       | New — 8 arms.                                                                                                                                                                                                        |
| `tests/Feature/Finance/FeeScheduleTest.php`              | Two new arms; two existing arms extended.                                                                                                                                                                            |
| `tests/fixtures/route-access-map.json`                   | Regenerated.                                                                                                                                                                                                         |
| `docs/handoff/u1-fee-schedules-brief.md`                 | Block 5 appended verbatim; header corrected.                                                                                                                                                                         |
| `docs/handoff/drives/2026-08-11-fee-schedules/`          | 11 drive screenshots.                                                                                                                                                                                                |

**No new permission was coined.** `app/Enums/Permission.php`, `PermissionGroup`, `grantsMap()` and
`rbac-grants-baseline.json` are untouched — confirmed by `git status`, which lists none of them.

### The fixture regeneration

The catalog diff was checked **before** running `rbac:sync`, because that command hard-deletes
`permissions` rows absent from the enum and takes both pivot tables with them:

```
db_rows=82 enum=83
EXTRA (would be HARD-DELETED by rbac:sync): 0
MISSING (would be created): 1
 + finance.bank-account.manage
```

`missing_rows` only, zero extra — the safe case per `docs/runbooks/rbac-grants-reconciliation.md`
§2a. `--fresh` was **not** used. Then:

```
$ php artisan rbac:sync
rbac:sync — roles/permissions synced; existing grants preserved (non-destructive).

$ php artisan rbac:derive-access
route-access-map.json written (378 routes).
```

The permission row landed and was granted to the two mapped roles (`admin`, `accounts_officer`);
`php artisan rbac:diff-grants` afterwards reports `CLEAN — grantsMap() and the live grants agree`,
`0 missing, 0 extra`.

The fixture diff is **purely additive, two entries**:

```
+    "GET /finance/fee-schedules": {
+        "auth": true,
+        "roles": [ "accounts_officer", "admin", "super_admin" ]
+    },
+    "PUT /api/v1/finance/fee-schedules/{feeSchedule}/draft": {
+        "auth": true,
+        "roles": [ "accounts_officer", "admin", "super_admin" ]
+    },
```

The second is **not this commit's route** — it is commit 1's draft-edit endpoint, which the fixture
never gained because nothing forced it to (§1b). It is correct and it is included; a reader
diffing this commit should not mistake it for a new route.

---

## 6. The browser drive

`APP_ENV=drive php artisan finance:seed-drive-fixture` → `portal_drive`, served on 8001, driven with
headless Chrome. The fixture's own count table printed the authoring slot per school:

```
+--------------+-------------------+-------+--------------+---------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts |
+--------------+-------------------+-------+--------------+---------------+
| A (school#1) | 1                 | 1     | 2            | 1             |
| B (school#2) | 1                 | 1     | 2            | 1             |
+--------------+-------------------+-------+--------------+---------------+
```

### Seat 1 — `maker@drive.test` (accounts_officer, school#1)

Raw, uncut, from the drive script:

```
  finance nav hrefs: ["/finance","/finance/opening-balances/import","/finance/bank-accounts","/finance/fee-schedules"]
  FILTER term options: ["|All terms","1|2026/2027 — First Term"]
  FILTER status options: ["|Any status","draft|Draft","pending_approval|With the ED","active|Active","superseded|Superseded","retired|Retired"]
  MODAL term options   (1): ["1|2026/2027 — First Term"]
  MODAL level options  (2): ["1|JSS 1","2|JSS 2"]
  MODAL account options(2): ["|Choose an account…","a27ab5dc-57c5-43f4-a08b-f192871f6eb9|Drive account · Drive Bank"]
  inline amount error visible: true
  row text: JSS 1 — First Term (drive) 2026/2027 — First Term JSS 1 Draft 2 ₦262,000.50 Edit Submit for approval
  collision message on screen: true
  EDIT prefill: [{"description":"Tuition","amount":"250000.50","account":"a27ab5dc-57c5-43f4-a08b-f192871f6eb9|Drive account · Drive Bank"},{"description":"Books","amount":"12000.00","account":"a27ab5dc-57c5-43f4-a08b-f192871f6eb9|Drive account · Drive Bank"}]
  row after edit: JSS 1 — First Term (drive) 2026/2027 — First Term JSS 1 Draft 2 ₦265,000.50 Edit Submit for approval
  row after submit: JSS 1 — First Term (drive) 2026/2027 — First Term JSS 1 With the ED 2 ₦265,000.50 With the executive director — the lines are frozen until the decision.
```

What that establishes, in order:

- The nav item is present and reachable (`01`, `04`).
- **The total is the server's sum, and it is right.** `250000.50 + 12000 = 262000.50`, rendered
  `₦262,000.50`; after the edit `250000.50 + 15000 = 265000.50`, rendered `₦265,000.50`. Nothing in
  the page computed either.
- **`nairaToMinor`'s null path is inline validation, not a crash** (`03`): typing `12,000` blocks the
  submit and marks that row, not the form.
- **The slot collision is actionable** (`05`) — the sentence in §4, in the modal and as a toast.
- **The edit prefill carries each line's destination account** (`06`) — the field commit 1 added,
  round-tripped through `minorToNairaInput` (`250000.50` back into the input unchanged, and `12000`
  normalised to `12000.00`, which `nairaToMinor` accepts).
- **Submitting freezes it** (`09`): status `With the ED`, and both buttons are gone in favour of a
  sentence.

Screenshots: `docs/handoff/drives/2026-08-11-fee-schedules/maker-01…09`.

### Seat 2 — `school-b@drive.test` (isolation, school#2)

```
  FILTER term options: ["|All terms","2|2026/2027 — First Term"]
  MODAL term options   (1): ["2|2026/2027 — First Term"]
  MODAL level options  (2): ["3|JSS 1","4|JSS 2"]
  MODAL account options(2): ["|Choose an account…","a27ab5dc-58b0-4fba-96e9-504f192c0530|Drive account · Drive Bank"]
```

**By count and by id, the two seats' selects are disjoint.** School A saw term `1`, class levels
`1,2`, account `…57c5-…`; School B saw term `2`, class levels `3,4`, account `…58b0-…`. The labels are
identical strings — `2026/2027 — First Term`, `JSS 1`, `Drive account · Drive Bank` — which is why the
ids matter: a label comparison here would have proved nothing. School B's schedule list is empty
(`isolation-01`), so School A's draft did not travel either.

Screenshots: `isolation-01`, `isolation-02`.

**Not driven:** the retire path and the supersede/re-price path. Both need an **active** schedule, and
a schedule can only become active through the ED's approval on `/finance/approvals` — a separate
seat and a separate screen. Both are exercised only as far as the button rules go (§7's status arms
are route/props-level, not lifecycle-level). This is the largest untested-by-eye area of the commit
and is stated rather than left to be discovered.

---

## 7. Watched reds — seven, each restored

Every guard added here was made to fail before being believed. Mutation, then the failure it produced.

| #   | Mutation                                                              | Failure                                                                                                                                                      |
| --- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| R1  | `::catalog()` returns `new self($schedule)` without `->withTotal()`   | `Failed asserting that null is identical to 325075.`                                                                                                         |
| R2  | `when($this->withTotal, …)` → `whenLoaded('items', …)`                | prefill key list: `+ 6 => 'total'`                                                                                                                           |
| R3  | delete the unique-currency guard in `scheduleTotal()`                 | `Expected response status code [201] but received 500` · `InvalidArgumentException: Currency mismatch: cannot combine NGN with USD` at `Money.php:241`       |
| R4  | remove `FINANCE_BANK_ACCOUNT_MANAGE` from `admin` in `grantsMap()`    | `Role(s) [admin] hold finance.fee-schedule.manage but not finance.bank-account.manage. …`                                                                    |
| R5  | route binds `ActiveSchool::getOrFail()` (the model) instead of `->id` | `Property [terms] does not have the expected size. Failed asserting that actual size 0 matches expected size 2.`                                             |
| R6  | `canPropose = true` in the page                                       | `…renders Submit-for-approval and Retire without asking for finance.fee-schedule.change.submit. Role(s) [admin] reach this screen and hold no such ability…` |
| R7  | delete the sidebar item                                               | `A finance page is registered, permission-gated and reachable from NO menu: /finance/fee-schedules.`                                                         |

R5 is the opening-balance scar reproduced deliberately: the route returns **200** in that state, and
only the prop assertion catches it.

Working tree confirmed restored after each (`git status --short` lists exactly the nine intended
paths; `grep` confirms `canPropose`, the two `FINANCE_BANK_ACCOUNT_MANAGE` entries and
`getOrFail()->id` are back).

---

## 8. The gate — two runs, reported as two

### Run 1 — **FAIL**, one step

```
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✗ test-ratchet

       ratchet: 1 NEW test failure(s) not in the baseline (regression):
         ✗ tests/Feature/Quality/PestNegatedExpectationMessagesTest.php::it no test passes a custom failure message to a negated Pest expectation

✗ quality: FAIL (1): test-ratchet
```

Steps 1–13 passed. The regression was **mine**: two arms in `FeeSchedulesScreenTest.php` wrote
`expect($x)->not->toBeEmpty($message)`, and Pest discards a custom message on a negated matcher — the
exact trap that lint exists for, and which `RouteAccessParityTest` also carries a note about. Both
rewritten as `expect($x->count())->toBeGreaterThan(0, $message)`, which keeps the sentence and puts
the count in the output.

A third instance of the same family bit earlier and is worth recording because it produced a
_misleading_ red rather than a caught one: `expect($page)->toContain($needle, $message)` — Pest's
`toContain` is **variadic**, so the message was asserted as a second substring and the failure
printed 47 kB of TSX. Rewritten as `expect(str_contains(...))->toBeTrue($message)`.

### Run 2 — **PASS**, 14/14

```
[1/14] dependency integrity …                        ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form …              ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint) ✓ lint-changed
       Pint: no changed PHP files
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)           ✓ tsc-ratchet
[5/14] frontend build (vite …)                       ✓ build
[6/14] authorization guard …                         ✓ authz-lint
[7/14] boundary lint (§17.2)                         ✓ boundary-lint
[8/14] grants-convergence lint …                     ✓ grants-convergence-lint
[9/14] money lint …                                  ✓ money-lint
[10/14] runtime-zero lint …                          ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)    ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)                   ✓ arch
[13/14] static analysis (Larastan level 5 …)         ✓ larastan
[14/14] tests (failure ratchet …)                    ✓ test-ratchet

✓ quality: PASS — per-push floor.
```

(The step names above are cut to fit the table; the ✓/✗ column and the step count are verbatim.)

**Step 3 is a false green and the brief predicted otherwise.** Block 5's gate note says "this commit
touches TypeScript, so tsc-ratchet and lint-changed are live in a way they were not for commit 1".
`tsc-ratchet` was live. `lint-changed` was **not**: it reports "no changed PHP files / no changed
frontend files" on an uncommitted tree — the open ticket
`docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`. Pint, Prettier and ESLint were
therefore run by hand against the explicit file list (per CLAUDE.md's empty-list guard), and each was
green before run 2:

- `pint <5 explicit files>` → fixed import ordering in two files, then clean.
- `prettier --check` on the three frontend files → "All matched files use Prettier code style!".
- `eslint` on the three → 0 problems (two real findings fixed first: a missing
  `react-hooks/set-state-in-effect` disable on the accounts effect, and a padding-line rule).
- `tsc --noEmit` → no diagnostics in either changed frontend file.

---

## 9. What I did not verify

- **The retire and supersede lifecycles end to end.** Both need an ED approval to produce an `active`
  schedule; not driven (§6).
- **The nested-422 per-row rendering under a server bag.** The parser is covered by the code and the
  inline (client-side) path was driven, but no drive produced a real `items.N.field` bag from the
  server — the seeded fixture makes every field valid, and a hand-forced one would have tested the
  provocation, not the flow.
- **Any seat other than the two driven.** No `admin`-role drive, so §2a's 403-button reasoning is
  argued from the grant map and asserted in a test, not seen.
- **The suite's non-determinism.** ADR 0053 records byte-identical code producing both PASS 14/14 and
  a red. Run 2's green is one observation.
- **`super_admin` on this screen.** It appears in the regenerated access map's role set for the new
  route (via `Gate::before`; `manage` is not a checker ability, so ADR 0040's exclusion does not
  apply). Not driven — the local copy has no super_admin holder and minting one is a bigger act than
  the screenshot is worth.
- **Anything about the production database.** All grant facts here were derived from the local copy
  `portaa10_portal` and from `RbacSeeder::grantsMap()`.

---

# Remediation — 2026-08-12 (cold review, amended into the same commit)

Two findings from a cold review against a fresh clone. One fix, two tickets. The branch was rebased
onto `origin/staging` @ `b403ca4` before this work; the rebase was conflict-free and is not the
subject here.

## R1 — the edit form re-denominated money

**The defect.** An edit replaces the item set **wholesale** — `EditFeeScheduleDraft` deletes and
re-inserts — so a field the form does not send back is not left alone, it is rewritten from whatever
default the write path supplies. The page carried `amount` and `bank_account_id` off each item and
**not** `currency`. `items.*.currency` is `sometimes`, not `required`
(`HasFeeScheduleItemRules`), so the omission is not a 422: `CreateFeeSchedule` reads
`$item['currency'] ?? Money::DEFAULT_CURRENCY` and writes NGN. A USD line survived its edit with the
same minor units and a different denomination.

**Why the cheaper remedy was not taken.** Refusing the edit when `total === null` catches only the
_mixed_-currency schedule. `scheduleTotal()` returns a perfectly valid non-null Money for a schedule
whose items are **all** USD, so that guard never fires on the more likely shape — and editing a
description on such a schedule silently re-prices every line into naira. The round-trip closes both;
the guard closes neither reliably.

**The fix**, in `resources/js/pages/admin/finance/fee-schedules.tsx`:

- `ItemRow` gains `currency`.
- `openFrom()` reads it in — `currency: item.amount.currency`, beside `bank_account_id`.
- `submit()` posts it back — `currency: row.currency`, on all three write paths.
- A `DEFAULT_CURRENCY` constant so the **create** path states NGN explicitly rather than relying on
  the server default.

The two carried fields are commented as one argument rather than two, because they are one: an
operator opening a draft to fix a typo must not have the fields they did not touch rewritten
underneath them. `bank_account_id` is the field commit 1 added for exactly that; `currency` is the
same defect one field over and worse, because an omitted bank account is a 422 and an omitted
currency is silent.

**No new UI.** The currency is round-tripped, not editable. The page still cannot author a non-NGN
item; it can no longer destroy one.

### The arms, and their reds

Two arms, because one is not enough and the reason is worth stating: the HTTP arm builds its own
body, so it stays green if the page stops sending the field.

| #   | Arm                                                                                                                                                                                                   | Mutation                                                                                          | Failure                                                                                                                                     |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| R8  | `the Edit body the page sends preserves each item's CURRENCY` — authors NGN 250000 + USD 90000, then issues the page's exact Edit body, built from the catalog response the way `openFrom()` reads it | drop `'currency' => $item['amount']['currency']` from the item map (this **is** the pre-fix page) | `An edit re-denominated the schedule…` · `Expected 1 => 'USD'`, `Actual 1 => 'NGN'`                                                         |
| R9  | `the page posts the currency back` — asserts the two round-trip lines are in the file that performs them                                                                                              | delete `currency: row.currency` from the page                                                     | `…no longer posts each item's currency back. items.*.currency is sometimes, so the omission is not a 422 — it is a silent re-denomination.` |

R8 also asserts the amounts and descriptions are untouched, so it distinguishes a **re-denomination**
from a re-pricing, and that `total` is still null afterwards.

## The measurement — how many callers post items with no currency

Asked for as a number, not a recommendation. **The rule was not changed.**

**What `required` would actually govern.** Only bodies passing through a FormRequest — an in-process
`app(CreateFeeSchedule::class)->handle(...)` call never sees `items.*.currency`. So the blast radius
was measured at `HasFeeScheduleItemRules::itemSpecs()`, temporarily instrumented, over a full suite
run, attributing each spec to its first `tests/` or `database/` frame:

```
HTTP-borne fee-item specs reaching a FormRequest:  29
  carrying a currency:                              7
  NOT carrying one:                                22
```

**22 specs, 11 call sites, 3 files:**

```
  8  tests/Feature/Finance/EditFeeScheduleDraftTest.php:203
  2  tests/Feature/Finance/EditFeeScheduleDraftTest.php:166
  2  tests/Feature/Finance/EditFeeScheduleDraftTest.php:122
  1  tests/Feature/Finance/EditFeeScheduleDraftTest.php:302
  2  tests/Feature/Finance/FeeScheduleTest.php:305
  2  tests/Feature/Finance/FeeScheduleTest.php:203
  1  tests/Feature/Finance/FeeScheduleTest.php:530
  1  tests/Feature/Finance/FeeScheduleTest.php:524
  1  tests/Feature/Finance/FeeScheduleTest.php:519
  1  tests/Feature/Finance/FeeSchedulesScreenTest.php:261
  1  tests/Feature/Finance/FeeSchedulesScreenTest.php:259
```

**Zero non-test callers.** Enumerated rather than assumed:

- The only production callers of the two write Actions are the three `FeeScheduleController` methods
  (`store`, `editDraft`, `supersede`), whose items come from HTTP.
- The only HTTP client in the repository is `fee-schedules.tsx`, whose three write calls now send a
  currency on every item.
- **The drive fixture authors no fee schedule at all** — `DriveFinanceStates` seeds bank accounts,
  invoices, payments and credit notes; `grep FeeSchedule` over `DriveFinanceStates`,
  `DriveCastSeeder` and `SeedDriveFixture` returns only comments about the screen.
- No factory or seeder constructs a fee item.

**A wider probe, for contrast, and why the smaller number is the right one.** Instrumenting the two
**Actions** instead (which catches in-process callers too) saw **110** specs, **103** without a
currency, across **22 sites in 8 files** — the extra 81 are direct `handle()` calls that no validation
rule can reach. Reported so the two figures are not confused: 103 is "how many item specs in the suite
omit a currency"; **22 is how many would start failing** if the rule became `required`.

**Two limits of the measurement, stated.** `itemSpecs()` runs only after validation passes, so a body
already rejected for another reason is not counted — such a body would gain a second error key rather
than a new failure. And the count is of specs the suite actually executes; a call site behind a
skipped test would not appear.

## Ticket 2 — the authority split

`docs/handoff/tickets/fee-schedule-authority-split.md`. Both halves in one ticket:

- `admin` holds `FINANCE_FEE_SCHEDULE_MANAGE` (`RbacSeeder.php:235`) and **not** `…CHANGE_SUBMIT` —
  the screen, Create, Edit and Re-price, with no way to publish or retire what it authors.
- `accounts_supervisor` holds `…CHANGE_SUBMIT` (`:456`) and **not** `…MANAGE` — it can propose a
  publish and cannot reach the screen a schedule is proposed from.

`accounts_officer` (`:381`, `:389`) is the only seeded role holding both. The ticket states the four
available rulings — grant `admin` the submit ability, drop `admin` from the page's gate, grant
`accounts_supervisor` the manage ability, or leave both and record why — and names the
grants-convergence consequence of any grant edit. Ticket and not fix: the map is a deliberate
artefact and this is the project lead's ruling, alongside the ED-role authority decisions.

## Also filed — the fresh-clone setup gap

`docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md`. `public/build/` is gitignored,
`bin/quality` builds it at step 5, and a standalone suite run in a clone never does — so every arm
that renders an Inertia page 500s with `ViteManifestNotFoundException` (or, with a stale manifest,
`ViteException: Unable to locate file in Vite manifest`). The ticket names the failure and the cause,
records that fabricating a manifest is **not** a safe workaround (it skips the guarantee step 5
exists for), and says the remedy belongs in `.claude/skills/finance-review/SKILL.md` as a setup step.
That skill was **not** edited here — it is not this branch's business.

## What changed in this remediation

| File                                                                | What                                                                                                |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `resources/js/pages/admin/finance/fee-schedules.tsx`                | `ItemRow.currency`, `DEFAULT_CURRENCY`, the prefill read, the submit write, and the shared comment. |
| `tests/Feature/Finance/FeeSchedulesScreenTest.php`                  | Two arms (R8, R9).                                                                                  |
| `docs/handoff/tickets/fee-schedule-authority-split.md`              | New.                                                                                                |
| `docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md` | New.                                                                                                |

No production PHP changed. `items.*.currency` is untouched. The measurement probes were temporary and
removed; `git status` after restoring them listed only the two files above.

---

# Fix 2 — `items.*.currency` becomes `required` (2026-08-12, amended into the same commit)

Ruled on the measurement in the previous section: 22 HTTP-borne specs without a currency, 11 call
sites, 3 files, all tests, zero non-test callers.

## The rule

`app/Finance/Http/Requests/Concerns/HasFeeScheduleItemRules.php` —
`'items.*.currency' => ['sometimes', …]` → `['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/']`.

The existing comment was **extended, not replaced**: the two halves of that one line catch two
different failures, and a reader who finds only one explained will read the other as redundant.

- The **regex** stops a MALFORMED currency — without it, `Money::fromKobo` throws inside the
  transaction and the caller gets a 500 instead of a 422.
- **`required`** stops an ABSENT one, which is the worse failure because it is silent. An edit
  replaces a schedule's items wholesale, so an omitted currency did not leave a USD line alone: it
  re-inserted it as NGN with the same minor units, and the schedule then reported a total that is not
  an amount of anything.

## `?? Money::DEFAULT_CURRENCY` stays in both Actions, and that is not an oversight

`CreateFeeSchedule` and `EditFeeScheduleDraft` are unchanged. The sentence is now in the rule's
comment, where the contradiction is visible, rather than only here:

> **Required at the HTTP edge, defaulted at the Action.** This rule governs only bodies that pass
> through a FormRequest; both Actions are also called in-process — the suite alone does so around a
> hundred times — and those callers never see a validation rule, so the `??` in each Action is what
> keeps a direct `handle()` call working.

That is the previous section's second measurement doing the work: **110 item specs reached the
Actions, 103 without a currency**, and the 81 that are not HTTP-borne are direct `handle()` calls no
rule can reach. Deleting either the `required` or the `??` on the strength of seeing the other would
break one of the two caller classes.

## The eleven call sites — and the six literals behind them

**11 request sites updated, and they are served by 6 distinct item literals.** Both numbers, because
"11 edits" would be false: three of the sites share one helper and three more share one variable.

| Literal                                                           | Serves (probe line numbers) |
| ----------------------------------------------------------------- | --------------------------- |
| `efsdBody()` helper — `EditFeeScheduleDraftTest.php:107-110`      | `:122`, `:166`, `:203`      |
| local `$body` closure — `EditFeeScheduleDraftTest.php:279`        | `:302`                      |
| SMOKE store body — `FeeScheduleTest.php:206-207`                  | `:203`                      |
| index-total arm body — `FeeScheduleTest.php:310-311`              | `:305`                      |
| write-routes-shape `$items` — `FeeScheduleTest.php:508`           | `:519`, `:524`, `:530`      |
| slot-collision `$body` closure — `FeeSchedulesScreenTest.php:256` | `:259`, `:261`              |

`3 + 1 + 1 + 1 + 3 + 2 = 11`. Confirmed against the measurement's site list, which named exactly those
eleven. Each edit is the same one: `'currency' => 'NGN'` added to the item array. No assertion was
weakened and no arm's expectation changed.

Two sites in `EditFeeScheduleDraftTest` that the probe did **not** name (`:283`, `:290`, `:295` — the
foreign-term/class-level 422 arms) share the literal at `:279` and so were updated with it. They never
reached `itemSpecs()` because they 422 earlier, which is exactly the measurement limit recorded in the
previous section; they would otherwise have gained a second error key without failing.

## The new red — R10

Added beside the existing malformed-currency arm, in the file that already owns this rule:
`tests/Feature/Finance/NestedCurrencyValidationTest.php`, `fee schedule: an item with NO currency at
all is a 422 naming the field`.

| #   | Mutation                                                                                                       | Failure                                                                                                                                  |
| --- | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| R10 | restore `'sometimes'` on the currency rule — the state this commit leaves behind, so the red **is** the defect | `Failed to find a validation error in the response for key: 'items.0.currency'` · `Expected response status code [422] but received 201` |

The arm covers both request classes — POST `/fee-schedules` and PUT `…/draft` — because they share
the trait and the edit path is the one the re-denomination actually travelled; a rule that bit only on
create would have left it open. It also asserts the same body **with** a currency is accepted, so the
422 is the missing field and not something else in the payload.

## R9 is now partly redundant, and stays

R10 makes the server refuse a body with no currency, which is a stronger guarantee than R9's check
that the page sends one. R9 is kept deliberately: the two pin different things and the commit should
carry both.

- **R9 — client convention.** The page reads each item's currency into the form and posts it back.
  Without it the page would send no currency and now get a 422 — a broken screen instead of a silent
  re-denomination, which is better but still broken.
- **R10 — server mechanism.** The absence is refused regardless of which client sent it, including a
  future second caller and curl.

Removing R9 on the strength of R10 would leave the page's round-trip pinned by nothing, and the first
symptom would be an operator unable to save an edit.

## Gate and reds

`bin/quality` — see the run below. All ten reds re-run as the last action before committing.

**One correction to a check made during this work**, recorded because it was stated before it was
right: I first reported the suite's six failures as "not baselined". They are — all six are in
`tests/ratchet-baseline.txt` (4 × `ActivityLogApiTest`, 2 × `GuardianProfileTest`). The grep that said
otherwise searched the pest-mangled method names against a file that stores human test names. Step 14
compares against that file and passes; nothing here regressed the suite.

## The ten reds, re-run as the last action

All ten, not just R10: the earlier nine were last watched before this rule change, and `required`
alters what some of them are exercised against.

| #   | Mutation                                               | Failure                                                                                                   |
| --- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| R1  | `::catalog()` drops `->withTotal()`                    | `Failed asserting that null is identical to 325075.`                                                      |
| R2  | `when($this->withTotal, …)` → `whenLoaded('items', …)` | prefill key list: `+ 6 => 'total'`                                                                        |
| R3  | delete the unique-currency guard in `scheduleTotal()`  | `Expected 201, received 500` · `InvalidArgumentException: Currency mismatch: cannot combine NGN with USD` |
| R4  | remove `FINANCE_BANK_ACCOUNT_MANAGE` from `admin`      | `Role(s) [admin] hold finance.fee-schedule.manage but not finance.bank-account.manage…`                   |
| R5  | route binds the School MODEL instead of `->id`         | `Property [terms] does not have the expected size… 0 matches expected size 2.`                            |
| R6  | `canPropose = true`                                    | `…renders Submit-for-approval and Retire without asking for finance.fee-schedule.change.submit…`          |
| R7  | delete the sidebar item                                | `A finance page is registered, permission-gated and reachable from NO menu: /finance/fee-schedules.`      |
| R8  | drop `currency` from the page-shaped Edit body         | **`Expected 200, received 422`**, the bag naming `items.*.currency` — see below                           |
| R9  | delete `currency: row.currency` from the page          | `…no longer posts each item's currency back.`                                                             |
| R10 | restore `'sometimes'` on the currency rule             | `Expected 422, received 201`                                                                              |

**R8's failure mode changed with this fix, and the change is the point.** In the previous section it
failed on the assertion — `Expected 1 => 'USD'`, `Actual 1 => 'NGN'` — because the body was accepted
and the schedule was re-denominated. It now fails a step earlier, with a 422, because `required`
refuses that body outright. Recorded so a future reader reproducing R8 against this tree is not
surprised into thinking the arm broke: **the mutation is still caught, and it is now caught by the
server rather than by an assertion about the damage.** The same subsumption is what makes R9 partly
redundant, above; R8 keeps its assertions on amounts, descriptions and the null total, which the 422
path never reaches, so the arm is not merely a duplicate of R10.

Tree after the sweep is exactly the Fix 2 change set — the rule, four test files, and this report.
