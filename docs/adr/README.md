# Architecture Decision Records

Nygard format, `NNNN-title.md`. An ADR is written when its decision is made —
at latest before the phase that implements it (v10 §19). Numbers 0001–0035 are
reserved by the approved register below; 0036+ are issued for decisions made
during implementation. 0006 and 0025 were superseded during review and are not
issued.

**Issued** = the decision is implemented and recorded in this directory.
**Reserved** = decided in the v10 specification; transcribed when its
implementing phase starts.

| # | Decision | Phase | Status |
|---|---|---|---|
| 0001 | Finance lives in `app/Finance/` — reference implementation of the Module Blueprint | 1 | Reserved (blueprint doc + arch tests armed; namespace not yet created) |
| [0002](0002-money-integer-minor-units-plus-currency.md) | Money as integer minor units + currency; rounding policy boundary | 1 | **Issued** |
| 0003 | Append-only Ledger; `finance_student_accounts` lockable projection | 2 | Reserved |
| 0004 | Permission naming, `Permission` enum, no `hasRole()` in Module code | 1 | Reserved (enum + seeder + parity test shipped in 1.2a) |
| 0005 | Policies are the enforcement layer; `Gate::before` super-admin bypass | 1 | Reserved (shipped in 1.2b/1.2d, flag-gated) |
| 0007 | Sequences: gap-free per School; signed gap policy | 1→Continuous | Reserved (delivery: pre-Ph5, see roadmap) |
| 0008 | Idempotency keys for Finance mutations and webhooks | 1→**Ph5** | Reserved (deferred, Validation Review §A) |
| 0009 | Generic Approval engine; maker ≠ checker at Policy + DB level | 3 | Reserved — **bound by [0040](0040-super-admin-never-overrides-maker-checker.md)** |
| 0010 | Configuration is School-scoped data rows | 2 | Reserved |
| 0011 | Domain events as the Finance ↔ Academics seam | 1→Continuous | Reserved (delivery: pre-Ph5) |
| 0012 | Finance tests on MySQL; SQLite insufficient | 1 | Reserved (whole suite on MySQL since 1.0a) |
| 0013 | API versioning `/api/v1/finance/*`; existing routes frozen | 1 | Reserved |
| 0014 | PDF engine | 5 | Reserved (deferred, §A) |
| 0015 | Deferred income recognition | 5 | Reserved |
| 0016 | Paystack integration | 12 | Reserved |
| 0017 | Backup/restore is infrastructure | 1 | Reserved |
| 0018 | Schools independent; `school_id` the boundary; no "tenant" | 1 | Reserved (enforced by scope + middleware rename) |
| 0019 | `model_has_roles` single source of School access | 1 | Reserved (shipped flag-gated in 1.2e) |
| 0020 | Bank accounts per School per fee category | 2 | Reserved |
| 0021 | Single login with School switching | 1 | Reserved |
| 0022 | Progression = new admission; no cross-School carry-over | 1 | Reserved |
| 0023 | No `Person` entity, no `Admission` entity | 1 | Reserved |
| 0024 | Cache keys include `school_id` | 2 | Reserved |
| 0026 | `runFor()` the only off-request context; `auth()->setUser($causer)` banned | 1 | Reserved (shipped in 1.3a; legacy jobs = 1.3b) |
| 0027 | `SchoolScope` fails closed; escape hatches banned in Modules | 1 | Reserved (shipped per-model flag-gated in 1.3c) |
| 0028 | Exports School-partitioned, served by DB id | 1 | Reserved (shipped in 1.1a) |
| 0029 | `Student` owns School membership; `StudentCurriculum` owns enrollment | 1 | Reserved (shipped in 1.3e) |
| 0030 | Cross-Module reads via contracts; `FinanceModuleStatus` | 2 | Reserved (expiry anchor for the `finance_*` lint baseline) |
| 0031 | Observability before money moves | 1→Continuous | Reserved (delivery: pre-Ph6) |
| 0032 | Audit log delete-protected at DB level | 1→Continuous | Reserved |
| 0033 | Shared Kernel boundary; Kernel never depends on a Module | 1 | Reserved (enforced by live arch tests since 1.5a) |
| 0034 | A Module's public API is `Contracts`/`Events`/`Enums` | 1 | Reserved (enforced by armed arch tests) |
| 0035 | The Architecture Constitution — 16 non-negotiables | 1 | Reserved (transcribed in [CONTRIBUTING.md](../../CONTRIBUTING.md)) |
| [0036](0036-authority-vs-isolation.md) | Authority and isolation are orthogonal axes | 1 | **Issued** |
| [0037](0037-money-wire-contract.md) | Money wire contract: `amount_minor` + `currency` | 1 | **Issued** |
| [0038](0038-money-column-naming.md) | Money columns: `{name}_minor` + `{name}_currency` | 1 | **Issued** |
| [0039](0039-money-serialization-path.md) | Money crosses the wire only via an API Resource | 1 | **Issued** |
| [0040](0040-super-admin-never-overrides-maker-checker.md) | `super_admin` never overrides maker–checker (binds ADR 0009) | 3 (constraint issued now) | **Issued** |
| [0041](0041-ratchet-baseline-keying.md) | Ratchet baselines are content-keyed | 1 | **Issued** |
| [0042](0042-activeschool-transport-coupling.md) | `ActiveSchool::id()` transport coupling — known debt + expiry | 1 | **Issued** |
| [0043](0043-authz-rollout-scaffolding.md) | `App\Support\Authz` is temporary authorization-rollout scaffolding | 1→Continuous | **Issued** |
| [0044](0044-result-enrollment-permissions.md) | Result & enrollment authorization moves from roles to permissions | 1→Continuous | **Issued (design)** |
