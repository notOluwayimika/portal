# Segregation of duties — what is guaranteed vs what is observed

Two different questions hide under "separation of duties," and conflating them is how an overstated
guarantee gets relied on. State them apart.

## Act level — ABSOLUTE, enforced by the database

**No user can approve a request they submitted.** Enforced by `CHECK (submitted_by <> decided_by)`
on every approval table (`finance_credit_notes`, `finance_void_requests`, `subject_result_statuses`,
and — by the schema invariant below — every future one). True **regardless** of permissions, roles,
admin status, or how the row is written, including raw SQL. A user holding *both* sides of a pair
still cannot approve their *own* submission, ever, by any path.

This is the guarantee the whole approval model rests on. It is not a convention; it is a constraint.

- **Schema invariant (Decision 4):** `SchemaConventionsTest` asserts from `information_schema` that
  **every table carrying both `submitted_by` and `decided_by` has a maker≠checker CHECK**. Refunds
  (instance #3) introduces a new approval document; if its migration shipped without the constraint,
  every layer above would still look correct (Policy refuses, Action refuses, tests pass) while the
  guarantee was silently absent. The test is bite-proven by a planted constraint-less table **in a
  migration** — a hand-`ALTER` would heal under `RefreshDatabase`, a migration does not.

## Capability level — OBSERVED, not prevented

**A user holding both sides of a pair is a violation by policy — but it is *detected*, not refused.**
The DB CHECK stops self-approval; it does **not** stop one person from approving a *colleague's* work
in both directions. That is a configuration hole: a setup that reads as segregated (two roles) but
is one human.

- **The rule (convention-derived, per school, effective ability):** for every checker ability C with
  maker M = `ApprovalAbility::matchingMakerFor(C)`, a user holding **both** C and M **within the same
  school** is a violation. Derived from the convention over the Permission catalog — refunds joins
  with no edit. Scoped per school (spatie teams): maker@A + checker@B share no record. Evaluated on
  **effective** ability (`can()`), so `super_admin` — excluded from the `Gate::before` bypass on
  checker abilities — can never be a violator (it holds makers via the bypass but never a checker).
  Codified once in `App\Support\DutySeparation`.
- **Detected at two surfaces, refusing nothing:**
  - `MakerCheckerSeparationTest` (runs on every `bin/quality`) — a **tripwire**: no user in the
    seeded fixtures holds both. A seeder or fixture edit that introduces one turns it red.
  - `php artisan finance:audit-duty-separation` — read-only, per school, lists both-sides users;
    exits non-zero on findings. For a pre-pilot checklist.
- **It is NOT refused at grant time.** Enforcement — refusing a grant that would create a both-sides
  user — is a **deliberate follow-up**, gated on the audit above and on the project lead accepting
  that a symmetric "finance officer" role becomes unusable and a one-bursar school needs an external
  checker. As of this writing the dev database contains a real both-sides finance user (an account
  holding `admin` + `accounts_officer` + `finance_director`), reported by the audit, not remediated
  here.

## Staffing readiness — a different check again

The CHECK guarantees no one person does both halves of an *act*; it does **not** guarantee a school
has two people who between them can do either. A school that grants everything to one person, or only
one side, is configured into a state where nothing can ever be approved.

`php artisan finance:check-staffing-readiness` — read-only, per school, per pair: is there a maker and
a **distinct** checker? Evaluated on **raw grant** (not the bypass), so `super_admin` is not miscounted
as an operator. **Residual it cannot see (not solved):** two "distinct" users who are the same human
with two accounts read as staffed.

## The one-line summary

Self-approval is **impossible** (database). Holding both capabilities is **visible** (tests + audit)
but currently **allowed** (enforcement is a follow-up). Having enough staff is **checkable** but not
guaranteed. Do not let a doc, a comment, or a UI imply the middle one is prevented — it is not yet.
