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
- **Detected at two surfaces:**
  - `MakerCheckerSeparationTest` (runs on every `bin/quality`) — a **tripwire**: no user in the
    seeded fixtures holds both. A seeder or fixture edit that introduces one turns it red.
  - `php artisan finance:audit-duty-separation` — read-only, per school, lists both-sides users;
    exits non-zero on findings. For a pre-pilot checklist.

## Grant level — REFUSED at grant time, for FINANCE pairs only

**A grant that would leave one user holding both sides of a *Finance* pair within a school is now
refused before it is written.** This is the promotion of the capability-level rule above from
*detected* to *prevented* — but **scoped to Finance pairs only** (`DutySeparation::enforcedPairs()`,
one commented boundary line). The result pairs (`result.approve ↔ result.submit`) stay
**detection-only** until the result workstream signs off on enforcement; a grant creating a result
both-sides user is still accepted (and still shows up in the audit).

- **One rule, refused at every mutation path** (Decision 1 — the definition lives once in
  `DutySeparation`, each path calls it):
  - **`User::assignRole`** — the chokepoint every role write crosses (HTTP role-sync, `grantSchoolAccess`,
    seeders, console). Throws `DutySeparationViolationException` *before* the spatie write, so a
    violating grant lands **nothing** (wholesale — a multi-role assign with one bad role applies none
    of them). Team-less assignments (`super_admin`) are not enforced: Finance is per-school.
  - **`SyncRolePermissionsRequest`** (the RBAC matrix) — two checks. The pre-existing one stops a
    single **role** holding both sides; the new one stops a permission edit from making a **member**
    of that role hold the opposite side via *another* role they already hold — the cross-role hole the
    single-role check cannot see. Surfaced as a validation error, so the whole sync is refused.
- **The refusal is actionable** (Decision 2): the exception message names the user, the school, the
  pair (both abilities), and the roles carrying each side — so whoever hit it knows *which of the two
  grants to give someone else*, not merely that they "violated duty separation".
- **No bypass** (Decision 3): the guard sits in the model override, below the HTTP layer, so there is
  no `--force`, no request flag, no super-admin exception that skips it (super_admin is exempt only
  because the platform role is team-less and holds no Finance grant, not because it is waved through).
  Developing *both* sides for a manual walkthrough therefore needs **two accounts** — see
  [drive-environment.md](drive-environment.md) § "Two accounts for the maker-checker walkthrough".
- **What it does NOT do:** it does not retroactively revoke an existing both-sides user (those predate
  the guard and are the audit's job to surface and an operator's to remediate), and it does not touch
  result pairs. Enforcement caps the *inflow*; detection still covers the *residual*.

### The residual detection still covers (Rider D — two lenses, and why both stay)

Enforcement blocks the **spatie API** (`assignRole`/`syncRoles` → the override). It cannot block a
write that never calls it. The write-path map, both tables:

- **`model_has_roles` (user ↔ role):** one chokepoint. `assignRole`, `syncRoles` (ends in
  `$this->assignRole(...)`), `grantSchoolAccess` (calls `assignRole`), and every controller / service /
  seeder that assigns a role all funnel through the `User::assignRole` override — so all are covered for
  the enforced (Finance) scope. The only way past it is a write that does not call it: a **raw**
  `model_has_roles` insert, a migration backfill, a `tinker` edit.
- **`role_has_permissions` (role ↔ permission):** only the HTTP matrix (`SyncRolePermissionsRequest`)
  is guarded (role-level *and* the member-level check). `RbacSeeder`'s `syncPermissions` /
  `givePermissionTo` and any future **migration** editing a role's abilities do **not** pass through
  that request, so a seeded/migrated map that grants a role a checker while its members hold the maker
  is refused by **neither** guard — it is caught only by the `MakerCheckerSeparationTest` tripwire (for
  the seeder) and the audit (for anything else).

Everything the enforcement cannot reach, detection does — that is the net's whole job. The two
detection surfaces live on **different lenses of "holds an ability", and that difference is
deliberate**:

| Lens | Method | Honours `Gate::before` bypass? | Used by | Why that lens |
| --- | --- | --- | --- | --- |
| **Effective** | `DutySeparation::holds` / `$user->can()` | yes | the both-sides **audit** + tripwire | so `super_admin` — excluded from the bypass on *checker* abilities — can never be misreported as a violator (it holds makers via the bypass, never a checker) |
| **Raw grant** | `DutySeparation::holdsViaGrant` / `hasPermissionTo()` | no | **staffing** readiness | so `super_admin`, which holds every maker effectively via the bypass but is a platform admin not school staff, is never miscounted as a real operator |

A raw-grant reading would libel the platform admin as a both-sides violator forever; an effective
reading would flatter a one-super-admin school as adequately staffed. Each check picks the lens that
makes *it* honest. The grant-time **enforcement** uses neither — it reads the roles' *declared*
abilities (`role_has_permissions`), because it is asked about a hypothetical resulting grant set, not
about what a live Gate would answer.

As of this writing the dev database contains a real both-sides finance user (an account holding
`admin` + `accounts_officer` + `accounts_supervisor`) that **predates** this guard — reported by the
audit, remediated by revoking one side (an operational grant fix, not a code change).

## Staffing readiness — a different check again

The CHECK guarantees no one person does both halves of an *act*; it does **not** guarantee a school
has two people who between them can do either. A school that grants everything to one person, or only
one side, is configured into a state where nothing can ever be approved.

`php artisan finance:check-staffing-readiness` — read-only, per school, per pair: is there a maker and
a **distinct** checker? Evaluated on **raw grant** (not the bypass), so `super_admin` is not miscounted
as an operator. **Residual it cannot see (not solved):** two "distinct" users who are the same human
with two accounts read as staffed.

## Before pilot — what a green `bin/quality` does and does NOT say

A green `bin/quality` runs `MakerCheckerSeparationTest` against the **seeded fixtures** — it proves the
*seeder/fixture* shape is clean and that the *guards fire*. It says **nothing about the grant state of a
deployed database**, which is edited at runtime by the RBAC matrix, by `grantSchoolAccess`, and (before
this guard, or through any raw write) by whatever put the current rows there. So the pre-pilot check is
two commands run **against the target database**, not the test one:

```bash
php artisan finance:audit-duty-separation      # both-sides users (exits non-zero on findings)
php artisan finance:check-staffing-readiness    # every enforced pair has a maker and a distinct checker
```

Enforcement stops *new* both-sides grants through the API from today; it does not heal a database that
already contains one, and it never sees a raw insert. Run the audit before pilot regardless of how green
the suite is.

## The one-line summary

Self-approval is **impossible** (database). Newly *granting* one user both sides of a **Finance** pair
is **refused** (grant-time enforcement, every mutation path) — but a **result** both-sides grant, and
any user planted through a **raw** write, is still only **visible** (tests + audit), not refused.
Having enough staff is **checkable** but not guaranteed. So: prevented for Finance-via-the-API,
detected for the rest. Do not let a doc, comment, or UI overstate the boundary — enforcement caps the
inflow through the spatie API; detection is the net under everything it cannot reach.
