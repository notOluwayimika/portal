# Brief — `rbac:diff-grants` reconciler + the grants-convergence lint

Load `finance-method`, `finance-context`, `finance-execute` before you start.

**Base:** `staging`, **at the tip after `fix/revoke-ia-cross-school` merges.** Not before.
Nothing here depends on that change's behaviour, but both edit `bin/quality`, and I would
rather you rebase than resolve. If it has not merged when you pick this up, stop and say so.
**Branch:** `feat/rbac-diff-grants`
**Shape:** 1 command + 1 lint + tests + 1 runbook + 1 `docs/testing.md` line. One commit.
**Review tier: FULL.** Roles, permissions, and a new gate step.

---

## The finding this exists to close

`RbacSeeder::syncLogged()` is non-destructive by contract — runtime matrix edits must
survive `rbac:sync`. The mechanism (`database/seeders/RbacSeeder.php`, ~:478-500):

```php
$newPermissions = array_diff($enumValues, $existingPermissions);
...
$toGrant = in_array($roleName, $existingRoles, true)
    ? array_values(array_intersect($permissions, $newPermissions))
    : $permissions;
```

`$newPermissions` is the set of permissions **created on this run**. So for a role that
already exists, `rbac:sync` grants **only permissions that did not exist before this run**.

**Consequence: adding an ALREADY-EXISTING permission to an ALREADY-EXISTING role in
`grantsMap()` grants nothing, on every environment where the seeder has already run.**
`array_intersect` provably excludes it. The map says the role holds it; the database says
it does not; nothing in the repo notices.

This is not a hypothesis. It is why `finance.access` is absent from `head_of_school` and
`principal` on the production copy: the permission row was created in `9caf958`
(2026-07-21); `7370e89` (2026-07-27) added it to those two roles, by which time it was no
longer in `$newPermissions`. The discount-policy permissions added in the same hunk WERE
new that run, which is exactly why those landed and `finance.access` did not.

It is also, I believe, the root cause of the 43-line `route-access-map.json` drift the last
oracle regeneration surfaced — the same gap, seen through a different oracle. Treat those
as one finding, not two.

**Scope of the defect class: every commit since the first seed that added a pre-existing
permission to a pre-existing role.** Two roles surfaced only because an oracle regeneration
happened to expose them. Nobody has enumerated the rest. **That enumeration is the point
of this command.** Do not write a convergence migration in this change — you would be
writing it against an unenumerated assumption, which is the mistake this is meant to end.

---

## Part 1 — `rbac:diff-grants`

`app/Console/Commands/RbacDiffGrants.php`.

```php
protected $signature = 'rbac:diff-grants {--json}';
```

`--json` precedent: `s7:divergence-snapshot`. Copy the posture of
`app/Console/Commands/AuditDutySeparation.php` — read-only, tabular, non-zero exit on
findings, an explicit "DETECTION ONLY" line.

### Non-negotiable posture

**It revokes nothing and grants nothing.** No writes of any kind. It is run against the
production copy; a reconciler that repairs is a reconciler that can destroy a legitimate
C6 edit before anyone has read the diff.

### Scope: global rows only

Diff **only** `roles.school_id IS NULL`, `guard_name = 'web'` (`RbacSeeder::GUARD`).
`grantsMap()` has no school dimension, so a school-scoped role cannot be diffed against it
without inventing an expectation. Count school-scoped `web` roles and report the count in
a one-line footer; never diff them. If that count is non-zero, say so plainly — I do not
believe any exist, and if they do that is itself a finding worth a ticket.

### Section A — catalog (run this first; it interprets everything below)

`PermissionEnum::values()` vs `permissions` where `guard_name = 'web'`. Report both
directions. This is a pre-check, not a nicety: the seeder creates and prunes permission
rows on every run, so a non-empty catalog diff means **`rbac:sync` has not been run on this
database**, and in that state every Section B `missing` is explained by that alone. If
Section A is non-empty, print a banner saying Section B is uninterpretable until
`rbac:sync` is run, and still print Section B, and still exit non-zero.

### Section B — grants, `missing` and `extra` reported SEPARATELY

For each role in `grantsMap()`:

- **`missing`** — in the map, not on the role in the database.
- **`extra`** — on the role in the database, not in the map.

Never merge these into one "drift" count. They have opposite causes and opposite remedies;
a single number would hide that.

Roles present in the database but **absent from `grantsMap()`** get their own list —
reported, never skipped. That is the `rogue_platform` shape, and skipping is how it would
survive a reconciler.

`super_admin` is diffed like any other role — `grantsMap()['super_admin']` is
`self::SUPER_ADMIN_PLATFORM` (`RbacSeeder.php:417`). A finding there means the self-heal
block at ~:503-512 did not run, which is a different and more serious problem. Say so in
the output if it fires.

### Section C — the cause, in the command

This is the load-bearing part and the reason the command is worth writing at all. Without
it the command says *what* differs and a human re-derives *why* every single time — and
that re-derivation step is precisely what produced the wrong diagnosis in the last report.

**The decisive fact you must verify before building on it** (`RbacSeeder.php:432`):

```php
activity()->withoutLogs(fn () => $this->syncLogged($fresh));
```

**The seeder writes no activity rows at all.** Therefore every `activity_log` row with
`log_name = 'rbac'` and `event IN ('permission_attached','permission_detached')` naming a
role+permission is a **non-seeder** mutation: the C6 matrix UI, a convergence migration, or
tinker. Read `app/Listeners/LogRbacChange.php` and confirm this for yourself before you
rely on it — if it is wrong, the whole diagnosis inverts and you should stop and say so.

Row shape written by that listener: `log_name='rbac'`, `subject_type=App\Models\Role`,
`subject_id=<role id>`, `event='permission_attached'|'permission_detached'`,
`properties->permissions` a JSON array of names, `causer_id` set when a request drove it and
NULL for console/migration.

Match on `subject_type` + `subject_id` + JSON membership of the permission name in
`properties->permissions`. Use the most recent matching row per (role, permission).

Then classify:

| Finding | Log row | Diagnosis |
|---|---|---|
| `missing` | none | **The non-destructive-sync ADD gap.** `grantsMap()` gained this after the permission already existed. Needs a convergence migration. |
| `missing` | `permission_detached` | A deliberate revoke — C6 local authority, or a convergence migration whose map was never updated. **Human decision. Do not converge.** |
| `extra` | `permission_attached` | A runtime matrix grant. **C6 local authority. Legitimate. Do not revoke.** |
| `extra` | none | The **mirror gap**: the permission was in `grantsMap()` when it was seeded and has since been REMOVED from the map with no revoke migration. |

Print the diagnosis, the `event`, the row `id` and `created_at`, and whether `causer_id` is
null — not just the verdict. A reconciler whose reasoning cannot be checked is a reconciler
that gets believed when it is wrong.

If MySQL JSON matching gets awkward, `JSON_CONTAINS(properties, JSON_QUOTE(?), '$.permissions')`
is the direct form. Verify it against the real column before committing to it; if the
listener's key name differs from `permissions`, follow the code, not this brief.

### Privacy

Role-level only. No user rows, no emails, no names. **Do not copy
`AuditDutySeparation.php:55`** — it emits `$user->email ?? ('user#'.$user->id)`, which
violates the standing rule. That is a separate ticket; do not fix it here and do not
propagate it.

### Exit code

Non-zero (`self::FAILURE`) if any section is non-empty, so it can sit in a pre-pilot
checklist. Zero when clean.

### Explicitly NOT a `bin/quality` step

Legitimate C6 matrix edits on a dev copy would make that gate permanently red, and a gate
people learn to ignore is worse than no gate. It is an operator command. Document it in a
runbook, not in the gate.

---

## Part 2 — the lint: `grantsMap()` addition requires a migration

`bin/ci-grants-convergence-lint.php`, invoked from `bin/quality` beside boundary-lint
(`bin/quality:126`), taking `"$BASE"` the way `lint-changed` does at `:102`.

### Why diff-aware, and the limit that forces it

The invariant — "a pre-existing permission added to `grantsMap()` ships a convergence
migration" — **cannot be asserted from state.** CI's database is freshly seeded, and on a
fresh seed `grantsMap()` always matches, by construction. The live production copy is the
only witness, and CI does not have it. So the diff is the only place the invariant is
visible.

That means the lint protects the future only. It does not catch anything already merged.
**That is the division of labour between the two halves of this change: the command covers
the past, the lint covers the future.** Say this in the lint's header docblock — a future
reader who assumes the lint is retroactive will draw a false conclusion from a green gate.

### The rule

Fail when the diff `BASE..HEAD` adds a permission to `grantsMap()` in
`database/seeders/RbacSeeder.php` and **none** of these three exemptions holds:

1. **The permission is new** — the same diff adds its `case` to `app/Enums/Permission.php`.
   Then it lands in `$newPermissions` and `rbac:sync` grants it. No migration needed.
2. **The role is new** — the same diff adds the role name to `RbacSeeder::ROLES`. Then
   `in_array($roleName, $existingRoles, true)` is false and the role receives the full
   `$permissions` array. No migration needed.
3. **A migration in the diff names the permission** — a file added under
   `database/migrations/` whose content contains the permission string. Not merely "a
   migration exists in the diff": it must mention the permission. The weak form would be
   exempted by any unrelated migration, which is wallpaper.

Resolve the permission name from added lines matching `PermissionEnum::([A-Z0-9_]+)->value`
(map the constant to its value via the enum) or a quoted permission string.

**Known imprecision, state it in the docblock:** attributing an added grant line to its
role from diff text alone is unreliable when hunks are tight. Do not fake precision. Report
the permission and the file/line, say the role is inferred from the nearest preceding
`'<role>' => [` in the hunk, and mark it inferred. The lint's job is to make a human look;
it is not a proof.

No baseline file. This is an absolute rule from day one, not a ratchet — there is nothing
pre-existing for it to grandfather, because it only ever sees new diffs.

---

## Part 3 — tests

`tests/Feature/Rbac/` (there is no `tests/Feature/Console`; do not create one).

`RbacDiffGrantsTest.php`:

- Clean database ⇒ no findings, exit 0.
- Revoke one permission from one global role directly, run the command ⇒ it appears under
  `missing` for that role and nothing else, exit non-zero.
- Grant one map-absent permission to a global role directly ⇒ `extra`, exit non-zero.
- With no `activity_log` row for that pair, the diagnosis reads as the sync-ADD gap; with a
  `permission_detached` row seeded for it, the diagnosis flips to the deliberate-revoke
  branch. **This is the assertion that matters most** — the classification is the whole
  value of the command, and it is the part most likely to be silently wrong.
- A school-scoped role with a divergent grant set is **not** reported as drift; it appears
  only in the footer count.
- A role in the database but absent from `grantsMap()` is reported, not skipped.
- `--json` parses and carries the same findings as the table.

`GrantsConvergenceLintTest.php` — exercise the lint over fixture diffs if you can do it
without shelling out to git in a test. If you cannot do it honestly, do not fake it: say so
in the report and rely on the watched red below instead. A test that constructs a fake diff
the lint never sees in life is worse than no test.

---

## Part 4 — watched reds (both required)

1. **Command.** On a scratch database: plant a drift on one global role, run the command,
   confirm it reports exactly that pair with the sync-ADD diagnosis and exits non-zero;
   restore; confirm clean and exit 0. Paste both outputs.
2. **Lint.** Add a line to `grantsMap()` granting a **pre-existing** permission to a
   **pre-existing** role, with no migration. Run `bin/quality` (or the lint alone) and
   confirm it fails and names the permission. Restore, confirm green. Paste the failure
   text verbatim.
3. **Exemption 1 must also be watched**, or the lint is untrustworthy in the direction that
   matters: add a genuinely new permission (enum case + grant, same diff, no migration) and
   confirm the lint **passes**. A gate that fires on the legitimate case will be disabled
   within a week.

---

## Stop conditions

- **`activity()->withoutLogs()` does not wrap the whole of `syncLogged()`.** Then seeder
  runs write `rbac` rows, Section C's classification is inverted, and the design is wrong.
  Stop and report.
- **`extra` grants that the log shows were made through the matrix UI.** Those are C6 local
  authority — the seeder's non-destructive contract exists specifically to preserve them.
  Do not converge them, do not propose converging them.
- **Any temptation to make the command write.** Stop.
- **`fix/revoke-ia-cross-school` has not merged.** Stop.

---

## Not in scope

- **The convergence migration itself.** It is a separate brief, written after Segun runs
  `rbac:diff-grants` against the local production copy (`portaa10_portal`) and we can size
  it against a real enumeration instead of an assumption.
- **The `finance.access` grant for `internal_auditor`** — Phase 2, per-school, and it
  queues behind whatever this reconciler turns up.
- `AuditDutySeparation.php:55`'s email leak. Ticket.
- `PermissionGroup.php:100` rendering `activity_log.view_cross_school` as a selectable chip
  that now always errors. Ticket.

---

## Hand-off

Report to `docs/handoff/reports/rbac-diff-grants.md`. Include, verbatim, the **full output
of `rbac:diff-grants` run against the development copy** — that output is the input to the
next brief, and structure and counts only: role names and permission names are fine, user
rows are not. Commit on the branch. Never push.
