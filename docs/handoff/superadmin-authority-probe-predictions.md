# Probe predictions — written BEFORE the matrix ran (2026-07-21)

Committed ahead of the observing test on this branch so the git history proves
prediction preceded observation (brief: "a test authored to match
already-observed behaviour records a fact but proves no understanding").

## Vendor reads these predictions rest on (installed versions, quoted)

1. **spatie/laravel-permission 7.4.1, `PermissionMiddleware:38`** —
   `if (! $user->canAny($permissions))` → routes **through the Gate** →
   `Gate::before` applies to `permission:` middleware. (Some other released
   versions call `hasAnyPermission()` directly — not this one.)
2. **`PermissionRegistrar::registerPermissions` (:116)** — Spatie registers its
   **own** `Gate::before`: `return $user->checkPermissionTo($ability) ?: null;`
   — true-or-**null**, never false. Active (`register_permission_check_method`
   = true). Package providers boot before app providers, so **Spatie's before
   runs first**, then the app's.
3. **App `AppServiceProvider::registerSuperAdminGate`** — returns `true` iff
   `auth.gate_before_superadmin && isSuperAdmin()`, else **null** — never
   false.

Composition consequence predicted: the two befores compose safely **only
because both are null-on-miss**. First non-null wins; either one returning
`false` on miss would silently defeat the other. Laravel 13.11.2 short-circuit
semantics assumed stable (non-null before skips policies entirely).

## Predicted matrix

`SA` = super_admin (web), seeded exactly its 15 legacy grants, none of ADR
0044's seven. `A7` = `result.approve` (unseeded for SA). `A15` =
`activity_log.view` (seeded for SA). `AX` = a nonexistent ability string.
Control = teacher (holds `result.submit`, not `result.approve`).

| # | Path | Flag ON (default) | Flag OFF |
|---|---|---|---|
| 1 | `$user->can()` — SA on A7 | **ALLOWED** (Spatie-before null → app-before true) — Mode A real at the Gate | **DENIED** (both befores null → no definition → false) |
| 1b | `can()` — SA on A15 | ALLOWED (Spatie-before true, app never needed) | **ALLOWED** (Spatie-before true — the fallback layer works) |
| 1c | `can()` — SA on AX | ALLOWED (app-before) | DENIED |
| 2 | `permission:` middleware — SA on A7-gated probe route | **ALLOWED** (canAny → Gate → app-before) — **Mode B refuted for v7.4.1**; C2's swap does not lock SA out while the flag is on | **DENIED** (403) |
| 3 | Policy (`ExportPolicy::download`, SA not owner, lacks `activity_log.export`… but A15-adjacent: use a not-owned Export) | **ALLOWED** — app-before short-circuits the policy; ownership rule never runs | **DENIED**… *unless* Spatie-before returns true first on the `download` ability name — predicted **no** (`download` is not a seeded permission name → checkPermissionTo throws/false → null) → policy runs → denies non-owner |
| 4 | FormRequest `authorize()` (`hasRole('admin'\|\|'head_of_school')` — the 4 live ADR-0044 requests) | **DENIED** — `hasRole` is a direct row check in the current team; the Gate is never consulted; the bypass is inapplicable. **Predicted pre-existing SA lockout on promote/register/update-status/reject today**, unrelated to C2 | **DENIED** (same) |
| C | Control: teacher `can(result.approve)` | **DENIED** (Spatie-before null — no row; app-before null — not SA) — instrument can say "denied" | DENIED |
| C+ | Control: teacher `can(result.submit)` | ALLOWED (Spatie-before true via seeded row) | ALLOWED |

**Predicted verdict: MIXED BY PATH** — the brief's "most likely and most
important result". Mode A is real on every Gate-routed path while the flag is
on (ADR 0040's guarantee does not rest on the seeded absence — the brief's
ADR-shaped concern stands). Mode B is refuted for the middleware path in the
installed version. Row 4 is a live, pre-existing lockout finding.

## Environment fact that the tree cannot prove

Production's actual `AUTH_GATE_BEFORE_SUPERADMIN` state — **open item for the
deploy owner**; recorded in the PR, not inferred from the config default.
