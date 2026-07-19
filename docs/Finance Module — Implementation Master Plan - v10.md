# Finance Implementation Specification v10 (Clean Edition)

**Brookstone School Management System — Invoicing & Receivables**
**Status:** Approved for implementation · **Timeline:** ≈44 weeks · **Source of truth for development.**

> This document supersedes the original implementation plan and all addenda. It contains only approved decisions.
> A **Change Summary** at the end records what was consolidated and removed.
>
> ⚠️ **Read §28 before actioning §1 or §4.4.** This spec's §1 Executive Summary and §4.4 Technical-Debt table describe the **project-start baseline** — they are preserved as the historical "why," not as current state. As of **2026-07-19**, most of Phase 1 has landed and a Finance walking skeleton is frozen as the module template (verified against the repo). **§28 — Reconciliation with delivered state** holds the per-item status and maps these 14 phases onto the walking-skeleton handoff's slices and F1–F6 invariants (`docs/handoff/session-2-start.md`).

---

## Table of Contents

| § | Section |
|---|---|
| 1 | Executive Summary |
| 2 | Business Context |
| 3 | Terminology |
| 4 | System Architecture |
| 5 | School Isolation Model |
| 6 | Identity Model |
| 7 | RBAC Strategy |
| 8 | Shared Kernel |
| 9 | Module Blueprint |
| 10 | Module Contracts |
| 11 | Architecture Constitution |
| 12 | Financial Architecture |
| 13 | Domain Events |
| 14 | Core Domain Model |
| 15 | Folder Structure |
| 16 | Engineering Standards |
| 17 | CI Requirements & Enforcement |
| 18 | Documentation Strategy |
| 19 | ADR Register |
| 20 | Implementation Phases |
| 21 | Dependency Matrix |
| 22 | Risks |
| 23 | Timeline & Milestones |
| 24 | Acceptance Criteria |
| 25 | Verification Checklist |
| 26 | Open Questions |
| 27 | Change Summary |
| 28 | Reconciliation with delivered state (2026-07-19) |

---

# 1. Executive Summary

Brookstone requires an Invoicing & Receivables module for its existing School Management System (Laravel 13 + React 19 + Inertia 3). The specification is not a billing screen — it is a **financial control system**: segregation of duties, maker–checker approval on ten transaction classes, an immutable audit trail, financial period locking, an exception dashboard, deferred income recognition, and Sage 50 output.

**The platform cannot support those controls today.** The School isolation and data layers are well-built, but the authorization layer has been hollowed out: 52 authorization checks are commented out in production code, 19 of 32 Permissions are never seeded, there are zero Policy classes and zero `Gate::` usage, and a live cross-School IDOR exists. Money, locking, idempotency, sequences, PDF generation, runtime configuration and production observability do not exist at all. CI has never passed.

> **Reconciliation — 2026-07-19, verified against the repo.** The paragraph above is the **project-start baseline**, retained for its rationale, not current state. Since then: the IDOR is closed, Permission seeders are wired, `.env.example` exists and CI runs its gates, `Money` and `Sequences` are built, and a Finance walking skeleton is frozen as the module template. The commented-authz count is down from 52 to **10 baselined** legacy checks (lint-gated in `lint.yml` + `composer ci:check`, ratcheting to zero). Still genuinely absent: the generic **Approvals** engine, **Idempotency**, **PDF**, **FeatureFlags**, **observability**, and the **`finance_student_accounts`** lock anchor. Full per-item status and the walking-skeleton mapping are in **§28**.

**Approach:** a **6-week Engineering Foundation phase** repairs and hardens the platform, followed by **13 independently shippable Finance phases**. Total ≈44 weeks with 2 developers.

**Four decisions define the architecture:**

| Decision | Rationale |
|---|---|
| **`school_id` is the sole isolation boundary** | Each School is an independent legal and accounting entity owning its own Students, Staff, Academics and Finance. One boundary serves business, security, accounting and operations. |
| **Append-only Ledger + `finance_student_accounts` lock anchor** | Makes deferred income (§9), Sage 50 journals (§13) and future GL (§19) cheap; the account row is the lockable projection that makes concurrent allocation safe. |
| **`app/Finance/` as the reference Module** | Finance's invariants (append-only, statutory numbering, maker–checker) cannot share a boundary with Academics. Establishes the Blueprint every future Module follows. |
| **Shared Kernel owns cross-cutting concerns** | Money, Sequences, Approvals, Idempotency, PDF and Feature Flags are platform concerns. Finance must never own them, or Admissions ends up depending on Finance. |

**Three items gate all Finance work, all in Phases 1–2:**
1. `finance_student_accounts` — you cannot `lockForUpdate` a `SUM()`; without a lock anchor, concurrent payments double-spend a credit balance.
2. **Observability** — no error tracking exists; a failed allocation is silent.
3. `model_has_roles` as the single source of School access, `ActiveSchool::runFor()` for off-request context, and `students.status` for billing eligibility.

---

# 2. Business Context

## 2.1 Confirmed business model

- The system is built **exclusively for Brookstone**. One organisation. **No other organisations will be onboarded.** It is not a SaaS platform, and Brookstone itself is **not an entity in the system**.
- The `schools` table represents Brookstone's **independent operational Schools**: Primary, Secondary, IFY Abuja, IFY Lagos, and future Schools.
- **"IFY Abuja" and "IFY Lagos" are independent Schools, not branches.** There are no branches, campuses, divisions or hierarchy.
- Each School is an **independent legal and accounting entity** with its own books, its own Sage 50 company file, and **potentially several bank accounts** for different fee categories (Tuition, Boarding, PTA, Transport).
- Each School independently owns: Students · Staff · Admissions · Academics · Finance · Reports · Configuration · RBAC scope.
- **Cross-School financial operations are prohibited.** A Guardian with children at two Schools pays each School separately.
- **Progression (Primary → Secondary → IFY) is a NEW admission.** Records remain with the originating School. No cross-School identity, history or balance carry-over.
- **A user may belong to several Schools with different roles per School** (e.g. Secondary → Teacher, IFY Abuja → Coordinator, Primary → no access). Access is granted by administrators.

## 2.2 Requirements source

`plan_docs/Brookstone_Portal_Requirements_Draft.pdf` — 19 sections. Referenced throughout as §1–§19.

## 2.3 Scope boundaries

**In scope:** invoicing · payments & allocation · advance/credit balances · discounts & scholarships · approvals · statements · notifications & dunning · reporting · period controls · audit dashboard · Paystack · Sage 50 export · WCBS migration.

**Explicitly out of scope:**

| Item | Decision |
|---|---|
| General Ledger, AP, Procurement, Payroll, Inventory, Fixed Assets, Budgeting (§19) | The Ledger makes these *possible later*. Not built now. |
| Inter-company accounting | Cross-School payments are prohibited. Never built. |
| In-app backup/restore (§18) | **Rejected as an application feature.** A restore button contradicts §15C (nothing deleted) and §15E (period locking). Delivered as infrastructure: automated snapshots + a **rehearsed** restore runbook. |
| Cross-School reporting | Not required. If ever needed: a read model iterating Schools — no new entity. |

---

# 3. Terminology

**Use these terms exactly. "Tenant", "organisation", "division", "campus" and "branch" must not appear in code, comments, ADRs or documentation.**

| Term | Meaning |
|---|---|
| **School** | An independent Brookstone operational School and legal entity. The isolation boundary. Table: `schools`; key: `school_id`. |
| **School isolation** | The row-scoped mechanism enforcing that boundary. *(Not "multi-tenancy".)* |
| **Active School** | The School a request or job is currently operating within. Resolved by `ActiveSchool`. |
| **Student** | A person enrolled at one School. School-scoped. Also the admission record. |
| **Guardian** | A Student's bill payer at one School. School-scoped. |
| **Staff** | A `User` holding a School-scoped staff Role. Not a separate entity. |
| **Shared Kernel** | Platform code every Module may depend on. Never depends on a Module. |
| **Module** | A self-contained domain: `app/Finance/`, later `app/Admissions/`, `app/HR/`… |
| **Domain** | The business area a Module models. |
| **Ledger** | The append-only financial record. Authoritative. |
| **Approval** | A maker–checker request. Generic engine in the Shared Kernel. |
| **Permission** | A dotted capability (`finance.invoice.approve`), evaluated in the Active School. |
| **Policy** | The authorization layer. Owns record-level rules. |
| **Action** | A final, single-public-method class owning exactly one transaction. |
| **Service** | Orchestration and read models. Never opens a transaction. |
| **Contract** | A Module's public interface for synchronous calls from other Modules. |

---

# 4. System Architecture

## 4.1 Stack

Laravel 13 · PHP 8.3 (CI matrix 8.3/8.4/8.5) · Inertia 3 · React 19.2 · Vite 8 · Tailwind 4 · shadcn/ui · TypeScript 5.7 (`strict`) · MySQL · `spatie/laravel-permission` 7.4 (teams) · `spatie/laravel-activitylog` 4.10 · `laravel/wayfinder` · `maatwebsite/excel`.

## 4.2 Current state

A **flat Laravel application**, ~21,000 lines in `app/`. No Modules, no bounded contexts.

| Layer | Count | Assessment |
|---|---|---|
| Models | 40 | Hybrid ID: `bigint` PK + `uuid` route key |
| Controllers | 48 | 34 JSON · 7 Inertia · some both |
| Services | 21 | Constructor-injected, stateless — the healthiest layer |
| **Policies** | **0** | **Record-level authorization does not exist** |
| **Events** | **0** | **No event bus — no seam for Finance** |
| Actions / DTOs / Repositories | 2 / 3 / 3 | Vestigial |

**Request flow:** Inertia renders page shells; React calls `/api/*` via axios for data.

## 4.3 Target state

```
┌─ SHARED KERNEL ─ never depends on a Module ────────────────────┐
│  Identity: User · School · Role · Permission                    │
│  Support:  ActiveSchool · Money · Sequences · Approvals         │
│            Idempotency · FeatureFlags · Pdf                     │
│  Cross-cutting: Audit · Notifications (channels) · Events bus   │
└────────────────────────────────────────────────────────────────┘
        ▲                                    ▲
        │ depends on                         │ depends on
┌───────┴──────────┐              ┌──────────┴─────────────────┐
│  app/Finance/    │◄─ events ────│  LEGACY (flat app/)        │
│  reference Module│   contracts  │  Students · Guardians ·     │
└──────────────────┘              │  Academics — migrates       │
                                  │  opportunistically          │
                                  └────────────────────────────┘
```

**Coupling flows one way:** Modules → Shared Kernel. Modules never depend on each other's internals.

## 4.4 Technical debt (must be resolved in Phase 1)

> **Baseline at project start. See §28.1 for the current status of every row** — as of 2026-07-19 all but items 2, 7, 11 and 13 are resolved, and 2/7 are enforced-but-staged rather than open. Do not re-do this work without checking §28.

| # | Debt | Impact |
|---|---|---|
| 1 | **Live cross-School IDOR** — `ActivityLogController::downloadExport` has permission check, filename regex and existence check all commented out; `$filename` flows unvalidated into `Storage::download`. Filenames are predictable. | Any staff member in any School can download any other user's audit export. |
| 2 | **52 authorization checks commented out** — GuardianController (16, incl. credential changes), StudentSubjectController (8), CurriculumSubjectController (8), ActivityLogController (8), GuardianImportController (6), others (6). `routes/api.php:217` claims per-endpoint gating that does not exist. | Route `role:` groups are the only gate. |
| 3 | **19 of 32 Permissions never seeded** — `ArmsDatabaseSeeder` has two seeders commented out. Spatie fails closed *silently*, so 7 live `authorize()` calls return `false` for everyone. | Permission state is unverifiable. |
| 4 | **`terms` has no `school_id`** — `ResolvesTermFilter:32` returns the first active Term **across all Schools**. Used by 4 controllers. | Term is the billing period. |
| 5 | **`ClassLevelArm` unscoped** — imports `BelongsToSchool`, never applies it; `school_id` absent from `$fillable`. Same for `MarkingComponent`. | Class-based fee schedules leak. |
| 6 | **Jobs have neither School nor Permission context** — jobs impersonate the causer (`auth()->setUser()`); a super-admin causer yields **no** School. `setPermissionsTeamId()` is never called in workers, and `PermissionRegistrar` is a singleton that leaks between jobs. | Async Finance is unsafe. |
| 7 | **`SchoolScope` fails open** — no auth ⇒ no scope; `catch (\Throwable)` returns an unscoped builder. | Console/queue reads span Schools. |
| 8 | **6 API endpoints publicly reachable** — declared above the auth group in `api.php:36-55`, re-declared inside it. Laravel takes the first. | Unauthenticated data access. |
| 9 | **CI has never passed** — `tests.yml` runs `cp .env.example .env`; the file does not exist. `lint.yml` runs fixers (`pint --parallel`, `prettier --write`, `eslint --fix`) then discards them; the commit step is commented out. `tsc` is in no workflow. `composer ci:check` exists and is unused. | No enforcement. |
| 10 | **`phpunit.mysql.xml` targets `portal-live` as `root`** with `RefreshDatabase` in 27/30 test files. | Running it truncates that database. |
| 11 | **143 TypeScript errors** (live `tsc --noEmit`); committed `tsc_errors.log` claims 51 and is stale. Was 101 two months ago. | Trend is the concern. |
| 12 | **2 factories for 40 models, both broken** — `UserFactory` inserts a non-existent `name` column; `GuardianFactory` calls `School::factory()` but `School` has no `HasFactory`. Tests use `forceCreate`, which **bypasses casts**. Tests run SQLite; production runs MySQL. | The money layer would be untested. |
| 13 | **No observability** — no Sentry/Bugsnag/Telescope/Horizon/Pulse. No `failed_jobs` alerting. | A failed allocation is silent. |
| 14 | **Queue misconfiguration** — `retry_after=90` vs job `timeout=3600` (duplicate batches); `after_commit => false`. | Duplicate invoices. |
| 15 | **`guardian_student` has no same-School constraint** — only `unique(guardian_id, student_id)`. | Allocation could cross Schools. |
| 16 | **`students` has no `status`** — see §12.6. | Cannot bill before a term starts. |
| 17 | **Racy `HasAdmissionNumber`** — read-then-write, no lock, no unique index. | Concurrent admissions collide. |

---

# 5. School Isolation Model

## 5.1 Principle

**`school_id` is the single business, security, accounting and operational boundary.** One School = one legal entity = one set of books = one isolation scope. Every row belongs to exactly one School — except `User`.

## 5.2 Enforcement points

**All nine must be satisfied by every Module.**

| # | Point | Mechanism |
|---|---|---|
| 1 | Query | `SchoolScope` + `BelongsToSchool` — **fails closed** |
| 2 | Write | `BelongsToSchool::creating` auto-fills `school_id` |
| 3 | Schema | FK to `schools`; composite uniques include `school_id` |
| 4 | Authorization | Spatie teams = `school_id`; Policies |
| 5 | Request context | `SetSchoolContext` middleware |
| 6 | Off-request context | `ActiveSchool::runFor()` |
| 7 | Cache | key contains `school_id` |
| 8 | Files/exports | path partitioned by `school_id` |
| 9 | Audit | `activity_log.school_id` |

## 5.3 Active School resolution — one carrier per transport, no fallback

```
HTTP (web)    → session('school_id')
HTTP (api)    → personal_access_tokens.school_id
Off-request   → ActiveSchool::runFor($schoolId, $cb)     EXPLICIT
Fallback      → NONE. No context = exception, never a guess.
```

**`users.school_id` is removed.** It was misused as an authorization fallback in three independent places, each commented as if intentional. A column that must never be read in an authorization path, sitting in the most obvious place to read one, will keep being read.

## 5.4 `ActiveSchool::runFor()` — the one context primitive

```php
ActiveSchool::runFor(int $schoolId, Closure $cb): mixed
// sets School context + setPermissionsTeamId($schoolId) + unsetRelation('roles')
// runs $cb
// ALWAYS restores previous context in a finally block
```

The `finally` restore prevents the `PermissionRegistrar` singleton leaking a team id into the next job on a long-running worker.

**Propagation strategy — standardized:**

| Surface | Strategy |
|---|---|
| HTTP | `SetSchoolContext` middleware → session/token |
| Queued jobs | Job carries `public readonly int $schoolId`; `SchoolAware` middleware wraps `handle()` in `runFor`. **`auth()->setUser($causer)` is banned.** |
| Scheduled commands | Explicit iteration: `foreach (School::all() as $s) ActiveSchool::runFor($s->id, fn () => …)`. Never ambient. |
| Notifications | School data passed **by value** at construction. Never resolved in the worker. |
| Events/listeners | Event carries `schoolId`; queued listeners use `SchoolAware`. |
| Cache | Key always includes `school_id`. |
| File exports | `exports/{schoolId}/{userId}/{uuid}.csv` + DB row; served by DB id, **never by filename**. |
| Audit | `ActivitySchoolResolver` prefers `ActiveSchool::id()`. |

## 5.5 Fail closed

Querying a School-scoped model with no Active School **throws `MissingSchoolContextException`**. The sole sanctioned escape is `Model::withoutSchoolScope()` — explicit, greppable, **banned in `App\Finance\*`**. Seeders and migrations opt out explicitly. Roll out per-model, never big-bang.

## 5.6 The four escape hatches

`SchoolScope` cannot close these. **All four are banned in Module code and enforced by arch test:**

`withoutGlobalScope` · `?? $user->school_id` · `auth()->setUser($causer)` · **`DB::table()`**

`DB::table()` matters most — it bypasses Eloquent and therefore the scope entirely.

---

# 6. Identity Model

## 6.1 `User` is the only cross-School entity

`User` = identity + credentials. **Deliberately exempt from `SchoolScope`** — scoping it breaks authentication. One human = one `User` = **one audit identity**, which is the basis of maker–checker.

## 6.2 Student, Guardian, Staff are School-scoped roles a person plays

| Entity | Scope | Notes |
|---|---|---|
| `Student` | School | Also the admission record. `UNIQUE(school_id, admission_number)`. |
| `Guardian` | School | `user_id` → `User`. One row **per School**. |
| Staff | School | Not an entity — a `User` with a School-scoped Role. |

**A Guardian with children at two Schools has two `Guardian` rows and one `User`.** This is correct under independent Schools, not a defect. Accepted cost: a phone-number change is made twice.

## 6.3 No `Person` entity

A `Person` would unify identity **across** Schools. Every requirement that would justify it is prohibited: cross-School student identity, cross-School billing, cross-School history. The one legitimate cross-School identity need — one human, one credential, one audit trail — **is already `User`**. A `Person` would be an entity with no consumer.

## 6.4 No `Admission` entity

`Student` carries `admission_number` + `admission_date`. **`Student` is the admission record.** Progression = a new `Student` row at the receiving School. A future Admissions Module adds an application → offer → acceptance pipeline and emits a `Student` as its output. `students.previous_school` (free text, exists) is the soft provenance link. **No FK** — it would invite cross-School joins.

## 6.5 Single login with School switching

**Decision: single login.** Justification:

| | Single login | Separate logins |
|---|---|---|
| Audit identity (§15C) | ✅ one causer id | ❌ fragmented |
| **Maker ≠ checker (§15B)** | ✅ enforceable | ❌ **defeatable** — raise as `jane.primary@`, approve as `jane.secondary@` |
| 2FA for approvers | ✅ once | ❌ N enrolments |
| Offboarding | ✅ once | ❌ miss one, access persists |
| Isolation benefit | — | ❌ **none** — same DB, same scope |

The maker–checker argument is decisive: separate logins would silently defeat the specification's central fraud control.

**Accepted cost — wrong-School posting.** Mandatory mitigations (Phase 6): persistent high-contrast School indicator; target School named on every financial write confirmation; explicit School confirmation on period close, approvals and refunds; School name on every invoice/receipt preview.

---

# 7. RBAC Strategy

## 7.1 Single source of truth

```
model_has_roles (school_id)   ← access AND authority, inseparable
  • a row  = access + role in that School
  • no row = no access          ("Primary → No Access")
```

`users.school_id`, the `school_user` pivot and the guardian-derived branch are **all removed**. Five mechanisms collapse to one.

**Migration (expand/contract):**
1. Backfill `users.school_id` + `school_user` → `model_has_roles` rows.
2. **Parity test**: new `accessibleSchoolIds()` returns an identical set per user. **Gate on green.**
3. Stop reading the old sources. Columns remain unused for one release.
4. Drop `users.school_id` and `school_user`.

## 7.2 Per-School roles

Spatie teams keyed on `school_id` already expresses *"Secondary → Teacher, IFY Abuja → Coordinator, Primary → no access"* exactly. **No change to the role model.**

Roles are **bundles of Permissions, never checked directly in business logic**. `hasRole()` is banned in Module code.

**Finance roles:** `accounts_officer` · `accounts_supervisor` · `head_of_account` · `internal_auditor`.
**Group-level roles** (Finance Director, Group Administrator, ICT Administrator, Registrar) are granted **per School, explicitly**. There is no global role concept — `super_admin` remains the sole team-less role (platform support, not a business role).

**Coverage gaps are detected, not automated.** The Audit Dashboard (Phase 11) reports *"Internal Auditor lacks access to School Y"*. This satisfies §15A's audit-coverage requirement with zero schema, and turns a silent gap into a detected exception.

## 7.3 Permissions

- **Naming:** `finance.<resource>.<action>` — dotted, lowercase, snake action.
- Backed by a **`Permission` enum**. No magic strings. Wildcards are disabled — enumerate explicitly.
- **One seeder**, wired into `DatabaseSeeder`, with **a test asserting the exact Permission/Role set**.

## 7.4 Authorization chain

```
request → SetSchoolContext → setPermissionsTeamId($activeSchoolId) → unsetRelation('roles')
        → $user->can('finance.invoice.approve')          ← resolved in THAT School's team
        → Policy: $this->authorize('approve', $invoice)  ← + record-level rules
```

- **Policies are the enforcement layer.** Route `role:` middleware is coarse defence-in-depth only. Middleware cannot see the record, so it can never express *"approver ≠ maker"*, *"amount ≤ your limit"*, or *"your own ward's invoice"*.
- **`Gate::before`** grants the super-admin bypass, resolving `isSuperAdmin()` in a **null-team** context.
- **Cache `accessibleSchoolIds`** (`user:{id}:schools`), invalidated on grant/revoke — `SetSchoolContext` calls `canAccessSchool()` every request.
- Permissions are shared to Inertia; `usePermissions` hook + `<Can>` component. `rolesFull` is no longer shipped.

## 7.5 Audit of RBAC

`Role`/`Permission` gain `LogsActivity`; `config/permission.php` sets `events_enabled => true`. Privilege escalation must leave a trace (§15C).

---

# 8. Shared Kernel

## 8.1 Boundary

**Shared Kernel = anything two Modules could plausibly need, or that exists before any Module does.**

```
app/Models/          User · School · Role · Permission
app/Support/
  ├── ActiveSchool/      School context + runFor()
  ├── Money/             Money VO · rounding policy
  ├── Sequences/         gap-free per-School numbering
  ├── Approvals/         generic maker–checker engine
  ├── Idempotency/       keys + middleware
  ├── FeatureFlags/      per-School gating
  └── Pdf/               rendering engine
app/Casts/           MoneyCast
app/Concerns/        BelongsToSchool · AddUuid · HasFullName
app/Enums/           cross-cutting only (Permission, Gender…)
app/Exceptions/      MissingSchoolContextException …
app/Notifications/   channel infrastructure (mail, SMS driver)
app/Providers/
```

**Kernel tables are unprefixed:** `sequences`, `approval_requests`, `idempotency_keys`.

## 8.2 Why these are shared, not Finance's

| Concern | If it lived in Finance… |
|---|---|
| **Sequences** | **Admissions would depend on Finance** for admission numbers. *(Shared Sequences also fixes the racy `HasAdmissionNumber`.)* |
| **Approvals** | **HR would depend on Finance** for leave approvals. The engine is already polymorphic by design; placing it in a Module and extracting later is pure waste. Finance supplies only the amount limits. |
| **Money** | Payroll, Procurement and Inventory valuation could not use it. |
| **Pdf** | Result cards need the engine as much as invoices do. Templates stay Module-owned. |

## 8.3 The non-negotiable rule

**`App\Support\*` and `App\Models\{User,School,Role,Permission}` may not reference any `App\<Module>\*`.** Enforced by arch test. A Kernel depending on a Module is a circular dependency.

## 8.4 Legacy

`app/Models/{Student,Guardian,Term,Curriculum…}` and the flat Services/Controllers are **not yet extracted**. They migrate opportunistically when touched. **Never a big-bang refactor.** A flat `app/` coexisting with `app/Finance/` is an acceptable steady state.

---

# 9. Module Blueprint

**Every Module — Finance, Admissions, Academics, HR, Library, Inventory, Payroll — uses this exact shape.** `app/Finance/` is the reference implementation, not a special case.

```
app/<Module>/
├── Contracts/       PUBLIC — what other Modules may call
├── Events/          PUBLIC — facts other Modules may react to
├── Enums/           PUBLIC — vocabulary used in contracts/events
│                    ── everything below is PRIVATE ──
├── Models/          School-scoped; BelongsToSchool mandatory
├── DTOs/            validated, typed, behavioural
├── Actions/         final · one public method · owns ONE transaction
├── Services/        orchestration + read models
├── Policies/        authorization; the enforcement layer
├── Listeners/       reactions to own + other Modules' events
├── Jobs/            carry school_id; SchoolAware middleware
├── Http/
│   ├── Controllers/ validate → authorize → delegate → respond
│   ├── Requests/    authorize() + rules()
│   └── Resources/
├── Support/         Module-internal helpers only
└── Exports/

routes/endpoints/<module>.php       → required into api.php
database/migrations/                → central (Laravel default)
resources/js/pages/admin/<module>/
resources/js/components/<module>/
tests/Feature/<Module>/  tests/Unit/<Module>/  tests/Arch/<Module>Test.php
docs/<module>/domain-model.md + runbooks/
```

**A Module is done when:** every Model is School-scoped · every controller action authorizes · every Action owns its transaction · arch tests pass · the public API is only `Contracts`/`Events`/`Enums` · it has a domain-model doc and an ADR per architectural decision.

**Adding a Module requires no platform change.**

---

# 10. Module Contracts

**A Module's public API is exactly three namespaces.**

| Namespace | Visibility |
|---|---|
| `App\<Module>\Contracts\*` | **public** — synchronous reads/commands |
| `App\<Module>\Events\*` | **public** — facts to react to |
| `App\<Module>\Enums\*` | **public** — vocabulary |
| `Models\` `Services\` `Actions\` `Jobs\` `Policies\` `Http\` `Support\` | **private** |

| Need | Mechanism |
|---|---|
| Read another Module's data | **Contract** — never its Models or tables |
| React to something happening | **Domain event** |
| Cause a change in another Module | **Contract**, explicitly |
| Query another Module's tables | **Banned** — fails CI |

**Coupling flows one way: the reactor depends on the published fact, never the reverse.** An event lets Academics stay ignorant of Finance; a contract gives Dashboard a typed, null-implementable dependency.

**Reference cases:**
- `ModuleClassificationService` currently reads `fee_invoices`/`fee_payments`/`fee_structures` via `DB::table()`. It will depend on a **`FinanceModuleStatus` contract** with a null implementation when Finance is disabled.
- Enrollment reaches Finance via **`StudentEnrolled`**, never by wiring `FinanceService` into `CurriculumEnrollmentService`.

---

# 11. Architecture Constitution

> **Non-negotiable. Violations fail CI, not code review. Changing any rule requires an ADR.**

**Boundaries**
1. **`school_id` is the only isolation boundary.** No cross-School reads, writes or transactions — ever.
2. **Modules own their data.** No Module reads another's tables, models or repositories.
3. **No cross-Module database coupling.** `DB::table()` on another Module's tables is banned.
4. **No circular dependencies.** The Shared Kernel never depends on a Module.
5. **A Module's public API is `Contracts`/`Events`/`Enums`.** Everything else is private.

**Layering**
6. **No business logic in controllers.** Controllers validate, authorize, delegate, respond.
7. **Actions own transactions.** Services orchestrate and query. Controllers never open a transaction.
8. **Policies own authorization.** Route middleware is defence-in-depth, never the only gate.
9. **Events for cross-Module side effects. Contracts for cross-Module reads.**

**Money & data**
10. **Money is integer minor units + explicit currency.** Never float. Never `decimal:N`.
11. **The Ledger is append-only.** Corrections are contra entries. Nothing is ever deleted.
12. **Balances derive from the Ledger** and materialize only as a **lockable projection**.
13. **School context is explicit or absent** — never inferred, never defaulted from a user column.
14. **The audit log is permanent** and delete-protected at the database level.

**Process**
15. **Authorization checks are never commented out.**
16. **Architecture changes require an ADR.**

---

# 12. Financial Architecture

## 12.1 Append-only Ledger

The AR subsidiary ledger is an **immutable, append-only, double-entry-ready transaction log**.

```
finance_ledger_entries   (INSERT only; no UPDATE, no DELETE)
  school_id · student_id · term_id (nullable)
  account (enum) · debit · credit · currency
  transaction_id · transaction_type
  posted_at · effective_at
  created_by · approved_by · reason
  batch_uuid · source · source_ref
```

**Consequences:**
- **Reversal is a contra entry**, not an `UPDATE` — satisfies §10 and §15C by construction.
- **Advance payment / credit balance** are accounts, not special-cased columns — satisfies §1 naturally.
- **Deferred income (§9)** = post to `deferred_income`, recognize into `revenue` on term start.
- **Sage 50 export (§13)** = a projection over Ledger entries.
- **Future GL/AP/Payroll (§19)** = new account types writing to the same Ledger.

Invoices, payments, credit notes and refunds are **documents that emit Ledger entries**. Documents stay user-facing; the Ledger stays authoritative.

## 12.2 `finance_student_accounts` — the lock anchor

**You cannot `lockForUpdate` a `SUM()`.** Without a lockable row, two concurrent payments read the same credit balance and both spend it — a **write-skew** anomaly that MySQL's default `REPEATABLE READ` does not prevent.

```
finance_student_accounts
  school_id · student_id · UNIQUE(school_id, student_id)
  balance_minor · credit_minor
  version
```

- **One row per (School, Student).** Every allocation `lockForUpdate`s it **first**, then reads/writes the Ledger.
- **The Ledger stays authoritative.** The account row is a projection, rebuildable at will.
- **A reconciliation job** re-derives every balance from the Ledger and asserts equality. Drift raises an exception (§15F).
- It is simultaneously the lock anchor, the balance cache and the reporting projection — one table resolving three problems.

## 12.3 Money

- **Integer minor units** (`bigint`, kobo) + explicit `currency` (ISO 4217, default `NGN`).
- **Never float. Never `decimal:N`** — Laravel's `decimal` cast is `number_format`; it returns a *string*. It is a formatter, not arithmetic-safe.
- `Money` VO + `MoneyCast` in the Shared Kernel.
- **A written rounding & allocation policy** (banker's vs half-up; who absorbs the remainder when splitting across siblings), signed before the first migration.
- `formatNaira()` on the frontend.

## 12.4 Concurrency & idempotency

- `lockForUpdate` on the `finance_student_accounts` row — **acquired first**.
- `DB::transaction($cb, attempts: 3)` as the house default for Finance writes.
- **Idempotency-key table + middleware** — mandatory for Paystack webhooks and the "record payment" form.
- `ShouldBeUnique` on generation and dunning jobs.
- Queue config: `after_commit => true`; `retry_after` reconciled against job `timeout`.

## 12.5 Sequences (§16)

Gap-free **per School** (statutory — each School is a legal entity), with a **School prefix** so references never collide:

```
SEC/INV/2026/000123      PRI/INV/2026/000047
```

**The trade-off, stated explicitly:** you cannot have *gap-free* **and** *batch-reserved* **and** *rollback-safe* simultaneously. One lock per invoice serialises §18's bulk generation (1,200 invoices = 1,200 lock cycles holding the row lock for the whole batch). **Reserve the block in a single statement** (`UPDATE … SET next_value = next_value + N`) inside the transaction and assign from it. Gaps then occur only on genuine rollback — **and must be logged and explained**. Brookstone Finance signs the gap policy in `accounting-policy.md`.

## 12.6 `students.status` — billing eligibility

**Required before Phase 5.** `students` has no status column; `StudentStatusEnum` lives on `StudentCurriculum` — the *enrollment*.

Two concepts are conflated:

| Concept | Question | Belongs on |
|---|---|---|
| **School membership** | *"Is this person a Student at this School?"* | `Student` |
| **Term enrollment** | *"Are they in JS1 this term? promoted? repeating?"* | `StudentCurriculum` |

**Why it blocks Finance:** §9 requires billing **before the term begins**, when no enrollment row exists. And without it, a departed Student is billed forever unless soft-deleted — which destroys the reference their financial history depends on (§15C).

```
students.status        enum(active, withdrawn, graduated, transferred) default 'active'
students.left_at       timestamptz nullable
students.leave_reason  string nullable
index (school_id, status)
```

Billing eligibility becomes one indexed predicate. This also makes progression-as-new-admission expressible: the Primary record is set `graduated`/`transferred` and **retained**; a new `Student` is admitted at Secondary.

## 12.7 Allocation engine (§5)

**Always within one School.** One payment → open invoices of a Guardian's Students **in that School only**.

- Configurable rules: oldest-invoice-first · exact-per-child · equal distribution · manual.
- Every allocation and reallocation is fully traceable.
- Payments record `bank_account_id` (where money landed). **Bank account does not constrain allocation** — a Tuition-account payment may settle a Boarding line — but a mismatch raises a flagged exception (`warn` default, `strict` optional per School).
- **`guardian_student` gains a same-School constraint.** Nothing currently prevents a Primary Guardian linking to a Secondary Student, which the engine would follow into another School's invoices.
- `Student::primaryGuardian()` is a `BelongsToMany` and nothing enforces exactly one primary — handle 0 and 2+ explicitly.

## 12.8 Bank accounts (per School, per fee category)

```
finance_bank_accounts
  school_id · uuid · name · bank_name · account_number · account_name
  ledger_account_code · currency · is_default · active
  unique(school_id, account_number)

fee_components.bank_account_id   ← nullable; which account this fee is banked into
fee_payments.bank_account_id     ← required; where the money landed
```

**Each bank account is a distinct asset account in the chart** — `Dr Bank:Tuition`, never a generic `Dr Bank`. Reconciliation (§8) and Daily Collections (§12) are per bank account. Paystack maps a subaccount / dedicated virtual account per bank account. Sage 50 maps bank codes per bank account.

## 12.9 Approval workflow (§15B)

**One generic engine in the Shared Kernel**, not ten.

```
approval_requests
  school_id · approvable_type · approvable_id · action (enum)
  requested_by · requested_at · reason · payload (json)
  state (pending|approved|rejected|expired)
  decided_by · decided_at · decision_reason · amount_minor
```

- **Maker ≠ checker enforced at the Policy layer AND with a DB check constraint.** Two layers, because §15B is a fraud control.
- **Approval limits (§17)** are per-role, per-action, per-amount configuration rows — supplied by Finance, not baked into the engine.
- The pending queue feeds the Audit Dashboard's "outstanding approvals" tile.

## 12.10 Configuration (§17) — bounded, not a DSL

**Per School. No inheritance — each School configures itself.**

Unbounded configurability produces a rules engine nobody can operate and nothing can test. **Data-driven configuration with a fixed schema per category**: billing frequencies, fee components, discount types, allocation rules, approval limits, reminder schedules are **rows, not scripts**. "Any future billing schedule" (§2) is a row with an interval spec — not an eval'd expression.

School-configurable data lives in School-scoped `finance_settings` tables, never `config/*.php` (deploy-time only).

## 12.11 School timezone

`schools.timezone` + working-hours configuration. **§12's "Daily Collections" and §15F's "transactions outside normal working hours" are undefined without it.**

---

# 13. Domain Events

There is no event bus today. Finance must not be hard-wired into enrollment.

**Shared Kernel introduces the bus. Modules publish their own events.**

| Event | Publisher | Finance reaction |
|---|---|---|
| `StudentEnrolled` | Academics | Raise invoice |
| `StudentWithdrawn` | Academics | Cancel invoice (§9) |
| `TermStarted` | Academics | Recognize deferred income (§9) |
| `TermClosed` | Academics | Period close candidate |
| `InvoiceIssued` | Finance | Notification (§11) |
| `PaymentReceived` | Finance | Notification |
| `PaymentAllocated` | Finance | Notification |
| `CreditBalanceCreated` | Finance | Notification |
| `BalanceOverdue` | Finance | Dunning |

**Rules:** events carry `schoolId` · queued listeners use `SchoolAware` · an event is a **published fact**, and depending on it is depending on a Module's public API.

---

# 14. Core Domain Model

```
User ─────────────────────────────── identity + credentials. NOT School-scoped.
 │                                   The only entity spanning Schools.
 └─< model_has_roles (school_id) ─── THE source of truth: access AND authority
                                     no row = no access

School ── independent Brookstone School · legal & accounting entity · the boundary
 ├─< Role ─< Permission ──────────── per-School (Spatie teams = school_id)
 ├─< Student ─────────────────────── School membership + admission record
 │    ├─ UNIQUE(school_id, admission_number)
 │    ├─ status
 │    └─< StudentCurriculum ──────── enrollment (term × class × exam type)
 ├─< Guardian ────────────────────── payer, per School; user_id → User
 │    └─> guardian_student ───────── M:M · same-School constraint
 ├─< AcademicSession ─< Term ─────── Term = the billing period (gains school_id)
 └─< Finance ─────────────────────── Ledger · invoices · payments · bank accounts
      └─ append-only · statutory numbering · period close
```

| Aggregate | Owner | Lifecycle | Invariants |
|---|---|---|---|
| **User** | — (global) | created by admin → 2FA → disabled | Unique email. Never School-scoped. One human = one User = one audit identity. |
| **School** | — | created by super admin → active/inactive | The isolation boundary. Independent books. Never deleted. |
| **Role/Permission** | School (teams) | granted/revoked by admin | Evaluated in the Active School. No row = no access. `hasRole()` banned in Module code. |
| **Student** | School | admitted → active → withdrawn/graduated/transferred | `UNIQUE(school_id, admission_number)`. Never deleted. Progression = a new Student at the receiving School. |
| **Guardian** | School | created → active/inactive/blocked | `user_id` → User. One row per School. Links only to same-School Students. |
| **StudentCurriculum** | School *(via Curriculum)* | enrolled → promoted/repeated/withdrawn | **Unscoped** — Finance never loads it directly; reach via `Curriculum` or events. |
| **Term** | School | upcoming → active → completed | The billing period. Gains `school_id`. |
| **Finance** | School | posted → **immutable** | Append-only. Balances derived. Corrections are contra entries. Never crosses Schools. |

**Cross-cutting invariants:**
1. Every row belongs to exactly one School — except `User`.
2. School context is explicit or absent; never inferred.
3. Authorization is evaluated in the Active School, at the Policy layer.
4. Financial records are append-only.
5. Cross-School reads happen only in declared read models, never ad hoc.

---

# 15. Folder Structure

```
app/Finance/
│                    ── PUBLIC API ──
├── Contracts/       FinanceModuleStatus …
├── Events/          InvoiceIssued, PaymentReceived, PaymentAllocated,
│                    CreditBalanceCreated, BalanceOverdue
├── Enums/           InvoiceState, PaymentMethod, LedgerAccount,
│                    AllocationRule, BillingFrequency, DiscountType
│                    ── PRIVATE ──
├── Models/          Invoice, InvoiceLine, Payment, Receipt, Allocation,
│                    LedgerEntry, StudentAccount, CreditNote, Refund,
│                    FeeComponent, FeeTemplate, FeeTemplateLine, BankAccount,
│                    Discount, DiscountAward, BillingPeriod, Settings
├── DTOs/            validated, typed, behavioural
├── Actions/         GenerateInvoice, RecordPayment, AllocatePayment,
│                    IssueCreditNote, ProcessRefund, ReverseReceipt, ClosePeriod
├── Services/        orchestration + read models
├── Policies/        InvoicePolicy, PaymentPolicy, RefundPolicy …
├── Listeners/       notification + projection listeners
├── Jobs/            GenerateInvoicesForPeriod, SendDunningReminders,
│                    ReconcilePayments, RecognizeDeferredRevenue
├── Http/            Controllers/ Requests/ Resources/
├── Support/         AllocationEngine, LedgerPoster   ← Finance-internal ONLY
└── Exports/         SageJournalExport, AgedDebtorsExport, StatementPdf (template)

routes/endpoints/finance.php        → wired into api.php
resources/js/pages/admin/finance/
resources/js/components/finance/
tests/Feature/Finance/  tests/Unit/Finance/  tests/Arch/FinanceTest.php
```

**Money, Sequences, Approvals, Idempotency, FeatureFlags and the Pdf engine are Shared Kernel — see §8.**

**Table naming:** `fee_invoices`, `fee_payments`, `fee_structures` (the three `ModuleClassificationService` already probes — naming them so lights up the admin dashboard for free); `finance_*` for the rest; Kernel tables unprefixed. Every table: `school_id` as **`foreignId`** (bigint — *not* `foreignUuid`; the hybrid ID conversion means the original migrations do not describe the live schema) + its own `uuid` route key.

---

# 16. Engineering Standards

| Area | Standard |
|---|---|
| **Permission naming** | `finance.<resource>.<action>` — dotted. Backed by an enum; no magic strings. |
| **Roles** | Bundles of Permissions. `hasRole()` banned in Module code. |
| **Authorization** | Every controller action calls `$this->authorize()`. Policies own record-level rules. |
| **Money** | Integer minor units + currency. `Money` VO + `MoneyCast`. Never float, never `decimal:N`. |
| **Transactions** | **Actions own transactions.** Services and Controllers never open one. `DB::transaction($cb, attempts: 3)`. |
| **Mutation** | Ledger entries are INSERT-only. Corrections are contra entries. |
| **Idempotency** | Every state-changing Finance POST accepts an idempotency key. Webhooks require one. |
| **School isolation** | Every Finance table has `school_id`; every Model uses `BelongsToSchool`; every job carries `school_id` and runs inside `ActiveSchool::runFor()`. |
| **Caching** | Every Finance cache key includes `school_id`: `finance:{concern}:{schoolId}:{…}`. |
| **Notifications** | School-specific data passed **by value at construction**; never re-resolved in the worker. |
| **Errors** | Real 422 + field errors. *(The existing `validation_error` macro discards errors and returns 400 — fixed for Finance routes.)* |
| **API** | Versioned: `/api/v1/finance/*`. Existing `/api/*` frozen for backward compatibility. |
| **DTOs** | Validated and typed, constructed from FormRequests. No `toArray()` round-trips. |
| **Frontend** | Inertia `<Form>` + wayfinder. **One** toast library, **one** modal. `<Can>` gate. `formatNaira()`. |
| **Tests** | Factories for every Finance Model. **Finance tests run on MySQL.** Every money path has a unit test; every approval path a maker≠checker test; every Model a cross-School isolation test. |
| **Commits** | Conventional Commits **with scopes**: `feat(finance): …` |

---

# 17. CI Requirements & Enforcement

**Every Constitution rule maps to a check that fails CI.** Documentation nobody enforces is how this codebase acquired 52 disabled authorization checks.

## 17.1 Arch tests (`tests/Arch/`)

```php
// 1 · Kernel never depends on a Module
arch()->expect('App\Support')->not->toUse('App\Finance');
arch()->expect('App\Casts')->not->toUse('App\Finance');

// 2 · Module internals are private
arch()->expect('App\Finance\Models')->toOnlyBeUsedIn('App\Finance');
arch()->expect('App\Finance\Actions')->toOnlyBeUsedIn('App\Finance');
arch()->expect('App\Finance\Services')->toOnlyBeUsedIn('App\Finance');

// 3 · Finance may not reach into Academics
arch()->expect('App\Finance')->not->toUse([
    'App\Models\Curriculum', 'App\Models\Score',
    'App\Models\StudentResult', 'App\Models\StudentCurriculum',
]);

// 4 · The escape hatches
arch()->expect('App\Finance')->not->toUse([
    'withoutGlobalScope', 'withoutSchoolScope', 'hasRole',
    'auth()->setUser', 'Illuminate\Support\Facades\DB::table',
]);

// 5 · School scoping is mandatory
arch()->expect('App\Finance\Models')->toUse('App\Concerns\BelongsToSchool');

// 6 · Layering
arch()->expect('App\Finance\Actions')->toBeFinal()->toHaveMethod('handle');
arch()->expect('App\Finance\Http\Controllers')->not->toUse('Illuminate\Support\Facades\DB');
arch()->expect('App\Finance\Models')->not->toUse('Illuminate\Support\Facades\DB');

// 7 · No circular Module dependencies (per Module pair)
arch()->expect('App\Finance')->not->toUse('App\Admissions');
```

## 17.2 Lint gates

| Rule | Rationale |
|---|---|
| `// abort_unless` · `// $this->authorize` · `// ->can(` → **fail** | Constitution 15. **The most load-bearing rule in this plan.** |
| `?? $user->school_id` → **fail** | Constitution 13 — written 3× already |
| `forceCreate` in `tests/**/Finance/` → **fail** | Bypasses `MoneyCast` |
| `decimal:` cast on a money column → **fail** | Constitution 10 |
| `DB::table('fee_%')` outside `App\Finance` → **fail** | Constitution 3 |

## 17.3 Pipeline

- **`composer ci:check`** (lint:check + format:check + types:check + test) as the merge gate — *it already exists in `composer.json` and is simply not wired up.*
- **Larastan level 5+**
- **tsc ratchet** — no new errors above the baseline
- **MySQL service** in CI for Finance tests
- **Branch protection** requiring all of it; workflow triggers corrected (`staging` added; three non-existent branches removed)

**Enforcement lands in Phase 1, before Finance code exists.** Retrofitting arch tests onto 15 Finance models is how they end up suppressed.

---

# 18. Documentation Strategy

The project has no README, no CONTRIBUTING and no ADRs — and `docs/`, `plan_docs/` and `implementation_plan.md` are **gitignored**, so the only written artifacts are invisible to every clone.

| Artifact | Content |
|---|---|
| **Un-gitignore `docs/`** | Immediate. |
| `README.md` | Setup, `.env.example`, running tests, architecture at a glance. |
| `CONTRIBUTING.md` | **The Architecture Constitution (§11)** + conventions: bigint `school_id`, offset pagination + `pagination` block, `routes/endpoints/<module>.php`, dotted Permissions via `App\Models\Role`/`Permission` (**not raw Spatie models — they miss the required `uuid`**). |
| `CLAUDE.md` | Agent-facing conventions. |
| `docs/module-blueprint.md` | §9 — the shape every Module follows. |
| `docs/adr/` | §19. |
| `docs/finance/domain-model.md` | Ledger accounts, document lifecycle, state machines, allocation algorithm. |
| **`docs/finance/accounting-policy.md`** | **Co-signed by Brookstone Finance.** Rounding, allocation precedence, revenue recognition, credit/advance semantics, period-close rules, **the sequence gap policy**. *The most important document in the project — the Ledger is untestable without an agreed policy.* |
| `docs/finance/runbooks/` | Period close/reopen, failed reconciliation, refund process, **DR restore**, **Paystack outage → manual fallback**. |
| `docs/finance/sage50-mapping.md` | Account + bank code mapping, file format. |
| `docs/finance/wcbs-migration.md` | Field mapping, provenance markers, reconciliation sign-off. |

---

# 19. ADR Register

`docs/adr/NNNN-title.md`, Nygard format. Written **before** the corresponding phase starts.

| # | Decision | Phase |
|---|---|---|
| 0001 | Finance lives in `app/Finance/` — the reference implementation of the Module Blueprint | 1 |
| 0002 | Money as integer minor units + currency; rounding policy | 1 |
| 0003 | Append-only Ledger; `finance_student_accounts` is a lockable projection reconciled by job | 2 |
| 0004 | Permission naming, `Permission` enum, no `hasRole()` in Module code | 1 |
| 0005 | Policies are *the* enforcement layer; route middleware is defence-in-depth. `Gate::before` super-admin bypass | 1 |
| 0007 | Sequences: gap-free per School + School prefix, allocated at commit; batch-reservation strategy + **signed gap policy** | 1 |
| 0008 | Idempotency keys for Finance mutations and webhooks | 1 |
| 0009 | Generic Approval engine (**Shared Kernel**); maker ≠ checker at Policy + DB level; Finance supplies amount limits | 3 |
| 0010 | Configuration is School-scoped data rows, not config files or a DSL | 2 |
| 0011 | Domain events as the Finance ↔ Academics seam | 1 |
| 0012 | Finance tests run on MySQL; SQLite is insufficient for money | 1 |
| 0013 | API versioning: `/api/v1/finance/*`; existing routes frozen | 1 |
| 0014 | PDF engine selection (Shared Kernel; Module-owned templates) | 5 |
| 0015 | Deferred income recognition model | 5 |
| 0016 | Paystack: webhooks, virtual accounts, reconciliation matching | 12 |
| 0017 | Backup/restore is infrastructure, not an app feature | 1 |
| 0018 | Schools are independent Brookstone Schools; `school_id` is the isolation boundary; no organisation/division/campus entity; "tenant" is not used. Cross-School payments prohibited; progression = new admission | 1 |
| 0019 | Per-School role assignment only; `model_has_roles` is the single source of School access; coverage gaps are detected, not automated | 1 |
| 0020 | Bank accounts per School per fee category; each a distinct Ledger asset account; bank account ≠ allocation constraint | 2 |
| 0021 | Single login with School switching — audit identity and maker–checker integrity | 1 |
| 0022 | Student progression = new admission; no cross-School identity, history or balance carry-over | 1 |
| 0023 | Student/Guardian model is sufficient; **no `Person` entity, no `Admission` entity** | 1 |
| 0024 | Cache keys include `school_id`; notifications pass School data by value | 2 |
| 0026 | `ActiveSchool::runFor()` is the only way to establish School context off-request; no fallback; `auth()->setUser($causer)` banned | 1 |
| 0027 | `SchoolScope` fails closed; `withoutSchoolScope()` the sole escape, banned in `App\Finance\*`; `DB::table()` banned in Module code | 1 |
| 0028 | Export artifacts are School-partitioned and served by DB id, never by filename | 1 |
| 0029 | `Student` owns School membership (`status`); `StudentCurriculum` owns term enrollment | 1 |
| 0030 | Cross-Module reads via contracts; writes via events; `DB::table()` on another Module's tables banned | 2 |
| 0031 | Observability baseline — error tracking, failed-job alerting, queue dashboard — required before money moves | 1 |
| 0032 | The audit log is delete-protected at the DB level; `activitylog:clean` disabled | 1 |
| 0033 | Shared Kernel boundary; the Kernel never depends on a Module | 1 |
| 0034 | A Module's public API is `Contracts`/`Events`/`Enums` | 1 |
| 0035 | The Architecture Constitution — the 16 non-negotiables | 1 |

*(0006 and 0025 were superseded during review and are not issued.)*

---

# 20. Implementation Phases

Every phase is independently deployable and independently valuable. Finance phases ship **behind a per-School feature flag**.

## Phase 1 — Engineering Foundation ⛔ *blocks everything* · 6 weeks

### 1A · Security hotfix — *ship in week 1, standalone*
- Fix the `downloadExport` IDOR: restore all three checks; **repartition exports** to `exports/{schoolId}/{userId}/{uuid}.csv` + a DB row (owner, School, expiry); serve by DB id, never by filename. The flat path is *why* the IDOR is cross-School.
- Wire `GuardianPermissionSeeder` + `StudentSubjectPermissionSeeder` into `ArmsDatabaseSeeder`.
- Move the six duplicate public route declarations inside the auth group; delete `/curricula/queued`.
- Fix the hardcoded default School — `CreateNewUser:29` and `AuthenticationController:32` place **every** self-registered user in "Secondary School".
- **Deliverable:** patch release + a regression test per fix.

### 1B · Engineering hygiene — *unblocks all verification*
- Create `.env.example` → **CI can run for the first time.**
- Fix or delete `phpunit.mysql.xml`.
- Point `lint.yml` at `composer ci:check`; add `types:check`; fix workflow branch triggers.
- Baseline the 143 tsc errors: fix `types/global.d.ts` `auth: unknown` (~23), de-dupe `StudentResult` (5), file the wayfinder route-param bug upstream (35 are generated). **Ratchet.**
- `SchoolFactory` + `HasFactory` on `School`; fix `UserFactory`; factories for every Model Finance touches; **seed all four Schools**.
- **Ban `forceCreate` in `tests/**/Finance/`** — it bypasses `MoneyCast`, so a money test would pass while the cast is broken.
- Larastan (level 5) + `pest-plugin-arch` + **MySQL service in CI**.
- Delete `tsc_errors.log`; pick one lockfile; un-gitignore `docs/`.

### 1C · RBAC rebuild
- `Permission` enum + one seeder wired into `DatabaseSeeder`; **a test asserting the exact Permission/Role set**.
- `Gate::before` super-admin bypass in a null-team context. *(Verify against Spatie 7.4's `HasRoles` `wherePivot` behaviour first.)*
- **Resolve all 52 commented-out checks** — decide per line; then the lint rule.
- Policies (start Guardian/Student/ActivityLog); `permission:` middleware; the four Finance roles.
- **Collapse five sources of School access into one** (§7.1): `model_has_roles` authoritative; expand/contract with a **parity test gating the drop** of `users.school_id` and `school_user`; remove **all three** `?? $user->school_id` fallbacks + the arch rule.
- Cache `accessibleSchoolIds`; invalidate on grant/revoke.
- `LogsActivity` on `Role`/`Permission`; `events_enabled => true`.
- Share Permissions to Inertia; `usePermissions` + `<Can>`; stop shipping `rolesFull`.
- Enforceable per-role 2FA. Route failed logins into the activity log.

### 1D · School isolation hardening
- **`terms.school_id`** (backfill from `academic_sessions`) + scope + fix `ResolvesTermFilter:32` and the 4 `CurriculumController` sites.
- Fix `ClassLevelArm` and `MarkingComponent`; audit for others.
- **`ActiveSchool::runFor()`** with `finally` restore + `SchoolAware` job middleware; retrofit all 5 jobs; **ban `auth()->setUser($causer)`**.
- Scheduled commands iterate Schools explicitly.
- Rename `SetTenantContext` → `SetSchoolContext` *(behaviour unchanged — the middleware is correct)*.
- `SchoolScope` **fails closed**; remove the `catch (\Throwable)`; roll out per-model.
- Fix `ActivitySchoolResolver` to prefer `ActiveSchool::id()`.
- **`students.status`** + `left_at` + `leave_reason` + `index(school_id, status)` — **blocks Phase 5**.
- **`guardian_student` same-School constraint** + data audit.
- `schools.timezone` + working hours.
- Queue: `after_commit => true`; reconcile `retry_after` vs `timeout`.

### 1E · Shared Kernel primitives
- `Money` VO + `MoneyCast` + `formatNaira()`.
- **Shared `Sequences`** (`sequences.type`: invoice · receipt · **admission**) — **also fixes the racy `HasAdmissionNumber`**.
- **Shared `Approvals`** engine (polymorphic `ApprovalRequest`).
- `Idempotency` table + middleware. `FeatureFlags`. **Shared `Pdf` engine.**
- Audit immutability: `updating`/`deleting` guards on `Activity`; capture **IP, user-agent, reason, approver**.
- **Disable `activitylog:clean`** + DB-level `DELETE` deny on `activity_log`.
- **Observability baseline** — error tracking + `failed_jobs` alerting + queue dashboard.
- Domain events: `StudentEnrolled`, `StudentWithdrawn`, `TermStarted`, `TermClosed`.

### 1F · Standards, governance & docs
README · CONTRIBUTING (**the Architecture Constitution**) · CLAUDE.md · `docs/module-blueprint.md` · ADRs · **the arch-test suite + lint rules, landed before Finance code exists**.

---

## Phase 2 — Finance Foundation & Configuration · 5 weeks
**Objective:** the Ledger + the configuration surface. No user-facing billing yet.
**Scope:** `app/Finance/` skeleton; Ledger schema + `LedgerPoster`; chart of accounts; **`finance_student_accounts`** (lock anchor + balance projection + reconciliation job); fee components/templates per year group & programme (§3); billing frequencies (§2); billing periods bound to `Term`; `finance_bank_accounts` + fee-category mapping; `finance_settings`; admin config UI; `Scholarship` gains a monetary value; Finance Permissions + 4 roles seeded; **`FinanceModuleStatus` contract**; feature flag.
**Deliverables:** migrations, models, config UI, ADRs 0003/0010/0020/0030, **`accounting-policy.md` co-signed by Brookstone Finance**.
**Acceptance:** an admin configures a full Brookstone fee template **and its bank accounts** with zero code changes; Ledger posts and balances derive correctly per bank account; cross-School isolation proven; `ModuleClassificationService` detects the Module via the contract.
**Risks:** over-configurable → unusable. Mitigate by building Brookstone's real template and real bank accounts as the acceptance test.
> **Start WCBS extract profiling here** — legacy data shape constrains the Ledger schema.

## Phase 3 — Approval Workflow Engine · 3 weeks
**Objective:** §15B, once, reusably (Shared Kernel).
**Scope:** approval state machine; Policy enforcing maker ≠ checker (**Policy + DB constraint**); approval limits by role/action/amount; pending-approvals queue UI; notifications; full audit capture.
**Acceptance:** a maker cannot approve their own request via UI **or** direct API; over-limit requests escalate; every decision is audited with reason.
> Shipped early: six later phases depend on it.

## Phase 4 — Discounts, Scholarships & Concessions · 2 weeks
**Objective:** §4 — percentage and fixed-amount; scholarships, concessions, sibling, special approval, performance.
**Acceptance:** discount application requires approval; the correct final payable is derived; sibling discount resolves within a School.
> Before invoicing: §4 requires discounts to reduce the invoice to the final payable.

## Phase 5 — Invoicing · 4 weeks
**Objective:** §3, §6, §9, §16.
**Scope:** generation (single + bulk by class/year group); gap-free numbering + School prefix; state machine (draft → issued → part-paid → settled → cancelled); **deferred income posting + recognition on term start**; cancellation via approval; invoice PDF; withdrawal-before-resumption.
**Dependencies:** Phases 2, 3, 4 · **`students.status` (1D)**.
**Acceptance:** bulk-generate a year group idempotently; **numbers gap-free under concurrency**; cancelled invoices retain history; deferred income recognizes across a term boundary.
**Risks:** bulk generation × concurrency × sequences is the highest-risk code in the Module. `ShouldBeUnique`, idempotency keys, load test.

## Phase 6 — Payments, Receipts & Allocation ⭐ · 5 weeks
**Objective:** §1, §5, §6 — *the heart of the specification*.
**Scope:** manual/offline recording (bank transfer, POS, cash) with references; receipt numbering + PDF; **payment without an invoice**; part payments; overpayment → advance/credit balance; the allocation engine (configurable + manual, single-School); multi-ward allocation within a School; fully traceable allocation & reallocation; `bank_account_id` on payments + mismatch exception; opening balances (approval-gated); **wrong-School UX guards**.
**Dependencies:** Phase 5 · Phase 2 bank accounts + `finance_student_accounts` · Phase 1D `guardian_student` constraint.
**Acceptance:** every §1 scenario passes; one payment across 3 wards in one School allocates per each rule; reallocation traceable; **two simultaneous payments against a Student with a credit balance consume it exactly once**, proven under parallel load **on MySQL**; a cross-School guardian↔student link cannot be allocated against.
**Risks:** the allocation engine is where money is lost. Property-based tests on *sum(allocations) + credit == payment, always*.

## Phase 7 — Adjustments · 3 weeks
**Objective:** §10 — credit notes, refunds, receipt reversals, sibling credit transfers (**within-School only**). All approval-gated, all contra entries.
**Acceptance:** every adjustment is a contra entry; balances re-derive; no `UPDATE`/`DELETE` touches a Ledger row (test + DB grant); a cross-School credit transfer is rejected.

## Phase 8 — Statements & Parent Portal · 3 weeks ∥
**Objective:** §7 — real-time per-child statement (opening balance, charges, discounts, payments, advances, credits, outstanding, receipt numbers, references, **allocation history**, dates); portal UI; statement PDF.
**Scope note:** the portal is **per-School**. The existing mock's unified cross-School view is **redesigned, not built**. Replaces `pages/parent/dashboard.tsx` and its 43 tsc errors.
**Acceptance:** statement reconciles to the Ledger exactly for every Phase 5–7 scenario; Guardians see only their own wards (Policy test); no view spans Schools.
> `result_locked` (fee-gates-results) is confirmed **School-scoped**. Whether to build it is a product decision.

## Phase 9 — Notifications & Dunning · 3 weeks ∥
**Objective:** §11 — the six triggers; Email + **SMS driver (new)** + in-portal; configurable templates; configurable reminder schedules; overdue detection; bulk reminders.
**Risks:** SMS is a new external dependency with per-message cost — provider decision, rate limiting, spend cap. Templates are user-authored → escaping/injection review.

## Phase 10 — Reporting, Financial Dashboard & Exports · 4 weeks
**Objective:** §12, §18 — all 14 reports; Excel + PDF; the real-time Financial Dashboard; revenue by class/year group/term; **collections & reconciliation per bank account**.
**Risks:** mitigated by `finance_student_accounts` (Phase 2) — reports read the projection, not a growing `SUM()`.

## Phase 11 — Period Controls, Audit Dashboard & Exception Monitoring · 3 weeks
**Objective:** §15E, §15F.
**Scope:** period close & locked-transaction enforcement; reopen restricted to Head of Account + audited; the Audit Dashboard — all 11 exception signals, **plus role-coverage gap and bank-account/fee-category mismatch**; exception reports.
**Also:** store activity **severity as a column at write time** — the current `LIKE`-pattern derivation is unindexable and will not scale.
**Dependencies:** Phases 7, 10 · `schools.timezone` · failed-login logging.
**Acceptance:** a locked period rejects writes at the **Policy** layer; every signal fires on a synthetic trigger; creating a 5th School raises a coverage-gap exception.

## Phase 12 — Paystack & Auto-Reconciliation · 4 weeks ∥
**Objective:** §8 — payment links, **idempotent signature-verified webhooks**, virtual accounts; auto-matching by reference; **the exception list for unmatched payments**; reconciliation reports; POS/bank statement import (reuse the guardian-import pipeline); **a subaccount per bank account**.
**Risks:** webhooks are hostile input — replay, out-of-order, duplicate. Never trust webhook amounts; verify against Paystack's API.

## Phase 13 — Sage 50 Export · 2 weeks ∥
**Objective:** §13 — a projection over Ledger entries → importable journals + payment postings; **one company file per School**; account + bank code mapping UI.
**Blocked on:** Brookstone confirming the Sage 50 import format.
**Acceptance:** a real Sage 50 instance imports the file with zero manual re-entry (**client-verified**).

## Phase 14 — WCBS Migration & Go-Live · 3 weeks
**Objective:** §14 — outstanding balances, advance payments, credit balances, historical references → opening Ledger entries with **provenance markers** (migrated rows have no genuine audit trail).
**Scope note:** **each School migrates independently.** No de-duplication, no identity merge, no cross-School carry-over. A child with Primary history now at Secondary is **two records**.
**Acceptance:** migrated totals reconcile to WCBS **to the kobo, per School**, signed off by Brookstone Finance; dry run in staging; documented rollback.
**Risks:** the highest-risk phase. Budget ≥3 dry runs and a reconciliation sign-off gate.

---

# 21. Dependency Matrix

| Phase | Hard dependencies | Parallel with |
|---|---|---|
| 1 Foundation | — | — *(blocks all)* |
| 2 Config & Ledger | 1 | — |
| 3 Approvals | 1, 2 | — |
| 4 Discounts | 2, 3 | — |
| 5 Invoicing | 2, 3, 4, **1D `students.status`** | — |
| 6 Payments & Allocation | 5, **2 `finance_student_accounts`**, **1D pivot constraint** | — |
| 7 Adjustments | 3, 6 | 8 |
| 8 Statements | 6 | 7, 9 |
| 9 Notifications | 6 | 7, 8 |
| 10 Reporting | 6, 7 | 11 |
| 11 Period & Audit | 7, 10, **1D timezone** | 12 |
| 12 Paystack | 6, **1E idempotency**, 2 bank accounts | 11, 13 |
| 13 Sage 50 | 7, *client format* | 12, 14 |
| 14 WCBS Migration | 6, 10, *profiling from 2* | 13 |

**Critical path:** `1 → 2 → 3 → 4 → 5 → 6 → 7 → 10 → 11`. Phases 8, 9, 12, 13 branch off 6/7 and parallelize with a second developer.

**External blockers — unblock now, in parallel with Phase 1:**
- **Brookstone Finance sign-off on `accounting-policy.md`** *(blocks 2)*
- **Each School's bank accounts + fee-category mapping** *(blocks 2)*
- Invoice/receipt reference format + **gap policy** *(blocks 5)*
- WCBS data extract *(blocks 14; profiling in 2)*
- Paystack account + sandbox credentials *(blocks 12)*
- Sage 50 import format *(blocks 13)*
- SMS provider decision + budget *(blocks 9)*
- Ruling on §18 backup/restore *(ADR 0017)*

---

# 22. Risks

| # | Risk | L | I | When | Mitigation |
|---|---|---|---|---|---|
| 1 | **Live IDOR is exploitable today** | H | H | **Before** | Phase 1A, week 1, standalone |
| 2 | **Cannot lock a derived balance** — write-skew → double-spent credit | H | H | **Before** (design Ph2) | `finance_student_accounts` lock anchor + reconciliation job |
| 3 | **No error tracking / failed-job alerting** — a failed allocation is silent; parent chased for money they paid | H | H | **Before Ph6** | Observability baseline (1E) |
| 4 | **Sequence serialises bulk invoicing** | H | H | **During** (Ph5) | Single-statement reservation; **signed gap policy** |
| 5 | **`forceCreate` bypasses `MoneyCast`; SQLite ≠ MySQL** — money tests pass while money is wrong | H | H | **Before** | Real factories + ban `forceCreate` + MySQL in CI |
| 6 | **`retry_after=90` vs `timeout=3600`** → duplicate invoice batch | H | H | **Before** (1D) | Reconcile; `ShouldBeUnique` |
| 7 | **Foundation work uncovers features that only work because authz is off** | H | M | **Before** | Expected — that discovery *is* Phase 1's value |
| 8 | **Sessions+cache+queue+Ledger on one MySQL** — contention exactly when Finance is busiest | H | H | **During** (before go-live) | Redis for cache/session/queue; read replica |
| 9 | **WCBS data is dirty; balances won't reconcile** | H | H | **During** | Profile in Ph2; ≥3 dry runs; sign-off gate |
| 10 | **`activitylog:clean` deletes the audit trail** — irreversible; not covered by `prohibitDestructiveCommands` | M | H | **Before** | Disable + DB-level DELETE deny (1E) |
| 11 | **`Gate::before` may not behave as expected** in Spatie 7.4 teams mode | M | H | **Before** | Verify against vendor source; regression test |
| 12 | **Removing `users.school_id` strips access** from a user granted only there | M | H | **Before** | Expand/contract; **parity test gates the drop** |
| 13 | **`accessibleSchoolIds` becomes a per-request query** | M | M | **Before** | Cache + invalidate; query-count test |
| 14 | **Fail-closed `SchoolScope` breaks seeders/console** | M | M | **Before** | Land behind tests; per-model rollout |
| 15 | **Type-error trend (101→143) swamps Finance** | M | M | **Before** | Ratchet in CI (1B) |
| 16 | **Wrong-School posting** — single login's accepted cost | M | H | **During** (Ph6/11) | School indicator; confirmation on financial writes |
| 17 | **Cross-School guardian↔student links already possible** | M | H | **Before** (1D) | Constraint + data audit + Ph6 regression test |
| 18 | **Approval fatigue → rubber-stamping** — control that looks real but isn't | M | H | **During** (Ph3) | Amount thresholds; **monitor approve-latency as control health** |
| 19 | **Audit severity via `LIKE`** — unindexable | H | M | **During** (Ph11) | Store severity at write time |
| 20 | **Paystack single point of failure** | M | M | **Can wait** | Manual/offline path exists — **document it as the fallback runbook** |
| 21 | **No tested restore** | M | H | **During** | Automated snapshots + a **rehearsed** restore |
| 22 | **Configurability (§17) becomes an unusable rules engine** | M | M | **During** (Ph2) | Bounded, data-driven; Brookstone's real template is the acceptance test |
| 23 | **Sage 50 format wrong** | M | M | **During** | Get the format before Ph13; verify on a real instance |
| 24 | **`wayfinder` is 0.1.x, pre-1.0** | M | M | **Can wait** | Pin exactly; tsc ratchet catches regressions |
| 25 | **Scope: §19 pulls a full GL in** | M | M | — | The Ledger makes GL *possible later*. Explicitly out of scope. |
| 26 | **The cultural risk** — the team's response to a broken permission layer was to disable 52 checks. No architecture survives that reflex. | M | H | **Before** (1C) | **The CI lint rule banning commented-out authorization is the mitigation** — load-bearing, not housekeeping |

**What actually kills this project:** risks 2 + 3 together — a concurrency bug moves money incorrectly and nothing alerts anyone; discovered by a parent months later, at which point trust in the numbers is gone, and for a finance system trust *is* the product. Both are cheap to fix now and compound catastrophically together.

---

# 23. Timeline & Milestones

**Assumptions — validate before committing:** 2 full-time developers, one senior; Brookstone Finance available for policy sign-off and UAT; external blockers resolved during Phase 1. **Estimates ±30%**, excluding UAT cycles.

| Phase | Weeks | Cumulative |
|---|---|---|
| 1 Engineering Foundation | 6 | 6 |
| 2 Config & Ledger | 5 | 11 |
| 3 Approval Engine | 3 | 14 |
| 4 Discounts | 2 | 16 |
| 5 Invoicing | 4 | 20 |
| 6 **Payments & Allocation** | 5 | 25 |
| 7 Adjustments | 3 | 28 |
| 8 Statements ∥ | 3 | 30 |
| 9 Notifications ∥ | 3 | 31 |
| 10 Reporting & Dashboard | 4 | 35 |
| 11 Period & Audit Dashboard | 3 | 38 |
| 12 Paystack ∥ | 4 | 40 |
| 13 Sage 50 ∥ | 2 | 41 |
| 14 WCBS Migration & Go-Live | 3 | **44** |

**≈44 weeks (~10 months)** with 2 developers and parallelization. **~58 weeks single-threaded.**

> **Be honest with the client:** this is a financial control system, not a billing screen. §15 alone (SoD, maker–checker, immutable audit, period controls, exception monitoring) is roughly a third of the effort — and it is the part that cannot be cut, because it is why Finance and Internal Audit wrote the document.

## Milestones

| M | Milestone | Phase | Signal |
|---|---|---|---|
| **M0** | **Secure & verifiable** — IDOR closed, CI green for the first time, Permissions deterministic | 1A/1B | *We can trust what we build.* |
| **M1** | **Foundation complete** — RBAC rebuilt, School isolation hardened, Kernel primitives exist, Constitution enforced | 1 | *Finance can begin.* |
| **M2** | **Configurable billing** — Brookstone's full fee structure and bank accounts configured with zero code | 2 | *§3, §17 proven.* |
| **M3** | **Controls live** — maker ≠ checker enforced and unbypassable | 3 | *§15B — the spec's core control.* |
| **M4** | **First invoice** — bulk-generate a year group with discounts, gap-free numbers, deferred income | 4/5 | *§3, §4, §9, §16.* |
| **M5** | ⭐ **First payment allocated** — overpay → credit → auto-applied; one payment across 3 wards | 6 | *§1, §5, §6 — the heart.* |
| **M6** | **Books can be corrected** — credit notes, refunds, reversals, approval-gated, all contra | 7 | *§10.* |
| **M7** | **Parents self-serve** — real-time statements replace the mock portal | 8 | *§7 — first parent-visible value.* |
| **M8** | **Finance can report** — 14 reports + live dashboard, Excel + PDF | 9/10 | *§11, §12, §18.* |
| **M9** | **Audit can audit** — period locking + audit dashboard + all exception signals | 11 | *§15E, §15F — Internal Audit signs off.* |
| **M10** | **Money flows automatically** — Paystack live, auto-reconciliation, exception list | 12 | *§8.* |
| **M11** | **Books integrate** — Sage 50 import verified with zero re-entry | 13 | *§13 — client-verified.* |
| **M12** | 🎯 **Go-live** — WCBS balances migrated and reconciled to the kobo, signed off | 14 | *§14.* |

---

# 24. Acceptance Criteria

## Phase 1 — the foundation

**Security & hygiene**
- ✅ CI green on all three PHP legs; `composer ci:check` gates merges.
- ✅ `tsc --noEmit` ≤ baseline; ratchet enforced.
- ✅ Zero commented-out authorization checks; lint rule prevents recurrence.
- ✅ Seeded Permission/Role set asserted by test.
- ✅ IDOR regression test; a request for another School's export artifact 403s **by DB lookup, not filename parsing**.
- ✅ `phpunit.mysql.xml` cannot target a non-test database.
- ✅ Larastan level 5 + arch tests green.

**RBAC & identity**
- ✅ **One source of truth:** `accessibleSchoolIds()` reads only `model_has_roles`. **Parity test green** before `users.school_id`/`school_user` are dropped.
- ✅ **The reference scenario:** a user with *Secondary → Teacher, IFY Abuja → Coordinator, Primary → no role* can act as a teacher in Secondary, a coordinator in IFY Abuja, and **cannot enter Primary at all** — asserted at the API layer.
- ✅ Super admin passes a Permission check inside a School context.
- ✅ `grep -r '?? \$user->school_id' app/` returns nothing; arch rule enforces it.
- ✅ No per-request regression: `accessibleSchoolIds` cached; query-count test.

**School isolation**
- ✅ A queued job proves correct School **and** Permission context for a **super-admin causer**.
- ✅ **No singleton leak:** two jobs for different Schools run back-to-back on one worker; the second sees its own School.
- ✅ Querying a School-scoped Model with no context **throws**, from console and worker alike.
- ✅ Cross-School isolation tests for `Term` and `ClassLevelArm`.
- ✅ `guardian_student` rejects a cross-School link; existing violations audited.
- ✅ `students.status` exists and is indexed with `school_id`; **"active Students at School X" is answerable with no join, including between terms**; a `withdrawn` Student is excluded from billing **without being deleted**.

## Finance phases

Each phase's acceptance criteria are stated in §20. Every money phase additionally requires:
- a **concurrency test on MySQL**,
- a **cross-School isolation test**,
- a **maker ≠ checker bypass attempt at the API layer**.

---

# 25. Verification Checklist

```bash
# CI can run at all (currently impossible — .env.example is missing)
cp .env.example .env && php artisan key:generate
composer ci:check          # lint:check + format:check + types:check + test

# Static analysis + boundaries
vendor/bin/phpstan analyse --level=5
vendor/bin/pest --group=arch

# Finance tests must run on MySQL, not SQLite
vendor/bin/pest --group=finance -c phpunit.mysql.xml   # AFTER it is made safe

# The regression suite proving Phase 1 landed
vendor/bin/pest --filter='IDOR|SuperAdminPermission|SchoolContextInJob|TermIsolation|
  ClassLevelArmIsolation|SeededPermissionSet|SchoolAccessParity|SchoolScopeFailsClosed|
  NoTeamLeakBetweenJobs|ExportPartitioning|StudentStatusBilling|GuardianStudentSameSchool'

# The fallback must not exist anywhere
grep -rn '?? \$user->school_id' app/ && echo "FAIL: School-context fallback still present"

# The trend line that matters
npx tsc --noEmit | tail -1   # baseline 143 → must never increase
```

**Manual verification — must be driven, not assumed:**

1. Log in as **super admin**, select a School, open Activity Logs → the log is **populated**. *(Today it is silently near-empty — this single check proves the `Gate::before` fix.)*
2. Log in as a **teacher**, call `GET /api/activity-logs/export` **directly**, bypassing the UI → **403**. Proves backend enforcement, not client-side hiding.
3. Request `GET /api/activity-logs/exports/{another user's artifact}` → **403**. Proves the IDOR is closed.
4. Dispatch a bulk job as a **super-admin causer** → rows land in the correct School with `school_id` set.
5. `GET /api/sessions` with **no auth header** → **401**. Proves the public-route leak is closed.
6. **The reference scenario, by hand:** grant one user *Secondary → Teacher*, *IFY Abuja → Coordinator*, nothing in *Primary*. Log in once. Switch to Secondary → teacher capabilities. Switch to IFY Abuja → coordinator capabilities, **teacher capabilities gone**. Attempt Primary → **403**, including by direct API call. *One walkthrough proves single-login, per-School roles, switching and enforcement.*
7. **Queue a School-A export while acting in School B** → the file contains **School B's** records.

**Each Finance phase is verified by driving the flow in the running app**, not only by tests.

---

# 26. Open Questions

**One remains, and it is product, not architecture:**

- 🟡 **`level_type`** is `enum('JSS','SSS')` — is it legitimately secondary-only, or do Primary and IFY need stage values? *Blocks nothing before Phase 2.*

**Product decisions pending (not blocking):**
- Whether to build the `result_locked` fee-gates-results rule at all. *(Its scope is settled: School-local.)*
- Invoice/receipt reference format confirmation.

---

# 27. Change Summary

This document consolidates the original implementation plan and seven review addenda into one authoritative specification.

## Major consolidations

| Consolidated | From |
|---|---|
| **Business context** (§2) — one statement of the confirmed model | Original plan's decision table + three rounds of domain correction |
| **School isolation** (§5) — one model, nine enforcement points, one context primitive | Scattered across the original §5, plus four addenda |
| **RBAC** (§7) — one source of truth, one authorization chain | Original §4 assessment + three addenda |
| **Shared Kernel** (§8) + **Module Blueprint** (§9) + **Contracts** (§10) | Governance addendum |
| **Architecture Constitution** (§11) — the 16 non-negotiables | Rules previously scattered across standards, arch rules and ADRs |
| **Financial architecture** (§12) — Ledger, lock anchor, Money, sequences, allocation, bank accounts, approvals, configuration | Original §5 + corrections from the pre-mortem |
| **Risk register** (§22) — 26 deduplicated risks | Three overlapping registers |
| **ADR register** (§19) — 33 issued ADRs | Five separate ADR tables |

## Removals

| Removed | Reason |
|---|---|
| **SaaS/multi-tenant framing**; the word "tenant" (~47 uses) | Brookstone is the only organisation. Schools are not customer tenants. |
| **`Organisation`, `Division`, `Campus`, `LegalEntity` entities**; identity elevation; de-duplication migration; consolidation service; sub-phase 1G; milestone M0.5 | Proposed during review on an invented hierarchy. The business model is flat. |
| **Inter-company accounting** and its 4–6 week contingency | Cross-School payments are prohibited. |
| **`Person` entity**, **`Admission` entity**, **`previous_school_id` FK** | No requirement the existing model cannot satisfy. `User` is the person; `Student` is the admission record. |
| **`roles.scope = 'group'`** + auto-grant listener | Contradicts per-School role scoping. Replaced by a detection signal at zero schema cost. |
| **`default_school_id`** | Demoting `users.school_id` re-creates the footgun in the same place. Column removed entirely. |
| **Org→School configuration inheritance** | Each School configures itself. |
| **Unified cross-School parent dashboard** | Contradicts confirmed isolation. Redesigned per School. |
| **Historical review commentary, superseded recommendations, iterative discussion, addendum cross-references** | Consolidated into the decisions above. |

## Corrections carried in

| Correction | Where |
|---|---|
| **You cannot `lockForUpdate` a `SUM()`** — the original "balances derived, never mutated" + "lockForUpdate on balance reads" was self-contradictory. `finance_student_accounts` is the lockable projection. | §12.2 |
| **Money, Sequences, Approvals, Idempotency, FeatureFlags, Pdf are Shared Kernel, not Finance's** — the original filed them under `app/Finance/` while building them in Phase 1. | §8 |
| **`students.status` is required** — §9 bills before a term begins, when no enrollment row exists. | §12.6 |
| **Sequence numbering cannot be gap-free, batch-reserved and rollback-safe at once** — the trade-off is now explicit and the gap policy must be signed. | §12.5 |
| **Per-School gap-free sequences + School prefix** — resolves the conflict between "separate legal entity per School" and "one sequence for Brookstone". | §12.5 |
| ADR **0006** superseded by **0026**; ADR **0025** withdrawn. | §19 |

**Timeline unchanged at ≈44 weeks.** The consolidation moved work and removed a contingency; it added none.

---

# 28. Reconciliation with delivered state (2026-07-19)

> Added post-hoc, **verified against the repository**. §1 and §4.4 are the project-start baseline and are left unedited as the historical "why"; this section is the **live delta** — what has landed, what is genuinely open, and how v10's 14 phases map onto the walking-skeleton handoff (`docs/handoff/session-2-start.md`) that development has actually been following.

## 28.1 Status of the §4.4 technical debt (all 17 items)

Legend: ✅ resolved · ◑ partial / enforced-but-staged · ⬜ open · ❓ not re-verified this pass

| # | Debt (§4.4) | Status | Evidence |
|---|---|---|---|
| 1 | Live cross-School IDOR (`downloadExport`) | ✅ | `downloadExport` calls `$this->authorize('download', $export)` via `ExportPolicy`; super_admin via `Gate::before`; expiry/existence checks restored |
| 2 | 52 commented-out authz checks | ◑ | Down to **10 baselined** legacy checks (non-Finance controllers). `bin/ci-authz-lint.php` wired into `lint.yml` + `composer ci:check`; fails on any *new* one; ratchets to zero |
| 3 | 19/32 Permissions never seeded | ✅ | `GuardianPermissionSeeder`, `StudentSubjectPermissionSeeder`, `TeacherAssignmentPermissionSeeder` wired into `ArmsDatabaseSeeder`; `ActivityLogPermissionSeeder` via `DatabaseSeeder` |
| 4 | `terms` has no `school_id` | ✅ | `2026_07_16_000002_add_school_id_to_terms_table` |
| 5 | `ClassLevelArm` unscoped | ✅ | `use BelongsToSchool`; `school_id` in `$fillable` |
| 6 | Jobs lack School/Permission context | ✅ | `app/Jobs/Middleware/SchoolAware.php`; jobs carry `school_id`; `auth()->setUser()` not used |
| 7 | `SchoolScope` fails open | ◑ | Fail-closed mechanism built (throws `MissingSchoolContextException`), but **per-model** rollout via `config('rbac.fail_closed_models')` — empty by default, so un-opted models still fail open. Deliberate staged rollout |
| 8 | 6 public API endpoints | ✅ | Duplicate declarations moved inside the `auth:sanctum` group in `routes/api.php` |
| 9 | CI has never passed | ✅ | `.env.example` present; `phpunit*.xml` pinned to `portal_testing`; `composer ci:check` + `lint.yml`/`tests.yml` run the gates; pre-existing failures frozen in `tests/ratchet-baseline.txt` |
| 10 | `phpunit.mysql.xml` targets `portal-live` | ✅ | Both `phpunit.xml` and `phpunit.mysql.xml` pin `DB_DATABASE=portal_testing` |
| 11 | 143 TypeScript errors | ⬜ | **Verified 2026-07-19.** Committed `tsc-baseline` = **149** (regenerated *up* from origin 143 on 2026-07-15, `93aaef4`); working tree = **151 in 30 files** (TS2322×23, TS18046×23, TS7006×19, TS2554×19) — i.e. **+2 over the real baseline**, not +8 over the stale 143. `bin/ci-tsc-ratchet.php` is wired in `lint.yml` (push + PR to staging/main) and **trips exit 1 at 151 > 149 when it runs** — so the check is live, yet 2 above-baseline errors sit in the tree ⇒ not hard-blocking (whether the `linter` job is a *required* status check is unverified — no `gh` in env). Tool weakness: `generate` writes *any* count (baseline rose 143→149) and `count < baseline` only prints "please lower" (exit 0, no auto-lock) — so "baselines only shrink" is **unenforced**. Fix is its own slice (see `docs/handoff/slice-2-brief.md`) |
| 12 | 2 broken factories | ✅ | `SchoolFactory` added, `UserFactory` fixed, `GuardianFactory` present. Finance-model factories not yet built (expected pre-invoicing) |
| 13 | No observability | ⬜ | No Sentry/Telescope/Bugsnag/Flare in `composer.json`. **v10 Phase 1E — still open** |
| 14 | Queue `retry_after` vs `timeout` | ✅ | `retry_after=3900`, `after_commit=true` across connections in `config/queue.php` |
| 15 | `guardian_student` no same-School constraint | ✅ | `2026_07_16_000003_add_guardian_student_same_school_constraint` |
| 16 | `students` has no `status` | ✅ | `2026_07_16_000001_add_status_to_students_table` |
| 17 | Racy `HasAdmissionNumber` | ✅ | Allocates via shared `Sequences` atomically |

## 28.2 §1's "do not exist at all" list — corrected

| Primitive | §1 claim | Actual |
|---|---|---|
| Money | absent | ✅ `app/Support/Money.php` + `MoneyCast` |
| Sequences | absent | ✅ `app/Support/Sequences/Sequences.php` |
| Idempotency | absent | ⬜ open (v10 1E) |
| PDF | absent | ⬜ open (v10 1E/5) |
| Runtime config (FeatureFlags) | absent | ⬜ open (v10 1E) |
| Observability | absent | ⬜ open (v10 1E) |
| Locking (`finance_student_accounts` anchor) | absent | ⬜ open — only `fee_ledger_transactions` exists (v10 2) |
| Generic Approvals engine | (v10 Phase 3) | ⬜ open — only a domain-specific `PrincipalApprovalController` exists |

Also stale in §1: "zero Policy classes" (`ExportPolicy` exists) and "zero `Gate::` usage" (`Gate::before` super_admin bypass lives in `AppServiceProvider`).

## 28.3 What the walking skeleton delivered (outside v10's origin frame)

An early thin vertical slice was built and **frozen as the module template**, ahead of finishing Phase 1's remaining kernel primitives:

- `app/Finance/` skeleton — actions `GenerateInvoice` / `RecordPayment` / `CancelInvoice`; models `Invoice` / `InvoiceLine` / `Payment` / `PaymentAllocation` / `LedgerTransaction`; `SubledgerPoster`; `BillableEnrollment` contract; `InvoiceStatus` / `LedgerEntryType` enums; `LedgerImmutableException`.
- Migrations — `finance_*` rename + `enforce_finance_child_school_integrity` (composite FK) + `create_fee_ledger_transactions` with append-only triggers (1.4c).
- `docs/finance/accounting-policy.md` signed by Brookstone Finance; `SchoolAccessParity` harness.

## 28.4 Sequencing divergence — read before trusting the phase order

The skeleton front-loaded shapes from v10 **Phase 2** (Finance skeleton + Ledger) and **Phase 5** (the invoice document) as a frozen template, **before** Phase 1's Approvals / Idempotency / Observability primitives landed. Under §21's dependency matrix (Phase 5 depends on 2, 3, 4) this is deliberately "out of order": the skeleton's purpose was to **freeze the module template**, not to ship billing. **Do not read the skeleton as "Phase 5 done."** Real invoicing is still to be built on top of it.

## 28.5 Phase ↔ slice ↔ invariant map

The handoff tracks progress with **F1–F6 invariants** and incremental **slices**; v10 tracks it with **14 phases**. They reconcile as:

| Handoff invariant | Meaning | v10 anchor | State |
|---|---|---|---|
| F1 `finance_` prefix | table naming | §15 | ✅ |
| F2 `school_id` present | every Finance table | §5.2 pt 3 | ✅ |
| F3 child = parent `school_id` | composite FK | `enforce_finance_child_school_integrity` | ✅ |
| F4 append-only ledger | INSERT-only + triggers | §12.1 | ✅ |
| F5 Finance owns truth | contract, not table reads | §10 `FinanceModuleStatus` | ◑ access-only; read-only-Contract rule pending a 2nd Contract |
| F6 `total = SUM(lines)` | computed + snapshotted | §20 Phase 5 acceptance | ⬜ pending "slice 2" |

| Handoff term | v10 equivalent |
|---|---|
| "Phase-1 prerequisites" | v10 **Phase 1** — mostly landed; open: observability, idempotency, PDF, FeatureFlags, generic Approvals engine (all 1E) |
| Walking skeleton (frozen template) | an early thin slice of v10 **Phase 2** (skeleton + Ledger) and **Phase 5** (invoice doc) |
| "slice 2: multi-line invoices" | v10 **Phase 5 — Invoicing**, first increment: lands F6 + void-safety (default scope excludes VOID; void posts a reversing ledger entry) |

## 28.6 Net

Engineering-foundation work is largely done and gate-enforced. The genuinely-open Phase-1 items are **observability, idempotency, PDF, FeatureFlags, the generic Approvals engine, and the `finance_student_accounts` lock anchor**. The next build increment ("slice 2" / Phase 5 invoicing) can proceed on the frozen skeleton — but **Phase 6 (Payments & Allocation) is gated on the lock anchor**, and **Phase 3 / §15B (maker–checker) is gated on the Approvals engine** — neither exists yet.
