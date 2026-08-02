# Brief — revoke `activity_log.view_cross_school` from `internal_auditor`

Load `finance-method`, `finance-context`, `finance-execute` before you start.

**Base:** `docs/stale-payment-gate-claim` @ `ac9c7c7` — **not** `staging`. See "Why this
base" below; if you disagree, stop and say so before cutting the branch.
**Branch:** `fix/revoke-ia-cross-school`
**Shape:** 1 migration + 1 seeder edit + 1 request rule + 3 test files + 1 fixture +
1 doc line. One commit.
**Review tier: FULL.** Roles, grants, a fixture oracle and `school_id` isolation.
Subagent review is the floor; say in your headline that a cold session is recommended
before merge.

---

## The finding

`database/seeders/RbacSeeder.php:398` grants `ACTIVITY_LOG_VIEW_CROSS_SCHOOL` to
`internal_auditor`. Landed in `a0ab3d7`.

`docs/Finance Module — Implementation Master Plan - v10.md:375` says, of that exact
permission, that it **"is read-shaped, is in scope, and must not be granted"** — because
it is a cross-School read and ADR 0036 makes isolation un-bypassable by role. The
document records this under DECIDED 2026-07-29. The grant contradicts it.

What the grant actually does, `app/Services/ActivityLog/ActivityLogQueryService.php:42-53`:

```php
if (! $user->can('activity_log.view_cross_school')) {
    $schoolId = $this->currentSchoolId($user);
    $query->where(function (Builder $q) ... {
        $q->where('activity_log.school_id', $schoolId);
```

Holding it **skips the school predicate entirely**. There is no narrower cross-school
path; the predicate is either applied or absent.

**Why it is not already a live breach, and why that is temporary.** `:55-57` of the same
file further restricts to self-caused rows unless the user holds `activity_log.view_all`.
`internal_auditor` does not hold `view_all`. That — not `finance.access`, which has
nothing to do with activity-log queries — is the only thing bounding it today. **The
planned Phase 2 auditor derivation grants every read segment, and `view_all` is a read
segment.** The moment that lands, IA reads every school's activity log. Fixing this
first is the whole point of the ordering: the grant brief must not be the thing that
arms the breach.

**Environment it bites in:** everywhere the seeder has run, including the production copy.
It is a live grant, not a documentation defect.

### Two corrections to things I have said, before you rely on them

1. **I previously said `internal_auditor` is the only holder. That is false.**
   `super_admin` holds `activity_log.view_cross_school` too, via
   `RbacSeeder::SUPER_ADMIN_PLATFORM` (`RbacSeeder.php:58-64`), and legitimately — ADR
   0045 A3, and `GrantsMapSeparationTest.php:46-57` documents why it is in the map. It
   appears twice in `rbac-grants-baseline.json`: `:172` (IA, goes) and `:197`
   (super_admin, **stays**). Anything in this change that touches the super_admin holding
   is wrong. The seeder self-heals that row every run (`RbacSeeder.php:488-497`).
2. **`tests/Feature/Isolation/SchoolAwareJobsTest.php:172`** carries a comment about
   `view_cross_school` legitimately spanning Schools — that is about **super_admin**, not
   IA. Do not touch it.

### Why this base

`ac9c7c7` (on `docs/stale-payment-gate-claim`, unmerged) already edits
`docs/rbac/finance-seat-realignment.md`, and this change must rewrite `:45` of that same
file. Basing on `staging` guarantees a conflict there. If the project lead has since
merged `ac9c7c7` to `staging`, base on `staging` instead and say so in your report.
Confirm the base commit exists before you branch — a report that names a base that is not
there is itself a finding.

---

## What NOT to do

- **Do not remove the line from `grantsMap()` and stop.** `rbac:sync` is **non-destructive
  in both directions** (`RbacSeeder.php:472-484`): for a role that already exists it grants
  only permissions created in that same run, and revokes **nothing**. A grant deleted from
  the map never leaves an environment where the role row already exists. The seeder edit
  alone changes nothing on the production copy. This is the trap in this task, and it is
  the exact defect `01fdeda` was written to repair on the ADD side.
- **Do not run `rbac:sync --fresh` to force it.** It discards runtime matrix edits on a
  database that is a copy of production.
- **Do not use `syncPermissions()` in the migration.** Its detach is raw and fires no
  Spatie event, so `LogRbacChange` never sees it and the revocation is invisible in
  `activity_log`. This is a governance act. Diff-based `revokePermissionTo` only.
- **Do not wrap the migration's mutations in `withoutLogs()`.** Seed-time mutations are
  provenance-by-code-review; this one is not seeding.
- **Do not touch `super_admin`'s holding**, in the map, the migration, the baseline or the
  new tests.
- **Do not touch `activity_log.view_system`.** Different permission, different question,
  not in scope.
- **Do not grant IA `activity_log.view_all` as compensation**, and do not grant
  `finance.access`. Both are separate decisions with their own briefs.
- **Do not delete the `internal_auditor` role or any other grant it holds.**
  `activity_log.view` and `activity_log.export` stay.
- **Do not write school-scoped (`school_id IS NOT NULL`) role rows.** Those are C6
  per-school configuration. Count and report them; never write them.

---

## Part 1 — the seeder map

`database/seeders/RbacSeeder.php`.

1. Remove `PermissionEnum::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value` from the
   `internal_auditor` entry (currently `:398`). The entry keeps
   `ACTIVITY_LOG_VIEW` and `ACTIVITY_LOG_EXPORT`.
2. The block comment above that entry (`:385-395`) currently explains the
   `finance.access` deferral and does not mention cross-school at all. Add to it, in the
   same register and width: what was removed, that `v10:375` is what forbids it (cite the
   path and line you have just read), that ADR 0036 is the underlying rule, and that
   `super_admin` retains it via `SUPER_ADMIN_PLATFORM`. Do not rewrite the existing
   `finance.access` paragraph — it is correct as of `ac9c7c7`.
3. Re-derive every line number you cite **after** your edit shifts them.

## Part 2 — the revocation migration

`database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php`.

Follow `database/migrations/2026_08_02_100000_realign_finance_governance_grants.php`
closely — it is the settled shape for exactly this problem. Read it before writing.
Requirements, numbered because "follow the pattern" is read selectively:

4. **Target derived from `RbacSeeder::grantsMap()`**, sliced to the one permission name.
   Do not hardcode `internal_auditor`'s intended grant set a second time.
5. **Governed scope: the global (`school_id IS NULL`) `internal_auditor` row only**, and
   only the single permission `activity_log.view_cross_school`. Every other role, every
   other permission, and every school-scoped row is untouched.
6. **Fresh-install guard**, keyed on the permission substrate, not the role row —
   `migrate` runs before any seeding, so on migrate-from-zero the permission does not
   exist. No-op with a printed line, do not abort. (Read the realign migration's guard
   docblock; the reasoning there applies verbatim and the mistake it avoids is real.)
7. **Pre-flight: the `internal_auditor` global role row must exist** past the fresh guard.
   Missing is an anomaly — abort naming it.
8. **Pre-flight: report, do not act on, any OTHER global role holding the permission.**
   Expected: `super_admin`, and only `super_admin`. If any third global role holds it,
   **abort** — that is a grant nobody has accounted for and it is a finding worth more
   than this migration.
9. **Report the school-scoped footprint** (count of school-scoped role rows carrying the
   permission) — printed, untouched.
10. **Idempotent.** Already revoked ⇒ clean no-op, no second activity row, printed.
11. **Diff-based `revokePermissionTo` inside `DB::transaction`**, then
    `app(PermissionRegistrar::class)->forgetCachedPermissions()`. Not `syncPermissions`,
    not `withoutLogs`.
12. **BEFORE/AFTER holder report**, per school, under the privacy rule: `school#<id>`,
    counts only. Derive holders exactly as the realign migration's `report()` does
    (`DutySeparation::holdsViaGrant`), so the two agree.
13. **`down()` a deliberate no-op** with a docblock saying why: restoring it would
    re-grant a cross-School read that `v10:375` forbids and ADR 0036 makes
    un-bypassable. Roll forward with a new named migration.

## Part 3 — pin the map

`tests/Feature/Rbac/GrantsMapSeparationTest.php` — the file that already pins
`grantsMap()` invariants, so this belongs with them rather than in a new file.

14. Add an assertion: **no role in `grantsMap()` other than `super_admin` grants
    `activity_log.view_cross_school`.** Iterate the map; never hardcode a role list.
15. The `super_admin` exemption is **explicit and justified in a comment** citing ADR
    0045 A3 and `SUPER_ADMIN_PLATFORM` — an unexplained exemption is how a deny-list goes
    stale.
16. `v10:375` asks for the isolation-crossing set to be "an explicit list, itself
    asserted". Today that list has one member. Write it as a named `const` array in the
    test with one entry and assert over it, so a second member is added in one place —
    but **do not invent a second member**. If while writing this you conclude another
    enum case crosses `school_id`, stop and report it; do not add it silently.

## Part 4 — close the runtime hole

A test pins the seeded map. It does not stop the C6 matrix from re-granting the
permission at runtime, and `SyncRolePermissionsRequest::rules()` currently accepts any
enum value (`:41-42`). A rule with no runtime mechanism is wallpaper, and this rule is
about the one boundary the architecture treats as absolute.

17. `app/Http/Requests/SyncRolePermissionsRequest.php` — add a validator rule in
    `withValidator()` rejecting `activity_log.view_cross_school` in the requested set,
    for any role reachable through the matrix. Message names the permission and the
    reason (ADR 0036, isolation is not role-configurable). Derive the forbidden set from
    a single named source — do not hardcode the string in two places between Part 3 and
    Part 4; if that means putting the list somewhere both can read, say where you put it
    and why in the report.
18. `super_admin` is already unreachable through the matrix
    (`SyncRolePermissionsRequest::authorize()` `:33`), so this rule cannot strand it.
    **Confirm that in the report rather than assuming it** — read `:33`.
19. Test it in whatever file already covers this request — **find it, do not guess the
    name**. If none exists, say so and create one.

## Part 5 — the doc line

20. `docs/rbac/finance-seat-realignment.md:45` currently reads
    `` `activity_log.view_cross_school` (rows 8/9, IA=D, cross-school). See the deferral below. ``
    Rewrite it: IA holds `activity_log.view`/`.export`; `view_cross_school` was granted by
    `a0ab3d7` and **revoked here**, naming this branch's commit and `v10:375`. Keep the
    history — record it as granted-then-revoked, do not erase it.
21. Grep the tree for any other prose asserting IA holds it. `docs/handoff/reports/**` is
    a historical record — **do not rewrite reports**; list what you found and left.

## Part 6 — oracles

Regenerate in this order and **say in your report that you ran them in this order**:

22. `php artisan rbac:sync`
23. `php artisan rbac:derive-access` → `tests/fixtures/route-access-map.json`
24. the seed-and-dump for `tests/fixtures/rbac-grants-baseline.json`. There is no command;
    produce it with the **exact expression `PermissionEnumTest.php:30-41` asserts against**
    (web-guard roles → sorted permission names, `sortKeys()`), so the fixture cannot drift
    from its own oracle. Paste the command you used.
25. `php artisan rbac:derive-map` → `tests/fixtures/route-middleware-baseline.json`.

**Pinned expectation — this is the load-bearing check of the whole change.**

- `rbac-grants-baseline.json`: **exactly one line changes** — `activity_log.view_cross_school`
  leaves the `internal_auditor` array (`:172`). The `super_admin` array (`:197`) is
  **unchanged**.
- `route-access-map.json`: **no diff.** No route is gated on this permission — the
  service applies it inside the query, not the router. If this file moves, something you
  did was wider than the brief.
- `route-middleware-baseline.json`: **no diff.**

**Any other diff line in any of the three is a STOP.** Classify every changed line as
(a) the one baseline grant, or (b) something else, and paste every (b) line in full
before proceeding.

Note the asymmetry so you do not misread the ratchet rule: the grants catalog legitimately
grows and shrinks; "baselines only shrink" governs the failure ratchets
(`tests/ratchet-baseline.txt`, `tsc-baseline`, the lint baselines), not this file.
`docs/rbac-implementation-plan.md:104-112`.

## Part 7 — prove it

26. `tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php` — the migration's
    arms, each running `up()` against a seeded DB:
    - **(a) revokes** — IA holds it before, does not after; `super_admin`'s holding is
      **byte-identical** before and after; IA's other two grants byte-identical.
    - **(b) idempotent** — a second `up()` changes no grant and writes **no** activity row.
    - **(c) the third-holder abort** — plant the grant on a global role outside
      {`internal_auditor`, `super_admin`}, `up()` aborts naming it, **no grant changed**.
    - **(d) audited** — the revocation writes exactly one `rbac` activity row. This is the
      arm that proves `revokePermissionTo` was used and not `syncPermissions`; without it
      the "diff-based, not sync" requirement is unproven.
27. **The watched red, two of them, both required, both states pasted:**
    - Arm (a): comment out the `revokePermissionTo` call. Expect (a) to fail on the
      surviving grant. Confirm the failure message names the permission, not just "false
      is not true".
    - Part 4: disable the new validator rule. Expect the matrix test to fail. Confirm the
      message names the permission.
    Restore both. Paste red and green for each.
28. `php artisan test --filter=Rbac` and `--filter=ActivityLog`. Paste raw.
29. `bin/quality`. Paste the tail, all 12 steps. If any step goes red, stop and report;
    do not fix an unrelated red inside this change.
30. **Local DB observation, under the privacy rule.** The migration's BEFORE/AFTER report
    is the answer — paste it. Additionally state, as a count and nothing more, how many
    users hold `internal_auditor` per school: `school#<id> holders=<n>`. **No names, no
    emails.** If the count is zero everywhere, say so — that changes how the production
    impact reads and it is not a reason to skip anything.

## Stop and report

- The base commit does not exist, or `RbacSeeder.php:398` does not carry the grant.
- A third global role holds the permission.
- `super_admin`'s baseline entry moves for any reason.
- `route-access-map.json` or `route-middleware-baseline.json` shows any diff.
- Either watched red cannot be produced.
- The migration cannot be made idempotent without `syncPermissions`.
- You conclude a second enum case crosses `school_id` (Part 3 item 16).
- `bin/quality` goes red at any step.

## Not in scope

`activity_log.view_all` — IA does not hold it and this change does not give it. The
`finance.access` grant for IA (its own brief, and it must land **after** this one).
`activity_log.view_system`. The read/act split on `finance.access` (M5). The Phase 2
symmetry gate (`v10:377`). The `SuperAdminAuthorityTest` nondeterminism — known, do not
chase it. The pre-existing `result.*` duty-separation findings.

## When you are done

Follow `finance-execute`'s hand-off section exactly:

- Write the report to `docs/handoff/reports/fix-revoke-ia-cross-school.md` using
  `references/report-template.md`.
- Spawn the `finance-reviewer` subagent with **only** that path and the branch name.
  Nothing else — no summary, no "the risky part is X", no reassurance about what you
  already checked.
- Return its findings raw, alongside your report, unanswered.
- **Then COMMIT on the branch. Do not push.** An uncommitted tree is one `git checkout`
  away from gone and gives the reviewer no ref to work from.
