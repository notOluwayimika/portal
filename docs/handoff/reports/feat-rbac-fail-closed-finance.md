# Implementation report — RBAC_FAIL_CLOSED_MODELS, the finance batch

## Headline

**Done.** The ten finance transactional models fail closed, the list is a versioned default in
`config/rbac.php` rather than an env var a deploy can forget, and `bin/quality` is green.

Branch `feat/rbac-fail-closed-finance`, base `cdd224a` (`origin/staging`, #225 merged). Two commits:
`43ca5c2` (the batch) and the second, which takes the project lead's two rulings and folds in the
review findings that were rulings rather than tickets.

**This report supersedes the version reviewed at `43ca5c2`.** Everything the reviewer found that was
ruled FOLD-IN is now in the code, and the two claims it correctly said were unproven — the split arm
and the stale watched reds — are proved below against the files as they ship.

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
| super_admin no-school request path | 41 finance routes (19 GET) | **YES — the whole surface** | `SetSchoolContext:51` reads `if (! $isSuperAdmin && ! $activeSchoolId)`, so a super_admin with no school falls through to every finance route. **But see "One correction to my own earlier framing" below — on the twelve routes that BIND a fail-closed model, binding runs before this middleware, so the changed surface is not super_admin-only.** |

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
| `config/rbac.php` | The batch as a versioned default; docblock rewritten; blank-means-unset; the typo warning at the point of use; the off-request limitation stated. |
| `.env.example` | `RBAC_FAIL_CLOSED_MODELS`, commented out, documented as an override. |
| `tests/Feature/Finance/PaymentRecordGateTest.php` | Arm 3 split into a bypass arm and an isolation arm (RULING 1). |
| `app/Support/SchoolContext.php` | **Docblock only.** Its "the list is EMPTY everywhere" premise is now false. |
| `bin/ci-boundary-lint.php` | **Comment only.** Same premise, but it was a LIVE rule's stated reason to exist. |
| `docs/roadmap.md` · `docs/rbac-implementation-plan.md` · `docs/Finance Module — …v10.md` | The flag is no longer dark; the deviation is named and argued, not edited away quietly. |
| `app/Models/Scopes/SchoolScope.php` | **Docblock only.** It said "Empty by default", which is now false. |
| `tests/Feature/Isolation/FinanceFailClosedBatchTest.php` | **New.** 19 arms. |

`SchoolScope.php` is a deviation from the brief's "one config change" fuse. It is a comment, no
behaviour, and leaving a docblock asserting the opposite of the code is the wallpaper problem in
miniature — a future reader would have trusted it over the config.

## RULING 1 — `PaymentRecordGateTest`, split, and both halves bite

The reviewer was right and my proposed one-liner was wrong. Changing `assertCreated()` to
`assertStatus(409)` would have produced a green arm asserting nothing: `SubstituteBindings` sits in
Laravel's middleware priority list ahead of both `SetSchoolContext` and the route's `permission:`
middleware, so a contextless request is refused before either gate runs, and a 409 arm passes
identically with the bypass on or off.

Arm 3 is now two arms with two different subjects, each bite-proved against a **different** mutation:

**Arm 3a — the BYPASS.** super_admin with a school selected records (201). Establishing that context
needed a real mechanism, not a fixture trick: `api/*` only gets session middleware when Sanctum's
`EnsureFrontendRequestsAreStateful` judges the request to come from the frontend, from
`Referer`/`Origin` against `sanctum.stateful`. Without that header `ActiveSchool::id()` never sees
the session's `school_id`, and a super_admin is explicitly denied the own-school fallback
(`ActiveSchool.php:54`). The new helper sends the header, which is exactly the transport the SPA uses.

```
RED A — config(['auth.gate_before_superadmin' => false])
  mutation verified: 1 line, diffed against the pre-mutation file
  FAILED: super_admin with a school selected is not gate-blocked on either payment route
    Expected response status code [201] but received 403.
```

That is the live bypass proof the reviewer said a 409 arm could not be, demonstrated rather than
asserted.

**Arm 3b — the ISOLATION.** super_admin with no school selected is refused (409).

```
RED B — the finance transactional batch emptied
  mutation verified: batch size printed from the running app before the run — size=1, first=App\Nope\NotAModel
  FAILED: super_admin with NO school selected is refused at 409 before the gate
    A super_admin with no school selected got 201 posting a payment against an invoice by uuid.
```

**201 — the payment is recorded.** That is the capability this commit removes, measured.

### The mutation that did NOT bite, and what it taught

My first attempt at RED B removed **only `Invoice`** from the list, on the assumption that the throw
came from binding the invoice. The arm stayed green. Probing it with
`withoutExceptionHandling()` gave the reason:

```
PROBE: Queried the School-scoped model [App\Finance\Models\PaymentAllocation]
       with no active School context.
```

`RecordPayment` reads `PaymentAllocation` downstream of the binding, so **two independent models on
the batch guard the same path** and no single-model mutation is a valid bite-proof for that arm. I
had already written "goes red if Invoice leaves `rbac.fail_closed_models`" into the test comment
before checking. It was false, it is corrected in place, and the correction says why — because the
next person to re-derive it with a one-model mutation would otherwise conclude the arm is dead.

This is also the second time this branch the *first* mutation silently did nothing. Both times the
"verify the mutation landed" rule caught it, and the second time only because I checked the batch
size the running application reported rather than trusting the diff — two earlier attempts produced
clean-looking diffs (`[] ?: [...]`, a trailing `//`) that left the batch at 10.

## RULING 2 — folded in, not ticketed

**(a) Two in-code rationales that asserted the opposite of what ships.** Both said
*"`config/rbac.php:78-81` reads `RBAC_FAIL_CLOSED_MODELS` … and it is EMPTY everywhere, so the scope
is fail-OPEN for every model"*, and both cited a line number the array no longer occupies.

- `app/Support/SchoolContext.php` — the kernel guard's justification.
- `bin/ci-boundary-lint.php` — the **live** `school-context-guard-missing` rule's stated reason to
  exist, which is the wallpaper problem inside the gate itself.

Rewritten to describe what ships, and to say why fail-closed does **not** make either redundant: the
two govern different branches of `SchoolScope::apply`. Fail-closed is the **null-context** branch; the
guard is the **wrong-context** branch — a maker in School A acting on School B's record has a context,
the scope filters on it correctly, and the action succeeds on an empty set. There is nothing there for
fail-closed to detect. Deleting the rule on the strength of *"the models fail closed now"* would
reopen exactly the hole it was written for, so that sentence is now in the rule's own header.

**(b) The flag ships LIT, and the deviation is named rather than edited away.** `docs/roadmap.md` no
longer lists `rbac.fail_closed_models` under "Rollout flags currently dark"; in its place is a
paragraph stating plainly that this departs from CLAUDE.md's *"rollout flags ship dark"*, and arguing
it: a dark flag defers a decision until evidence arrives, whereas this flag's whole design is
per-model opt-in **after** an audit — so shipping it dark once the audit is done would mean the audit
produced nothing. The audit, the drive and the measured 200/201 are cited from there. The residual
(nothing off-request is protected; the catalog is a later batch) is stated in the same paragraph.

I do not think that argument fails. The one place it would is if "dark" were meant to cover *any*
behaviour change, in which case the versioned default is the wrong shape entirely and the batch should
go back behind the env var — but that would restore the forgettable-by-deploy property this commit
exists to remove, so the two readings cannot both be satisfied.

`docs/rbac-implementation-plan.md` and the Finance master plan both carried "empty" as a current fact;
both now record the ten transactional models, the 2026-08-09 date, and — in both — that Debt item 7
stays **open**, because the auth-gate means no off-request path is covered.

**(c) The misattribution is out of the config, and RED 4 is in its place.** The block cited the #224
nine-of-fifteen maker-checker survey as this batch's evidence. It cannot be: all fifteen run *with* a
context, on the scoped branch. What replaces it is what was observed — 200 with every School's
accounts, 201 recording money into any School, and 409 once the models are listed. The retraction is
kept in the docblock in parentheses so it is not reintroduced by someone reaching for the strongest-
sounding argument.

**(d)** RED 1 and RED 4 re-run against the shipped 19-arm file — see *The watched reds* below.

**(e) The limitation is in the docblock, not only in a ticket.** `config/rbac.php` now states that the
throw is gated on `auth()->check()`, so **no** off-request path is covered — not a command, not a
seeder, not a job without an authenticated principal, not an unauthenticated read — and that this is
why no scheduled command can start throwing because of this list. Someone reading "fail closed" will
assume console is covered; the docblock is where they are reading.

**The typo warning sits at the point of use.** Immediately under the example `RBAC_FAIL_CLOSED_MODELS=`
line, because that is where someone is typing when they can cause it: nothing validates the names, so
one missing character protects nine models instead of ten, silently. Named as the same failure the
versioned default was introduced to remove, wearing a different hat.

## The behaviour change itself — what six routes now do

The mechanism, from the failure's own stack trace:

```
App\Exceptions\MissingSchoolContextException: Queried the School-scoped model
[App\Finance\Models\Invoice] with no active School context.
  #6 ImplicitRouteBinding.php(60): Model->resolveRouteBinding('a275f3f6-...', 'uuid')
  #10 SubstituteBindings.php(43): Router->substituteImplicitBindings(...)
  #12 Authenticate.php(63): Pipeline->{closure}(...)
```

Neither `SetSchoolContext` nor either permission-middleware frame appears below `Authenticate`.
Binding the invoice is itself a scoped read, and it happens before both gates. `RecordPayment` was
written to take school off the *bound invoice* rather than off `ActiveSchool`, which is the only
reason this route ever worked for a contextless super_admin.

Twelve finance routes bind a fail-closed model. Six are `approve`/`reject`, which ADR 0040 already
denies a super_admin, so **six change behaviour for them**: create a payment, credit note or void
request against an invoice, and the three opening-balance-batch operations. Selecting a school
restores all six, which is what makes this isolation rather than a capability cut — and arm 3a proves
it, not just claims it.

**Nothing was added to `tests/ratchet-baseline.txt`.** Baselining an intentional behaviour change is
how it stops being visible.

### One correction to my own earlier framing

I wrote that `SetSchoolContext:51` was what admitted the contextless super_admin, and used it to
bound the surface to super_admins. The stack trace says otherwise: binding runs *before* that
middleware, so on those twelve routes **any** authenticated principal with no resolvable school is
now refused at 409 where they previously got 403 from `SetSchoolContext:56-58`. Same refusal,
different code. In the copy that is a population of one (`user#3197`, the super_admin), and no
frontend code keys on either status — but the reasoning in my STEP 1 table was mechanically wrong and
would have under-counted the surface again next time.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Isolation/FinanceFailClosedBatchTest.php
{"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":31,"duration_ms":22001}

DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/PaymentRecordGateTest.php
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":9,"duration_ms":17146}
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
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

An earlier run at `43ca5c2` also failed `boundary-lint` and `arch` on my own `User::forceCreate(...)`
— this file's name matches `tests/.*Finance`, so it trips the `force-create-finance-tests` rule, which
exists because `forceCreate` bypasses `MoneyCast`. Nothing here creates money, so the rule is blunt
about this file, and I fixed my side rather than the rule: a name-scoped hard rule a test can talk its
way out of is not a rule. `User::factory()` costs nothing.

## The watched reds — and the mutation proved landed each time

Per RED 3 in #225, every mutation was diffed **against the pre-mutation file**, not against `HEAD`.
Diffing against `HEAD` would have shown my whole commit and told me nothing about whether the
mutation itself applied.

**RED 1 and RED 4 — `StudentAccount` removed from the batch. RE-RUN against the file as it ships**,
because the reviewer was right that the original output was captured from a 17-arm file and 19 arms
ship. Stale output is not evidence. Baseline first, then the mutation, then the run:

```
BASELINE  {"tool":"pest","result":"passed","tests":19,"passed":19,"assertions":31}

MUTATION LANDED (diffed against the pre-mutation file, not HEAD)
  - 165             StudentAccount::class,

AFTER     tests=19 -> 18   passed=16   failed=2

FAILED: ships the finance transactional batch as the default, with no env var set
  config/rbac.php no longer ships the finance transactional batch as its DEFAULT. …
  -    6 => 'App\Finance\Models\StudentAccount',

FAILED: refuses a super_admin finance API read with no school as a clean 409, not a 500
  A super_admin with no school selected got 200 from the finance accounts endpoint. 409 is the
  fail-closed refusal; 200 means the read went through unscoped and returned every School's accounts,
  and 500 means the exception escaped its own renderer.
```

**19 → 18.** The dataset arm for that model *silently disappeared* — precisely the vacuity the
provenance arm exists to catch, and why the ten names are written out literally in exactly one place.
And **200**: off the list, a contextless super_admin reads every School's student accounts in one
list. That is the defect, measured on the real route stack rather than argued from the scope.

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

**RED A and RED B** — the two halves of the split `PaymentRecordGateTest` arm — are in RULING 1
above, together with the mutation that did **not** bite and why.

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

- **super_admin not driven in a browser.** `user#3197` exists and is the right seat, but logging in
  needs a password change on a production copy — reversible (save the hash, set a known one, restore)
  and still a credential write, which the environment's permission classifier refused. I did not
  route around it. Covered by HTTP-layer arms instead; your call whether to authorise it.
- **The catalog batch not flipped** — as instructed, though the enumeration clears it.
- **No arm pins the 409 for a contextless NON-super-admin** on a bound route, which the stack trace
  shows is now also possible. One user in the copy is in that state and they are the super_admin.
- **`SchoolScopeFailsClosedTest`'s first arm is now mis-named.** It reads *"stays fail-open (no throw)
  when no model is opted in — **the default**"*. It sets `config(['rbac.fail_closed_models' => []])`
  explicitly, so it still passes and still tests something true; only the "— the default" clause is
  now false. **ticket.**
- **The reviewer's findings 5, 6 and 7 rest on reading code paths, not on planted regressions** — it
  has no write access. They are carried as tickets below, unverified by a bite-proof.

## Findings raised, not fixed

- **Fail-closed does not protect off-request paths at all**, because the throw is gated on
  `auth()->check()`. A queued job that skips `SchoolAware` reads unscoped and silently. Now stated in
  the config docblock (RULING 2e) rather than only here, because "fail closed" reads broader than it
  is. Still **ticket** as a gap.
- **A mistyped override silently empties the batch.** Nothing validates the parsed names;
  `App\Finance\Model\Invoice` (one missing `s`) matches nothing, raises nothing, logs nothing.
  Warned about at the point of use in the config (per your ruling); the fix is a `class_exists()`
  check over the list. **ticket.**
- **No detector for an environment that already sets `RBAC_FAIL_CLOSED_MODELS`.** The old design
  expected each environment to set it, so a deployed `.env` may carry a partial list — in which case
  the versioned default silently does not apply there, which is the failure this change exists to
  remove, inverted. Check before merge:
  `php artisan tinker --execute="var_dump(env('RBAC_FAIL_CLOSED_MODELS'));"` per environment. I did
  not read `.env` (operating constraint), so I have no evidence either way. **ticket.**
- **`config/rbac.php` names private module classes where both boundary gates are blind.**
  `docs/module-blueprint.md:45` marks `App\Finance\Models\` private and `ArchitectureBoundaryTest`
  pins it, but Pest arch only analyses namespaced classes and `bin/ci-boundary-lint.php` scans only
  `app/` and `tests/` — so a config file naming module internals passes both. Not unprecedented
  (`routes/endpoints/finance.php` imports private controllers), and string literals are a drop-in.
  **ticket.**
- **`SetSchoolContext:51` does not bound the blast radius** — binding runs first. Corrected in place
  above; recorded so the next enumeration does not under-count the surface again. **ticket.**
- **#225's "zero super_admin holders" is wrong** and the reasoning built on it should be re-read.
  The doubled-backslash `model_type` rule applies to raw SQL, not to query-builder bindings —
  worth correcting in `finance-context`, since it will produce a confident zero again otherwise.
  `finance-context` also still says `bin/quality` is 13 steps; it is 14. **ticket.**
- **The `force-create-finance-tests` lint keys on the file NAME**, so an isolation test with
  "Finance" in its name is governed by a MoneyCast rule regardless of whether it touches money. It
  caught me and I complied, but the coupling is incidental. **ticket.**
