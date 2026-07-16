# Module Blueprint

Transcribed from the approved specification (v10 §9–§10). **Every Module —
Finance, Admissions, Academics, HR, Library, Inventory, Payroll — uses this
exact shape.** `app/Finance/` will be the reference implementation, not a
special case. No Module exists yet; the §17.1 architecture tests and §17.2
boundary lint are already armed and activate automatically when the first
Module namespace appears.

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

## Public API — exactly three namespaces

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

Coupling flows one way: the reactor depends on the published fact, never the
reverse.

**Reference cases (current repo):**
- `App\Services\Dashboard\ModuleClassificationService` still reads
  `fee_invoices`/`fee_payments`/`fee_structures` via `DB::table()` — a
  baselined, expiring exception (see `boundary-lint-baseline.txt`). It will
  depend on a **`FinanceModuleStatus` contract** (ADR 0030, Ph2) with a null
  implementation while Finance is disabled.
- Enrollment will reach Finance via **`StudentEnrolled`**, never by wiring a
  Finance service into `CurriculumEnrollmentService`.

## Definition of done for a Module

Every Model is School-scoped · every controller action authorizes · every
Action owns its transaction · arch tests pass · the public API is only
`Contracts`/`Events`/`Enums` · it has a domain-model doc and an ADR per
architectural decision. **Adding a Module requires no platform change.**
