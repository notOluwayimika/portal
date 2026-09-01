# Three authorisation holes closed

**Branch:** `fix/three-authorisation-holes` (off `staging` @ `a54b46c7`, tree clean)
**Date:** 2026-09-01

All three holes had the same shape: the route middleware admits a **wider** set of seats than the
ability the endpoint means, and the guard that would have narrowed it was an `App\Support\Authz`
call in **observe** mode — which records a would-be denial to `authz_observations` and lets the
request continue (`Authz::gate`, `app/Support/Authz.php:40-51`). None of the three fixes is
conditional on `authz.enforce`; each is a bare `abort_unless`/`abort_if`, and every arm runs under
**both** settings of the flag.

The census numbers quoted below (13 unauthorised submissions, 2 users, 12 curriculum subjects) come
from the brief, not from a measurement of mine — I have no access to the production observation
stream and did not re-derive them.

**Corrections after cold review (2026-09-01).** The first version of this report gave the
before-state as *"5 passed / 4 failed"* and called it "the same file". It was not: that figure came
from an earlier, pre-dataset-axis version of the test file. Re-measured with the **committed** file
against base `a54b46c7` — **18 arms, 13 passed, 5 failed** — and the numbers below are that run.
The file declares **8** `it()` blocks, not nine. The first version also said the Export button was
the only UI consequence; fix 1 has one too, it is reachable by the refused seat, and it is addressed
below and ticketed. Two further findings from that review are ticketed rather than fixed here.

---

## Fix 1 — result submission is assigned-teacher-only

`app/Http/Controllers/CurriculumSubjectController.php` — the `curriculum_subject.owned_by_teacher`
guard in `submit()` is now a hard `abort_unless(..., 403)`.

**The rule implemented, stated in the docblock at the call site:** a result may be submitted only by
a teacher **assigned to this curriculum subject** through `teacher_curriculum_subjects`. No seat is
exempt — not `admin`, not `head_of_school`, not `super_admin`.

**Why no privileged-seat exemption.** The brief asked for the seats to be read before hard-refusing,
because an over-tight guard silently stops real teachers. They were, and none of the three
candidates is meant to submit:

- `result.submit` appears **exactly once** in `RbacSeeder::grantsMap()` — under `teacher`
  (`database/seeders/RbacSeeder.php:305`). It is the only holder.
- `admin` and `head_of_school` hold the **checker** side (`$resultChecker` = `result.approve`,
  `result.reject`, `result.view_scores`) and are deliberately excluded from the maker side: *"one
  actor holding maker AND checker for the same result defeats SoD"* (`RbacSeeder.php:194-199`, ADR
  0044 recommendation (a)). Refusing them here is that separation, not a new restriction.
- `super_admin` holds no domain grant at all — `SUPER_ADMIN_PLATFORM` is `rbac.impersonate`,
  `rbac.manage_users`, `activity_log.view_system`, `activity_log.view_cross_school`
  (`RbacSeeder.php:71-76`). Its `can('result.submit')` is purely the `Gate::before` bypass, and
  `CheckStaffingReadiness` already records that the seat *"is a platform admin, not school staff"*.
  `submitted_by` is a durable maker identity that `authorizeDecision()` later compares the checker
  against, so a maker who is not the assigned teacher is false provenance in an audited record.

So the census user holding **admin+teacher** is refused by design. The remedy for someone who
genuinely teaches the subject is the assignment row, not a code exemption.

The ability check one line above (`isTeacher()` → `can('result.submit')`) is **untouched** and stays
in observe mode; it was not in scope and the ownership abort now refuses those seats regardless.

**The refusal carries a message, and that is load-bearing rather than polish.** `bootstrap/app.php`
has no `HttpException` renderable (its `renderable` list covers Validation, DutySeparation,
Authentication, `AuthorizationException`, NotFound, MethodNotAllowed, Connection, Query, Transport —
not `abort()`), so a bare `abort(403)` returns `{"message": ""}`. The one screen that reaches this
guard — `resources/js/components/subject-result-status-panel.tsx`, whose Submit button is shown to
anyone holding the `teacher` role, assigned or not — reads
`err?.response?.data?.message ?? 'Action failed.'`, and `??` does **not** substitute for an empty
string, then renders `{error && …}`. The refusal would have arrived as nothing at all. Both new
aborts now name the refusal; the 404 in fix 2 deliberately stays bare, because any message there is
a message about a row the caller must not learn exists.

## Fix 2 — `SavedActivityFilter@destroy`: same school, and yours

`app/Http/Controllers/ActivityLog/SavedActivityFilterController.php` — `destroy()` gained the
school narrowing its siblings already had, and its ownership refusal became unconditional.

| Refusal | Status | Why that status |
| --- | --- | --- |
| row belongs to another school | **404** | the house convention for a row the caller has no business seeing — `StudentSubjectController@drop`/`@restore`, `StudentCurriculumController@unenroll` and `GuardianImportController@authorizeSchool` all pass 404 for this shape. A 403 would confirm the row exists. |
| row is in this school but another user's | **403** | by that line existence is not the secret; the authority to delete it is. Same status the observe-mode guard already carried. |

A null `currentSchoolId` casts to `0` and matches no `school_id`, so the isolation check fails
closed.

`BelongsToSchool` was **not** added to the model, per the brief — ticketed as
[`docs/handoff/tickets/saved-activity-filter-has-no-school-scope.md`](../tickets/saved-activity-filter-has-no-school-scope.md),
which also records the measured blast radius (three query sites, all already narrowing by hand) and
the sequential-integer route binding.

## Fix 3 — the export route is gated on `activity_log.export`

`routes/endpoints/activity-log.php` — `GET /api/activity-logs/export` now carries
`->middleware('permission:activity_log.export')`. Route-level, so it **intersects** with the group's
`permission:activity_log.view` rather than replacing it (`RouteAccessMap::derive` stacks
`permission:` entries as an intersection, `app/Support/RouteAccessMap.php:51-69`).

`teacher` holds `activity_log.view` via `$activityStaff` and so was admitted by the group; the only
thing between it and a full CSV of the school's audit trail was the controller's observe-mode
`Authz::abilityCheck`.

**`internal_auditor` reaches it before and after — measured, not assumed.** The known-negative arm
was written and run against unmodified `staging` code first (200), then against the fixed code
(200). Same for `admin` and `head_of_school`. That seat was locked out of this feed entirely until
`2026_09_01_120000_grant_internal_auditor_activity_log_view_all` and must not be locked out again.

**And the grants were measured on the production copy, not inferred from the seeder.** Turning an
observed denial into a door refusal is only safe if the production `role_has_permissions` rows match
the map — and this repo has already paid for that drift twice, once **yesterday on this exact role
and permission family** (`2026_09_01_120000_grant_internal_auditor_activity_log_view_all` exists
because a line added to the map lands on fresh installs and does nothing on the production copy).
The arms seed from zero, where every role is new and therefore gets the whole map by construction,
so they cannot see this. Queried read-only against the copy (`portaa10_portal`).

The team column on `roles` is **`school_id`**, not spatie's default `team_id` — confirmed by
`SHOW COLUMNS FROM roles` before the query rather than assumed, because the predicate is the whole
query.

*Global roles holding `activity_log.view` and NOT `activity_log.export`* — **one row**:

| id | name | guard |
| --- | --- | --- |
| 3 | `teacher` | web |

Re-run **without** the `school_id IS NULL` predicate, to catch per-school rows: the **same single
row**, and it could not have differed — of 16 role rows, **zero** are school-scoped. (That matters
independently, because `RouteAccessMap::holders()` filters to `whereNull('school_id')` and would not
have seen one.)

The denominator, stated so "one row" is falsifiable: exactly four roles hold `activity_log.view` at
all — `admin` (1), `head_of_school` (2), `teacher` (3), `internal_auditor` (16). Three of them also
hold `activity_log.export`. `teacher` is the only seat that loses the export, which is the whole
point of the change — so the copy matches the map for this permission family and **no grant
migration is needed**.

**No UI regression on the export:** the page that carries the Export button is the web route
`GET /activity-logs`, gated on `permission:admin_area.access` — `admin` and `super_admin` only, per
`route-access-map.json`. A teacher never sees the button.

**The neighbouring route was checked and left alone.** `GET /api/activity-logs/exports/{export}`
stays on the group gate because its guard is already enforced: `ExportPolicy::download` requires
`activity_log.export` **and** artifact ownership, reached through `Gate::authorize`, which aborts
unconditionally.

### Fixtures — targeted, not regenerated

`route-access-map.json` is committed at 378 routes and regenerates at 429, so a wholesale rewrite
would newly bless 51 routes nobody has reviewed. Both fixtures were edited by hand, one entry each:

- `route-middleware-baseline.json` · `GET /api/activity-logs/export` gains
  `"permission:activity_log.export"` **after** `"permission:activity_log.view"`. The order was
  derived from the live route (`php artisan route:list --json`), not assumed.
- `route-access-map.json` · same key, `teacher` removed; `admin`, `head_of_school`,
  `internal_auditor`, `super_admin` remain.

`RouteMiddlewareBaselineTest` and `RouteAccessParityTest` both pass, which is the check that those
two hand edits match what the code actually produces.

---

## Arms — `tests/Feature/Rbac/AuthorizationWidthTest.php`

Eight `it()` blocks × the `authz enforce` dataset (`false` — the production default — and `true`),
one of them also crossed with a two-role dataset = **18 arms, all green**. The flag is a dataset axis rather than a `beforeEach` setting so that
*"independent of AUTHZ_ENFORCE"* is executable rather than a sentence nothing checks.

Each fixture is built so the mechanism under test is the **only** thing that can refuse:

| Arm | The discriminating construction |
| --- | --- |
| fix 1 refused | `admin`+`teacher` (the census seat). Holds `score.manage` so the route group admits; holds `result.submit` so the ability check passes; **has a `Teacher` row** so `$teacherId !== null` is satisfied. Only the assignment clause is unsatisfied. Asserts 403, that no `SubjectResultStatus` row was written, **and that the response message is non-empty** — asserted non-empty rather than by its prose, because the property is that a refusal reaches the screen, not the wording. |
| fix 1 known negative | the assigned teacher — 200, and `status === 'submitted'`. |
| fix 2 cross-school | `internal_auditor` in **another** school; asserts **404 specifically**, so an ownership 403 does not satisfy it. Row still present afterwards. |
| fix 2 cross-user | `internal_auditor` in the **same** school, so isolation cannot be what refuses; asserts 403, a non-empty message, and that the row is still present. |
| fix 2 known negative | the owner — 204, row gone. |
| fix 3 refused | `teacher`, asserted to hold `activity_log.view` and **not** `activity_log.export`, so a 403 can only be the new route-level gate. Also asserts a non-empty message — this refusal comes from **middleware**, so the body is vendor behaviour (`UnauthorizedException::forPermissions`, "User does not have the right permissions."), which was read and not pinned until now. |
| fix 3 known negatives | `internal_auditor` — 200; `admin` and `head_of_school` — 200. |

### Bite-proofs — each fix reverted alone, only its own arms red

Run against the final (dataset-axis) version of the file, not an earlier one.

| Reverted | Result |
| --- | --- |
| fix 1 · `abort_unless` → `Authz::ensure` | 17/18, red: *fix 1 refused*, **observe dataset only** — `Expected 403, received 200`. The enforce dataset stays green because `Authz::ensure` does abort under the flag; that split is the whole point of the fix. |
| fix 2a · isolation `abort_if` deleted | 16/18, red: *cross-school*, both datasets — `Expected 404, received 403`. The ownership guard catches it instead, and the arm distinguishes the two. |
| fix 2b · ownership `abort_unless` → `Authz::ensure` | 17/18, red: *cross-user*, observe only — `Expected 403, received 204` (the delete happened). |
| fix 3 · route middleware removed | 17/18, red: *fix 3 refused*, observe only — `Expected 403, received 200`. |
| the two abort **messages** deleted | 14/18, red: *fix 1 refused* and *fix 2 cross-user*, **both datasets** — `Expecting '' not to be ''`, which is exactly the empty-body failure mode and not a status change. |
| `UnauthorizedException::forPermissions`'s message emptied in `vendor/` — the mutant that stands in for a vendor bump, since this refusal's body is not ours to write | 16/18, red: *fix 3 refused*, **both datasets** — `Expecting '' not to be ''`. Vendor file restored and verified byte-identical by md5 (`d4ef33f2ee26d19e84dd11bfb26f605d` before and after); the file is gitignored and is not in this commit. |

**Before the fixes**, the committed test file against base `a54b46c7` (source files reverted, test
file at HEAD): **18 arms, 13 passed, 5 failed.** The five:

| Arm | Base behaviour |
| --- | --- |
| fix 1 refused, observe | `Expected 403, received 200` |
| fix 2 cross-school, observe | `Expected 404, received 204` (the delete happened) |
| fix 2 cross-school, enforce | `Expected 404, received 403` (ownership refused it, not isolation) |
| fix 2 cross-user, observe | `Expected 403, received 204` |
| fix 3 refused, observe | `Expected 403, received 200` |

**Every known negative was already green at base**, which is what makes them known negatives rather
than a hope. Under `enforce` the base already refuses three of the four — which is precisely why the
flag is an axis: the holes were open under the production default, and only under it.

## Gates run

`pint` (changed files only, as an array) · `ci-authz-lint` · `ci-boundary-lint` ·
`ci-citation-lint` · `ci-activity-catalogue-lint` · `ci-dev-namespace-lint` ·
`ci-identifier-generation-lint` · `ci-money-lint` · `ci-runtime-zero-lint` · `ci-message-text-lint` ·
`ci-sql-clock-lint` · `ci-dependency-integrity-lint` · `ci-grants-convergence-lint origin/staging` ·
`pest --group=arch` (133/133) · `composer analyse` (phpstan, 0 errors) — all green.

Regression run over the touched areas: `tests/Feature/Rbac`, `tests/Feature/ActivityLog`,
`tests/Feature/Academics`, `tests/Feature/Isolation` — **619 tests, 615 passed, 4 failed**. All four
failures are `ActivityLogApiTest` and are lines 1-4 of `tests/ratchet-baseline.txt`; they are
pre-existing on `staging` and none is on a path this branch touches.

`bin/quality` was **not** run — Segun runs it in his own terminal.

## Residuals and what was deliberately not done

- **No drive.** This change adds no screen; the three surfaces are API endpoints, and the arms
  exercise them end-to-end through the real HTTP kernel including the middleware stack. It has **two**
  UI consequences, not one. The Export button was checked statically and is unreachable by the refused
  seat. The result **Submit** button is not: it is shown to anyone holding the `teacher` role, so an
  unassigned teacher now sees an enabled button that always 403s. This branch made that refusal
  legible (the message above, with an arm behind it); gating the button on the assignment needs the
  server to surface that fact to the panel, and is ticketed as
  [`submit-button-shown-to-unassigned-teachers.md`](../tickets/submit-button-shown-to-unassigned-teachers.md)
  — which also raises the class fix, a generic `HttpException` renderable in `bootstrap/app.php`, so
  the next bare `abort()` is legible by default instead of by each call site remembering.
- **`BelongsToSchool` on `SavedActivityFilter`** — ticketed, not done (brief).
- **The 51-route fixture backlog** — untouched, and it is why both fixture edits are by hand.
- **The observe-mode ability checks around these three guards are unchanged.** `isTeacher()` in
  `submit()`, and `Authz::abilityCheck('activity_log.view')` in `destroy()`, both still only observe.
  Each is now redundant for the seats this branch refuses, but neither is enforced, and flipping them
  is the AUTHZ_ENFORCE rollout's job rather than this branch's.
- **`assignScore` / `clearScore` have no assignment check at all**, and a comment above `assignScore`
  says they do. Pre-existing on `staging`, not introduced or widened here, so it does not block this
  merge — but it is the more severe finding, because this branch made the *act* of submitting
  assigned-teacher-only while the *content* of the submitted record stays authorable by any
  `score.manage` holder in the school. Ticketed as
  [`score-entry-has-no-assignment-check.md`](../tickets/score-entry-has-no-assignment-check.md).
- **The export button is gated on ROLE NAMES, not on the ability**, and the list omits the seat that
  holds it. `resources/js/pages/admin/activity-logs/index.tsx:42-46` derives
  `canExport` as `roles.includes('admin') || roles.includes('head_of_school') ||
  roles.includes('super_admin')`. `internal_auditor` is absent, and would be unable to reach the page
  anyway — the whole admin area is gated on `permission:admin_area.access` (`routes/web.php:703`),
  which that seat does not hold. So the one role this feed exists for has the API ability and **no UI
  at all**. Not touched here: it is a pre-existing UI gap, it is the opposite direction from the hole
  this branch closed, and widening `admin_area.access` is a grant decision, not a test fix. Worth a
  ticket of its own if the auditor is expected to use a screen rather than the API.
- **The census stream itself is unchanged.** These three guards no longer write `authz_observations`
  rows, because they abort instead. That is intended — an enforced control has nothing left to
  measure — but anyone reading the observation table for these three abilities after deploy will see
  the rows stop, and that is not the hole closing quietly, it is the guard being promoted.
