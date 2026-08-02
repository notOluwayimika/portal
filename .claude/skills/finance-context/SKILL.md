---
name: finance-context
description: Durable substrate facts about the Brookstone multi-school platform — money handling, the finance_* schema, spatie team-scoped RBAC, MySQL 8.0.43 behaviours that have bitten this project, the quality gates and the three fixture oracles. Load this alongside finance-method for ANY Brookstone task touching app/Finance, migrations, roles or permissions, money, tests, gates or baselines. Load it before writing a query, a migration, a brief or a review, so you start oriented instead of re-deriving the same facts. It is orientation, not conclusions — verify anything you are about to assert.
---

# Substrate

Everything here is a **durable** fact: pinned by a test, a migration or a
schema, and it changes only when someone deliberately changes it. Use it to
orient — where to look, what to suspect, what not to re-learn.

Nothing here is **state**. Who holds which role, what has shipped, what is done,
what a count is today — those go stale between sessions and become a lie
generator. Derive state from the repo or the database, every time.

And even for the durable facts: if you are about to assert one in a finding, a
brief or a review, open the file and confirm it first. This file makes you fast,
not right.

## Stack

Laravel 12-era, PHP 8.3, Inertia + React + Tailwind + shadcn-ui, TypeScript
strict, MySQL 8.0.43, Pest ^4.6, `spatie/laravel-permission` (teams = schools),
`spatie/laravel-activitylog`, `maatwebsite/excel`, wayfinder, Fortify.

## Boundaries

Finance is a bounded context at `app/Finance/` — requests at
`app/Finance/Http/Requests/`, controllers at `app/Finance/Http/Controllers/`.
Shared kernel lives in `app/Support/`, `app/Casts/`, `app/Concerns/`.

`bin/ci-boundary-lint.php` forbids `DB::table` on a `finance_` string literal
outside `app/Finance`.

## Money

Integer minor units, always. Column pairs `{name}_minor` + `{name}_currency`.
`App\Support\Money` with `App\Casts\MoneyCast`. Never a float, never a
`decimal:` cast.

- `Money::percentage(int)` — banker's rounding.
- `Money::times(int)` — exact.
- Division is `allocate(int $parts)`. There is **no** `split()`.
- `plus` / `minus` / `equals` throw on currency mismatch.
- `DEFAULT_CURRENCY = 'NGN'`.

The `*_currency` column population is **closed at 10**. Eight cast through
`MoneyCast`; two do not — `finance_discount_policies.value_currency` and
`finance_discount_policy_changes.value_currency`.

## Schema naming

The five `fee_*` tables were renamed to `finance_*` by
`database/migrations/2026_07_19_110000_rename_fee_tables_to_finance.php`,
including `fee_payments` → `finance_payments`. **Read the rename migration, not
the create filenames** — the creates still carry the old names.

Finance tables are append-only, enforced by named MySQL triggers signalling
SQLSTATE `'45000'` (driver code **1644**). `finance_student_accounts` is an
exception: it is a projection and carries no immutability trigger.

## Isolation

`school_id` is the only isolation boundary — `BelongsToSchool` + `SchoolScope`.
`super_admin` bypasses *authorization*, never *isolation* (ADR 0036).

## RBAC — spatie with teams

`model_has_roles` is **polymorphic**: `role_id`, `model_type`, `model_id`,
`school_id`. Filter `model_type = 'App\\\\Models\\\\User'` — doubled backslashes
in raw SQL.

`roles` is **also** team-scoped: it has a `school_id` column and a unique index
`roles_team_name_guard_unique` on `(IFNULL(school_id,0), name, guard_name)`.
Consequence: one role name can carry different grants per school today, with no
new tables. That is the mechanism C6 (per-school configurable authority matrix)
would use.

Team context is mandatory for assignment: `setPermissionsTeamId($school->id)`
then `unsetRelation('roles')` before `assignRole`, or you get
`NullTeamRoleAssignmentException`. See `User::grantSchoolAccess()`
(`app/Models/User.php:277-290`) and `ActiveSchool::runFor()`
(`app/Support/ActiveSchool.php:99-116`).

`rbac:sync` is **non-destructive in both directions**. For a role that already
exists it grants only permissions created in that same run
(`RbacSeeder::sync()`). So a grant-map edit that adds a permission which already
exists lands on fresh installs and silently does nothing on every environment
where the role row already exists. Both directions of that drift have needed
dedicated convergence migrations.

## Duty separation

Approval wiring is convention-derived: `ApprovalAbility::CHECKER_SEGMENTS =
['approve','reject']`, `terminalSegment()`, `matchingMakerFor()`,
`DutySeparation::pairs()`, and a `Gate::before` bypass exclusion (ADR 0040).

Three primitives, and **none is a superset of another**:

- `DutySeparation::violations($user, $schoolId)` — **user**-scoped. Walks
  holders. A both-sides role held by nobody is invisible to it.
- `DutySeparation::violationsFromRolePermissionSync($role, $abilities)` —
  **role**-scoped. Sees a role granting both sides even with zero holders. One
  caller: `app/Http/Requests/SyncRolePermissionsRequest.php:86`.
- `DutySeparation::assertAssignmentAllowed()` — **grant-time**. Throws before
  the write, and runs *only* on assignment. It therefore cannot see a violation
  created retroactively by a grant-map change to roles already assigned. That
  gap is real and has shipped a production hazard before.

`enforcedPairs()` filters `pairs()` to checkers starting `finance.` — a
deliberate blast-radius decision. When writing a guard inside a finance
migration, filter to `enforcedPairs()` or pre-existing `result.*` findings will
abort it.

## MySQL 8.0.43 — verified behaviours

- `NOT REGEXP BINARY` errors **3995** on utf8mb4. Use `COLLATE utf8mb4_bin`.
- `SUM(bool)` over an empty table returns **NULL**, not 0. Wrap in `COALESCE`.
- `REGEXP` is accepted inside `CHECK`, and `COLLATE utf8mb4_bin` takes effect there.
- CHECK violation = **3819**. Dropping an absent CHECK = **1091**.
- 8.0.43 rejects `DROP CHECK … IF EXISTS` and `DROP CONSTRAINT … IF EXISTS` (1064).
- **DDL commits implicitly.** `RefreshDatabase`'s transaction will not roll back
  a `CREATE TABLE`, so scratch tables must be dropped in a `finally`.
- `information_schema.CHECK_CONSTRAINTS` has **no `TABLE_NAME`**. Join
  `information_schema.TABLE_CONSTRAINTS` on `(CONSTRAINT_SCHEMA,
  CONSTRAINT_NAME)` filtered to `CONSTRAINT_TYPE = 'CHECK'`.

PDO `errorInfo`: `[0]` SQLSTATE, `[1]` driver code, `[2]` message. Per
`bootstrap/app.php`: 1062 → 409, 1451 → 409, 1205/1213 → 409. Everything else —
including 1452, 1644, 3819, 3995 — falls through to a generic 500.

## Gates and oracles

`bin/quality` is a 12-step script; `core.hooksPath = .githooks` and
`.githooks/pre-push` runs it. `bin/quality:146` runs the full Pest suite with no
group filter.

Three fixture oracles, regenerated in this strict order:

1. `rbac:sync`
2. `rbac:derive-access`
3. the baselines — `tests/fixtures/route-access-map.json`,
   `tests/fixtures/rbac-grants-baseline.json`,
   `tests/fixtures/route-middleware-baseline.json`

## Two rules that are commonly misstated

**"Every migration is reversible" is not a rule here.** At least two migrations
carry a deliberate, documented no-op `down()` —
`2026_08_02_100000_realign_finance_governance_grants.php` and
`2026_08_03_100000_converge_finance_change_grants.php` — because rolling them
back would restore a broken governance state. Roll forward with a new named
migration. Reviewing against a blanket reversibility rule produces wrong
findings.

**"Baselines only shrink" governs the *ratchet* baselines specifically** (ADR
0041; `docs/handoff/c1-brief.md:187`). Flattened across all fixtures it is
false: `route-middleware-baseline.json` legitimately gains entries when routes
are added. And `docs/handoff/slice-2-brief.md:67` records the ratchet rule as
currently **unenforced** — which by the wallpaper principle is itself the more
interesting fact.

## Environment mechanics

The local database is `portaa10_portal`, a copy of production, and it is where
findings are derived. But the advising side's VM has **no `mysql` client, no
`php`, no `docker` and no network**, and MySQL listens on `127.0.0.1` of the
project lead's machine. SQL therefore cannot be executed from the advising side
at all: database findings must be written into the implementing-agent brief as
queries or artisan calls the agent runs, never handed over as a separate ask.
