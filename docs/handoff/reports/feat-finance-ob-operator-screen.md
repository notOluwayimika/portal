# Implementation report — §9 step 5b-iii, the opening-balance operator screen (U12b)

## Headline

**Done.** A bursar-office operator can download the template, upload a WCBS extract with a control
total, read the findings, and submit the batch for approval — and the whole flow was driven in a
real browser, which is where two defects turned up that every test was green through.

Branch `feat/finance-ob-operator-screen`, base `367a966` (`origin/staging`, #222 merged).

**Read these first, because they change how the rest of this report should be weighed:**

1. **The browser drive found two real defects.** Both would have shipped. Both are now fixed and
   pinned by assertions that go red without the fix. Details under *The browser drive*.
2. **§9 is now closed end to end.** Before this commit nothing could reach `submitted`, so the queue
   5a built and 5b-ii made decidable rendered zero rows on every real database. The two halves met
   for the first time in the drive.
3. **Approve was NOT pressed**, deliberately, and the condition for pressing it is restated below.

## Deviations from the brief

**One, and it is the largest single decision in the commit: the validator was extracted into a
service.** The brief bounded this at "one screen, its controller methods, its request, its job, and
their tests", and named four things that trip the fuse — a schema change, a new table, a new
permission, a change to the decision surface. An extraction is none of those, so the fuse did not
fire; but it is a ~400-line move through a heavily-documented command and it deserves to be named
rather than found.

**Why it was not optional.** The screen must reach the same verdict as
`finance:import-opening-balances` on the same file. There are exactly two ways to do that — one
implementation or two — and two is how a data team ends up holding a console run and a screen run
that disagree about one extract. That is the defect R13 already refuses one level up, where the
`COLUMNS` map drives both the template and the validator so they cannot drift.

`App\Finance\Services\OpeningBalanceFileValidator` now owns `read()` and `stage()` plus the three
format constants; the command keeps option parsing, the batch insert and the operator report; the
job calls the same two methods against a batch the controller already created. **The oracle for
"behaviour unchanged" is the command's own 47KB of coverage, which was not edited** — see *Proof*.

Two smaller judgement calls, stated because they were choices:

- **The three format constants moved and are ALIASED on the command**
  (`public const COLUMNS = OpeningBalanceFileValidator::COLUMNS;`). The format's owner is the thing
  that parses it. They are re-exported because the template export and two test files already name
  `ImportOpeningBalances::COLUMNS`, and a rename inside a commit about a screen is a rename nobody
  asked for. A PHP constant expression referencing another class's constant is the same value, not a
  copy, so there is nothing to drift.
- **`LIST_LIMIT` did not move.** The other three describe the FILE; that one describes how much of a
  list fits on a terminal, and the screen has no such limit.

## Contradictions of the premise

**None.** The brief's first amendment said the false premise it had carried was now true, and it is:
`routes/endpoints/finance.php` carries the approve/reject routes, `OpeningBalanceBatchPolicy` exists,
`approval-feeds.ts` gives the `opening_balance` entry real `decide` urls and the confirmation. All
read, not assumed — and then driven in a browser, which is stronger than reading.

## What changed

| File | What |
|---|---|
| `app/Finance/Services/OpeningBalanceFileValidator.php` | **New.** The extracted validator + the three format constants. |
| `app/Finance/DTOs/OpeningBalanceValidationResult.php` | **New.** What one pass observed. Its docblock is where the privacy rule is enforced — a field it cannot hold is a field no surface can leak. |
| `app/Finance/Jobs/ProcessOpeningBalanceImport.php` | **New.** `SchoolAware`, `tries=1`, `timeout=3600`; guardian's job shape. |
| `app/Finance/Http/Requests/StoreOpeningBalanceImportRequest.php` | **New.** File + control total + closing term + cutover date + reference. |
| `app/Finance/Http/Controllers/OpeningBalanceBatchController.php` | `store`, `show`, `index`, `report`, `submit` + a private `serialize()`. |
| `app/Finance/Console/ImportOpeningBalances.php` | Delegates. `report()` takes the result object instead of sixteen parameters. |
| `routes/endpoints/finance.php` | Five maker routes, all on `finance.opening-balance.submit`. |
| `routes/web.php` | The screen, gated on the maker ability, passing `terms` as props. |
| `resources/js/pages/admin/finance/opening-balances/import.tsx` | **New.** The screen. |
| `resources/js/services/opening-balance-imports.ts` | **New.** Transport; every url off wayfinder, never a literal. |
| `tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php` | **New.** 10 arms. |
| `tests/Feature/Finance/OpeningBalanceDecisionSurfaceTest.php` | PROOF H widened from 4 routes to all 9. |

### No schema change, and the two columns that were NOT added

`finance_opening_balance_batches` already is the job record — `status`, `findings`, `row_count`,
`file_row_count`, `control_total`. The controller inserts it in `draft`, which is the enum's own
words: *"Inserted, not yet run to completion."*

- **No `file_path`.** The upload is stored at a path DERIVED from the batch uuid
  (`ProcessOpeningBalanceImport::pathFor`), so the controller and the job compute the same location
  from the same fact.
- **No `report_path`.** The report is rendered on demand from `findings` and the staged rows. A
  stored artifact would be a second copy that can disagree with the screen.

A job crash is distinguished from a bad file: `file_unreadable` is a fact about their extract and
joins the same `findings` JSON; `import_failed` is a fact about us and is named separately so nobody
sends a data team hunting for our defect.

### PROOF H widened, and why that is a finding about the arm

5b-ii's route-ability pin filtered on `'api/v1/finance/opening-balance-batches/'` — **with a trailing
slash** — which excluded the two new bare-collection routes, leaving the maker's UPLOAD route as the
one opening-balance endpoint whose ability nothing pinned. The prefix is now slash-free and all nine
routes are asserted as an exact set.

## Proof

### The extraction changed no behaviour

```
DB_DATABASE=portal_testing ./vendor/bin/pest \
  OpeningBalanceImportTest OpeningBalanceImportTemplateTest OpeningBalancePostingTest \
  OpeningBalanceApprovalGateTest OpeningBalanceDecisionSurfaceTest
{"tool":"pest","result":"passed","tests":92,"passed":92,"assertions":492}
```

Those five files were **not edited** (beyond PROOF H's route set). They drive the command, not the
service, so a verdict changed by the move would fail there.

### The operator screen

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php
{"tool":"pest","result":"passed","tests":10,"passed":10,"assertions":71}
```

### bin/quality — raw, unedited (ANSI colour codes stripped; nothing else removed)

```
quality gate — base 367a966

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

## The watched red

### The four the brief named

| # | Mutation | Result |
|---|---|---|
| 1 | L1 stamps only the arithmetic row, not the whole group | **RED** — `PROOF — an L1 failure … rejects the WHOLE row-group`, line numbers `[2,3]` no longer both present |
| 2 | L2's `control_total_mismatch` suppressed | **RED** — the L2 arm, and the not-validated-submit arm with it |
| 4 | the maker gate stripped off the status route | **RED** — `Expected response status code [403] but received 200` |

### RED 3 — the one that needed three attempts to make fail

`SubmitOpeningBalanceBatch` checks `status === Validated` **twice**: once before the transaction and
once on the locked re-read. Removing either alone leaves the arm green.

```
RED 3a — the LOCKED re-read removed, the pre-transaction check left   GREEN — the other layer still refused
RED 3b — the PRE-TRANSACTION check removed, the locked re-read left   GREEN — the other layer still refused
RED 3c — BOTH removed                                                 RED — Expected 422 but received 200
```

So the arm pins *"a non-validated batch cannot be submitted"* and **not** *"this particular check
exists"*. That is the same shape as 5b-ii's RED 2 and it is the honest description of what the
assertion buys.

### RED 5 — the terms prop, added because the drive found it

```
it serves the operator screen to a maker, with the terms it needs, and 403s a checker
Property [terms] does not have the expected size.
Failed asserting that actual size 0 matches expected size 1.
```

## The browser drive

The fourth deferral, come due. `user#3451` (accounts_supervisor → maker) and `user#3452`
(executive_director → checker) in `school#1`; Chromium via Playwright, installed **outside the
repository** so `node_modules` was untouched.

**No user in the copy held any of the three seats** — `accounts_officer`, `accounts_supervisor` and
`executive_director` have the grants, and `model_has_roles` had zero holders of any of them in either
school. Two users were created and granted through `grantSchoolAccess` inside a transaction, as the
brief permits, rather than skipping the drive for a fourth time.

### What the drive found — two defects, both green in every test

**1. The term select was EMPTY, so the form could never be submitted.**
`routes/web.php` bound `ActiveSchool::getOrFail()` — which returns a **School model**
(`ActiveSchool.php:66`) — into `where('school_id', …)`, where `id()` returns the int. It matched
nothing. The page rendered, returned 200, and every assertion passed, because the tests asserted the
screen *renders*. Fixed to `->id`, and `assertInertia(... ->has('terms', 1))` now pins it.

**2. The upload response blanked the screen with a TypeError.**
`store` returned the batch WITHOUT `rejected_rows`, while the page holds one "active batch" object
and immediately reads `active.rejected_rows.length`. The browser console showed
`Cannot read properties of undefined (reading 'length')`. Fixed so `store` and `submit` answer in the
same shape as `show`, and `assertJsonStructure(['rejected_rows', 'rejected_rows_truncated'])` pins it.

Neither was reachable by a server-side assertion. This is the fourth commit in this feature to defer
the drive and the first to run it; it paid immediately.

### What was seen

**The template, fetched THROUGH THE APP by clicking the button** — 5b-i shipped it and linked it
from nowhere; this is the first time it has been fetched any way other than a test harness:

```
• template download fired from the BUTTON: opening-balance-import-template.xlsx
•   download failure: none
•   saved, 10763 bytes           (magic 504b0304 — a real xlsx)
```

**The findings, rendered** (an extract with a deliberate L1 failure and a wrong control total):

```
•     REJECTED
•     control_total_mismatch
•     student_total_mismatch — Σ of this student's 2 fee-type balance(s) = 145000.00
                               but student_total_balance = 145000.01 (Δ -1 kobo).
•     student_total_mismatch — (the SAME student's second line)
```

Both of the student's lines carry it — L1 rejects the row-group — and the batch-level
`control_total_mismatch` sits apart from them. **The privacy discipline held on a rendered page**:
line numbers, admission numbers and both sides of the failed check; no name anywhere.

**A clean extract, validated and submitted**, then as the checker:

```
• queue row: OPENING BALANCE  Batch · DRIVE-DIALOG-1786224119678 — Cutover Drive — 8/8/2026
             ₦220,000.00  Approve Reject
```

That is **5a's TypeScript-composed subject string read off a rendered page for the first time**, and
the control total through `formatNaira`.

**The irreversibility dialog — opened, read, CANCELLED:**

```
DIALOG ¶ Approving batch DRIVE-DIALOG-1786224119678 posts its opening balances into the subledger
         immediately — a charge for every fee type a student owes, and a credit for every student
         in credit.
DIALOG ¶ The batch states a control total of ₦220,000.00 — the figure the uploader read off WCBS
         and attested to. Check it against WCBS before you approve.
DIALOG ¶ This is irreversible. Posted balances cannot be un-posted, deleted or moved to another
         school, and this school may never post a second batch.
CONFIRM BUTTON: 1        (labelled "Post opening balances", not "Confirm")

after cancel — dialog present: 0
after cancel — row still there: 1
```

**Then REJECT**, which posts nothing: the row left the queue.

**APPROVE WAS NOT PRESSED.** The condition inherited from #221's report is unchanged and is restated
here so the next commit inherits it in turn:

> Approve stays undriven until there is a database we are willing to spend. G1/G1b make the first
> approval consume that school's single posting slot permanently — no un-post, no delete, no move —
> and the local database is a production copy that other findings are derived from.

### Database observations, under the privacy rule

Four migrations were pending locally and were applied (`2026_08_08_110000`, `2026_08_08_120000`,
`2026_08_09_100000`, `2026_08_09_110000`). The grants convergence reported
`already aligned — no grants changed, no activity rows written`.

The drive left, in `school#1`: **1 validated and 5 rejected** batches, all named `DRIVE-*`. Nothing
posted; `finance_ledger_transactions` and `finance_payments` were not written by any of it. The two
batches the drive left `submitted` were rejected afterwards through the real Action, so the checker's
queue is clean. `rbac:sync --fresh` was **not** run.

## Not done

- **Approve is still undriven**, by the standing condition above.
- **No `report_path` / no stored report artifact** — rendered on demand instead. If a future
  requirement needs the report as it stood at validation time rather than as it renders now, that is
  a schema decision to argue, not a field to slip in.
- **The screen does not paginate its rejected rows.** It shows the first 200 and says so
  (`rejected_rows_truncated`); the full set is in the CSV. A cutover with thousands of rejects is a
  cutover that should be fixed in WCBS, not paged through.
- **The two drive users remain in the local copy** (`user#3451`, `user#3452`, `school#1`). They exist
  only there; nothing in this diff creates them. Flagged so they are not mistaken for real seats.
- **`page.request.get()` on an API route returns 401** in Playwright — no `Referer`, so Sanctum does
  not treat it as stateful. That is a harness artifact, not a defect; the real button click works,
  which is what the drive asserts.

## Findings raised, not fixed

### Ticket — a loop-driven arm whose body never executes (as instructed, recorded not built)

The vacuous-arm class raised on the previous branch has no general detector short of mutation
testing. **One subclass is cheap**: an arm whose assertions live inside a `foreach` that can iterate
zero times asserts nothing, and nothing says so.

**This repository already invented the convention and applies it inconsistently:**

- `tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php` — *"every decidable entry wires its
  approve and reject…"* guards it: `expect($decidable)->toBeGreaterThan(0, 'No entry was parsed as
  decidable — the loop above asserted nothing.')`
- `tests/Feature/Rbac/RouteAccessParityTest.php:67-88` — *"keeps the deviation list honest"* does
  **not**. `ACCESS_DEVIATIONS` is empty and legitimately reaches empty, so the loop body never runs.
  That is precisely why the rewrite in the previous commit could not be made to fail.

**Gateable by the parser already shipped** in `PestNegatedExpectationMessagesTest`: `token_get_all`,
find each `foreach` in `tests/` whose body contains an `expect(`, and require a non-vacuity assertion
on its counter or subject. The same honesty caveat as that gate applies — the rule would be a
definition, and its first census would be a property of the rule.

**Severity: ticket.** No money, no isolation; a test that promises coverage and delivers none.

### Others

- `resources/js/services/guardian-imports.ts` writes its API paths as string literals while this
  commit's service module takes every url from wayfinder. The older pattern is a second copy of a
  route, and this feature has already paid once for a route nothing linked. **ticket.**
- `ActiveSchool::getOrFail()` returning a model and `id()` returning an int is a real footgun — the
  defect above is what it looks like in practice, and it fails silently rather than loudly, because
  Eloquent binds the model without complaint. Worth a named accessor or a type-level guard.
  **ticket.**
