# Finance seat realignment — 2026-08-01

Realigns the finance roles to the five seats Brookstone's business actually has, moves the grants to
match the answered authority matrix, and removes an approval authority `principal` was never sanctioned
to hold. One commit: `RbacSeeder` (roles + grants), a rename migration, the readiness label fix, a test,
this doc, and the three regenerated RBAC oracles.

**Precondition (production `finance:check-staffing-readiness`, verified before this commit):** 0 makers /
0 checkers for `finance.credit-note` and `finance.invoice.void-request` in both schools; no user held
`accounts_officer`, `finance_director` or `finance_void_approver` anywhere. The rename is therefore free —
a seeder + role-row change, not a data migration.

## The five seats → roles

| Seat | Role | Change |
|---|---|---|
| AO — Account Officer | `accounts_officer` | kept; **+** fee-schedule.change.submit (row 2), discount-policy.change.submit (row 20, derived) |
| AS — Accounts Supervisor | `accounts_supervisor` | **renamed from `finance_director`** (it is the supervisor/checker, not the lead); **+** fee-schedule.change.submit (row 2) |
| FL — Finance Lead | `finance_lead` | **new** — proposer: credit-note.submit (row 16), discount-policy.change.submit (row 20, derived) |
| IA — Internal Auditor | `internal_auditor` | **new** — activity-log only (see the deferral below) |
| HoS — Head of School | `head_of_school` | **inverted** — loses the submit sides, gains the approve sides |
| — | `finance_void_approver` | **deleted** — no such business seat; 0 holders in production |
| — | `principal` | **loses** the two finance approvals it should never have had |

## Every grant moved, with its matrix row

- **AO `accounts_officer`** — kept finance.access, invoice.generate, invoice.reduction.apply,
  fee-schedule.manage, credit-note.submit, void-request.submit. **Added** `finance.fee-schedule.change.submit`
  (row 2, AO=P) and `finance.discount-policy.change.submit` (row 20, derived).
- **AS `accounts_supervisor`** — kept credit-note approve/reject + void-request approve/reject (rows 15/16,
  AS=A) + finance.access. **Added** `finance.fee-schedule.change.submit` (row 2, AS=P). (A maker side on a
  different pair from its checker sides — no both-sides violation.)
- **FL `finance_lead`** (new) — `finance.access`, `finance.credit-note.submit` (row 16, FL=P),
  `finance.discount-policy.change.submit` (row 20, derived).
- **HoS `head_of_school`** — **lost** `finance.discount-policy.change.submit` and
  `finance.fee-schedule.change.submit`; **gained** `finance.fee-schedule.change.approve/reject` (row 2,
  HoS=A) and `finance.discount-policy.change.approve/reject` (row 20, derived). HoS must never hold both
  sides — `DutySeparation::assertRoleSetAllowed()` refuses the half-done state (proven in
  `FinanceRoleRealignmentTest`).
- **`principal`** — **lost** `finance.discount-policy.change.approve/reject` and
  `finance.fee-schedule.change.approve/reject`. **`principal` appears nowhere in Brookstone's finance
  matrix and had been holding a finance approval authority the business never sanctioned (removed
  2026-08-01).** It keeps only `finance.access` (route gate); nothing else about `principal` changed.
- **IA `internal_auditor`** (new) — `activity_log.view`, `activity_log.export`,
  `activity_log.view_cross_school` (rows 8/9, IA=D, cross-school). See the deferral below.

## Row 20 is DERIVED, not answered — confirm with the business

Row 20 (create / amend / retire a discount policy) was never put to Brookstone. The grants above assign
AO/FL as proposers and HoS as approver **by analogy with row 2** (fee-schedule change), which the business
did answer. **Flag for confirmation** — if the business wants a different seat owning discount-policy
governance, the discount-policy.change grants move accordingly. Presented here as derived, not decided.

## Internal Auditor ships activity-log-only — a deliberate deferral

The brief first gave IA `finance.access` "to look, not act." **That is wrong: `finance.access` is not a
read-only gate.** `routes/endpoints/finance.php:24` and `:143` (POST `…/payments`) carry `finance.access`
and **no further permission**; `PaymentController` calls no `authorize()`, and both payment FormRequests
`authorize()` return `true`. So `finance.access` **alone posts a payment**. Granting it to the control role
would let the auditor *create financial transactions* — the exact inversion the matrix forbids (IA=V, not
D, on the finance screens).

So **IA holds no `finance.access`.** It is activity-log-only. Its finance-screen READ access (matrix rows
3–6, IA=V) is **deferred** until `finance.access` is split into read vs act — a separate change. Recorded
here as a named, deliberate deferral, not an oversight. Verified: `internal_auditor` reaches only 38
auth-only/public routes and **zero** `/finance/*` routes.

## Known pre-existing authority leak — NOT fixed here

Exactly two mutating routes gate on `finance.access` alone: `POST /v1/finance/invoices/{invoice}/payments`
and `POST /v1/finance/students/{student}/payments`. So **`finance_lead` and `accounts_supervisor` can post
payments via `finance.access` alone**, though the matrix gives AS only **V** on row 4 (record a payment)
and FL is not a payment seat. This leak **pre-dates this commit** — `finance_director` (now
`accounts_supervisor`) already reached those routes — and is **not fixed here**. It is the concrete case
for a dedicated `finance.payment.record` permission that splits payment authority off `finance.access`.

## Before / after staffing readiness — STAGING (`brookstone_portal_db`), not production

Both tables are from **staging** (`brookstone_portal_db`), labelled so. The BEFORE table is staging **after
`rbac:sync`** — i.e. the seeded grants of the *pre-realignment* code, not an untouched snapshot. **These are
staffing numbers, not a defect** — a GAP means the school is not staffed into the seat the business named,
which is an operational action (assign a user), not a bug in this commit. **Production numbers remain a TODO
for someone with prod access.**

**BEFORE** (pre-realignment seeded grants, staging holders):

| Pair | makers | checkers | flow |
|---|---|---|---|
| finance.credit-note.approve / .reject | 0 | 1 | GAP |
| finance.invoice.void-request.approve / .reject | 0 | 1 | GAP |
| finance.discount-policy.change.approve / .reject | 4 | 0 | GAP |
| finance.fee-schedule.change.approve / .reject | 4 | 0 | GAP |

**AFTER** (realigned):

| Pair | makers | checkers | flow |
|---|---|---|---|
| finance.credit-note.approve / .reject | 0 | 1 | GAP |
| finance.invoice.void-request.approve / .reject | 0 | 1 | GAP |
| finance.discount-policy.change.approve / .reject | 0 | 4 | GAP |
| finance.fee-schedule.change.approve / .reject | 1 | 4 | **OK** |

**These did NOT move OK→GAP as the brief predicted for production** — on staging `principal` has **0
holders** (so the discount/fee-schedule pairs were already GAP, with the empty side on the checker), and
`adandukwe` holds `accounts_supervisor`, which gained the fee-schedule submit maker side (→ fee-schedule
goes to OK). The brief's OK→GAP prediction assumed `principal` had holders — which it does **not** on this
database. This is the earlier conclusion confirmed: **the readiness table the project lead was given
(checkers on the discount/fee-schedule pairs in *both* schools) did not come from this database** — this one
has a single school, 0 `principal` holders, and those permissions were absent until `rbac:sync`.

## D1 oracle coverage — a recorded loss

`finance_void_approver` existed **only** to make the access oracle exercise the D1 single-side-checker case
(a role reaching `/finance/approvals` with just one checker ability). Deleting it removes that oracle row.
The D1 *behaviour* is not lost — it is tested directly by `DiscountPolicyTest` / `FeeScheduleChangeTest`
convention(c), which build a one-sided-checker user from an explicit permission list, not from this role.
The nearest remaining single-side-checker role is `head_of_school` (checker-only, no maker abilities).
Recorded as a known, deliberate coverage reduction.

## Deploy note — the grant moves need `rbac:sync --fresh`

The rename migration carries the role rows, but the **grant moves on existing roles** (principal loses
approve, HoS gains approve, AO/AS gain submit) are `RbacSeeder`-map changes. A **non-destructive**
`rbac:sync` applies grants only where a role/permission was newly created this run, so it does **not** move
existing-role grants — only `finance_lead`/`internal_auditor` (new) would pick theirs up. Applying the
realignment to a live database requires **`rbac:sync --fresh`** (resets grants to the map; preserves
`model_has_roles` user assignments). Noted so production deploy uses the right form.

## What this commit does NOT do

Rows 1, 3, 4, 5, 7, 8 (finance-data export), 10, 11, 12, 13, 14, 17, 18, 19 have no permission behind them
and are untouched. No new permission is invented (IA cannot export *finance* data — only activity-log —
because only `activity_log.export` exists; that is a missing permission, not a missing grant). No user is
assigned to any role. `finance.access` is not split into read/act. The per-school configurable authority
matrix (C6) is a separate design pass.
