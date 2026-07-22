# Roadmap & source of truth

Two approved documents govern this work. They do not compete — they answer
different questions, and this page records the reconciliation so there is a
single authoritative roadmap.

| Question                                                                                                                                      | Authority                                                                                                               |
| --------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| **What the architecture is** (Constitution, isolation/identity/RBAC models, financial architecture, Module Blueprint, ADR register 0001–0035) | **Finance Implementation Specification v10** (`plan_docs/`, untracked)                                                  |
| **When and in what order it is delivered** (milestones 1.0–1.5, slice contents, Core vs Continuous, rollout flags, deferrals)                 | **Phase 1 Execution Plan** (approved after v10; explicitly preserves v10's architecture and re-sequences only delivery) |

Where the two describe delivery differently, **the Execution Plan governs** —
it was approved later, for exactly that purpose. No technical decision from v10
was changed by it.

## Engineering Invariants (Permanent, ADR-amendable)

Architectural rules stable across sessions — kept separate from status, progress,
blocked work, and session plans, so handoffs update only volatile state while
these remain stable.

**Governance:** stable, not immutable. These change **only by ADR**, and every
change records why the prior invariant failed contact with reality. An invariant
that can't be amended by evidence calcifies the way v10's stale counts did.

**Format rule:** every invariant names its enforcement mechanism. A rule without
a mechanism is wallpaper — "never read `$user->school_id`" was true for months
while the code did it anyway; the _lint_ is what made it real. If an invariant
has no enforcement, that gap is itself a finding (three are flagged below).

1. **Authoritative domain facts.** No domain event ships until its transition is
   authoritative (one canonical transition), single-sourced (one publication
   point), atomic, and committed. If any is missing, STOP and fix the transition
   first.
   _Enforced by:_ the published-fact inventory in this roadmap; no event class
   without its four checks passing.

2. **Expand/contract for one-way changes.** Any irreversible schema evolution
   (enrollment episodes, `users.school_id` removal) follows expand →
   migrate-readers → contract, with a STOP-for-review before the irreversible
   step.
   _Enforced by:_ mandatory production snapshot **and a named owner's written
   acknowledgement at the moment of crossing** — a snapshot nobody owns is a file
   nobody restores.

3. **Distrust green that rests on a disabled precondition.** Verification attacks
   the implementation, never confirms it. Passing tests are evidence, not proof —
   and the specific reflex: always ask what a green test is passing _because of_.
   Every production defect found this way shared one shape — something looked
   fine because the thing that would have revealed the problem was itself
   disabled (impersonation masking scope; a dead method masking a notification;
   team-state leaking between tests). Inventory known-positive cases; baselines
   only shrink; "obviously stale" clusters contain live bugs (5/22, 2/18).
   _Enforced by:_ adversarial verification pass per slice; bite-prove every gate
   before trusting its silence.

4. **Modules communicate via DTOs, never models.** Immutable DTO contracts +
   identifiers across boundaries; events publish past-tense facts, not commands.
   _Enforced by:_ Deptrac/arch test on the module boundary — an Eloquent model in
   an event payload fails the build. **GAP (finding):** no event-payload arch
   rule exists yet (`tests/Arch/ArchitectureBoundaryTest.php` has no event rule —
   no domain events exist to test). The rule must land WITH the first event
   class, not after it.

5. **Finance separation.** Academic modules publish business facts only. Finance
   owns all financial behaviour (billing, discounts, waivers, credit notes,
   write-offs, ledger). Academic status values carry **no** financial semantics.
   _Enforced by:_ the `finance-table-outside-finance` boundary lint (keys on the
   `finance_*` prefix, renamed from `fee_*` at the template freeze); status enums
   with no billing branch (registrar ruling 2026-07: terminal statuses are pure
   academic facts).

6. **Shared Kernel is domain-agnostic.** No Finance-, Admissions-, or
   presentation-specific assumptions in shared infrastructure.
   _Enforced by:_ grep-clean of domain terms in `app/Support` — **GAP (finding):**
   currently a manual, per-primitive check (done once for Sequences), not a CI
   lint. Candidate: add a domain-term pattern to `bin/ci-boundary-lint.php`
   scoped to `app/Support/`.

7. **One authoritative service entry point per invariant.** Controllers, imports,
   jobs, background processes converge through it — never duplicate business
   logic. (This project has found the create-fan-out repeatedly: enrollment,
   guardian, teacher.)
   _Enforced by:_ lint flagging direct `Model::create` on models that have an
   authoritative service. **GAP (finding):** this lint does not exist —
   `ci-identifier-generation-lint` covers raw inserts on Student/Teacher only;
   `StudentCurriculum::create` fan-out (found 3×) is unlinted. The enrollment
   Option-B slice converges the paths and must land this lint with it.

8. **Money handling.** Integer minor units, explicit ISO-4217 currency, never a
   float, never a `decimal:` cast; crosses the wire as `{amount_minor, currency}`,
   never a decimal (Constitution rule 10).
   _Enforced by:_ decimal-cast boundary lint; the `Money` VO; the wire contract
   in ADR 0037.

9. **Append-only financial and audit records.** Never edited, never deleted;
   corrections are reversals. Enforced at the database, not only the model (§15C).
   _Enforced by:_ `activity_log` UPDATE/DELETE triggers + model guard + disabled
   clean command + `audit:verify-immutability` post-restore assertion (1.4c); the
   ledger's reversal-only design (Ph2+).

10. **Environment gates.** Environment-dependent work is not complete without its
    runtime evidence — production snapshot, parity soak, running scheduler,
    observe-mode traffic. Local success cannot substitute. (A registered
    `schedule:run` that nothing invokes produces evidence into a table that never
    prunes — inert.)
    _Enforced by:_ the blocked-on-prod list in volatile state; no "done" without
    the named evidence.

### Finance module template invariants (frozen 2026-07-19)

Same rule as above — recorded only with a named, verified mechanism; not-yet-
enforced items are marked GAP, never promoted. Four are enforced, one is enforced
with a scope limit, one is a GAP pending slice 2.

- **F1. Finance tables use the `finance_*` prefix.** _Enforced by:_ the
  `finance-table-outside-finance` boundary lint (bite-proven: a `finance_` table
  literal in a non-Finance app file fails CI). ✅ real.
- **F2. Every Finance aggregate carries `school_id` (uniform, filterable).**
  _Enforced by:_ `SchemaConventionsTest` asserts the column on every `finance_*`
  table (added this slice), plus the arch rule requiring `BelongsToSchool` on
  every Finance model. ✅ real (previously would have been aspirational — the
  arch rule alone asserts the trait, not the column; the schema test closes that).
- **F3. A child row's `school_id` equals its parent's.** _Enforced by:_ the
  composite FK `(child_fk, school_id) → parent(id, school_id)` at the DB — a
  divergent child is rejected as a foreign-key violation (bite-proven at the DB,
  not the model). ✅ real.
  **Extended across the Finance↔Academic seam by slice (i) (2026-07-19).**
  `finance_invoices (student_curriculum_id, school_id) → student_curricula (id,
school_id)` means an invoice's School is now structurally tied to its episode's,
  instead of being satisfied _by proxy_ through the ACL adapter's
  `students.school_id → curricula.school_id → 0` derivation. The null→0 fallback is
  no longer load-bearing for correctness — it remains only as a fail-closed guard
  in `GenerateInvoice`. Same slice also made F3 hold on the academic side:
  `student_curricula` composite FKs to **both** `students` and `curricula`, so a
  cross-School episode is unrepresentable whichever `school_id` is supplied.
- **F4. Financial movements are append-only; corrections are reversals, never
  rewrites.** _Enforced by:_ the 1.4c DB triggers on the ledger/lines/payments/
  allocations (UPDATE+DELETE denied; invoice DELETE denied, status may mutate).
  Confirmed surviving the `fee_→finance_` rename, verified by name. ✅ real.
- **F5. Finance owns financial truth; Academic never mutates it.**
  _Access-enforced by:_ the arch rule (`App\Finance\Models` private to
  `App\Finance` — Academic cannot reference a Finance model) + the
  `finance-table-outside-finance` lint (Academic cannot touch a `finance_*` table
  via raw SQL). Both ACCESS paths (model-reference, raw-SQL) are ✅ real. **GAP —
  the semantic "no mutation" is NOT proven:** the two rules block _access_ to
  Finance internals, but a future Finance **Contract** (the sanctioned public API)
  that exposed a _mutator_ method would pass both rules and let Academic drive a
  Finance state change. _Closing mechanism (pending):_ a lint/arch rule asserting
  Finance **Contracts are read-only by default** (a mutating contract method is an
  explicit, reviewed exception). **Pending until a second Contract exists** — today
  there is one (`BillableEnrollmentProvider`, a read port), so the rule would have
  nothing to discriminate; it lands with the next Contract, whose shape defines
  what "read-only by default" must catch.
- **F6. Invoice total = SUM(lines), computed once and snapshotted.** ✅ real
  (slice 2), with one residual GAP recorded below.
  _Enforced by:_ (1) **derivation** — `GenerateInvoice` takes line specs and
  derives the total by exact integer addition (`Money::plus`); there is no wire
  field and no Action parameter by which a caller can supply a total, so a
  mismatch cannot be _authored_. `Money::plus` also throws on currency mismatch,
  making a mixed-currency invoice impossible by construction. (2) **immutability**
  — the `finance_invoices_total_immutable` BEFORE UPDATE trigger denies any change
  to `total_minor`/`total_currency` at the DB while leaving the status transition
  free, so the snapshot cannot _drift_ afterwards. Bite-proven: a raw
  `UPDATE … SET total_minor` throws, and removing the trigger turns exactly that
  test red. Multi-line proof uses three distinct non-round amounts
  (12345+67891+250003=330239) so a count×price or max() bug cannot pass.
  **Residual GAP — post-creation line INSERT.** `total ≠ SUM(lines)` has a second
  source: inserting a line into an already-created invoice. `finance_invoice_lines`
  denies UPDATE and DELETE but permits INSERT, so at the DB this remains possible.
  It is **not domain-reachable** — a grep of every line-INSERT path found exactly
  one, inside `GenerateInvoice`'s creating transaction; there is no
  add-line-to-existing-invoice route, method, or raw write, and `Invoice::lines()`
  is otherwise read-only. So this is a tamper vector, not an operational path.
  _Closing mechanism (pending):_ the **seal** — a `lines_sealed_at` column plus a
  BEFORE INSERT trigger on `finance_invoice_lines` rejecting lines on a sealed
  invoice, and a seal-time SQL re-verification that `total = SUM(lines)`. It lands
  when a draft / multi-step-build lifecycle makes "sealed" an observable state the
  domain actually has. Building it now would front-load a shape with no consumer —
  the mistake recorded in v10 §28.4.

- **F7. At most one ACTIVE invoice per enrollment episode.** ✅ real (slice 2).
  A _set_-based invariant no single Invoice aggregate can see, so it is enforced at
  the DB: a STORED generated column
  `active_enrollment_key = IF(status='issued', student_curriculum_id, NULL)` with
  `UNIQUE(school_id, active_enrollment_key)`. Issued ⇒ the slot is taken; void ⇒ it
  recomputes to NULL and NULLs do not collide, so the policy's "repeat = billed
  fresh" re-bill after a void still works (a naive
  `UNIQUE(school_id, student_curriculum_id)` would forbid it, since voided invoices
  are append-only and never leave). Generated rather than app-maintained, so no code
  path can forget to set or clear it. The Action's pre-check is only the friendly-422
  path; **bite-proven** that the index is the real guarantee — with the index removed
  the raw-insert and concurrency tests go red while the pre-check test still passes.

## Reconciled deviations (Execution Plan — Validation Review §A)

v10 §20 packs all foundation work into a 6-week Phase 1. The approved
Execution Plan split that into **Phase-1 Core** (gates Finance Ph2) and a
**Continuous track** (each item lands before the phase it actually blocks):

| v10 Phase-1 item                                 | Reconciled delivery             | Actually gates                                              |
| ------------------------------------------------ | ------------------------------- | ----------------------------------------------------------- |
| Idempotency table + middleware (§12.4, ADR 0008) | **Ph5**                         | record-payment / webhooks                                   |
| FeatureFlags service                             | **Ph2**                         | per-School Finance flag                                     |
| Approvals engine (ADR 0009)                      | **Ph3**                         | the approval-engine phase                                   |
| Pdf engine (ADR 0014)                            | **Ph5**                         | first invoice/statement template                            |
| Sequences (ADR 0007)                             | Continuous (early)              | Ph5 (also fixes the live admission-number race)             |
| Observability (ADR 0031)                         | Continuous                      | before Ph6 ("before money moves")                           |
| Event bus + 4 Academics facts (ADR 0011)         | Continuous                      | Ph5 (first Finance listener)                                |
| Audit immutability (ADR 0032)                    | Continuous (early)              | protects the existing log                                   |
| 53 commented-authz restores                      | Continuous (baseline burn-down) | security debt, not Ph2                                      |
| Legacy jobs → `SchoolAware` (1.3b)               | Continuous                      | precondition for enabling fail-closed on job-touched models |

**Verified audit counts supersede the spec's.** The Execution Plan's code
audit re-measured v10 §4.2/§4.4 and every figure was worse than claimed; the
verified numbers below are authoritative, and v10's risk register, acceptance
criteria and Phase-1 estimate — built on the lower figures — must be read
against them:

| Debt item                                | v10 claims | Verified (authoritative)                       |
| ---------------------------------------- | ---------- | ---------------------------------------------- |
| Commented-out authorization checks       | 52         | **53**                                         |
| Controllers containing them              | 5          | **7**                                          |
| Publicly leaked (unauthenticated) routes | 6          | **7**                                          |
| Permissions actually defined             | 32         | **28** (19 of them never seeded at audit time) |

(Jobs impersonating a causer without team context: **6 of 7 job classes**
verified — v10's "5 jobs" undercounted by one, reconciled in slice 1.3b, which
retrofitted **all 7** to `SchoolAware` and removed every impersonation.)

### Debt corrections & residuals (post-1.3b investigation)

- **Halting-event defect (slice 1.3b.1).** `BelongsToSchool::creating`'s
  `school_id` auto-fill (§5.2 enforcement point 2) was silently inert on 9
  models. Root cause: Laravel's `creating` event is halting (`until()`), and
  `AddUuid`'s arrow-fn returned the uuid (non-null), halting the chain before
  the fill. Fixed by converting `AddUuid` to a block closure; a reflection-wide
  conformance test now guards every `BelongsToSchool` model, and a boundary-lint
  rule (`halting-event-arrow-fn`) prevents recurrence. No live isolation breach
  occurred (8 of 9 tables are `school_id NOT NULL` → fail-loud; the 9th,
  `students`, is nullable but its sole create path passes `school_id`
  explicitly).
- **Debt item 17 (admission/staff numbering) — correction.** Previously
  recorded only as a _concurrency race_ (racy read-then-write in
  `HasAdmissionNumber`/`HasStaffNumber`). The same halting defect means the
  duplicate-number **validation** `creating` hooks **never executed** on
  `AddUuid`-first models (Student, Teacher) — a **functional-correctness gap in
  addition to the race**. The validation hooks now run (1.3b.1); the concurrency
  race is still owned by the Sequences slice (1.4b).
- **Debt item 7 (SchoolScope fail-open) — residual, NOT fully complete.** 1.3b
  fixed queued _scope application_ (scoping now applies under `runFor`), but the
  fail-closed **throw remains auth-gated**: a principal-less off-request
  execution (an unauth scheduled command, or a future non-`SchoolAware` job with
  no auth) still reads **unscoped** rather than throwing. Owning future slice:
  the `users.school_id`-drop / ADR 0042 slice (make `ActiveSchool::id()`
  transport-agnostic), or a dedicated scope-level backstop. Debt item 7 stays
  **open**.
- **1.3b verification note — correction.** An earlier 1.3b verification
  attributed the non-firing auto-fill to a "test-harness dispatcher blind spot."
  That was wrong. The cause is **halting-event ordering** (above), reproducible
  identically in **both PHPUnit and Tinker** — the harness faithfully reflected
  production.

## Phase-1 status (as of M1.5b)

**Core — complete:** 1.0a/b/c (CI + MySQL suite + factories) · 1.1a/b (IDOR
patch, route/seeder fixes) · 1.2a (Permission enum) · 1.2b (`Gate::before`,
flag) · 1.2d (authz lint + policy pattern) · 1.2e (single access source, flag)
· 1.3a (`runFor` + `SchoolAware` + rename) · 1.3c (fail-closed scope,
per-model flag; super-admin isolation refactor) · 1.3d (Term/ClassLevelArm/
MarkingComponent scoping) · 1.3e (`students.status`) · 1.3f (guardian
same-School constraint + timezone + queue) · 1.4a (Money VO + cast + wire
contract) · 1.5a (arch tests + boundary lint + Larastan L5) · 1.5b (this docs
slice).

**Continuous — done:** 1.3b (all 7 queueable jobs retrofitted to `SchoolAware`;
`auth()->setUser($causer)` eliminated; `SchoolScope`/`BelongsToSchool` now
resolve off-request context from `ActiveSchool::runFor()`).

**Continuous — open:** 1.2c1–c3 (**commented-authz entries: 0 remain of 53** —
the guard cluster was triaged in the commented-guard-cluster slice and the final
two `users.school_id` ownership guards were deleted per the §7 decision, slice A1
of docs/rbac-implementation-plan.md; authz-lint baseline is now empty; §24
conditions 2–4 — enforcement + evidence — remain open), the restored checks run
**observe-first** via `App\Support\Authz` (S5 — see below) ·
1.2f remainder (drop `users.school_id` + `school_user` after parity; expires
ADR 0042's debt) · fail-closed per-model enablement (jobs no longer block it;
per-model request-path audit remains the gate) · 1.4d–e (
observability, event bus) · frontend `formatNaira` (§12.3 names it; only ad-hoc
`toLocaleString` rendering exists today).

**Not authorization debt — test role-seeding debt (reclassified):** the ~25
Guardian / ActivityLog feature-test failures are produced by the `role:`
route-middleware that is _already enforcing_ on those routes, not by the dormant
`->can()` checks (which are still commented and inert). They are test-seeding
gaps — the tests do not seed the role the middleware requires — and must be
fixed by correcting test setup, **not** by touching authorization. They are
**not** S5 observe-mode evidence and prove nothing about the commented checks.

**Rollout flags currently dark:** `auth.gate_before_superadmin` (on by
default, verified — but see the ADR 0045 proposal: super_admin's ambient bypass is
slated for removal, not permanence) · `rbac.single_source_access` (off;
parity-gated) · `rbac.fail_closed_models` (empty; per-model — 1.3b landed, so job
context no longer blocks any model; each enablement still needs its request-path
audit) · `rbac.two_factor_enforced` (C7 platform master switch; **default on in
prod, off in non-prod** — a config flag, deliberately NOT an `environment()` check,
so the enforcement path stays testable and staging-soakable; audited when flipped).

**Cross-stream coordination — resolved 2026-07-21 (recorded, not re-litigable):**

- **ADR 0040 approval limits — Finance inherits the result-workflow separation as
  the Ph3 default.** No bespoke limits shape; Ph3's `finance.*.approve` adopts the
  same maker≠checker model, so this is not re-opened when the approvals engine lands.
- **#86 Money rounding gate — the accounting policy was signed BEFORE the rounding
  was written.** ADR 0002's gate held as intended; the safeguard was not hollow.
  Closed.
- **I6 Finance-role 2FA default — forward marker for the Finance stream.** The 4
  Finance roles are not seeded yet. C7 built the `roles.two_factor_required` column
  + mechanism and set the default for `super_admin`/`admin` only. **When Finance
  seeds its 4 roles, their `two_factor_required` default is Finance's to set** (I6),
  and it takes effect **subject to the `rbac.two_factor_enforced` platform flag**
  above. Do not let it land 2FA-optional by omission.

## super_admin authority probe — findings (2026-07-21, chore/superadmin-authority-probe)

Predict-first matrix (predictions committed before observation —
docs/handoff/superadmin-authority-probe-predictions.md); **all cells observed
as predicted**; standing test `SuperAdminAuthorityTest` (survives the ADR 0043
§5 teardown). Verdict: **mixed by path**, per-path record:

- **Mode A is real on every Gate-routed path** while `auth.gate_before_superadmin`
  is on (default): `can()`, `permission:` middleware, and policies all resolve
  `true` for super_admin regardless of the seeded absence — C1's "super_admin
  gets none of the seven" decides nothing at the Gate. ADR 0040's guarantee
  therefore must NOT rest on the seed absence (the SoD-as-domain-invariant ADR
  question stands, raised separately — probe brief §"what this does not settle").
- **Mode B refuted for the installed version** (spatie 7.4.1): PermissionMiddleware
  uses `canAny()` → the Gate → the bypass applies. **C2's swap does not lock
  super_admin out while the flag is on**; flag-off + swap = lockout, now a
  declared, tested dependency (SuperAdminAuthorityTest row 2).
- **Live pre-existing lockout (new finding):** the four `hasRole()` FormRequests
  (Promote/Register/UpdateStatus/RejectSubjectResult) deny super_admin TODAY in
  both flag states — `hasRole` never consults the Gate. Resolved by C3's ADR
  0044 implementation (swap to permissions); until then it is a known truth,
  not a bug to hotfix.
- **Dual `Gate::before` composition:** Spatie registers its own before
  (PermissionRegistrar:116, package boots first) returning true-or-null; the
  app's returns true-or-null. Safe ONLY by the null-on-miss convention —
  either returning `false` would silently defeat the other. Pinned
  behaviorally by the standing test's control rows.
- **Open environment fact:** production's `AUTH_GATE_BEFORE_SUPERADMIN` state —
  deploy owner to confirm; not inferable from the tree.

## C2 — role: → permission: middleware swap (2026-07-21, feat/rbac-permission-middleware)

All 28 `role:` groups resolved: 27 swapped to `permission:` (spatie
PermissionMiddleware via the Gate — probe-cleared), `/super-admin` stays
`role:super_admin` (EnsureRole, the one remaining consumer). Mechanism change
only, outcome-parity proven:

- **Oracle first:** `rbac:derive-access` snapshotted every route's allowed-role
  set to `tests/fixtures/route-access-map.json` BEFORE the swap (same
  discipline as the probe's predictions). `RouteAccessParityTest` re-derives
  the map live (permission holders from the seeded DB + the flag-gated
  Gate::before bypass) and demands per-route, per-role equality — 299 routes,
  plus per-role HTTP smokes for the parts a static derivation can't see.
- **Route-access permission tier:** 19 new enum cases (enum 41 → 60), each
  granted to exactly the role set of the group it replaced; guardian (0 → 6)
  and principal (0 → 9) hold their first permissions. `finance.access` is
  interim (I1 ⚑ — superseded by Ph2 `finance.<resource>.<action>`).
  super_admin stays at exactly 15 (bypass covers it; probe invariant green).
- **One declared deviation:** `POST /api/logout` left its
  admin|head_of_school|form_teacher group → plain `auth:sanctum`. The endpoint
  also 500'd for every caller since inception (`Auth::logout()` on Sanctum's
  RequestGuard has no such method) — fixed alongside the deviation.
- **The flag is now load-bearing:** pre-swap, EnsureRole bypassed super_admin
  unconditionally; post-swap its passage on the 27 groups depends on
  `auth.gate_before_superadmin`. Flag-off + swap = super_admin lockout
  (SuperAdminAuthorityTest row 2) — the open prod-flag question above must be
  answered before this slice deploys.
- **Test-fixture consequence (the contract working):** roles are nothing
  without grants now. 15 test files fabricating bare roles were switched to
  seed the canonical RbacSeeder map; 2 Finance test files among them
  (announce-listed per §4.1 — one mechanical line each, no semantic change).

## C3 — policies + ADR 0044/0040 implementation (2026-07-21, feat/rbac-policies)

Retires the §7.2 role-gate debt for the result/enrollment workflow **and** makes
ADR 0040 real for the first time. ADR 0040 was not a blocker to be amended: ADR
0044 §"Maker–checker separation" binds this slice to implement it, so C3
executes it.

- **Bypass exclusion, as a convention.** Any ability whose terminal segment is
  `approve`/`reject` is excluded from the super-admin `Gate::before`
  (`App\Support\ApprovalAbility`). ADR 0040 words it as `finance.*.approve`,
  which `result.approve`/`result.reject` do not match — a literal list would
  have shipped denylist drift immediately. An enum-enumerating test asserts every
  matching case is excluded, so Ph3's `finance.invoice.approve` is covered the
  day it is created. Bare Policy ability names are covered too (`Gate::authorize`
  passes `approve`, not `result.approve`).
- **Structural maker ≠ checker.** `SubjectResultPolicy` + a DB CHECK
  (`submitted_by <> decided_by`). Needed a schema change: the table held one
  `updated_by`, overwritten per transition, so the approver's write **destroyed
  the submitter's identity** — the rule was unrepresentable, not just unenforced.
  Backfill is partial on purpose (approved rows recover only their decider; the
  lost maker stays NULL rather than being inferred).
- **ADR 0044 steps 2/3/5 done** (step 4 was C2). The four `hasRole` FormRequests
  now authorize by permission — which **resolves the super_admin lockout the
  authority probe found**, for the three non-checker requests. Reject stays
  denied for super_admin, now by design rather than by accident.
- **Probe cells deliberately changed:** `SuperAdminAuthorityTest` rows 1, 2 and 4
  recorded pre-C3 truth. The mechanism findings (Mode A real at the Gate, Mode B
  refuted) are re-pinned via a non-checker ability; new cells assert the
  exclusion. Recorded as an intended change, not a regression.
- **Role-gate re-audit closed:** the remaining `hasRole()` calls are target-identity
  or view-shape branches, not authorization — enumerated in ADR 0044's
  implementation record.
- **Still open (Finance interface item):** ADR 0040's "configured limits" for
  approval authority are undesigned — C3 implements separation, not limits — and
  its third decision (super-admin Finance actions raise an audit signal) stays
  Ph11, with its home now recorded in ADR 0040 (a severity rule in
  `ActivitySeverityService`, not new plumbing in `app/Finance/**`).

## 1.4e — domain event bus + Academics facts: investigation STOP (2026-07)

Investigation-first. **All four proposed published facts hit the STOP condition —
none has a single authoritative, atomic transition to publish from. No event bus
or events implemented; publishing now would bake unstable contracts every Ph2
Finance consumer would later churn on.** Evidence + the prerequisite each needs:

- **StudentEnrolled — no single publication point (fan-out debt).** The authority
  is `CurriculumEnrollmentService::enroll` (atomic, transaction-wrapped), and its
  own docblock says "Controllers MUST NOT create StudentCurriculum directly" — but
  `StudentCurriculumController@promote` (:159) and `@register` (:212) each
  `StudentCurriculum::create(...)` **directly**, bypassing the service. Three
  creation points. _Prerequisite:_ route promote/register through the service, then
  publish once from it.
- **StudentWithdrawn — the business transition does not exist.** The Finance-
  relevant fact is the STUDENT leaving (billing stops; §12.6 `students.status`).
  `students.left_at` / `leave_reason` have **zero writes** anywhere (unwritten Ph5
  placeholders); no code sets `students.status` to withdrawn. What exists is
  ENROLLMENT-level and split: `unenroll()` (service, atomic → `ended_at`) versus
  `StudentCurriculumController@updateStatus('withdrawn')` which sets the status and
  then **DELETEs the enrollment row** — a competing, record-destroying path that
  bypasses `unenroll()`. _Prerequisite:_ build the student-level withdrawal
  transition and reconcile the two enrollment-end mechanisms (a deleted row is a
  fact Finance cannot later reference).
- **TermStarted / TermClosed — no authoritative transition operation.** `terms`
  has a `status` enum (upcoming/active/completed), but every reference is a READ;
  the only writer is `TermController::update` — a generic CRUD form edit
  (`->update([...$request->all()])`) — and there is no dedicated start/close
  operation and no scheduled date-driven activation. Revenue recognition on term
  start (§9) cannot hang off a form edit. _Prerequisite:_ an explicit term-
  lifecycle operation (or a date-driven scheduler once 1.4d scheduling exists).

**Recommendation:** defer 1.4e until the prerequisite transitions exist and are
atomic/single-sourced. The correct first published contract is likely
`EnrollmentEnded` (from a consolidated `unenroll`) and a real student-status
transition — derived from the consumer's need — not the four names as proposed.
The thin bus (dispatcher + queued afterCommit listeners) is trivial to add once
there is a stable fact to carry; building it now with no stable source earns no
abstraction.

### Unmasked baseline failures — classification (2026-07, investigation only)

> **STATUS 2026-07-20 — both LIVE BUGS are FIXED; baseline 18 → 14.**
> Bug 1 `5beec0a` (+ `ea383c9` Larastan follow-up), Bug 2 `5b79f6e`, all on staging.
> Both were re-verified against the code and **bite-proven red-when-removed**:
> reintroducing the `return;` fails all three notification tests on the
> _dispatch assertion itself_ (`the expected GuardianAccountCreatedNotification
notification was not sent`), not on an incidental one.
>
> **One classification below is WRONG and is corrected here.** GuardianProfile
> "resends invitation" was credited with catching Bug 1. It did not, and could not:
> its setup called `$user->update(['email_verified_at' => null])`, and
> `email_verified_at` is **absent from `User::$fillable`**, so mass assignment
> silently discarded it. The guardian stayed _activated_, the service correctly
> refused, and the 400 it "caught" was unrelated to `notifyGuardian`. Repaired to
> `forceFill(...)->save()` with an assertion that the write actually landed — it now
> passes and left the baseline. **A test whose setup silently no-ops asserts nothing,
> and reads as evidence for whatever it happens to be filed under.**
>
> Two findings opened while verifying, neither fixed here (see "Follow-ups" below):
> the guardian students-list mixed case is uncovered, and `CurriculumResource::32`
> chains four relations unguarded.

The S7 assignRole invariant was masking 18 baseline failures behind "no team on
assignRole". Unmasked, classified (no fixes):

**LIVE BUGS (ranked by blast radius):**

1. **`GuardianService::notifyGuardian()` is a dead method — `return;` on its first
   line** (committed in a vague "feat: updates", no comment). The ONLY dispatch of
   `GuardianAccountCreatedNotification` (`:328`) sits _after_ that return, so **no
   guardian ever receives an account/invite notification** — account creation,
   enable-login, and resend-invitation all silently send nothing in the REAL path,
   not just the test. **Finance-adjacent (§7 parent statements / Ph7 guardian
   portal):** the guardian portal-invite delivery is broken, so Finance's
   parent-facing statement delivery inherits a dead dependency. Caught by
   GuardianManagement "enable-login…notifies", GuardianProfile "resends
   invitation", GuardianRegistration new-guardian. **Fix = delete the `return;`**
   (then verify the synthetic-email/queue guards below it behave).
2. **`GuardianController@students:343` — 500 (`load() on null`).**
   `$s->currentCurriculum->load(['curriculum'])` assumes every linked student has a
   current enrollment; a student between/without enrollments (or withdrawn) makes
   `currentCurriculum` null → the whole guardian's student list 500s. One
   enrollment-less student breaks the entire response. Guardian/parent-facing view.
   **Fix = null-guard** (`$s->currentCurriculum ? … : null`).

**STALE (architecture deliberately changed — proven, not asserted-to-pass):**

- ActivityLogApi "blocks users without activity_log.view" (expects 403, gets 200):
  S5 made `activity_log.view` **observe-mode** (records, does not block, until
  `AUTHZ_ENFORCE=true`; ADR 0043). The test asserts pre-observe enforcement.

**AMBIGUOUS — BOTH DECIDED 2026-07-21. Kept here for the reasoning; neither is open.**

- **Registrar email update — RULED: keep the hard 403.** A user with
  `guardian.update` but not `guardian.update_credentials` is rejected outright, not
  silently-succeeded. Silently discarding a submitted security-relevant field is the
  worse failure mode — the registrar sees success and believes the email changed.
  **No code change: the code was already right and the TEST encoded the unsafe
  expectation** (it asserted 200-with-silent-ignore). The test now asserts 403 and
  that _nothing_ was written, not even the allowed field.
  _Possible future UX, not built and never a silent drop:_ 200 with an explicit
  "email ignored, credential permission required" signal, so a registrar editing an
  address is not blocked by an untouched email field. That needs a deliberate
  response contract; until it exists, 403.
- **Cross-school attach 201-vs-400 — ROOT-CAUSED: STALE TEST (no isolation bug).**
  The 400 is an **incidental validation** error, not an isolation check:
  `GuardianController@attach` validates `email` `required_if:can_login,true`, and
  the test sends `can_login=true` with the email in `identifier` but **no `email`
  field** (proven: adding `email` → 201). §6.2 cross-school attach is deliberate
  and works — `resolveExistingGuardianForAttachment` looks the guardian up
  **globally** then `grantSchoolAccess(activeSchool)` grants access (proven:
  cross-school access granted). No isolation guard rejects it, correctly, because
  §6 permits it. **RESOLVED 2026-07-21.** Secondary (a): the `email required_if:can_login` rule was
  not merely "arguably mis-scoped" — it was a **LIVE BUG**. On `mode=existing` the
  submitted email is never read (`resolveExistingGuardianForAttachment` keys off
  `guardian_id`/`identifier`, and a `can_login` upgrade re-issues credentials from the
  guardian's OWN `user->email`), while `add-guardian-modal.tsx` sends only
  `guardian_id` + `identifier` for existing mode — so "attach an existing guardian and
  give them login" **422'd from the real UI** on a field the backend then ignored.
  **The fix shape suggested here, `required_if:mode,new`, was WRONG**: it over-requires,
  demanding an email for every new guardian even when `can_login` is false and no login
  is provisioned. The condition is the CONJUNCTION (mode=new AND can_login=true), which
  `required_if` cannot express, so the rule is built explicitly. Three branches
  bite-proven, including the no-login case that fails if the over-requiring shape is
  ever adopted.

    Secondary (b) — ALREADY FIXED by `ea423a1`, before this slice opened: the test asserted
    `guardian_student.guardian_id = guardianB->id`, but under the per-School Guardian
    model a school-A record is attached. The test now sends the `email` the old rule
    demanded, asserts **201**, asserts the school-A per-School record, and asserts the
    school-B row was **never** cross-linked. Verified passing; §6 re-confirmed from the
    code (guardian resolved GLOBALLY, `grantSchoolAccess` granted, per-School record
    created so the student link is never cross-School). **No §6 gap.**

- **422-vs-400 cluster — RULED: 422, app-wide, DONE 2026-07-21.** Business-rule /
  validation errors standardise on 422: HTTP-correct for a well-formed but
  semantically invalid request, Laravel's own default, and what every test author in
  this repo reached for independently — the app was the outlier.

    Changed at **one site**: the `validation_error` macro
    (`app/Providers/ResponseMacroProvider.php`), whose only production caller is the
    `ValidationException` renderer in `bootstrap/app.php`. **Scoped to
    `ValidationException` only** — `abort()`-sourced statuses (409, 422, 403) are
    untouched, proven by test. There is no `abort(400)` anywhere in `app/`, so no
    legitimate 400 existed to preserve; after this change the app emits no 400 at all.

    The macro now also returns the `errors` payload instead of only logging it. That
    was not scope creep but a repair: the frontend already depended on it and was dead
    code. `student-form.tsx` and `add-standalone-guardian-modal.tsx` branch on
    `status === 422` (which never matched), and `score-entry-page`, `pending-reviews`
    and `subject-result-status-panel` read `response.data.errors.<field>[0]` (which was
    never sent).

    **This unblocks the cross-school-attach cleanup queued behind it** (the item above:
    its 201-vs-400 is an incidental `required_if:can_login` validation error, which is
    now a 201-vs-**422** — the classification is unchanged, still a stale test, and it
    can now be cleaned up against a settled convention).

- ActivityLogApi 3 count mismatches + GuardianProfile counts/422/password-reset
  notification: permission-scoping + activity-count assertions likely shifted by the
  observe rollout + permission model; per-test triage, lower priority.

**Commented-guard coverage gap (2026-07-20, found restoring the `resetPassword` guard):**

`883ff6c` ("feat: phase 1 updates", 62 files) blanket-commented **47 `abort_unless`
guards** in one sweep. `a27b0a3`'s S5 rollout restored the _authorization_ ones as
`Authz::abilityCheck` — but that sweep was scoped to _authorization_ **by design**, and
this is a **precondition**, so it was outside the remediation's remit and stayed
commented. Constitution rule 15
says _"Authorization checks are never commented out"_ — by its own wording this class is
**outside** rule 15, which is why nothing flagged it. Correctly classified as
correctness/hygiene, not an invariant breach — but it was live behaviour: the endpoint
dereferenced a possibly-null `$user->email` and mailed reset links to synthetic
`@no-email.local` addresses, reporting success.

> **CORRECTION (2026-07-20, later the same day).** This block first said the guard
> survived because "`ci-authz-lint` reads authorization only". **That was wrong.** The
> lint's regex has always matched `abort_unless(`/`abort_if(`, so commented
> _preconditions_ were never invisible to it. It survived because the lint's **baseline
> grandfathered it** — and because the lint's shrink-check only _warned_ and still
> exited 0, so the baseline could never be forced down. Both defects are fixed in the
> commented-guard-cluster slice, along with a third: the baseline keyed entries by
> file+text, so duplicate lines silently deduped and a newly-commented guard matching a
> baselined line passed the lint entirely.

**9 commented guards remain** (StudentSubjectController:228,
StudentCurriculumController:63/246, CurriculumSubjectController:272/377/380/406/429,
SavedActivityFilterController:63). Most are `403` and therefore rule-15/authz-lint
territory, grandfathered by that lint's baseline (it fails only on _new_ ones). Each
needs the same treatment applied here: find the commit, establish whether the disabling
was deliberate, and restore to correct intent rather than blanket-uncommenting.
Worth a slice; the pattern clusters because one careless sweep created all of it.

**Follow-ups opened while verifying the two fixes (2026-07-20, not fixed here):**

- **Guardian students-list MIXED case is uncovered.** `GuardianManagementTest`
  "lists all students linked to a guardian" asserts the null-guard, but **both** its
  students are enrollment-less, so it cannot distinguish "one bad apple does not spoil
  the list" — which is the production scenario Bug 2 actually described. Writing it
  was attempted and **abandoned deliberately**: an enrolled sibling makes
  `CurriculumResource` render, which needs academicSession + classLevelArm
  (+ classLevel, arm) + examType + term. `CurriculumFactory` supplies none of them,
  there is no factory state that does, and **no test in the repo renders
  `CurriculumResource` at all**. Same cost/value call already made for the dashboard
  join test. Worth doing only alongside a reusable complete-curriculum factory state.
- **`CurriculumResource::32` chains four relations with no null-guards** — **FIXED**
  in the reset-guard/null-chain slice (rebuilt with the `?->` idiom, every hop guarded,
  absent parts omitted rather than concatenated as empty strings; bite-proven by
  restoring the raw chain and watching it fatal). Original note follows —
  `$this->academicSession->name . $this->classLevelArm->classLevel->name . …
$this->examType->name . $this->term->name`. Every link is FK-backed and
  non-nullable, so production should be safe, but it is the _same family_ as Bug 2 and
  it 500s the entire guardian students list (and every other consumer) if any one is
  missing. Found because an incomplete fixture reproduced it exactly.

**Next fix slices (order):** ~~(1) delete the `notifyGuardian` `return;`~~ **DONE**;
~~(2) null-guard `students:343`~~ **DONE**; (3) decide
the 422/400 business-rule convention (then root-cause cross-school attach); (4)
registrar partial-update decision; (5) triage the count/permission-scoping stale
set.

### Finance walking-skeleton feasibility — SEQUENCING DECISION: B (2026-07)

**Aggregate split (do not conflate): the skeleton needs the STUDENT SUBLEDGER
only, not the GL journal.** Two easily-conflated aggregates are not peers:

- **Student subledger** — per-student receivable movements (charge, payment,
  allocation, reversal), append-only, School-scoped, the balance §12 derives.
  Every step in the skeleton trace below is a subledger movement. **This is what
  the skeleton creates.**
- **GL journal / Sage export (§13)** — account-level double-entry against a
  chart of accounts, periodic export. **A later phase.** The skeleton creates NO
  GL/journal tables, and no FK inventory is owed for them; when §13 lands, the
  journal derives FROM committed subledger movements (one-way), so building the
  subledger first loses nothing.

"Ledger" anywhere in the skeleton scope below means the student subledger.
(The §12.2 drift-verification job is likewise subledger-internal — derived
balance vs movements — not a GL concern.)

Traced the thin vertical (enrollment → invoice → subledger charge → payment
allocation → withdraw-cancel) against TODAY's schema. **Outcome: B — one small
blocker, not the full Option-B slice.** Finance does not need enrollment
episodes; it needs an enrollment **reference that survives withdrawal**.

- **Q1 — reference, not episodes.** Every step binds to enrollment identity
  (`student_curriculum_id`/uuid, school, status). **No step reads
  results/scores** — the Option-B re-key protects _grade history under repeats_,
  a purely academic concern. N-episode enrollment becomes Finance-relevant only
  when the **repeat workflow** exists (a second fresh bill for the same pair
  needs a second row → the `active_key` flip). No repeat workflow exists, so no
  Finance behaviour can trigger it yet. Exact dependency: **Option B full slice
  must land before the repeat workflow ships — not before Finance starts.**
- **Q2 — the withdraw-delete is the one blocker, and it is separable.** §9 needs
  the enrollment row durable; `updateStatus('withdrawn')` DELETEs it. New
  evidence makes this fix even less optional: the delete **already fails with a
  QueryException for any enrollment that has `student_subjects` rows (FK
  RESTRICT)** — and `enroll()` auto-attaches compulsory subjects, so the path is
  half-broken today — while `behavioral_assessments`/`psychomotor_skills` FKs
  **CASCADE**, silently destroying assessment history when it does succeed. Fix
  shape: soft-end (status=withdrawn + `ended_at`/`ended_by`, same mechanics as
  `unenroll()`), a small standalone slice. **Flagged trade:** with
  `UNIQUE(student_id, curriculum_id)` still in place, soft-end removes the only
  same-curriculum re-entry path (the delete) until the Option-B flip — accepted:
  RESTRICT already blocks it for real enrollments, and the repeat workflow that
  legitimises re-entry doesn't exist yet.
- **Q3 — skeleton prerequisites.** Hard blocker: the soft-end fix, landed
  **before the first invoice row exists** (referent durability + a future FK to
  `student_curricula` would otherwise make withdraw 500 or cascade-destroy the
  referent). Already built: Money VO, School isolation, the append-only
  mechanism (replicate 1.4c triggers on ledger tables), authz observe. Stubbable
  for the skeleton: invoice **numbering** (internal id; the gap-free sequence
  needs its own ADR + signed accounting policy — a **production** gate, not a
  skeleton gate), the approval/maker-checker engine (Ph3), the automated billing
  trigger. NOT blockers: the enrollment-create fan-out, the event bus, the
  results/scores re-key.
- **Q4 — billing trigger.** The 3-path create fan-out makes an _automated_
  trigger fire inconsistently — sidestepped for the skeleton by a single manual
  "generate invoice for enrollment X" entry point. The fan-out convergence + the
  `EnrollmentCreated` fact remain prerequisites for **automated** billing
  (1.4e), not for the skeleton.

**Sequencing (supersedes "enrollment is Finance's prerequisite"):**
(1) withdraw soft-end slice — **DONE (2026-07)**; (2) Finance walking skeleton
(manual trigger, stubbed numbering, no approvals) — **DONE (2026-07), STOP for
review**; (3) Option-B full slice before the repeat workflow / automated billing;
(4) gap-free numbering ADR + signed policy before production invoicing.

### Finance walking skeleton — landed (2026-07), conventions pending review

First `app/Finance/` code + the module template. The thin vertical (enrollment →
invoice → ledger charge → payment → allocation → cancel-by-reversal) is built and
driven end-to-end through the HTTP stack. **Conventions report + future-phase
check: [finance/walking-skeleton-conventions.md](finance/walking-skeleton-conventions.md)
— review before slice 2 copies the template.** All six day-one rules honoured;
the four guards bite-proven (boundary arch rule, ON DELETE RESTRICT, append-only
ledger triggers, Money decimal lint). Future-phase check: maker-checker (Ph3), GL
export (§13) and recurring billing are all additive — **no redesign forced, no
STOP**. **Template FROZEN 2026-07-19** (see the Finance template invariants in the
Engineering Invariants section): the open decisions were resolved — `finance_*`
prefix, uniform DB-enforced `school_id`, `@property`/`@mixin`, `/api/v1/finance`;
the 422-vs-400 error shape is deferred to the app-wide decision.
Larastan 0, ratchet unchanged (15).

**Accounting policy signed (Brookstone, 2026-07):**
[finance/accounting-policy.md](finance/accounting-policy.md) — banker's rounding
(remainder on the final installment), unique-with-gaps numbering (the gap-tolerant
`Sequences` kernel is correct as-is — no gap-free work), cancellation = VOID (never
delete, never `SoftDeletes`), void-must-not-leak (default-exclude scope + reversing
ledger entry), waiver/discount shown beneath the full fee (snapshot lines), repeat
billed fresh, per-School configurable prefix/approver/repeat. Each enforcement is
marked ENFORCED (gap-tolerant numbering, never-hard-delete, not-soft-delete,
reversing-ledger-nets-to-zero, snapshot integrity, no-repeat-logic) or PENDING
slice-2/Ph2-3 (banker's rounding op, VOID status + exclude-void scope, prefixes,
waiver/discount presentation, School-scoped config). The `Money` VO docblock still
reads "policy unsigned" — a known staleness to update in slice 2 when the rounding
op lands (not touched here: no code changes in this doc slice).

**Boundary lock before the first Finance migration — DONE (2026-07):**
[finance-data-ownership.md](finance-data-ownership.md) — ownership inventory
(subledger vs GL split: skeleton builds the student subledger only), FK inventory
(REF / LOOKUP / SNAPSHOT per relationship), cross-module identity classification
(student/episode/school = durable FK; **curricula/terms/sessions = never a live
FK** — routed hard-deletes + CASCADE chains, labels snapshot), lifetime analysis
(the academic FK graph is CASCADE end-to-end; **every Finance FK is `ON DELETE
RESTRICT`, which armors the whole upstream chain the moment one invoice
exists**), and the ledger-immutability decision (**reuse the 1.4c
trigger+guard+verify pattern**, not a new mechanism). Skeleton confirmed safe to
begin under the six day-one rules recorded there. Ph2 follow-up noted:
`CurriculumController::destroy` will need a graceful "has financial records"
guard once RESTRICT FKs exist.

### Withdraw soft-end — landed (2026-07)

- **One shared soft-end**: `CurriculumEnrollmentService::softEnd(enrollment,
actor, terminalStatus, reason)` — sets the Option-B terminal status AND
  `ended_at`/`ended_by`/`end_reason` together, never deletes, rejects `active` as
  a terminal status, 409 on double-end. `unenroll()` delegates to it (fixing the
  anomaly where unenrolled rows kept `status=active` and still read as
  `currentCurriculum`); `updateStatus('withdrawn')` routes through it — **the
  delete is gone**. `promote()`'s source→PROMOTED transition is the remaining
  termination path NOT yet converged (sets status without `ended_at`) — deferred
  by design to Option-B `endEpisode`.
- **Delete-sensitive callers audited**: ClassResultsController + OutstandingComment
  filter by status (safe); `CurriculumController@assignSubject` iterated
  enrollments UNFILTERED → adjusted to active-only (withdrawn rows must not
  receive new compulsory subjects); `StudentResource:18` fallback now shows the
  withdrawn row as "last enrollment" (registrar-aligned: history visible);
  `BackfillPastTermJob` already skips WITHDRAWN (now also skips unenrolled rows —
  consistent with its intent).
- **Historical CASCADE damage (dev DB `brookstone_portal_db`)**: **zero
  enrollment deletions within audit coverage** — 0 `deleted` events for
  StudentCurriculum in the immutable activity log (coverage begins 2026-05-25).
  The evidence channel itself was bite-proven (a probe delete produced exactly
  one audit row, so 0 means 0 — not "logging never fired"). Bounds: deletions
  BEFORE 2026-05-25 are invisible (no surviving evidence — cannot be determined);
  cascaded behavioral/psychomotor losses are not directly countable (those models
  don't log) but are bounded by enrollment deletions = 0 within coverage.
  **Production assessed (2026-07-19) — see the verification block below.**
- **Documented trade** (until the Option-B `active_key` flip):
  `UNIQUE(student_id, curriculum_id)` still blocks same-curriculum re-enrolment
  after withdrawal. Accepted: FK RESTRICT already blocked the old delete for real
  enrollments (subjects attached), and no repeat workflow exists yet.
- **Finance forward-compat confirmed**: the enrollment row survives withdrawal
  with a stable id/uuid; a future invoice FK to `student_curricula` stays valid;
  §9 cancellation can reference the withdrawn enrollment; **no delete path
  remains** on any termination route (the only `->delete()` on StudentCurriculum
  is gone from the app).

### Production verification — S7 divergence + CASCADE damage (2026-07-19)

The environment owner ran the validated read-only set
([prod-divergence-and-cascade-queries.sql](runbooks/prod-divergence-and-cascade-queries.sql))
against live production. Audit coverage window there: **2026-05-22 → 2026-07-19**
(~2 months, 124,687 rows).

- **S7 access divergence — CLEAN.** A1 (`school_user`) / A2 (`users.school_id`) /
  A3 (guardians) all returned **none**: every legacy access source is fully
  mirrored in `model_has_roles`. **Dropping `users.school_id` / `school_user`
  costs no user their access — no backfill required.** This closes the divergence
  _data_ STOP-gate; the drop itself is still a later post-deploy one-way step with
  its own STOP-before-flip (this settles only the data question).
- **CASCADE assessment loss — no detectable damage, with an honest ceiling.** B1
  (StudentCurriculum delete events) = **0**; B2 (orphaned behavioral/psychomotor)
  = **0/0**; B3 (withdrawn carrying assessments) = **0**. Orphans persist
  regardless of when created, so their absence is the stronger signal. **For any
  Brookstone disclosure:** no enrollment deletions and no orphaned assessment data
  _within coverage_; deletions **before 2026-05-22 are unquantifiable from
  surviving data** — a known blind spot, not a proven-clean history. No stakeholder
  escalation warranted; the pre-coverage window must be stated, not papered over.
- **Scheduler — confirmed running** every minute in production (observe-mode
  `authz:prune` and future Finance jobs will fire — clears the deployment
  prerequisite recorded below).

### Enrollment Option B — registrar selected; design done (2026-07)

Registrar chose **Option B** (episodic enrollments, full history, active-only
uniqueness; student code fixed; surrogate key on the episode). A **repeat** (no
completion, no return in time) rolls the same curriculum forward with no gap,
distinct from withdraw-and-return. Full investigation + design (no code, no
migration): **[enrollment-option-b-design.md](enrollment-option-b-design.md)**.

**Headline finding:** Option B's real cost is NOT the enrollment index — it is that
`student_results` `UNIQUE(student_id, curriculum_subject_id)` and `scores`
`UNIQUE(student_id, curriculum_subject_id, marking_component_id)` are keyed on the
student+curriculum_subject, not the episode, and are written via `updateOrCreate` —
so a **repeat (same curriculum) silently OVERWRITES the prior attempt's results**.
Assessments/subjects are already `student_curriculum_id`-keyed (episode-safe). The
results/scores re-key is the high-blast-radius change; the `active_key` unique is
mechanical. **Billing RESOLVED (registrar): every episode bills fresh, uniformly;
terminal statuses are pure academic facts; no continuation branch.** The design is
now single-model and **ready to sequence** as one coupled slice (`active_key` +
end/create convergence + results/scores re-key + all read-side fixes). The
read-side audit (broadsheet / transcript / result card / score-entry / approval /
year-averages / completeness / getScores / dashboards all key on
`(student, curriculum_subject)` and break under N episodes) is the true blast
radius, now inventoried in the doc. Open for Ph2 only: §7 statement presentation of
a waived fee.

**Design input for the re-key slice (parked 2026-07, do not act):** `scores` is
**not append-only** — the prod audit-log query set surfaced **883 historical
`Score` delete events** (`activity_log`, `App\Models\Score`), vs 0 for
`StudentCurriculum`. The deleted values ARE recoverable, but only from
`activity_log.properties` → `{"old": {"score": …}}` (all 883 carry it); the
`attribute_changes` column is **NULL** for every one, and `properties.old`
captures only the logged attribute (`score`), not the full row/context. So the
re-key slice should decide whether the re-keyed `scores`/`student_results` tables
**inherit the 1.4c immutability pattern** (DB triggers + guard) — 883 deletions
with only a partial audit-side fallback is the evidence they probably should.
This is design input for that slice, not work to do now.

### Enrollment `school_id` — two-slice verdict + design decisions CLOSED (2026-07-19)

An enrollment episode has **no School of its own**: `student_curricula` carries no
`school_id` and the model is globally unscoped (verified — no `BelongsToSchool`,
no `addGlobalScope`). Every consumer re-derives school through scoped relations,
which is what produced Finance slice 2's three-branch resolution
(`students.school_id` → `curricula.school_id` → `0`) and forced cross-School
isolation into application code instead of the schema.

**VERDICT (settled, not re-litigable): two slices, `school_id` FIRST.** Bundling
it into Option B would forfeit independent rollback — `school_id` is _always_
reversible, Option B's re-key is lossy after the first repeat — and would put two
independent one-way clocks in one migration file, to save one ~1k-row table
rebuild. The two also share no backfill work (Option B's `active_key` is a STORED
generated column needing **no** backfill pass) and have disjoint reader sets.

**The (i)/(ii) split — load-bearing, do not blur:**

- **(i) Column + composite FKs.** Makes `episode.school == student.school ==
curriculum.school` structural; cross-School episodes become unrepresentable **at
  creation**; retires 3 hand-rolled checks (`CurriculumEnrollmentService:34`,
  `StudentCurriculumController:154`, `:206`) and closes the `StudentService::update`
  gap. Always reversible. **Closes ZERO read-side lookup holes.**
- **(ii) `BelongsToSchool` adoption on `StudentCurriculum`.** Closes the **9**
  unscoped `{studentCurriculum:uuid}` route bindings and changes the
  `PrincipalApprovalController:50` mass write. A fail-closed behaviour change =
  its own per-model rollout (§5.5). **NOT part of (i).**

**D1 — win boundary (framing, enforced).** Slice (i)'s completion report MUST lead
with: _"(i) makes cross-school episodes unrepresentable at creation and closes none
of the read-side binding/lookup holes; those are (ii)."_ Verified: all 9 bindings
(`web.php:312,:394`; `api.php:130,:136,:261,:262`;
`endpoints/form-teacher.php:8`; `endpoints/head-of-school.php:8,:9`) resolve a
uuid against a globally unscoped model and **remain unscoped after (i)** — adding
a column scopes nothing.

**D2 — `students.school_id` is IMMUTABLE-after-create; NO `ON UPDATE CASCADE`.**
Evidence: a grep of every `school_id` write path in `app/` finds **no operation
that updates `students.school_id`**. It is set once in `StudentService::store()`
and `StudentService::update()` deliberately omits it. The only `forceFill` on a
`school_id` is `SuperAdmin/AdminController:105`, which targets **users**, not
students. Domain-wise this is correct: a student moving School is a _new admission_
(v10 §2.1), not an UPDATE — and CASCADE would silently rewrite the School
attribution of every historical billed/graded episode. **Consequence:** composite
FKs need no cascade, history is safe by construction, and the latent defect is
that `'school_id'` sits in `Student::$fillable` (`Student.php:24`) with nothing
enforcing immutability. Slice (i) guards it (remove from `$fillable` or add an
`updating` guard).

**D3 — the `finance_invoices` composite FK RIDES slice (i), in its own migration
file.** Slice 2's guard is `UNIQUE(school_id, active_enrollment_key)` where the key
is `student_curriculum_id` — it already depends on the episode's School while
deriving it from a different table. Once `student_curricula.school_id` exists the
same fact lives in two tables, and a column without the FK that disciplines it is
the wallpaper pattern (i) exists to end. So
`finance_invoices (student_curriculum_id, school_id) → student_curricula (id,
school_id)` lands **in the same slice** — but as a **separate migration file**, so
the Finance coupling can be rolled back independently of the academic column (the
same principle that produced the two-slice verdict, applied in miniature).

**D4 — footprint is FOUR tables, not three.** Verified: neither parent has
`UNIQUE(id, school_id)` today — `students` has `PRIMARY(id)`, `unique_uuid`,
`(school_id, admission_number)`; `curricula` has `PRIMARY(id)`, `unique_uuid`,
`(school_id, class_level_arm_id, term_id, exam_type_id, is_ccm)`. Both need one
added. With D3, `student_curricula` also needs its own `UNIQUE(id, school_id)` to
parent the Finance FK. So slice (i) touches **`students` + `curricula` +
`student_curricula` + `finance_invoices`**. ⚠️ `students` prod row count is the one
place the "trivial in dev" (611 rows) estimate may not hold — a deploy-planning
look-for, not audited here.

**Slice (i) ENTRY CONDITIONS (all must hold before it opens):**

1. D1–D4 resolved and recorded (this section) — ✅ done.
2. **Pre-flight is the integrity test, not a checkbox.** Run the detection query
   (`docs/runbooks/prod-divergence-and-cascade-queries.sql` §C1, with §C1b's
   partition proof), **list** the offending episodes, **remediate to zero**
   (`docs/runbooks/slice-i-preflight-and-remediation.md`), _then_ run the
   migration. Rationale: the backfill copies `school_id` from the student, so the
   student composite FK is tautologically satisfied and the **curriculum**
   composite FK is the only one that can reject real data — it fails for every
   episode where `students.school_id <> curricula.school_id`, aborting the
   migration mid-deploy. Those rows are **expected, not hypothetical**: slice (i)
   exists because `StudentService::update`'s dead guard and the unscoped
   `exists:curricula,id` were live, and both produce exactly "local student +
   foreign curriculum". ⚠️ Dev cannot test this — it holds **one** School in both
   `students` and `curricula`, so a mismatch is structurally impossible there and
   its zero carries no information. Note `finance_invoices` is created _empty_ in
   the same deploy, so its composite FK cannot fail at the first Phase-1 deploy
   (the invoiced-offender case applies only to re-runs / Finance-bearing envs).
3. `BelongsToSchool` adoption is explicitly OUT of scope for (i).
4. `StudentRequest` / `ImportStudentRequest` scoped-`exists` fixes folded in — the
   FK would otherwise surface as a raw `QueryException`.
5. Opened as a fresh deliberate session — one-way, four-table, creation-path-touching.

**SLICE (i) LANDED (2026-07-19).** Two migration files, same deploy (Gate 1 —
"own file" buys clean `down()` ordering and independent rollback, never deferred
shipping; and the FK's creation doubles as a total consistency check over existing
data). Four tables: additive `UNIQUE(id, school_id)` on `students` + `curricula` +
`student_curricula`; `student_curricula.school_id` backfilled from
`students.school_id`; single-column FKs **swapped** (not stacked) for composites —
`(student_id, school_id) → students`, `(curriculum_id, school_id) → curricula`,
`finance_invoices (student_curriculum_id, school_id) → student_curricula`.

_ON DELETE was mapped per-FK, not chosen globally:_ each composite preserves the
semantics of the FK it replaces (CASCADE on the academic pair, RESTRICT on the
Finance child). Verified this is safe — a `students` delete cannot reach a
`finance_invoices` row: `finance_invoices.student_id` (RESTRICT) blocks it directly
and `finance_invoices.student_curriculum_id` (RESTRICT) blocks the cascade, and in
InnoDB a cascaded delete reaching a RESTRICT child fails the whole statement. The
armour for an invoiced episode lives on `finance_invoices`, not on the episode.
_ON UPDATE is NO ACTION everywhere_ (D2). Verified on the dev DB: **977/977**
episodes backfilled, 0 nulls, 0 mismatches against either parent; `down()` restores
the original FK names and index set exactly, and re-`up()` is clean.

`StudentCurriculum` now DERIVES `school_id` from the student in its `creating` hook
(a block closure — `creating` is a halting event), so no caller passes it; an
explicitly wrong value is _not_ masked and is rejected by the FK. `Student` guards
`school_id` as immutable-after-create on `updating` (removing it from `$fillable`
would have silently dropped it on `create()` and broken student creation).

**SLICE (ii) LANDED (2026-07-20) — the read side.** Boundary sentence: _slice (ii)
closes the read-side holes — the `{studentCurriculum:uuid}` bindings, the
`where('uuid')->firstOrFail()` assessment lookups, the three
`exists:student_curricula,uuid` rules, the `DashboardAnalysisService` join and the
`PrincipalApprovalController` mass write. It does **not** close Debt 7's
off-request fail-open, and it does not enable `rbac.fail_closed_models`._

_Shape:_ `addGlobalScope(new SchoolScope)` directly, **not** the `BelongsToSchool`
trait. The trait bundles the scope with a `creating` hook filling `school_id` from
**ambient ActiveSchool**, which registers first and would beat slice (i)'s
student-derived fill — re-coupling an episode's School to _who is logged in_, the
exact defect slice (i) removed. Follows the 7-model precedent (`Curriculum`,
`ClassLevel`, `Arm`, `Subject`, `ExamType`, `AcademicSession`, `GradeBoundary`).
**The rule:** use the trait when ambient context is the right _source_ of
`school_id`; use the bare scope when the value is _derived from a parent_.

_What the scope does NOT reach_ (found by re-derivation, fixed explicitly):
`exists:student_curricula,uuid` — Laravel's presence verifier queries the DB
directly and applies no Eloquent scope (3 rules scoped by hand); and the raw
`DashboardAnalysisService` join (predicate added **inside the JOIN condition**, so
`LEFT JOIN` semantics survive — a `WHERE` would silently make it an inner join and
drop no-enrollment rows from the slot counts).

_Off-request audit (Debt 7):_ `BackfillPastTermJob` and `MoveFromCcmJob` both carry
`public readonly int $schoolId` + `middleware(): [new SchoolAware]`, so they run
under `runFor` and the scope filters correctly. No console command touches
`StudentCurriculum`. `StudentCurriculumObserver` inherits its caller's context.
**No reader relies on the fail-closed throw**, which is what makes leaving
`rbac.fail_closed_models` alone safe here — the throw is `auth()->check()`-gated
(Debt 7) and would not have covered those paths anyway.

_Follow-on, unchanged trigger:_ enabling `RBAC_FAIL_CLOSED_MODELS` for
`App\Models\StudentCurriculum` is an independent behaviour change over every
context-less read path and needs its own audit. The standalone
`student_curricula.school_id` index also stays deferred — `EXPLAIN` still shows a
school-only filter as a full index scan, but no query this slice adds needs it.

**Parked debt — homed here so it survives the handoff docs that first recorded it.**
Each has a named TRIGGER; none is fixed by slice (i).

- **~~tsc ratchet is a false-green~~ — RESOLVED 2026-07-19, and the recorded cause
  was WRONG.** The earlier entry claimed a "+2 regression above baseline". There was
  never a regression. `resources/js/routes` **and** `resources/js/actions` are
  wayfinder-**generated** from the PHP routes and **gitignored**, and `lint.yml` had
  no generation step — so a fresh CI checkout did not contain them at all. The same
  commit therefore produced three different counts:

    | Measurement                                          | Count   |
    | ---------------------------------------------------- | ------- |
    | Stale local generation                               | 151     |
    | **CI-equivalent (no generation)**                    | **145** |
    | **True — freshly generated from current PHP routes** | **148** |
    | Old committed baseline                               | 149     |

    CI computed **145 ≤ 149 and passed unconditionally**, with ~4 errors of slack: the
    generated files' real errors were simply replaced by cheaper `TS2307`
    "cannot find module" ones. The gate was wired and did run — it was **measuring a
    different, smaller codebase than any developer sees.** The "151" that prompted the
    defect was an artifact of _stale_ generated files; the true count (148) was
    already **below** the floor.

    _Fixed:_ `lint.yml` now runs `php artisan wayfinder:generate` before
    `types:check` (so the count is reproducible and CI measures the real tree);
    `tsc-baseline` ratcheted **down** 149 → **148** (the true count); and
    `ci-tsc-ratchet.php` now **exit 1 on a decrease** instead of printing "please
    lower" — so "baselines only shrink" is enforced. Bite-proven: planted type error →
    exit 1; removed → green; simulated improvement → exit 1 demanding the floor drop.
    **`ci-test-ratchet.php` shared the same warn-only weakness and was fixed
    identically** (it now exits 1 when a baselined test starts passing).

    ⚠️ **Still needs a human:** whether the `linter` / `tests` jobs are _required
    status checks_ in branch protection. "The job fails" is proven here; "a failing
    job blocks merge" is a GitHub settings fact that cannot be read from the tree.

- **`DashboardAnalysisService:237-256` — unfiltered `student_curricula` join leg.**
  The `leftJoin('student_curricula', …)` at `:237` and the DISTINCT count at
  `:252-256` (keyed on `student_curricula.student_id`) carry **no school
  predicate**; isolation there rides on `class_levels.school_id` (`:217`) and
  `curricula.school_id` (`:245`) upstream. Probably safe today — the leg is reached
  only from already-scoped `curriculum_subjects` — but it is the weakest of the
  raw-SQL sites and the only one not explicitly filtered. _Had no home until
  2026-07-19._ **Trigger: slice (ii).** When `StudentCurriculum` adopts
  `BelongsToSchool`, every Eloquent path gains a `school_id` predicate while this
  raw join does not — re-audit it then, and add the standalone
  `student_curricula.school_id` index at the same time (`EXPLAIN` confirms a
  school-only filter is currently a full index scan, `type=index rows=977`).

**Defects confirmed during this pass (both live, neither gated on the migration):**

- **`promotedTo()` loads the wrong entity.** The FK is self-referencing —
  `promoted_to_id → student_curricula.id`, with the migration comment stating the
  intent outright: _"Self-referencing FK — points to the next student_curricula row
  after promotion."_ Both internal writers agree (`StudentCurriculumController:178`
  `=> $new->id`; `BackfillPastTermJob:254` `=> $sourceEnrollment->id`). But
  `StudentCurriculum::promotedTo()` (`:63-66`) does
  `belongsTo(Curriculum::class, 'promoted_to_id')`, `StudentRequest:66` validates it
  as `exists:curricula,id`, and `models.ts:444` types it `promoted_to?: Curriculum`.
  **The FK is right; the model, the request rule and the TS type are wrong.**
  Currently latent — **0 rows** have `promoted_to_id` set — and it activates on the
  first promotion (or FK-fails if a curriculum id is submitted). Fix belongs with
  the Option-B promotion-chain slice, which is built on this column.
- **`StudentService::update` dead guard.** `:118` reads
  `$student->studentCurriculum?->curriculum_id`, but `Student` has no
  `studentCurriculum` (singular) relation — only `studentCurricula()` (`:83`) and
  `currentCurriculum()` (`:88`). Laravel returns null for an undefined relation, so
  the branch **always fires**, and the `updateOrCreate` beneath it is the one
  enrollment-creation path with no School check. (`store()`/`import()` are
  double-guarded — `Curriculum::findOrFail` under SchoolScope, then
  `enroll()`'s check.)

### Same-curriculum re-enrollment — registrar decision matrix (awaiting product input)

**Specification scan (UI copy · validation messages · comments · ADRs · roadmap ·
migration comments · test names): no artifact specifies the rule.** The only
statements are self-contradictory validation messages ("Student is already
enrolled in this curriculum", `where(student_id,curriculum_id)->exists()` — implies
forbidden) sitting beside the delete-on-withdrawal (implies permitted); the
`unique(student_id, curriculum_id)` migration carries no intent comment; no ADR,
roadmap line (only this investigation's own notes), or test name states it. A
`'repeated'` status value exists (`StudentStatusEnum`, allowed by
`UpdateStudentCurriculumStatusRequest`) but **no workflow uses it** — a hint at
intent, not a specification. Archaeology cannot settle it; it is a product call.

**Decision matrix — the registrar's one word (A/B/C) selects a fixed schema +
identity model, no further investigation round.** (Not recommending one.)

| Dimension                                                 | A — prohibited forever                                                                                                                                                     | B — re-enterable after withdrawal (history kept)                                                                                                                                         | C — re-enterable only via a named workflow                                                                                     |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Schema                                                    | keep `UNIQUE(student_id, curriculum_id)`; **stop the delete** (soft-end via `ended_at`/status) — the constraint then correctly means "one enrollment per curriculum, ever" | **change uniqueness** to active-only: MySQL has no partial index → generated `active_key = (ended_at IS NULL ? curriculum_id : NULL)`, `UNIQUE(student_id, active_key)`; stop the delete | same active-only uniqueness as B; general `register`/`promote` keep the prohibition, only the named path inserts a new episode |
| StudentCurriculum identity                                | natural key `(student, curriculum)` — exactly one immutable row per pair; lifecycle on `status`/`ended_at`                                                                 | surrogate `id`/`uuid` — **episodic**: many rows per `(student, curriculum)` over time                                                                                                    | surrogate/episodic like B, but new episodes only from the gated workflow                                                       |
| History retention                                         | full (single row + its status/ended_at)                                                                                                                                    | full (one row per episode)                                                                                                                                                               | full (one row per episode)                                                                                                     |
| Reporting impact                                          | simplest — one row per `(student, curriculum)`; "ever enrolled" = row exists                                                                                               | richer but must handle N rows per pair; **audit every `where(student_id, curriculum_id)` query that assumes ≤1**                                                                         | same as B for reporting; entry restricted                                                                                      |
| Audit trail                                               | append-only, no deletion (aligns §15C spirit)                                                                                                                              | append-only, per-episode                                                                                                                                                                 | append-only, per-episode                                                                                                       |
| Finance §9 (durable referent for withdrawal→cancellation) | referent exists (the one row persists) — cancel against a stable id                                                                                                        | **best** — each billing episode is its own durable row/id                                                                                                                                | good — episodic referent, entry-gated                                                                                          |
| Migration complexity                                      | **LOW** — replace delete with soft-end; constraint unchanged; existing "already enrolled" checks become correct                                                            | **HIGH** — uniqueness redesign (generated column + index) + stop delete + audit all `(student,curriculum)` reads                                                                         | **MEDIUM–HIGH** — B's schema + a named re-enroll operation + keep general-path prohibitions                                    |

**Candidate workflows to name if the answer is C:** (C1) status-only repeat —
model a repeat as `status='repeated'` on the SAME row (schema unchanged, but
conflates the repeat term with the original enrollment); (C2) a dedicated
"re-enroll after withdrawal" admin operation gated on prior `withdrawn` status
(episodic rows like B, but general `register`/`promote` still refuse). The
registrar names which, if C.

**Common to all three:** the record-destroying `->delete()` on withdrawal must
stop regardless (it violates §15C append-only and destroys the §9 referent) — A
makes that a one-line soft-end; B/C couple it to the uniqueness redesign.

### 1.4e deep-dive (second pass — findings, no implementation)

**§1 enrollment convergence (proposal, smallest change).** `enroll()` already
takes `(Student, Curriculum, User, array $options)` and does the compulsory-subject
auto-attach. `@promote` and `@register` re-implement `StudentCurriculum::create`
inline and **skip `autoAttachCompulsorySubjects`** — a functional divergence, not
just a style one. Smallest convergence: `@register` → `enroll($student, $target, $user)`;
`@promote` → `enroll($student, $target, $user, ['status' => …])` for the new row +
keep the source→PROMOTED update in the same transaction. The controller already
injects the service. No schema change; a behaviour _fix_ (promotions/registrations
would gain the compulsory subjects they currently miss). Its own slice + tests;
not published as an event yet.

**§2b deletion defect — does NOT clear; the dependency IS the finding.**
`student_curricula` has `UNIQUE(student_id, curriculum_id)` and **no** soft-deletes
(verified from schema). So `updateStatus('withdrawn')` → `->delete()` is a
_workaround_: it removes the row so the student can re-enrol in the same curriculum
— a retained withdrawn row would block that at the unique index (and at
`@register`'s own `where(student_id,curriculum_id)->exists()` guard). The same
constraint means `unenroll()` (which keeps the row with `ended_at`) **already**
cannot re-enrol into the same curriculum — `enroll()` checks only
`whereNull('ended_at')`, then `create` hits the unique index → unhandled
`QueryException`. The two enrollment-end mechanisms diverge _because of_ the
uniqueness model. **Naive "stop the delete" is unsafe** — it breaks re-enrolment.
Correct fix (soft-end on withdrawal **and** active-only uniqueness — MySQL has no
partial unique index, so a generated `active_key` column with
`UNIQUE(student_id, active_key)` where inactive rows key to NULL) is a coupled
schema+logic slice, not a one-liner. Recorded as a prerequisite; nothing shipped.

**§2a withdrawal state machine — half-built.** Two enums exist —
`StudentStatusEnum` (used for _enrollment_ status) and `StudentMembershipStatus`
(student-level, also `withdrawn`) — but `students.status`/`left_at`/`leave_reason`
are unwritten. Intended model appears two-level: enrollment lifecycle (per
curriculum) vs student membership (per School — the Finance-relevant one). The
authoritative state machine is a design proposal for a later slice; not designed
here.

**§3 term lifecycle — CRUD today, should be date-derived.** `terms.status` is
written only by `TermController::update` (generic form edit); every other reference
is a read; no start/close operation, no scheduler. `terms` already carries
`start_date`/`end_date`. **Recommendation: term start/close is date-derived** —
recognition (§9) keys off the date, so **no lifecycle operation and no event are
needed**. If a deliberate close is ever wanted it needs the 1.4d scheduler; do not
build lifecycle commands before it.

**§4 published-fact inventory (candidates):**

| Fact                          | Authoritative transition | Single point?                 | Atomic post-commit? | Consumer            | Publish now?                     |
| ----------------------------- | ------------------------ | ----------------------------- | ------------------- | ------------------- | -------------------------------- |
| EnrollmentCreated             | `enroll` service         | No (promote/register bypass)  | yes                 | billing eligibility | prereq §1                        |
| EnrollmentEnded               | `unenroll` (ended_at)    | No (withdraw-delete competes) | yes                 | invoice cancel      | prereq §2b                       |
| StudentWithdrawn (membership) | does not exist           | n/a                           | n/a                 | billing stop        | prereq §2a                       |
| StudentPromoted               | `@promote` (direct)      | no                            | yes                 | academics           | prereq §1                        |
| TermStarted/Closed            | none (CRUD status)       | no                            | no                  | revenue recog       | not an event — date-derived (§3) |
| ResultApproved                | `@approve`               | single-ish                    | yes                 | Ph3                 | prereq ADR 0044                  |

**§5 readiness — what blocks 1.4e.** Every fact needs one authoritative
transition, one publication point, an atomic committed change, and a stable DTO.
Today **zero** facts have all four. Order: §1 convergence → §2b uniqueness rework

- soft-end withdrawal → §2a membership state machine → then EnrollmentCreated /
  EnrollmentEnded / StudentWithdrawn are publishable; TermStarted/Closed reclassified
  as date-derived (no event). The bus itself is trivial and comes last.

## 1.4c — audit-log immutability (2026-07)

**Implemented.** `activity_log` (spatie, backed by `App\Models\Activity`) is now
append-only and permanent (§15C), enforced in depth:

- **Model guard:** `Activity` `updating`/`deleting` throw
  `AuditLogImmutableException`. Safe because the write path is insert-only —
  verified 0 of 124k+ rows have `updated_at > created_at`; `school_id` is set in
  `creating`, before insert. No legitimate in-cycle update exists, so ALL
  mutation is blocked.
- **Database backstop:** BEFORE UPDATE + BEFORE DELETE triggers on `activity_log`
  SIGNAL an error — the layer that needs no application code, catching raw
  `DB::table()` writes, tinker, and a mass `->delete()` (which bypasses model
  events, e.g. `activitylog:clean`). DDL (future schema migrations) is unaffected;
  only row DML is blocked.
- **`activitylog:clean` disabled:** overridden by `App\Console\Commands\ActivitylogClean`
  (wins the signature) which refuses with an explanation; the DELETE trigger is
  the backstop if the spatie command is ever reached.

**`BackfillActivityLogSchoolId` interaction:** it was a completed one-time repair
(its raw `school_id` UPDATE). It ran BEFORE this lock; new rows are tagged at
creation by the resolver, and the residual null-`school_id` rows are unresolvable
system/cross-School events. Under the lock its UPDATE is now denied, so the
command guards on `information_schema.triggers` and refuses gracefully (only
`--dry-run` still works as a diagnostic). Its two update-path tests were replaced
by a refuse-when-locked test. `App\Models\AuditLog` is a SEPARATE table
(`audit_logs`), out of scope.

Bite-proofs (`AuditLogImmutabilityTest`, MySQL, 7): model update/delete throw;
raw DB update/delete denied at the DB; the mass-delete (clean) path denied at the
DB; `activitylog:clean` deletes nothing; a normal audited action still writes.

### 1.4c — trigger lifecycle & restore runbook (verification pass)

**Triggers survive `ALTER TABLE` (verified).** In MySQL a trigger is a schema
object bound to the table, not the column set — `ADD COLUMN`/`DROP COLUMN`
preserves both `activity_log` triggers (empirically confirmed). So a routine Ph2
audit-column addition cannot silently remove the guarantee. **Runbook rule:** a
migration that DROPS/recreates `activity_log` (or rebuilds it) must recreate the
triggers, and deploy must run `php artisan audit:verify-immutability` afterward.

**Triggers do NOT reliably survive a logical restore — the real gap.** The model
guard is application code and always present, but it does not stop raw SQL or a
mass `->delete()`; the triggers are the layer a restore can strip. Per mechanism:

| Restore mechanism                                                                | Triggers survive?                                                                                                                                                            |
| -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Logical dump/restore (`mysqldump`) — `activity_log` only OR full into a fresh DB | **Only if** dumped with `--triggers` (default-on but frequently disabled by managed-DB export tooling) AND the restoring user holds `TRIGGER` privilege. Often **stripped**. |
| Physical / binary restore (XtraBackup, filesystem/volume snapshot)               | **Yes** — triggers are in the physical data-dictionary files.                                                                                                                |
| Point-in-time recovery (base backup + binlog replay)                             | Survives if the base backup had them (physical) or the `CREATE TRIGGER` falls within the replayed binlog window; a logical base backup without `--triggers` does **not**.    |

**Mandated mitigation (implemented):** `php artisan audit:verify-immutability`
asserts both `activity_log` triggers exist and **exits non-zero, loudly, if
either is absent** (`VerifyAuditLogImmutabilityTest` proves pass-when-present /
fail-when-stripped). **Runbook: run it after ANY database restore and in the
deploy pipeline after `migrate`.** Without it, a restore that drops the triggers
leaves the audit log silently mutable — discovered only when someone edits a row
that should be immutable. (It already caught a genuinely-missing-triggers state on
the dev DB before its migration was applied.)

## 1.4b — Shared Sequences (investigation + implementation, 2026-07)

**Implemented.** `App\Support\Sequences\Sequences` (Shared Kernel) + a `sequences`
table (unique `(scope, key)`) provide an atomic, gap-tolerant counter incremented
under `SELECT … FOR UPDATE`. `HasAdmissionNumber` and `HasStaffNumber` now generate
the number **in `creating`** (before the insert, from the sequence) instead of a
post-insert `UPDATE`, closing the null-number window and the max+1 race. The
sequence **seeds from the current domain max on first use**, so the switch never
reissues an existing identifier. **Gap-tolerant only** — the service docblock
forbids reuse for gap-free Finance receipt/invoice numbering (§12.5), which needs a
signed policy and its own ADR. **The Shared Kernel service carries NO domain
meaning** — no Finance/invoice/receipt/accounting/gap-free terminology (it exposes
only a generic per-`(scope, key)` counter). The **application-level** boundary
lives here, not in the Kernel: any future _gap-free_ identifier (e.g. Finance
receipt/invoice numbering, which is legally contiguous) needs its own design + a
signed accounting policy that does not yet exist, and must NOT assume this
gap-tolerant counter satisfies it. **Transactional guarantee:** `save()` on the
consuming models wraps allocation + INSERT in one transaction, so a failed
persist rolls the allocation back with it — an allocated number never survives as
a committed assignment without its row (bite-proven). **School-scoped
concurrency:** the `FOR UPDATE` lock is on the `(scope, key)` counter row and the
key embeds the School, so different Schools lock different rows — no global counter,
no cross-School contention (bite-proven: two Schools → two independent rows).

**Design comparison (evidence, not familiarity) — chosen: counter table + row lock:**

| Option                                                          | Concurrency correctness     | Txn atomicity               | Rollback            | Op. complexity    | Portability    | Perf                             | Verdict                                                                                       |
| --------------------------------------------------------------- | --------------------------- | --------------------------- | ------------------- | ----------------- | -------------- | -------------------------------- | --------------------------------------------------------------------------------------------- |
| **Counter table + `SELECT…FOR UPDATE`**                         | serialises same-key writers | participates in caller txn  | reverts with caller | low (one table)   | standard SQL   | one indexed row lock/alloc       | **CHOSEN**                                                                                    |
| Counter table + `INSERT…ON DUPLICATE KEY UPDATE LAST_INSERT_ID` | correct                     | atomic single stmt          | reverts             | medium            | MySQL-specific | fastest                          | rejected — `LAST_INSERT_ID()` returns the PK not the value on first insert; brittle read-back |
| Advisory lock (`GET_LOCK`)                                      | correct                     | **NOT** part of the row txn | lock ≠ data         | medium            | MySQL-specific | lock RTT                         | rejected — connection-scoped, not atomic with the insert/rollback                             |
| Optimistic retry on `max+1` + unique index                      | correct after retries       | n/a                         | n/a                 | high (retry loop) | portable       | contends on the HOT domain table | rejected — retries under load, reads the domain table (the defect we're removing)             |

MySQL tests:
`SequencesServiceTest` (counter mechanics, seed-once, 200-call uniqueness+contiguity,
`FOR UPDATE` present) and `IdentifierGeneratorTest` (no null window, atomic
sequential, seed-from-max, gap-tolerance, per-School, unique-index backstop, staff
path). Concurrency proof is a **deterministic reproduction, labelled as such**
(true OS parallelism isn't reliably expressible in Pest; the row lock is the
guarantee).

### Shared Sequences — guarantees, non-guarantees, failure modes (adversarial pass)

**Guarantees:** uniqueness under concurrency · transactional allocation (allocation

- INSERT are one unit via the trait `save()` override — a failed persist rolls the
  allocation back) · School isolation (the `FOR UPDATE` lock is on the `(scope, key)`
  counter row and the key embeds the School — different Schools lock different rows) ·
  gap tolerance. **Explicitly NOT guaranteed:** gap-free numbering · chronological
  ordering across Schools · any global ordering · regulatory/legal numbering. Those
  require a separate design + signed policy (not this Kernel primitive).

**Lock ordering / deadlock (§1):** `Model::save()` fires `creating` (→
`Sequences::next`, which locks the counter row) **before** `performInsert`
(framework: `performInsert` fires `creating` then inserts). So every allocation
locks the sequence row first, then inserts the domain row — one consistent order,
no path inserts the domain row first. Same-School concurrent creates contend on
the single counter row (serialise, cannot deadlock on one lock); different Schools
lock independent rows and never contend. **No deadlock scenario exists.**

**Bypass enforcement (§2):** the trait `save()` override is the only entry point
carrying the transactional guarantee. All current create paths
(`StudentService::create`, `TeacherService`'s `teacher()->create`, bulk import)
go through it and already wrap in `DB::transaction`; `updateOrCreate` /
`firstOrCreate` / `forceCreate` also route through `save()` (events fire → number
generated). The genuinely dangerous bypasses — a raw
`DB::table('students'|'teachers')->insert` or a model `::insert` / `createQuietly`
— skip the event pipeline and would write a **NULL** identifier; none exist today
and a new `bin/ci-identifier-generation-lint.php` (CI + `composer lint:boundaries`)
now **fails** on any, so the prohibition is enforced, not prose. Residual: an
instance `->saveQuietly()` on a Student/Teacher variable isn't greppable with
confidence and is not linted — noted here as the one convention-only gap.
**§2 inverse:** wrapping `save()` in a transaction did not change either
consumer's error handling — both already wrapped creates in `DB::transaction`, and
the bulk imports keep per-row `try/catch` failure tolerance (the trait's nested
savepoint propagates the failure to the existing per-row handler unchanged).

**Sequence-table lifecycle (§3):** sequence rows are **permanent state** — no
prune / truncate / reset / cleanup touches the `sequences` table (only the
migration `down()` drops it on rollback). **Partial-restore hazard (asymmetric,
does NOT self-heal):** if `sequences` is restored to a state older than the domain
table, the counter sits below the live domain max; because the row already exists,
seed-from-max does **not** re-run, and the next allocation collides with the
composite unique index → a generation _failure_ (exception) that repeats until the
counter passes the domain max. Nothing detects or auto-recovers this.
**Operational runbook:** after any restore where `sequences` may lag the domain,
`DELETE` the affected `(scope, key)` rows — they lazily re-seed from the domain max
on next use — or `UPDATE value` to the current domain max.

**Failure-mode inventory (§4) — none yields a duplicate or a silent null:**

| Failure                            | Behaviour                                                          | dup? | reused? | skipped? | null? |
| ---------------------------------- | ------------------------------------------------------------------ | ---- | ------- | -------- | ----- |
| Rollback before insert             | savepoint reverts the increment                                    | no   | yes     | no       | no    |
| Rollback after insert              | whole save-txn reverts (row + increment)                           | no   | yes     | no       | no    |
| Unique-index violation             | save-txn rolls back; surfaced as an exception                      | no   | yes     | no       | no    |
| Deadlock victim                    | n/a — no deadlock scenario exists (§1)                             | no   | —       | —        | no    |
| DB restart                         | InnoDB reverts uncommitted allocations                             | no   | yes     | no       | no    |
| Txn / lock-wait timeout            | waiter errors, save-txn rolls back; caller retries → next value    | no   | yes     | maybe    | no    |
| Manual DB edits                    | out of code scope; same as partial-restore if counter set low      | no\* | —       | maybe    | no\*  |
| Sequence row corruption            | same as manual edit / partial restore → collision, runbook re-seed | no   | —       | —        | no    |
| Partial restore (counter < domain) | collision → generation failure until re-seeded (runbook)           | no   | —       | —        | no    |

\* the composite UNIQUE index prevents a duplicate and a NULL never persists (a
bypassing raw insert is blocked by the §2 lint); the worst outcome is a surfaced
generation failure, never a silent bad identifier.

**Inventory — exactly TWO runtime generators** (grep-verified, no others):
`HasAdmissionNumber` (Student, `admission_number`) and `HasStaffNumber` (Teacher,
`staff_number`). No `student_number` / `employee_number` / receipt / invoice
generator exists; invoice/receipt are Finance (Ph2+, out of scope, none built).

**Both are architecturally identical** — school-scoped, prefixed
(`GFA/YYYY/NNN`, `STF/YYYY/NNN`), zero-padded-3 suffix, gap-tolerant `max+1`.

**Schema (verified directly from MySQL, not inferred):**

- `students`: `UNIQUE(school_id, admission_number)` — present.
- `teachers`: `UNIQUE(school_id, staff_number)` — present (the flagged
  `Teacher.staff_number` candidate — confirmed, not assumed).
- Both columns nullable (transient NULLs allowed under the unique index). No
  missing/redundant index. **The composite unique index makes the application
  `creating` duplicate check redundant for _correctness_** (it survives only as a
  friendlier error message).

**Functional defect (the one the prompt names) — ALREADY FIXED.** Generation and
the duplicate-detection `creating` hook were silently halted by `AddUuid`'s
halting `creating` event (it returned the uuid, stopping the chain). Fixed in
1.3b.1 (`AddUuid` is now a block closure, boundary-lint enforced). Bite-proven:
the `creating` duplicate check now executes for both admission and staff numbers.

**Concurrency finding — the race is real, but it cannot produce a duplicate.**
`nextAdmissionNumber`/`nextStaffNumber` are unlocked read-then-write
(`SELECT max … → +1 → write`); two concurrent reads compute the SAME next value
(bite-proven). Under real concurrency the second write is rejected by the unique
index, so the failure mode is a **generation FAILURE** (the row keeps a null
number / the request errors), **never a duplicate**. Generation also happens in
`created` (post-insert `UPDATE`), so the number is not part of the atomic insert
and a failed update leaves a null. No transaction, no lock, no retry.

**Classification (evidence-driven, not for consistency):**

| Generator                    | Verdict                          | Why                                                                                                                                                                                        |
| ---------------------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `admission_number` (Student) | **Migrate → Shared Sequences**   | racy unlocked read-then-write + non-atomic `created`-time generation; needs an atomic, school-scoped, prefixed counter                                                                     |
| `staff_number` (Teacher)     | **Migrate → Shared Sequences**   | identical architecture and identical defect → shares the service's requirements exactly                                                                                                    |
| invoice / receipt (Finance)  | **Out of scope (identify only)** | do not exist; Finance Ph2+; likely need _gap-free_ (regulatory), a stronger requirement than admission/staff gap-tolerance — must not be assumed to share this service without its own ADR |

**Migration target & boundary (ADR 0033 §8.1/§8.2):** the shared service lives in
`app/Support/Sequences/` (Shared Kernel). Student (Academics/Admissions) and
Teacher (HR/Academics) depend on the Kernel, never on each other or on Finance;
the Kernel never depends on a Module. The redesign generates the number **before
insert** via an atomic per-`(school, prefix)` counter (a `sequences` table with a
locked/`ON DUPLICATE KEY UPDATE` increment, gap-tolerant) so concurrent creates
get distinct values without relying on the unique index as the failure backstop.
Gap-free is NOT a requirement for admission/staff (only Finance receipts) — do not
over-build. **Implementation is the next slice; this slice is investigation +
bite-proofs only.**

## Decision: Larastan is baseline-relative (ratchet)

The §24 exit criterion "Larastan Level 5 + arch tests green" and the Execution
Plan's ratchet philosophy were previously ambiguous about whether "green" means
**absolute zero findings** or **zero findings above the committed baseline**.

**Decision (architectural, not an assumption):** Larastan Level 5 is enforced
**baseline-relative** — CI fails only on findings NOT in `phpstan-baseline.neon`,
identically to the test / tsc / authz / boundary ratchets. Level 5 is fixed for
Phase 1; the baseline may only shrink. "Green" at Phase-1 exit therefore means
**green against the baseline**, and burning the baseline toward zero is
opportunistic Continuous work, not a Phase-1 exit blocker.

Rationale: every other CI gate in this repo is a ratchet (freeze pre-existing
debt, fail on regressions). An absolute-zero Larastan bar would be the sole
exception, would gate Phase-1 exit on a multi-week static-analysis cleanup with
no Finance-blocking payoff, and would contradict the approved delivery model.
This decision makes the ratchet interpretation explicit and binding.

## Decision: the §24 authorization checkpoint is not closed by `authz-lint = 0`

**A green authz-lint is a false signal for §24.** The lint counts _commented-out_
authorization; the S5 rollout clears each commented check by restoring it as live
code that runs in **observe mode** (records a would-be denial, then continues —
never blocks). So authz-lint reaches 0 the moment the checks are restored, while
**no request is actually authorized** — enforcement is still off. Treating
authz-lint = 0 as the exit condition would mark §24 "done" over an app that
authorizes nothing.

**Binding: the §24 authorization checkpoint closes only when all four hold —**

1. `authz-lint = 0` (no commented authorization remains), **and**
2. `AUTHZ_ENFORCE=true` is set in the production environment, **and**
3. the observe-mode evidence (`authz_observations`) has been reviewed and every
   would-be denial classified as expected (not a legitimate-access regression), **and**
4. enforcement is verified _active_ in production — a request lacking a required
   permission actually receives 403, confirmed by a live check, not by config alone.

Until all four hold, §24 authorization is **open**, regardless of the lint number.

### `AUTHZ_ENFORCE` is temporary rollout infrastructure (not permanent config)

`config/authz.php` (`AUTHZ_ENFORCE`, default `false`) and `App\Support\Authz`'s
two-mode gate exist **only** to roll authorization out safely (observe → analyse
→ enforce). This is scaffolding with a defined end, not a permanent feature flag:

- **Owning slice for removal:** the S5 enforcement slice (the one that sets
  `AUTHZ_ENFORCE=true` in production and closes §24 above).
- **Removal condition:** enforcement has been active and stable in production for
  one full release cycle with the observation table reviewed and quiet (no new
  unexpected denials), i.e. §24 closed per the four criteria above.
- **Planned deletion:** once that holds, delete the `enforce` branch from
  `App\Support\Authz` (checks become unconditional — fail = `abort(403)`), drop
  `config/authz.php`'s `enforce` key and the `AUTHZ_ENFORCE` env var, and prune +
  drop the `authz_observations` table (see ADR 0043). If enforcement instead
  becomes permanently config-toggleable, that reversal requires its own ADR — it
  must not become permanent-by-default drift.

`Authz` itself (the call-site shape) is likewise transitional; its long-term
disposition — fold into Laravel Policies/Gates, or keep as a thin wrapper — is
decided in **ADR 0043**, which also records that no new (Finance) business logic
may depend on `Authz`. The ordered **teardown sequence** (migrate every call to
its permanent home → verify no `Authz::` remains → delete wrapper → remove flag →
drop table + commands + schedule → delete rollout-only tests, keep the invariant
tests) is fixed in **ADR 0043 §5**. Deletion must never remove authorization:
each check is migrated before the wrapper is removed.

### `authz_observations` retention & pruning

- **Retention window: 30 days.** Rows older than 30 days are deleted daily by the
  scheduled `authz:prune --older-than=30` ([routes/console.php](../routes/console.php)) —
  the retention period lives here (roadmap + schedule), not only as a command
  default. At rollout teardown the table is dropped entirely (ADR 0043 §5).
- **Data minimization:** observations store the request **path only**
  (`getPathInfo()`), never `fullUrl()` — no query string, so no PII from query
  parameters enters the table (ADR 0043 §4). It is rollout evidence, not an audit
  log.
- **Pruning is scheduled, not merely available:** without a schedule an evidence
  table becomes permanent telemetry by default, which ADR 0043 §4 forbids.

### Scheduler is the first-and-only scheduled task — a Phase-2 gap

`authz:prune` (2026-07, this slice) is the **first scheduled task in the
codebase**; before it, no `withSchedule()` / `Schedule::` existed. The
registration point is now `routes/console.php`. **Gap (owning slice: a Phase-2
"scheduling infrastructure" slice, not built now):** Phase 2+ assumes ambient
scheduled work that does not yet exist — revenue recognition on term start (§9),
dunning reminders (§11), the ledger drift-verification job (§12.2), reconciliation.
Those are **School-scoped** and per §5.4 must iterate Schools via
`ActiveSchool::runFor()`; `authz:prune` is exempt only because it deletes by age
and reads no School-owned data. The scheduling _mechanism_ (a running
`schedule:run` cron / `schedule:work`) must be provisioned in the deploy
environment before any Phase-2 scheduled job is relied upon.

**Deployment prerequisite — the scheduler must actually run (registration ≠
execution).** `schedule:list` proves the task is _registered_; it does not prove
the OS invokes it. Exactly one of the following must be configured in the
deployment environment, and its execution **verified** (e.g. observe an
`authz:prune` run in the logs, or a scheduled `->onSuccess()`/health ping):

- **cron** (one entry, calls the runner every minute):
  `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- **Supervisor / systemd** long-running worker (alternative to cron):
  `php artisan schedule:work` under a process supervisor that restarts it.
- **Platform scheduler** (Forge "Scheduler", a Kubernetes CronJob, Envoyer/
  Vapor scheduler, etc.) configured to run `schedule:run` every minute.

**This is an environment concern the repository cannot prove.** There is no cron
manifest, Procfile, systemd unit, or platform-scheduler config committed; the repo
only proves _registration_ (`routes/console.php` + `ScheduleTest`). Whether
`schedule:run` is actually invoked in any environment is **not determinable from
this repository — do not infer it is.** **Binding: observe mode must not receive
production traffic until scheduler execution has been verified in that
environment** — otherwise `authz_observations` grows unbounded (§5b(b) is solved
only on paper) and the data-minimization/retention guarantees are void.

**Deployment pre-flight — every FK-dropping migration's `down()` must be verified
for RE-UPGRADE, not just rollback.** Found once, check everywhere: the Finance
template rename's `down()` had a real bug — MySQL's `DROP FOREIGN KEY` leaves the
FK's backing index behind, so the first `down()` left a state `up()` could not
reapply (the re-added single-col FK reused the leftover composite index, so the
expected-named index never came back and the re-`up()`'s `DROP INDEX` failed). It
was caught only by running rollback **then migrating again** — not by rollback
alone. This is a general MySQL gotcha, not specific to those tables. So when the
first-deploy is planned, for every Phase-1 migration that drops a foreign key,
verify the four paths (fresh / upgrade / rollback / **re-upgrade**), because a
broken `down()` hurts most during an incident: you roll back to recover, then
cannot re-deploy the fix. _Do not audit them now — this is the captured pre-flight,
looked-for at deploy planning._

### S7 — remove `users.school_id` + `school_user` (execution plan + runtime-zero gate)

The last mechanical §24 item (1.2f remainder). **The runtime dependency graph
below shows runtime-zero is NOT yet satisfied — the schema migration is therefore
prohibited** (hard gate). Every executable reference must first be removed,
repointed, or justified.

**Runtime dependency graph (executable references, verified 2026-07):**

_`users.school_id` (read/write):_

- `ActiveSchool::id()` [:54-55](../app/Support/ActiveSchool.php#L54) — the fallback source (source 3). **Remove (S7 Step 3).**
- `ActivitySchoolResolver` — **DONE (S7 Step 2):** now reads through `ActiveSchool::id()`; the direct `auth()->user()->school_id` read is gone.
- `SuperAdmin\AdminController` [:104-105](../app/Http/Controllers/SuperAdmin/AdminController.php#L104) — reads + **writes** the column (maintenance). **Delete (S7 Step 4).**
- `TeacherService` [:69](../app/Services/TeacherService.php#L69) — **writes** `users.school_id` on teacher creation (from `TeacherRequest`'s validated `school_id`). **Repoint** to a role/pivot grant before the column drop.
- `User` `$fillable` [:30](../app/Models/User.php#L30) + `computeAccessibleSchoolIds()` legacy branch [:142](../app/Models/User.php#L142) + `school()` relation [:246](../app/Models/User.php#L246). Legacy branch is bypassed when `rbac.single_source_access=on`; `$fillable`/relation removed at column drop.
- Commented ownership checks: [StudentSubjectController:227](../app/Http/Controllers/StudentSubjectController.php#L227), [StudentCurriculumController:67](../app/Http/Controllers/StudentCurriculumController.php#L67) — **delete** at drop (do not migrate).
- Frontend type `auth.ts` `school_id: string` [resources/js/types/auth.ts](../resources/js/types/auth.ts) — remove at drop; audit consumers.

_`school_user` pivot (read/write):_

- `User::schools()` belongsToMany + `computeAccessibleSchoolIds()` legacy branch — bypassed by the single-source flag; removed at drop.
- **Direct data-visibility readers (independent of the flag — the load-bearing risk):** `GuardianService` [:38](../app/Services/GuardianService.php#L38) join, `Teacher` [:54](../app/Models/Teacher.php#L54) subquery, `Guardian` [:93](../app/Models/Guardian.php#L93) subquery. These scope guardian/teacher multi-school visibility off `school_user` and are **not** covered by flipping `single_source_access`. They must be repointed to `model_has_roles` (the backfilled single source) **before** the pivot is dropped.
- `TeacherSchoolAccessController` (writes grants) + frontend `school-checklist.tsx` / `manage-teacher-schools-dialog.tsx` (manage grants) — repoint grant-writes to the single source.
- Backfill migration `2026_07_14_000002_backfill_guardian_school_access.php` — historical; leave.

**Runtime-zero verification method:** `grep -rn --include='*.php' 'users?\.school_id\|->school_id\b\|school_user' app/ database/ routes/` restricted to executable lines (exclude comments/tests/backfill), cross-checked against a grep for `->schools()` and `auth()->user()->school_id`. **Current result: NOT zero** — the readers above remain. An arch test asserting zero `school_user` / `user->school_id` executable references should be the machine-verifiable gate before the migration.

**Sequence (each step independently mergeable; STOP for review before any
destructive change):** Step 2 (ActivitySchoolResolver) ✅ done · Step 3 remove
`ActiveSchool` fallback · Step 4 delete `AdminController:104` write · repoint
`TeacherService`/`GuardianService`/`Teacher`/`Guardian`/`TeacherSchoolAccess` off
the legacy sources · Step 5 enable `rbac.single_source_access` · Step 6 **parity
soak** (dual-compute, see below) · Step 7 rollback rehearsal · **Step 8 STOP** ·
then column drop (working `down()`, ADR 0042 expired, boundary-lint baseline 5→1).

**Parity soak must be dual-compute, not flag-flip.** `accessibleSchoolIds()` memoizes
keyed on `single_source_access` (S3), so a single request derives one path. Real
per-user divergence is only caught by computing **both** paths for the **same**
request and logging mismatches (user · School · old · new · source · reason) —
a flag-flip between runs compares different traffic and misses it. **Coverage
clause:** zero mismatches over zero (or unrepresentative) decisions is not
evidence; the soak log must show every user category (single/multi-School, super
admin, zero-access), ≥2 Schools, and both HTTP and queue transports, and must
report the decision count + distribution. **Zero mismatches _with_ coverage is the
only condition permitting the migration.**

**Near-miss — a flag proves parity only for the paths it controls.**
`rbac.single_source_access` gates `accessibleSchoolIds()`, but three `school_user`
readers (`GuardianService`, `Teacher`/`Guardian` scopes) sat **outside** the
flag. A parity soak would have measured the controlled path, reported green, and
the column drop would then have broken guardian/teacher visibility in production.
**Lesson (Phase 2 will use flags for exactly this pattern): enumerate every
reader of a source before trusting a flagged rollout — control ≠ coverage.** Fixed
in this slice by funnelling the three readers through `App\Support\SchoolAccess`,
which is gated by the same flag, so the soak now covers them.

**S7 progress (this slice — no schema change; runtime-zero still closed):**

- **Runtime-zero gate landed + CI-wired** (`bin/ci-runtime-zero-lint.php`,
  `runtime-zero-baseline.txt`, composer `lint:boundaries` + `lint.yml`). Fails on
  any NEW executable `users.school_id` / `school_user` reference; baseline
  **6** (was 8 before the reader consolidation), ratcheting to **0** at the column
  drop (its expiry). Coverage caveat: it catches literal reads + the pivot table
  name + raw `users.school_id`; the two column **writes** (`AdminController`
  forceFill, `TeacherService` `User::create`) and Laravel's implicit
  `belongsToMany` pivot (`User::schools()`) are tracked here in the graph, not by
  the regex.
- **Divergence count (§1) = 0 in dev** (`school_user` 776 rows / `users.school_id`
  846 set → 0 orphans without a matching `model_has_roles` row). **Production must
  reconfirm before the flag flip** — dev is not authoritative.
- **Parity instrument built + bite-proven** (`App\Support\SchoolAccessParity`,
  `rbac.parity_soak`): dual-computes both paths per decision, logs `lost`/`gained`
  per `(user, School)`; a test injects a known divergence and asserts detection
  with all fields.
- **Readers repointed** (flag-gated via `SchoolAccess`); **Step 2 (audit
  resolver)** already landed.
- **§5 — `ActiveSchool::id()` null-dependants (before source-3 removal):** callers
  that correctly want `null` with no principal and do **not** rely on the
  `users.school_id` fallback — `SchoolScope` (console/worker), `SetSchoolContext`
  (drives select-school), `HandleInertiaRequests` (guarded by `$user`),
  `ActivitySchoolResolver` (falls through). Single-school request readers
  (`(int) ActiveSchool::id()` in controllers) are covered by the **session**
  school_id set at login ([SchoolAwareLoginResponse:45](../app/Http/Responses/SchoolAwareLoginResponse.php#L45)),
  so source-3 is a redundant safety net — but any authenticated path with neither
  session nor token school_id must be audited before Step 3. **Source-3 NOT
  removed in this slice.**
- **§2 — writer semantics (proposed; NOT implemented — stop for review):** the two
  `users.school_id` writers need an intent decision, not a translation.
  `TeacherService` writes the column on teacher creation from `TeacherRequest`'s
  `school_id`; the proposed replacement is **teacher creation grants a role in
  that School** (`model_has_roles` row) — a behaviour change (creation now confers
  a role). Which role? Presumably `teacher` in the selected School. `AdminController`
  [:104-105](../app/Http/Controllers/SuperAdmin/AdminController.php#L104) _maintains_
  the column (resets a user's home School when it leaves their accessible set);
  once the column is gone this maintenance has **nothing to maintain** and should
  **simply delete**, not translate. Both need sign-off before implementation.

### S7 writer semantics — teacher creation already IS School assignment (§1 evidence)

Investigated before proposing any writer change (do not infer from the column write):

- **What creates a teacher:** `TeacherService::processTeacherAccount` (single) and
  the bulk-import path (`~line 155`). Both, inside one DB transaction:
  `User::create([... 'school_id' => ActiveSchool::id() ...])` → **`$user->assignRole('teacher')`** →
  `store()` (the `teachers` row).
- **When a teacher becomes authorized to a School:** at `assignRole('teacher')` —
  in spatie teams mode this writes a `model_has_roles` row in the **active team**
  (the admin's School, set by `SetSchoolContext`). **That row is the access
  grant.** Additional Schools are granted via
  `TeacherSchoolAccessController` → `grantSchoolAccess($school, 'teacher')`.
- **Can a teacher exist before School access?** No — creation atomically assigns
  the role in the same transaction.
- **Is the `users.school_id` write a business rule or legacy compat?** **Legacy
  compat.** Access is already conferred by `assignRole`; `users.school_id` is a
  redundant home-School stamp on the _User_. It is distinct from
  **`teachers.school_id`** (the Teacher model's own home-School column, a
  `BelongsToSchool` field that is **NOT** part of S7 and stays).
- **Proposed writer change (still STOP-for-review, not implemented):** delete
  `'school_id'` from the two `User::create(...)` calls — **no new role grant is
  needed, it already exists.** One implementation guard: `assignRole` must run
  with the correct permissions-team set (true on the request path today); the
  writer slice must assert/So set the team explicitly so an off-request create
  path can never write a null-team (global) role row.

### S7 divergence-prevention — the assignRole team invariant + writer disposition (§2)

The objective is not that divergence is zero today but that the app can no longer
**create** it tomorrow. Two mechanisms:

**1. Permanent invariant (landed):** `User::assignRole` is overridden to throw
`NullTeamRoleAssignmentException` if a school-scoped role is assigned with a null
permissions-team (`super_admin` exempt). A null-team role grants access to no
School — the precise divergence S7 removes. Enforced at the model so no call site
can bypass it, on request or off (proven by `AssignRoleTeamInvariantTest`,
including the `runFor` off-request case). It surfaced one real test-setup bug
(`GuardianProfileTest` helpers assigned roles with no team — fixed to establish
context; they had only "passed" via team-state leaking between tests).

**2. Every writer to a legacy source — location · disposition:**

| Writer                                                                   | Source                                                         | Writes a role too?                                 | Divergence-capable?           | Disposition                                                     |
| ------------------------------------------------------------------------ | -------------------------------------------------------------- | -------------------------------------------------- | ----------------------------- | --------------------------------------------------------------- |
| `TeacherService` `User::create` (×2)                                     | `users.school_id`                                              | Yes — `assignRole('teacher')`, now team-guaranteed | No                            | delete the `school_id` key (writer change, gated on prod count) |
| `GuardianService::enableLogin` scenario-1 `User::create`                 | `users.school_id`                                              | Yes — `assignRole('guardian')` + a Guardian record | No                            | delete the `school_id` key at the writer change                 |
| `SuperAdmin\AdminController:105` `forceFill(['school_id'])`              | `users.school_id`                                              | **No** — resets the column only                    | **Yes** (column without role) | **delete** — nothing to maintain once the column is gone        |
| `User::grantSchoolAccess` `schools()->syncWithoutDetaching`              | `school_user`                                                  | Yes — role + pivot written together                | No                            | delete the pivot write at the column drop (role write stays)    |
| `User::revokeSchoolAccess` `schools()->detach`                           | `school_user`                                                  | removes role + pivot together                      | No                            | delete the pivot side at the column drop                        |
| `createGuardianWithUser` / attach path → `grantSchoolAccess('guardian')` | `school_user` (+ role)                                         | Yes (via grantSchoolAccess)                        | No                            | pivot side removed at drop                                      |
| backfill migration `2026_07_14_000002`                                   | `school_user`                                                  | historical one-time                                | No                            | leave (history)                                                 |
| `Api\AuthenticationController` `forceFill(['school_id'])` (×2)           | **`personal_access_tokens.school_id`** — NOT `users.school_id` | n/a                                                | No                            | **out of S7 scope** (token column, not the user column)         |

**Guardians (explicitly checked, §2):** guardian access is written as a Guardian
record **plus** a role — via `grantSchoolAccess('guardian')` (create/attach) or a
team-guaranteed `assignRole('guardian')` (enableLogin). Both sides are written
together, so no guardian path creates divergence. `enableLogin`'s bare
`assignRole('guardian')` is correct-by-context (the Guardian is `SchoolScope`d to
the active School, so the ambient team is the guardian's School) and is now
additionally null-team-guarded by the invariant; converting it to
`grantSchoolAccess` for symmetry is a recommended (non-blocking) tidy.

**Conclusion:** after the invariant, the **only** divergence-capable writer is
`AdminController:105` (column-only reset), whose disposition is deletion. No code
path can create a null-team role or a role/legacy-source mismatch once the writer
change + that deletion land.

**CORRECTION (2026-07, empirically verified) — the writer deletion is NOT
flag-independent.** The premise "the column write is redundant to a role grant"
holds only under `single_source_access = ON`. Under the **legacy path (flag OFF,
the production default)**, `accessibleSchoolIds()` does **not** read
`model_has_roles`; for two writers the column is load-bearing:

- **`TeacherService` (×2):** a teacher created role-only (no `users.school_id`,
  and teacher creation writes **no** `school_user` pivot) resolves to an **empty**
  accessible-school set under the legacy path — proven in tinker: flag OFF → `[]`,
  flag ON → `[13]`. An empty set means `SchoolAwareLoginResponse` rejects the
  login. Deleting this write while the flag is off **strips new teachers' login.**
- **`AdminController:105`:** it _resets_ `users.school_id` when a home-School is
  revoked, precisely so the legacy fallback (`if ($this->school_id) push`) stops
  granting the revoked School. Deleting it leaves a **revoked School still granted
  via the fallback** — a security regression under the legacy path.
- **`GuardianService::enableLogin`:** SAFE to delete — guardians resolve via the
  guardian-record branch of the legacy union (proven: flag OFF → `[14]` with no
  column), so the column is redundant for guardians.

There is **no** role↔column/pivot sync listener (checked `app/Listeners`,
`app/Observers`), so the legacy path genuinely depends on the column.
**Corrected disposition:** the teacher and AdminController writes must be
**flag-gated** (write only while `single_source_access` is off) or **coupled to
the flag flip**, not deleted unconditionally. Deleting them now would regress
production. Held for review; only the guardian removal is safe today.

### S7 production divergence snapshot — the command (§3b/§1)

`php artisan s7:divergence-snapshot [--json]` (read-only) is the baseline-snapshot
gate. It counts users whose access came from a legacy source but was never
mirrored into `model_has_roles`, across **all three** sources
(`school_user`, `users.school_id`, guardian records), grouped by School,
**excluding super_admin** (team-less by design). Emits `taken_at` / environment /
database / per-source counts / total; exit 0 = PASS, exit 1 = STOP. Run against
**production or a fresh untouched snapshot** immediately before enabling the
parity instrumentation (dev is the wrong sample — sources seeded together agree by
construction). Non-zero is a **review STOP** (a backfill grants real access to
real people), never a mechanical fix. Locked by `S7DivergenceSnapshotTest`.

### S7 architectural corrections (2026-07)

- **§0 — `accessibleSchoolIds()` is EITHER/OR, not a union with roles.**
  `computeAccessibleSchoolIds()` returns `schoolIdsFromRoles()` (roles only) when
  `single_source_access` is ON, **else** `legacyAccessibleSchoolIds()` — which is
  `school_user` pivot ∪ guardian records ∪ `users.school_id`, and **does not read
  roles**. The two paths are mutually exclusive. This is why a role-only user
  resolves to `[]` under the flag-off legacy path. Any earlier phrasing implying
  the legacy branch _unions_ `schoolIdsFromRoles()` is wrong; the model docblock
  is corrected to state the either/or explicitly.
- **§2 — writer strategy is Option B (not A).** Leave ALL compatibility writers
  (`TeacherService` ×2, `AdminController:105`, `GuardianService::enableLogin`)
  untouched until the column-drop slice; delete them together there. Option A
  (flag-gated writes that self-disable at the flip) is **rejected**: a teacher
  onboarded _after_ the flip would have no column value, so a flip-back (soak
  surprise / prod ≠ staging) drops them to `[]` and rejects login — Option A makes
  the flag one-way for everyone onboarded after it, destroying the reversibility
  that is the point of expand/contract. Option B keeps the column populated until
  the schema actually changes, so the flip stays reversible. The guardian
  `enableLogin` write is safe to remove independently but is **not** removed early
  — that would fragment the writer set for one line that dies at the drop anyway.
  `AdminController:105` is reclassified: not maintenance but the control that
  stops the legacy fallback granting a revoked School — it dies **with** the
  fallback.
- **§3 — every role assignment flows through the invariant; no bypass.** All
  `assignRole` call sites are on `User` instances, so the `User::assignRole`
  override (the team invariant) governs them; the only direct `model_has_roles`
  access is a **read** (`AdminController:21`). No `syncRoles` / `roles()->attach` /
  direct role INSERT exists. Two seeders (`TeacherSeeder`, `GuardianSeeder`)
  assigned roles with no team — the invariant correctly caught them; fixed to
  establish context (`UserSeeder` already did). The dead, unrouted
  `AuthenticationController@register` `assignRole('admin')` would throw under the
  invariant if ever wired — a latent bug the invariant surfaces, left as-is
  (registration is disabled).
- **§4 — runtime-zero gate is now two sections.** _Section A_ = application
  references (must reach 0 before the drop) — baseline **12**. _Section B_ =
  migration tooling that legitimately references the legacy schema
  (`S7DivergenceSnapshot`, `SchoolAccessParity`) — reported separately, **never**
  gates Section A, expires with the S7 teardown. Section B = 3.
- **§7 — hidden write-path audit.** Sweep of observers, jobs, console commands,
  factories, seeders, listeners, imports, exports, notifications, scheduled tasks,
  services, traits: the ONLY writers to `users.school_id` are `TeacherService` ×2,
  `GuardianService::enableLogin`, `AdminController:105`, and the seeders
  (Teacher/Guardian/User — seed data). The ONLY writers to `school_user` are
  `User::grantSchoolAccess` (`schools()->syncWithoutDetaching`) and
  `revokeSchoolAccess` (`detach`). No hidden path in any other layer. A final
  re-sweep is required immediately before the drop (§7 gate).

### Runtime-zero gate — blind spots + compensating controls (§2)

The gate is a grep; it cannot see everything. Each blind spot and its control:

| Blind spot                                                                       | Detected?                                             | Compensating control                                                                                                                                                                            |
| -------------------------------------------------------------------------------- | ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Explicit `school_user` string                                                    | **Yes** (pattern)                                     | —                                                                                                                                                                                               |
| Implicit `belongsToMany(School)` pivot + `->schools()` consumers                 | **Yes** (patterns added §2)                           | the gate now fails while any `->schools()` call remains — it cannot report 0 with the pivot still resolved                                                                                      |
| `$user->school_id` / `->user()->school_id` reads, raw `users.school_id`          | **Yes** (patterns)                                    | —                                                                                                                                                                                               |
| `$this->school_id` in User.php                                                   | **Yes** (file-scoped pattern)                         | —                                                                                                                                                                                               |
| Column **writes** (`AdminController` forceFill, `TeacherService` `User::create`) | **No** (would over-match `'school_id' =>` everywhere) | **boundary-lint** (`school-id-fallback-context` + maintenance-write) + the writer disposition in the readiness matrix below                                                                     |
| Dynamic property access (`$model->{$attr}`, `getAttribute('school_id')`)         | **No**                                                | code review + the readiness matrix; `ActivitySchoolResolver::schoolIdOf` uses `getAttribute('school_id')` polymorphically and is intentionally model-agnostic (not a users.school_id reference) |
| Dynamically-built SQL / raw `DB::statement` string interpolation                 | **No**                                                | none automated — none exists today (grep for `DB::statement`/`DB::raw` with `school_user` is empty); a reviewer check at the writer/drop slice                                                  |
| Reflection / container resolution                                                | **No**                                                | none exists; no `school_id`/`school_user` is resolved by string via the container                                                                                                               |
| Vendor callbacks (spatie relation naming)                                        | Partially                                             | the `belongsToMany(School)` + `->schools()` patterns catch the app-side wiring; spatie's internal pivot name derivation is covered by removing the relation                                     |
| Frontend (`auth.ts` `school_id`)                                                 | **No** (PHP-only lint)                                | tsc + the readiness matrix (delete at drop)                                                                                                                                                     |

### S7 parity-soak exit criteria (§3) — required BEFORE `RBAC_SINGLE_SOURCE_ACCESS` in staging

Parity is "complete" only when the soak log (`school-access-parity` channel) shows:

- **Coverage — every category must appear** (count per category reported, not just the total):
  single-School user, multi-School user, super admin, zero-access user; **≥2
  Schools**; transports **HTTP and queue** (scheduled if any School-scoped
  scheduled job exists by then).
- **Minimum volume:** ≥1 observation per (category × transport) cell, and enough
  distinct users that every active School appears at least once. Report the
  decision count and its distribution — **zero mismatches over thin coverage is
  not evidence.**
- **Threshold:** **zero unexplained mismatches.** Any `lost` row blocks (a
  revocation risk → backfill). Any `gained` row must be explained (usually a role
  granted after the legacy source lapsed) or blocks.

### S7 production divergence count (§3b) — a GATE, not a re-confirmation

The dev-DB 0/0 result is **not evidence** — dev's `school_user` and
`users.school_id` were seeded together, so they agree by construction (wrong
sample). Drift only accumulates in production, and **the soak cannot substitute
for this count**: the soak only sees users who generate traffic in the window; a
teacher who does not log in that week is invisible to it but still loses access at
the drop. **Run against production, before the soak:**

```sql
-- (a) school_user rows with no matching model_has_roles row
SELECT su.school_id, COUNT(*) AS orphan_pivot_rows
FROM school_user su
LEFT JOIN model_has_roles mhr
  ON mhr.model_id = su.user_id AND mhr.model_type = 'App\\Models\\User'
 AND mhr.school_id = su.school_id
WHERE mhr.role_id IS NULL
GROUP BY su.school_id;

-- (b) users.school_id values with no matching role row
SELECT u.school_id, COUNT(*) AS orphan_home_school
FROM users u
LEFT JOIN model_has_roles mhr
  ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
 AND mhr.school_id = u.school_id
WHERE u.school_id IS NOT NULL AND mhr.role_id IS NULL
GROUP BY u.school_id;
```

Non-zero → a **backfill decision** (the single largest S7 risk), resolved before the soak.

### S7 column-drop readiness matrix (§4) — every runtime reference classified

The migration cannot begin until every row is `already removed` or has a landed
disposition **and** `runtime-zero-lint` reports 0.

| File / ref                                                                                | Purpose                        | Owner slice       | Disposition                                                                                    |
| ----------------------------------------------------------------------------------------- | ------------------------------ | ----------------- | ---------------------------------------------------------------------------------------------- |
| `ActiveSchool::id()` source-3 (`$user->school_id` ×2)                                     | single-School context fallback | S7 Step 3         | **delete** (redundant with session/token; §5 gates)                                            |
| `User.php` `computeAccessibleSchoolIds` legacy branch (`$this->school_id`, `->schools()`) | legacy access union            | column-drop slice | **delete** (single-source is the path)                                                         |
| `User.php` `belongsToMany(School)` + `schools()` (sync/detach/pluck)                      | pivot relation + grant writes  | column-drop slice | **delete relation**; grant writes move to role-only (`grantSchoolAccess` already writes roles) |
| `SchoolAccess` `from('school_user')` branch                                               | flag-off reader path           | column-drop slice | **delete** the else branch (flag-on model_has_roles path remains)                              |
| `AdminController:104` read + `:105` forceFill write                                       | home-School maintenance        | writer slice      | **delete** (nothing to maintain once the column is gone)                                       |
| `TeacherService` `User::create([...'school_id'])` ×2                                      | legacy home-School stamp       | writer slice      | **delete the `'school_id'` key** (role grant already present)                                  |
| `TeacherSchoolAccessController` `->schools()->get()` / `grantSchoolAccess`                | extra-School grants            | column-drop slice | repoint reads to roles; grant writes already role-based                                        |
| commented `users.school_id` ownership checks (StudentSubject/StudentCurriculum)           | dead ownership guards          | column-drop slice | **delete** (redundant with SchoolScope)                                                        |
| `auth.ts` `school_id: string`                                                             | frontend user type             | column-drop slice | **delete** + audit consumers                                                                   |
| the 4 nested parent-child integrity checks                                                | route integrity                | —                 | **retain** (not redundant with SchoolScope)                                                    |

### S7 ADR regression gates (§5) — assert when source-3 is removed

When Step 3 lands, these become explicit regression tests (not assumptions):

- HTTP context resolves **only** from session/token School context (no
  users.school_id) — a request with neither yields no context, not a stale home
  School.
- Queue workers still honour `ActiveSchool::runFor()` (extend
  `ActivitySchoolResolverTest` / `NoTeamLeakBetweenJobs`).
- Console commands needing School context **fail closed** unless explicitly
  iterating Schools (extend `SchoolScopeFailsClosed`).
- Activity logging attributes to the effective School, not the authenticated user
  alone (`ActivitySchoolResolverTest` already covers runFor; add a
  session-vs-user divergence case).

### Role-gate inventory (§7.2 debt — running code, confirmed 2026-07)

The result/enrollment maker–checker workflow authorizes by **role**, in
violation of Constitution §7.2 (authorize by permission, never role). This is the
authoritative inventory; the permission model that replaces it is designed in
**ADR 0044** (implementation is a later slice).

- **Live `hasRole()` in FormRequests (enforcing now):**
  [RejectSubjectResultRequest](../app/Http/Requests/RejectSubjectResultRequest.php),
  [PromoteStudentRequest](../app/Http/Requests/PromoteStudentRequest.php),
  [UpdateStudentCurriculumStatusRequest](../app/Http/Requests/UpdateStudentCurriculumStatusRequest.php),
  [RegisterStudentCurriculumRequest](../app/Http/Requests/RegisterStudentCurriculumRequest.php)
  — all `admin || head_of_school`.
- **Live role-gate:** `PrincipalController@…` `abort_unless($principal->hasRole('principal'), 404)`.
- **Commented role checks (dormant, in the authz-lint baseline):**
  `CurriculumSubjectController` submit (`isTeacher`), approve / reject
  (`isReviewer`), `StudentCurriculumController@getScoresWithMarkingComponents`.
- **NOT authorization — presentation/relationship branching (leave; confirmed
  none is a security decision):** `DashboardController@…` guardian/teacher landing
  branch, `CurriculumController` / `StudentController` "guardian viewing their own
  child" checks, `GuardianService` guardian-relationship branch. These choose what
  to _show_, not whether to _permit_; the actual gate is the route `role:`
  middleware + FormRequest authorize on those endpoints.
- **Already correct (the convention to match):**
  `student_curriculum.unenroll` authorizes by permission
  ([UnenrollStudentRequest](../app/Http/Requests/StudentSubject/UnenrollStudentRequest.php)).

Re-audit is deferred to **after the implementation slice** (a design does not
change what is in the code).

## Governance — current state (not intent)

- CI: `linter` + `tests` workflows run on PRs to `staging`/`main`.
- **Branch protection / required status checks are not confirmed as enabled**
  on GitHub; merges are performed by the maintainer after review. Enabling
  protection is an outstanding GitHub-settings action (v10 §17.3), not a repo
  change.
- `plan_docs/` is untracked by design; this page and the ADRs are the
  in-repo record.
