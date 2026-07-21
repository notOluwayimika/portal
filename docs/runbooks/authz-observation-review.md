# Authz observation review — the §24 condition-3 workflow

How observe-mode evidence becomes the reviewed classification that (with the
other three conditions) permits `AUTHZ_ENFORCE=true`. Roadmap §"the §24
authorization checkpoint": authz-lint = 0 ✅ (A1) · **evidence reviewed (this
runbook)** · `AUTHZ_ENFORCE=true` in prod · enforcement verified live with a
real 403. A green lint alone is a false signal — the checks are restored in
observe mode, which records and never blocks.

## The unit of review

A **denial class** = one `(ability, controller_action)` pair. Rows are
individual would-be denials; classes are what get classified. The class list
comes from real traffic only — there is nothing to review until the deploy is
live and observe-mode traffic has flowed (scheduler execution was verified in
prod 2026-07-19, so `authz:prune` retention already holds).

## Cadence

1. **Wait for a representative window.** Minimum one full school working week
   after the Phase-1 deploy; thin coverage proves nothing (the S7 soak's
   coverage rule applies here in spirit: a zero over traffic that never
   exercised the path is not evidence).
2. **Summarize:**
   `php artisan authz:observations --summarize` (add `--since=` to scope a
   window; `--json` for machine reading). Each row shows the class, its
   volume, distinct users/schools, the role mix, and its current
   classification status.
3. **Classify every class** in
   [authz-observation-classifications.json](authz-observation-classifications.json)
   — one entry per class, via a **reviewed PR** (the git trail is the review
   evidence; the observations table is pruned at 30 days and dropped at
   teardown, this file is not):

   ```json
   {
       "ability": "guardian.update_credentials",
       "controller_action": "GuardianController@resetPassword",
       "classification": "expected",
       "reviewed_by": "<github handle>",
       "reviewed_at": "2026-07-28",
       "note": "registrar lacks credential permission by design (ADR 0044 grants table)"
   }
   ```

   - `expected` — the denial is correct: the caller genuinely lacks the
     ability and should be blocked once enforcement is on.
   - `regression` — legitimate access that enforcement would break. **Each
     regression gets its own fix slice** (grant the permission in
     `RbacSeeder`'s map, or correct the check) and is re-classified
     `expected` only after the fix lands and the class stops appearing.
4. **Gate:** `php artisan authz:observations --unclassified` — exits **1**
   while any observed class lacks a classification, **0** when review is
   complete. The enforcement slice (A4/A5 in
   [docs/rbac-implementation-plan.md](../rbac-implementation-plan.md)) must
   record this at 0, on production evidence, in its PR.

## What this does NOT cover

- **Classes that never appear.** An ability nobody hit in the window is
  invisible here — that is exactly why §24 condition 4 (a live 403 probe
  after the flip) exists. Absence of observations is not evidence of safety.
- **Business-rule/state checks.** `Authz::ensure` ownership rows (check_type
  ≠ permission) are observed too and classified the same way, but ADR 0043 §4
  is explicit that state validation is not authorization — if a class turns
  out to be state validation, its fix is to move it out of `Authz`, not to
  classify around it.
- **Teardown.** After §24 closes and one stable release cycle passes, the
  commands and table go (ADR 0043 §5). This file and its history stay.
