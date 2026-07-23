# 0045-A findings — super_admin de-bypass investigation (2026-07-23)

Investigation only; no behaviour changed. Three findings, one of which reshapes
the track.

## Finding 1 — IMPERSONATION DOES NOT EXIST. The audit's outcome is neither
"correct" nor "defect list" but a third case the brief did not anticipate.

Targeted grep of the tree (`impersonat*`, `setUser`, `loginAs`, routes,
controllers, config): **there is no user-facing impersonation feature.** Every
reference is the *removed* job-context hack — `auth()->setUser($causer)` in 6 of
7 jobs, eradicated by slice 1.3b (`SchoolAware` retrofit) and banned by §5.6 /
CLAUDE.md. The roadmap's "impersonation masking scope" production defect was
THAT, not a session feature.

**Consequences:**
- The four correctness claims (context, attribution, boundedness, clean exit)
  cannot be re-derived — they become the **acceptance criteria for a feature
  0045-B must BUILD**, bite-proven at birth rather than audited later.
- The only impersonation this codebase ever had is the anti-pattern it
  deliberately eradicated. The new feature must not resurrect
  `auth()->setUser` semantics: the design keeps the OPERATOR as the
  authenticated principal for audit while the IMPERSONATED user's identity
  drives authorization + `ActiveSchool`/team context — a session wrapper, not
  an identity swap.
- 0045-B's scope grows: permission seeding + attribution + invariant rewrite
  **+ the impersonation session mechanism itself**. Track sequencing is
  unchanged (all still inert/additive while the bypass is on), but B is a
  larger slice than the ADR assumed, or splits (B1 impersonation, B2 seeding).

## Finding 2 — dev's super_admin row has DRIFTED, and the seeder cannot heal it.

Re-derived: dev DB's super_admin(web) holds **7 of the seeded 15** (the
activity_log.* set only; the 8 student_subject/curriculum grants are absent).
The test-DB truth remains 15 (probe precondition test, floor-green). The
operational insight: the seeder's non-destructive contract grants only when the
permission OR role is newly created — **a drifted super_admin row is never
re-healed by plain `rbac:sync`**, by design. For 0045-B this means the explicit
platform-admin set must be seeded with an idempotent, self-healing mechanism
(or a one-time `--fresh`-class migration step), not the non-destructive path —
otherwise prod drift silently survives the de-bypass and strands super_admin.

## Finding 3 — enumeration: platform-admin vs domain (feeds 0045-B's seeder)

**Platform-admin (stays, becomes the explicit set):**
- School lifecycle: `/super-admin/schools` CRUD + fallback-signature (2 routes).
- Cross-School user management: `/super-admin/admins` (create, school sync).
- RBAC administration: `/super-admin/rbac` matrix + per-role 2FA toggle; the C5
  school-user module (today reached via bypass over `rbac.manage_users`).
- Platform audit reads: `activity_log.view_system`, `activity_log.view_cross_school`
  (genuinely platform-scoped).
- Impersonation itself (the control to enter a session) — once built.

**Domain (goes; impersonation-only after 0045-C):**
- The other explicit grants: `student_subject.*` (5), `student_curriculum.unenroll`,
  `curriculum_subject.archive`, `curriculum_subject.restore`, and the
  school-scoped activity_log reads (`view`, `view_all`, `view_own`, `export`,
  `view_sensitive`) — school-scoped work belonging to a School principal.
- Everything the bypass currently grants ambiently (~all non-checker abilities).

**Break-glass candidates (need the human ruling):**
- The commented `/cleanup` route in `routes/web.php` (bulk score/result repair)
  is the one in-tree precedent of a platform operation that maps to no single
  user's authority. Nothing live today. Candidate ruling: **no break-glass
  path** — incident repair runs as reviewed artisan commands under
  `ActiveSchool::runFor()` (the established off-request pattern), named and
  audited per incident, not a standing permission.

## Acceptance status

- Break-glass: candidates surfaced; **ruling parked for the architect** (one
  item: standing break-glass permission vs per-incident reviewed commands —
  recommendation: the latter).
- Impersonation claims: **converted to build-time acceptance criteria**
  (Finding 1) — bite-proofs land with the feature in 0045-B, red-first.
- Enumeration: complete, targeted-grep-verified, cross-checked against the
  seeder's explicit 15 and the `/super-admin` surface.
- No behaviour changed; no baseline moves; `SuperAdminAuthorityTest` untouched.
