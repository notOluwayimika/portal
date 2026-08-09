# Implementation report — bank accounts (S6/U3, commit 1 of 2)

## Headline

**Done.** The table, model, controller, routes, screen, nav entry and permission exist; the system
behaves identically to before; there is now a way to create a bank account for commit 2 to require.

Branch `feat/finance-bank-accounts`, base `7de0671` (`origin/staging`, #230 merged).
`bin/quality` green 14/14.

**Full-review tier** — a new permission, a new table, and a new authorization surface.

## The finding that corrects the brief

**Coining a permission has THREE obligations, not two.** The brief named the enum case and the
`grantsMap()` entry. Both were done, and five tests went red:

```
PermissionGroupTest   ErrorException: Undefined array key "finance.bank-account.manage"
                      at app/Enums/Permission.php:250
SchoolRbacConsoleTest actual size 82 matches expected size 83
PermissionEnumTest    Failed asserting that two arrays are equal
RbacConsoleTest       Failed asserting that two arrays are identical
```

`Permission::group()` reads `PermissionGroup::lookup()[$this->value]` with **no fallback**, and that
is deliberate — its docblock says an unfiled case must be a failing test rather than a permission
that quietly disappears from the RBAC console. So the checklist is:

1. the case in `app/Enums/Permission.php`
2. **its membership in `app/Enums/PermissionGroup.php`** ← the one the brief did not name
3. the entry in `RbacSeeder::grantsMap()`
4. regenerate `tests/fixtures/rbac-grants-baseline.json` and `tests/fixtures/route-access-map.json`

It fails at build, which is the good kind of failure — but it cost a full gate cycle, and it belongs
in the brief template.

## The permission trap — the fuse did NOT fire, and why

The forcing migration `2026_08_06_100000_move_head_of_school_finance_to_executive_director` makes
each governed role's `finance.` slice **equal** a frozen literal. A grant to a governed role is
written by the seeder and revoked by that migration on the next deploy — at deploy, not at build.

| Role | Governed? | Gets `finance.bank-account.manage` |
|---|---|---|
| `admin` | no | **yes** |
| `accounts_officer` | no | **yes** |
| `accounts_supervisor` · `executive_director` · `head_of_school` | **yes** | no |

`admin` + `accounts_officer` is exactly where `finance.fee-schedule.manage` already sits. **No
`@converges` marker is needed and no convergence migration is needed** — the permission is granted
only to roles the forcing migration does not touch, so there is nothing for it to strip.

That is asserted, not reasoned: an arm reads `grantsMap()` and fails if any holder is governed.
Watched red below shows both it *and* the repository's existing
`ForcingMigrationsDoNotStripLaterGrantsTest` firing on the same mutation.

### The convergence lint's first green was vacuous

```
grants-convergence-lint: OK — database/seeders/RbacSeeder.php is unchanged in this diff.
```

The change was uncommitted, and the lint is diff-aware against `HEAD` — the same trap #229 found in
`lint-changed`, in a second gate. Committed, then re-run:

```
grants-convergence-lint: OK — no unexempted grant addition (7de0671..ddaaf6a; 2 exempted).
  · finance.bank-account.manage @ RbacSeeder.php:225 — exempt: permission is NEW in this diff
  · finance.bank-account.manage @ RbacSeeder.php:371 — exempt: permission is NEW in this diff
```

Exemption 1, exactly as the brief predicted. **Any diff-aware gate run over uncommitted work is
reporting on an empty diff**; that is now two of them, and it generalises to `bin/ci-*-lint.php`
taking a `$BASE`.

## Which seat holds it — argued, and I did not take the brief's lean

The brief suspected the payment-recording maker seat was wrong for finance configuration. I went the
other way, on precedent: **`accounts_officer` already holds `finance.fee-schedule.manage`**, which is
a *larger* lever — it changes what students are charged. Withholding bank accounts from a seat that
can already rewrite the fee schedule is inconsistent, and `fee-schedule.manage` is the precedent the
brief itself named as closest.

**Who ends up able to do this:** `admin` and `accounts_officer`, per school.

The duty-separation question is real but belongs to commit 2, and is ticketed below: once
`bank_account_id` attaches to payments, the officer would control both the money record and its
destination label. Today it attaches to nothing.

## What was built

| File | What |
|---|---|
| `2026_08_10_100000_create_finance_bank_accounts_table` | School-scoped; `(school_id, account_number)` unique; `deactivated_at`. |
| `app/Finance/Models/BankAccount.php` | `AddUuid`, `BelongsToSchool`; `active()` / `inDisplayOrder()` scopes. |
| `BankAccountController` + two FormRequests | index / store / update / deactivate / reactivate. **No destroy.** |
| `routes/endpoints/finance.php`, `routes/web.php` | 5 API routes + 1 page, all on `finance.bank-account.manage`. |
| `resources/js/pages/admin/finance/bank-accounts.tsx` | The screen. |
| `app-sidebar.tsx` | Nav entry, gated on the same permission (#225's coverage gate requires it). |

**Columns are driven by reconciliation**, which is the reason the table exists: `bank_name` and
`account_number` are what a bursar matches against a statement; `label` is what an operator
recognises (a ten-digit number is not). `account_name` is nullable — a nicety for the payer, not a
reconciliation key, and demanding it would block a school that only has the number.

**Uniqueness is per school, not global.** Two schools may legitimately bank the same account; within
one school a duplicate number makes a statement line ambiguous. Both directions have arms.

**Only the LABELS are editable.** `bank_name` and `account_number` are immutable from creation —
see *Ruling 1* below. An earlier draft of this commit allowed editing them in place, on the argument
that a bank account is a description rather than an event; the ruling reversed it, and the reversal
is right: a description that other rows are matched against is not free to change once anything
points at it.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/BankAccountTest.php
{"tool":"pest","result":"passed","tests":13,"passed":13}
```

### bin/quality — raw, unedited (ANSI stripped)

```
quality gate — base 7de0671

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 12 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
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

## The watched reds — mutation observed in the running program

**RED 1 — the routes gated on `finance.access` instead of the new permission.** Read from the live
router, not the diff:

```
RUNNING ROUTER: api,…,PermissionMiddleware:finance.access
FAILED: refuses every bank-account route to a seat holding only finance.access
  Expected response status code [403] but received 201.
```

**RED 2 — deactivate implemented as a delete.** Method body read by reflection:

```
RUNNING PROGRAM: public function deactivate(BankAccount $bankAccount): JsonResponse
RUNNING PROGRAM: $bankAccount->delete();
FAILED: deactivates without deleting, and the row stays listed
```

**RED 3 — the grant moved to a governed role**, which is the one that fails at DEPLOY rather than at
build:

```
RUNNING PROGRAM grantsMap holder: admin
RUNNING PROGRAM grantsMap holder: accounts_officer
RUNNING PROGRAM grantsMap holder: executive_director      ← the mutation

FAILED (my arm): finance.bank-account.manage is granted to a role governed by the forcing
  convergence migration. The seeder will write that grant and the migration will revoke it on the
  next deploy, silently.

FAILED (the repo's own ForcingMigrationsDoNotStripLaterGrantsTest): these grants are written by the
  seeder map and then REVOKED by a forcing convergence migration on the next deploy…
```

Both fire. My arm agrees with the pre-existing enforcement rather than substituting for it.

## The browser drive — portal_drive, never the production copy

`maker@drive.test` (`user#2`, `school#1`, `accounts_officer`), on the drive fixture:

```
  page title: Bank accounts - Laravel
  rows before: 0
  rows after create: 1
  after edit:  Zenith — Fees (main) | Zenith Bank | 1234567890 | … | Active
  rows after deactivate: 1
  row still visible as a record: Zenith — Fees (main) | … | Deactivated
  after reactivate: … Active
```

**The claim that matters:** after deactivating, the row count is still 1 and the row reads
*Deactivated*. It is withdrawn from choice, not erased.

Sidebar, checked from a page that renders the shell:

```
on /finance                -> ["/finance","/finance/opening-balances/import","/finance/bank-accounts", …]
on /finance/bank-accounts  -> ["/finance","/finance/opening-balances/import","/finance/bank-accounts"]
```

### A correction to my own drive

I first reported `sidebar entry present: false`. That was a **measurement artifact**: I counted the
link immediately after login, before the redirect had settled — the page title was still
"Log in - Laravel", so no shell had rendered. Re-checked from `/finance`, the entry is present. The
first number was wrong and the conclusion drawn from it would have been a phantom defect.

## Ruling 1 — identifying fields are immutable, in three independently-proven layers

`bank_name` and `account_number` cannot change, **from creation** — not "once referenced". A rule
with a switch-on point needs somebody to define *referenced* (allocations? ledger rows? a draft
invoice?) and has a window in which it does not hold. Immutable-from-creation has neither. A school
whose banking details change deactivates and creates a new account, which is also the truer record:
it IS a different account.

`label` and `account_name` stay editable — display and courtesy, not reconciliation keys.

### Both SIGNAL constraints, measured

```
MESSAGE_TEXT length: 89 (limit 128) OK
contains apostrophe: no
```

An arm re-measures both against the **live trigger** read from `information_schema`, not against the
migration file: the file is what we wrote, the trigger is what runs. An apostrophe breaks mysqldump
(an unrestorable backup, found at the worst moment); over 128 characters SIGNAL fails with 1648
instead of raising 1644, so the guard reports a MySQL error rather than its own refusal.

### Three layers, three reds, three different outcomes

Each arm asserts what its layer PRODUCES, not that a check exists — 5b-ii's RED 2 lesson.

| Layer | Mutation | Fails with | Other two layers |
|---|---|---|---|
| database (trigger) | condition → `IF FALSE` | **1644**, "did not refuse a change to account_number" | green |
| request (FormRequest) | comparison → `if (false)` | **422 expected, 200 received** | green |
| screen (modal) | identity fields back in the edit branch | source shape — "still renders bank_name as an input" | green |

No layer passes because a neighbour covers for it.

**RED A did not fire on the first attempt, and that is worth recording.** I dropped the trigger from
the database directly — and `RefreshDatabase` re-migrated it back before the assertions ran. Caught
only because the trigger count was printed and went 0 → 1 across the run. Re-done as a mutation to
the MIGRATION, which is what actually reaches the test process. Dropping an object the test suite
re-creates is a mutation that cannot land, and it reports as a clean green.

**Two of my own assertions were wrong before they were right**, both for the same class of reason —
matching a formatted artefact literally:

- The trigger's `MESSAGE_TEXT` literal sits on the line AFTER the assignment (MySQL preserves the
  statement's line breaks), so an inline `MESSAGE_TEXT = 'Bank name` match could never hold.
- Prettier reflows JSX prose; the modal's explanatory sentence is broken over six lines, so a
  multi-word `str_contains` against the component source was a check that could only fail.

Both now normalise first, with the reason written beside them.

## Ruling 2 — the three obligations, put where someone stands

| File | What it now carries | Why there |
|---|---|---|
| `app/Enums/Permission.php` (header) | The full checklist: case → group → grant, then regenerate both oracles. Names the five tests that fail if you stop at step 1. | It is the file you OPEN to coin one. |
| `app/Enums/PermissionGroup.php` (header) | Obligation 2, addressed to someone who arrived from `Undefined array key`. | It is where the error SENDS you. |
| `RbacSeeder::grantsMap()` | Obligation 3 plus the forcing-migration warning. | It is where you choose the ROLE, which is the choice that fails at deploy. |

`Permission::group()`'s missing fallback is documented as the mechanism rather than as trivia:

> Do NOT "fix" a missing-key error by adding a default — the absence of a fallback IS the mechanism,
> and a default would convert a red build into a permission that exists in code and cannot be
> administered.

Code docblocks were chosen over `docs/` deliberately: this failure arrives as a stack trace pointing
at these files, and a document is read before work rather than during it.

## Ruling 3 — the ticket, enumerated by walking the script

`docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`.

**Exactly two of the fourteen steps are diff-aware**: `lint-changed` (`bin/quality:177`) and
`grants-convergence-lint` (`:213`). Derived two independent ways that agree — `grep '"$BASE"'`
returns those two `check` lines, and `grep -l "git diff" bin/*` returns only `lint-changed.sh`,
`ci-grants-convergence-lint.php` and `quality` itself. The ticket lists all fourteen with why each is
or is not in scope.

The three baseline-comparing steps (`tsc-ratchet`, `authz-lint`, `test-ratchet`) are **excluded and
say why**: they compare against a committed baseline rather than a diff, so they always examine the
whole tree, and their failure mode is a stale baseline — ADR 0041's subject, not this one.

Requirement recorded as: report what was examined, not only what was found; zero examined is
legitimate and must not fail; silence about zero is the defect. Not implemented here — it modifies
the enforcement floor that verifies this very commit, which wants its own commit and its own watched
red.

## The ticket was re-framed, and the first framing would have been closed by a no-op

I first filed this as a "silent green". **That was wrong.** Neither step is silent — both report
honestly about the range they are given:

```
bin/lint-changed.sh:59              ==> Pint: no changed PHP files
ci-grants-convergence-lint.php:514  OK — RbacSeeder.php is unchanged in this diff.
```

The defect is the **range**, not the reporting: `git diff … "$BASE"...HEAD` (`lint-changed.sh:51`)
excludes uncommitted work. A ticket asking for better reporting would have been closed by a change
that fixed nothing.

And the causal chain is the part a future reader could not reconstruct: **the formatting sweeps are
the workaround the scoping bug requires.** The tool meant to lint your changes cannot see them until
you commit, so you reach for `pint <directory>` to check your work — and a directory is the only
argument that feels natural when you have no file list to hand. #223 and this branch's 71 files are
downstream of #229's and this branch's zero-file greens, not separate carelessness.

### The count already existed, already worked, and did not help

`bin/quality:78-95` printed, accurately, on both versions of this tree:

```
bloated commit (89 files):   Pint (check) on 82 changed PHP file(s)
real commit    (18 files):   Pint (check) on 12 changed PHP file(s)
```

**82 against 12.** The instrument shipped in #229 was correct and its placement was wrong: it
scrolled past inside fourteen steps of gate output four minutes before the push, and what actually
caught the sweep was `git diff --stat` read against a mental model of the change. So the requirement
is placement — the count belongs in the pre-push summary beside the commit's file count, at the
moment someone decides to push. A number in a log is data; the same number at the prompt is a check.

**Explicitly out of scope, and the ticket says so:** no gate should fail a commit for containing
correct formatting changes. All 71 files were more compliant afterwards.

Recorded as behaviour rather than as a ticket, in `CLAUDE.md`: **Pint is invoked through
`bin/lint-changed.sh`, never directly against a path**; until the scoping is fixed, pass explicit
files from `git diff --name-only`.

## The re-drive, after the immutability ruling

The first drive predated Ruling 1, so it proved nothing about the screen layer. Re-driven on
`portal_drive` with the trigger present:

```
  CREATE modal fields: ["ba-label","ba-bank_name","ba-account_number","ba-account_name"]
  EDIT modal fields:   ["ba-label","ba-account_name"]
  bank_name rendered on edit:       false
  account_number rendered on edit:  false
  operator is told why:             true
  after saving the label: Zenith — Fees (main) | Zenith Bank | 1234567890 | Active
```

The identity fields are **absent** on edit, not disabled — which is the claim, since a disabled input
that posts anyway is not a guard. The label still saves, so the arm is not passing because editing is
broken outright.

## Not done, and out of scope by instruction

- **No `bank_account_id` anywhere.** No writer changes, no payment-modal changes. That is commit 2.
- **`deactivated_at` is not yet consulted by anything** — nothing offers a bank account to choose
  from until commit 2. The `active()` scope exists for that consumer and has no caller today.

## The commit was 89 files and should have been 18

Caught before the push, by reading `--stat` and noticing the number did not match the change.

`./vendor/bin/pint app/Finance app/Enums database/seeders database/migrations tests/Feature/Finance`
— Pint on whole DIRECTORIES — reformatted **71 unrelated files**: every enum, every seeder, and forty
of the migrations from April onward. All correct by the project's own style rules, and all noise that
would have buried an 18-file change and made the diff unreviewable.

**This happened three times on this work.** #223 ran Pint across `tests/`; this branch ran it across
five directories (89 files); and then — in the command written IMMEDIATELY AFTER adding the rule
"never run pint against a directory" to `CLAUDE.md` — `pint $(git diff --name-only HEAD …)` on an
already-clean tree expanded to **no arguments**, and `pint` with no path lints the whole project:
**172 files**.

That third one is the useful one, because it shows a documented habit is not the fix. The
hand-rolled substitute for `bin/lint-changed.sh` is booby-trapped: its failure mode is "do the
largest possible thing when given nothing", silently, exit code 0. `CLAUDE.md` now carries the
guarded form; the real fix is requirement 1 of the ticket, which removes the need to hand-roll it.

`bin/quality` was green on the 89-file version — correctly, since every one of those files was *more*
compliant afterwards. No gate in this repository objects to a commit that reformats a third of the
codebase as a side effect, and that is worth knowing rather than assuming the floor would have caught
it.

**The first revert silently did nothing.** `xargs -a /tmp/strays.txt git checkout 7de0671 --` ran,
exited 0, and left every file unchanged; the file count stayed at 89. Found by diffing one stray
rather than trusting the exit code, and redone as a `while read` loop. That is the third
mutation-did-not-land instance on this branch — after the trigger drop that `RefreshDatabase`
re-created, and the two literal matches against formatted artefacts.

## Findings raised, not fixed

- **Duty separation for commit 2.** Once `bank_account_id` is required on payments,
  `accounts_officer` will both record the money and define its destination. Today it attaches to
  nothing, so this is a question for the commit that attaches it, not this one. **ticket.**
- **Diff-aware gates report on an empty diff over uncommitted work.** Two instances now
  (`lint-changed` in #229, `grants-convergence-lint` here). Every `bin/ci-*-lint.php` taking a
  `$BASE` shares the shape; the fix that worked for `lint-changed` — print what was examined — would
  generalise. **ticket.**
- **#225's nav-coverage gate reads SOURCE TEXT, so it cannot see whether an entry actually renders.**
  It passed here while I briefly believed the entry was missing; had that been real, the gate would
  not have caught it. A runtime assertion would need a rendered sidebar, which is what the drive is
  for — worth stating as the gate's known limit rather than assuming it covers this. **ticket.**
