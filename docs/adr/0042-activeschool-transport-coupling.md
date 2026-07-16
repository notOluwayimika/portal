# 0042 — `ActiveSchool::id()` is not yet transport-agnostic (known debt + expiry)

**Status:** Accepted as recorded debt with a bound expiry. This ADR exists
because the facts below were independently rediscovered twice; do not
rediscover them a third time.

## Context — current facts

`App\Support\ActiveSchool::id()` resolves, in order:

1. the `runFor()` static override — transport-neutral (the sanctioned
   off-request context; jobs via `SchoolAware`);
2. `request()->session()->get('school_id')` — request/session-coupled, guarded
   by `hasSession()`;
3. the Sanctum token's `school_id` — token-transport-coupled;
4. **`users.school_id`** for non-super-admins — the guarded form of the
   Constitution-13 fallback (baselined by boundary-lint,
   `school-id-fallback-context`).

`App\Support\ActivitySchoolResolver` does **not** consult `ActiveSchool` at
all: it reads the global `session('school_id')`, then
`auth()->user()->school_id`, then causer/subject relations. Consequence: under
a `SchoolAware` job (override set, no session), activity rows resolve their
School from the **causer/subject**, not the job's declared School. An attempt
to point the resolver at `ActiveSchool::id()` was reverted (1.3c) because
`id()`'s session source requires `$request->hasSession()` and broke
session-less paths.

## Decision

- Both facts are **temporary, baselined debt**, not accepted architecture.
- **Expiry:** the `users.school_id` drop (§5.3/§7.1) — after the
  `rbac.single_source_access` parity gate — removes source (4) and the
  resolver's user fallback; the same slice makes `id()` transport-agnostic
  (override → session-if-present → token) and points
  `ActivitySchoolResolver` at it.
- Until then: fail-closed enablement for job-touched models waits on 1.3b
  (legacy jobs → `SchoolAware`), and audit-row School attribution from jobs is
  known-approximate (causer/subject-derived).

## Consequences

- The boundary-lint baseline entries for `ActiveSchool` and
  `ActivitySchoolResolver` are the enforcement-visible form of this ADR; they
  are deleted in the expiry slice, which must also delete this ADR's debt
  section or supersede the ADR.
