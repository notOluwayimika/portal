# Implementation report — RBAC_FAIL_CLOSED_MODELS, the finance batch

## Headline

**Built, committed, NOT pushed — one decision is yours before it can go.** The ten finance
transactional models fail closed, the list is a versioned default in `config/rbac.php` rather than an
env var a deploy can forget, and 13 of `bin/quality`'s 14 steps are green.

The fourteenth is a single test failure, and it is this commit's intended behaviour arriving
somewhere I was told not to work around it:

```
ratchet: 1 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Finance/PaymentRecordGateTest.php::it super_admin is not gate-blocked on either payment route (record is not a checker ability)
```

Branch `feat/rbac-fail-closed-finance`, base `cdd224a` (`origin/staging`, #225 merged).

**This is full-review tier** — RBAC, `school_id` isolation, and a platform-wide read-behaviour
change. Subagent review attached; recommend a cold session before merge.

## The thing to read first — a correction to #225

**The local copy has ONE super_admin holder, `user#3197`. My report on #225 said zero, and that was
wrong.** The cause is a query bug of mine, not a change in the data:

```
no model_type filter                       : 1
where model_type = App\Models\User         : 1
where model_type = App\\Models\\User       : 0      <-- what I ran in #225
distinct model_type values                 : ["App\\Models\\User"]
```

`finance-context` records *"Filter `model_type = 'App\\\\Models\\\\User'` — doubled backslashes in
raw SQL."* That is true **of raw SQL**, where the backslashes are consumed by the SQL string parser.
I applied it to a query-builder **binding**, which takes the literal PHP value — so the doubled form
matched nothing and returned a confident zero. #225's "minting a platform authority to take a
screenshot is a larger act than the screenshot is worth" reasoning was therefore answering a question
that did not need asking.

`user#3197` has `users.school_id = NULL` and their `super_admin` role row has `school_id = NULL` —
i.e. exactly the no-school super_admin seat this commit changes behaviour for.

## STEP 1 — the enumeration, before anything was flipped

`app/Finance/Models/` holds **16** models, not 17. The brief's own two lists — ten transactional and
six catalog — sum to 16, so the 17 is a slip in the prose, not a missing file. All 16 use
`BelongsToSchool`.

| Reader class | Examined | Reads a finance model with NO context | Evidence |
|---|---|---|---|
| Scheduled commands (`routes/console.php`) | 6 entries (5 real + `inspire`) | **0** | `finance:reconcile-accounts` and `finance:audit-ledger-coherence` iterate `School::query()->get()` inside `ActiveSchool::runFor` (`ReconcileAccounts:56-57`, `AuditLedgerCoherence:100-101`). `finance:audit-duty-separation` and `finance:check-staffing-readiness` use raw `DB` only, which never enters `SchoolScope`. |
| All console commands | 26 files | **0** | Only 3 import a finance Eloquent model: `ReconcileAccounts` (runFor ×2), `ImportOpeningBalances` (runFor ×2), `DriveFinanceStates` (runFor ×0 — but it is **not scheduled**, and its caller `SeedDriveFixture:78,88` wraps every call in `ActiveSchool::runFor`). |
| Seeders | 2 mention "Finance" | **0** | `DriveCastSeeder` and `RbacSeeder` reference Finance in comments and role names only; neither imports a finance model. |
| Factories | 0 finance factories exist | **0** | No factory in `database/factories/` matches any finance noun. |
| Queued jobs (`app/Jobs`) | 8 | **0** | No file references `App\Finance\Models\`. `ProcessOpeningBalanceImport` carries the `SchoolAware` middleware. |
| Any file outside `app/Finance/` | whole tree | **0** | `grep -rln 'App\Finance\Models\' app database routes bootstrap` returns **nothing** outside `app/Finance/`. |
| super_admin no-school request path | 41 finance routes (19 GET) | **YES — the whole surface** | `SetSchoolContext:51` reads `if (! $isSuperAdmin && ! $activeSchoolId)`, so a super_admin with no school falls through to every finance route. |

**No off-request reader needed code changed to give it context, so the fuse did not fire.** The one
no-context reader is the super_admin request path, which is a decision about intended behaviour, not
a command missing a `runFor`.

### Why the console risk was structurally zero, not merely absent

`SchoolScope:59` gates the throw on `auth()->check()`. Artisan has no authenticated user:

```
auth check: bool(false)
batch size: 10
```

So a scheduled command **cannot** throw `MissingSchoolContextException` however this batch is
configured. The brief's worst case — *"a command that starts throwing every night is the failure
mode this commit could cause"* — is not reachable by this change. All three finance commands were
also run for real against the dev database, before and after:

```
finance:reconcile-accounts      -> exit 0
finance:audit-ledger-coherence  -> exit 0
finance:audit-duty-separation   -> exit 1   (PRE-EXISTING)
```

The exit-1 is the `result.*` duty-separation finding the brief already describes as nightly since
2026-07-25. Verified pre-existing by stashing this change and re-running: **byte-identical output**,
same two holders (`user#6`, `user#3199`), same four rows. Not mine.

**This also bounds what the batch buys.** Fail-closed protects the *request* surface only. A future
queued job that skips `SchoolAware` and reads a finance model would still read unscoped, silently —
the gate that saves the commands is the same gate that would not save that job. Worth a ticket; not
this commit.

## STEP 2 — the batch

The brief's suggested ten, unchanged, because the enumeration removed nothing:

`LedgerTransaction, Payment, PaymentAllocation, Invoice, InvoiceLine, CreditNote, StudentAccount,
OpeningBalanceBatch, OpeningBalanceRow, VoidRequest`

The six catalog models are out, as suggested. Worth stating plainly: **the enumeration found no
blocker for them either** — they have no no-context reader — so the case for a second batch is
already made and is a decision rather than more investigation.

## STEP 3 — where the list lives

`config/rbac.php` now ships the batch as its **default**, with `RBAC_FAIL_CLOSED_MODELS` demoted to a
per-environment retreat. The old "Default: EMPTY" paragraph is gone.

### One thing I added that the brief did not ask for, because it would have undone the commit

`.env.example` was going to get a bare `RBAC_FAIL_CLOSED_MODELS=` line. Measured before writing it:

```php
Env::get("PROBE_EMPTY",  "FALLBACK-USED")   // present-but-empty  => string(0) ""
Env::get("PROBE_ABSENT", "FALLBACK-USED")   // absent             => "FALLBACK-USED"
```

`env()` distinguishes absent from present-and-blank. So the line the brief asked me to add to
`.env.example` is precisely the line that, once copied into a real `.env`, **replaces the default
with an empty list and switches the entire batch off platform-wide, silently.** Adding it as written
would have shipped a protection and its own off-switch in the same commit.

Two changes follow:

1. The config treats a blank override as "not set" — `trim(env(...)) ?: implode(...)`. A deliberate
   retreat is spelled by naming the models that stay on, which is not something a copy-paste does by
   accident.
2. `.env.example` carries the variable **commented out**, with the reasoning, and a non-empty example
   value.

Pinned by its own arm and its own watched red (RED 2 below).

## What changed

| File | What |
|---|---|
| `config/rbac.php` | The batch as a versioned default; docblock rewritten; blank-means-unset. |
| `.env.example` | `RBAC_FAIL_CLOSED_MODELS`, commented out, documented as an override. |
| `app/Models/Scopes/SchoolScope.php` | **Docblock only.** It said "Empty by default", which is now false. |
| `tests/Feature/Isolation/FinanceFailClosedBatchTest.php` | **New.** 19 arms. |

`SchoolScope.php` is a deviation from the brief's "one config change" fuse. It is a comment, no
behaviour, and leaving a docblock asserting the opposite of the code is the wallpaper problem in
miniature — a future reader would have trusted it over the config.

## THE DECISION FOR YOU — one test, and I did not touch it

`PaymentRecordGateTest::it super_admin is not gate-blocked on either payment route` fails:

```
Expected response status code [201] but received 409.
App\Exceptions\MissingSchoolContextException: Queried the School-scoped model
[App\Finance\Models\Invoice] with no active School context.
  #6 ImplicitRouteBinding.php(60): Model->resolveRouteBinding('a275f3f6-...', 'uuid')
```

**The throw is at route-model binding — frame #6 — before the controller and before any Action's own
context handling.** That is the mechanism, and it is the part worth understanding: `RecordPayment`
was written to take school off the *bound invoice* rather than off `ActiveSchool`, which is why this
route worked for a contextless super_admin at all. Binding the invoice is now itself a scoped read.

Twelve finance routes bind a fail-closed model. Six are `approve`/`reject`, which ADR 0040 already
denies a super_admin, so **six routes actually change behaviour for them**: create a payment, credit
note or void request against an invoice, and the three opening-balance-batch operations.

The test's own comment already documents the sibling case, and it argues for accepting this:

> *"The account route deliberately asserts 422, NOT 201, and NOT 403 … that 422 is a context refusal
> from BELOW the gate, and its being 422 rather than 403 is itself proof the gate let super_admin
> through. (The invoice route sidesteps this: RecordPayment takes school off the bound invoice, not
> ActiveSchool.)"*

This commit removes the sidestep. The invoice route now behaves like the account route, and the
test's actual subject — that the gate does not 403 a super_admin — is still true: 409 is not 403.

So the resolution is most likely a one-line change of `assertCreated()` to a 409 assertion plus a
comment, exactly paralleling the 422 arm above it. **I did not make it.** The brief said a throw on a
super_admin no-school path *"is a decision for the project lead — not something you work around"*, and
editing the assertion is the work-around. It is also a real capability being removed: a super_admin
who has not selected a school can no longer record a payment against an invoice by uuid.

The alternative — dropping `Invoice` from the batch — I do not recommend and did not do. It is the
central money model and the survey's evidence is mostly about it.

**Nothing was added to `tests/ratchet-baseline.txt`.** Baselining an intentional behaviour change is
how it stops being visible.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Isolation/FinanceFailClosedBatchTest.php
{"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":31,"duration_ms":13607}
```

### bin/quality — raw, unedited (ANSI stripped; nothing else removed)

```
quality gate — base cdd224a

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
   ✗ test-ratchet

       ratchet: 1 NEW test failure(s) not in the baseline (regression):
         ✗ tests/Feature/Finance/PaymentRecordGateTest.php::it super_admin is not gate-blocked on either payment route (record is not a checker ability)

       Fix the regression, or — if the failure is intentional — add it to tests/ratchet-baseline.txt.

✗ quality: FAIL (1): test-ratchet
Push blocked. Fix the above, or bypass deliberately with --no-verify (recorded as intent, not accident).
```

An earlier run also failed `boundary-lint` and `arch` on my own `User::forceCreate(...)` — this
file's name matches `tests/.*Finance`, so it trips the `force-create-finance-tests` rule, which
exists because `forceCreate` bypasses `MoneyCast`. Nothing here creates money, so the rule is blunt
about this file, and I fixed my side rather than the rule: a name-scoped hard rule a test can talk
its way out of is not a rule. `User::factory()` costs nothing.

## The watched reds — and the mutation proved landed each time

Per RED 3 in #225, every mutation was diffed **against the pre-mutation file**, not against `HEAD`.
Diffing against `HEAD` would have shown my whole commit and told me nothing about whether the
mutation itself applied.

**RED 1 — `StudentAccount` removed from the batch.** Mutation verified: `diff` showed the single
deleted line.

```
FAILED: ships the finance transactional batch as the default, with no env var set
  config/rbac.php no longer ships the finance transactional batch as its DEFAULT. …
  -    6 => 'App\Finance\Models\StudentAccount',
```

Arm count went **17 → 16**: the dataset arm for that model *silently disappeared*. That is precisely
the vacuity the provenance arm exists to catch, and it is why the ten names are written out literally
in exactly one place.

**RED 2 — the blank-means-unset guard reverted to plain `env(..., default)`.** Mutation verified
against the pre-mutation file (2 lines modified).

```
FAILED: a BLANK RBAC_FAIL_CLOSED_MODELS falls back to the default instead of disabling everything
  A blank RBAC_FAIL_CLOSED_MODELS no longer falls back to the shipped default. An .env carrying the
  bare line from a template would disable School isolation on every finance money model at once,
  platform-wide and silently.
  --- Expected            +++ Actual
  [ …10 finance models… ]  Array &0 []
```

The first attempt at this arm failed with `"Expecting [] not to be empty"` — a bare `->not->`
matcher, whose custom message Pest discards (#222). Rewritten as a positive `toBe()`; the message
above is the result.

**RED 3 — `auth()->check() &&` deleted from `SchoolScope:59`.** Mutation verified (1 line modified).

```
ERROR: leaves UNAUTHENTICATED reads completely unchanged
  Queried the School-scoped model [App\Finance\Models\Invoice] with no active School context.
```

The arm that would break login is live.

**RED 4 — the one that shows what the batch is actually for.** `StudentAccount` removed again, this
time watching the HTTP arm:

```
FAILED: refuses a super_admin finance API read with no school as a clean 409, not a 500
  A super_admin with no school selected got 200 from the finance accounts endpoint. 409 is the
  fail-closed refusal; 200 means the read went through unscoped and returned every School's
  accounts, and 500 means the exception escaped its own renderer.
```

**200.** Off the list, a super_admin with no school selected reads every School's student accounts in
one list. That is the defect, measured on the real route stack rather than argued from the scope.

## The browser drive

### The population that matters in term one — driven, and proven by comparison

Two seats already in the copy, each finance page opened and its **rendered rows** counted, because a
page whose read threw would render empty rather than error and a status-code-only drive could not
tell those apart:

```
=== MAKER   (user#3451, accounts_supervisor) ===
  /finance                             200  rows=1   threw=false  "Finance - Laravel"
  /finance/approvals                   403  rows=0   threw=false  "Forbidden"
  /finance/opening-balances/import     200  rows=14  threw=false  "Opening balances — import"
  4xx/5xx or JS errors seen: [ '403 /dashboard', '403 /finance/approvals' ]

=== CHECKER (user#3452, executive_director) ===
  /finance                             200  rows=1   threw=false  "Finance - Laravel"
  /finance/approvals                   200  rows=1   threw=false  "Finance — pending approvals"
  /finance/opening-balances/import     403  rows=0   threw=false  "Forbidden"
  4xx/5xx or JS errors seen: [ '403 /dashboard', '403 /finance/opening-balances/import' ]
```

A negative claim ("nothing changed for them") is not proven by a green run, so the same script was
run **with the change stashed** — batch size 0 — and the output is **byte-identical** to the above,
batch size 10. That is the comparison, not an assurance. The 403s are authorization (maker has no
checker ability, checker no import ability), matching #225's drive exactly.

### The super_admin seat — NOT driven, and this time not for #225's reason

`user#3197` exists and is exactly the seat to drive. Logging in as them needs a password, and the
only reversible way I had — save the bcrypt hash, set a known one, drive, restore — is a **credential
write on a production copy**. The environment's permission classifier refused it, and I did not
route around it. It is your call whether to authorise that or to drive it yourself.

What the screenshot would have shown is asserted instead, at the HTTP layer, on the real route stack:

- **`GET /api/v1/finance/accounts` → 409** `{"message":"No active school selected."}` — and RED 4
  proves it is 200 without the flip.
- **The finance web shell still renders 200.**

That second one **corrects an assumption I started with and would otherwise have written into this
report.** `MissingSchoolContextException::render()` redirects to `school.select` on the web
transport, so the natural expectation is that a super_admin gets bounced to the school picker. They
do not: every finance web route is an Inertia shell that reads no finance model server-side
(`routes/web.php:155-156` renders the statement page from the `Student` alone). The page returns 200
and the refusal surfaces only in the XHR it then issues.

**So the operator experience is an error state inside a rendered finance page, not a redirect to
"select a school".** That is worse UX than the redirect the exception was designed to give, it is a
consequence of this commit, and it is pinned by an arm so it stays a chosen behaviour.

## Not done

- **`PaymentRecordGateTest` not touched** — the decision above. `bin/quality` is red until you rule.
- **Not pushed, no PR** — pushing needs `--no-verify` while that step is red, which is not mine to
  take unilaterally.
- **super_admin not driven in a browser** — reason above; blocked, not judged unnecessary.
- **The catalog batch not flipped** — as instructed, though the enumeration clears it.
- **`SchoolScopeFailsClosedTest`'s first arm is now mis-named.** It reads *"stays fail-open (no throw)
  when no model is opted in — **the default**"*. It sets `config(['rbac.fail_closed_models' => []])`
  explicitly, so it still passes and still tests something true; only the "— the default" clause is
  now false. Not edited (one test file, per the fuse). **ticket.**

## Findings raised, not fixed

- **Fail-closed does not protect off-request paths at all**, because the throw is gated on
  `auth()->check()`. A queued job that skips `SchoolAware` reads unscoped and silently. The same gate
  that makes this commit safe for the nightly commands is the one that would not save that job.
  **ticket.**
- **#225's "zero super_admin holders" is wrong** and the reasoning built on it should be re-read.
  The doubled-backslash `model_type` rule applies to raw SQL, not to query-builder bindings —
  worth correcting in `finance-context`, since it will produce a confident zero again otherwise.
  **ticket.**
- **The `force-create-finance-tests` lint keys on the file NAME**, so an isolation test with
  "Finance" in its name is governed by a MoneyCast rule regardless of whether it touches money. It
  caught me and I complied, but the coupling is incidental. **ticket.**
