# Runbook — reconciling `RbacSeeder::grantsMap()` against a live database

**Command:** `php artisan rbac:diff-grants [--json]`
**Posture:** READ-ONLY. It revokes nothing, grants nothing, and writes nothing.
**Exit:** `0` clean, `1` findings — so it slots into a pre-pilot checklist.

This is an **operator** command, deliberately **not** a `bin/quality` step. Legitimate C6
matrix edits on a dev copy would make that gate permanently red, and a gate people learn to
ignore is worse than no gate. The gate half of this pair is
`bin/ci-grants-convergence-lint.php`, which is diff-aware and covers the future only.

## The defect it enumerates

`RbacSeeder::sync()` is non-destructive by contract — runtime matrix edits must survive
`rbac:sync`. The mechanism is [database/seeders/RbacSeeder.php:494-496](../../database/seeders/RbacSeeder.php#L494-L496):

```php
$toGrant = in_array($roleName, $existingRoles, true)
    ? array_values(array_intersect($permissions, $newPermissions))
    : $permissions;
```

`$newPermissions` ([:478](../../database/seeders/RbacSeeder.php#L478)) is the set of permissions
**created on this run**. So for a role that already exists, `rbac:sync` grants only permissions
that did not exist before this run.

**Adding an already-existing permission to an already-existing role in `grantsMap()` therefore
grants nothing** on every environment where the seeder has already run. The map says the role
holds it; the database says it does not; and a fresh seed will never show it, because on a fresh
seed `$existingRoles` is empty and every role takes the `: $permissions` branch.

## Why a diagnosis is possible at all

[`RbacSeeder::sync():432`](../../database/seeders/RbacSeeder.php#L432) wraps the **entire** body of
the private `syncLogged()` in `activity()->withoutLogs(...)`, and
[`LogRbacChange:41`](../../app/Listeners/LogRbacChange.php#L41) writes through the `activity()`
helper, which returns early when logging is disabled. **The seeder writes no activity rows at
all.** Every `activity_log` row with `log_name = 'rbac'` and a
`permission_attached` / `permission_detached` event is therefore a **non-seeder** mutation: the C6
matrix UI, a convergence migration, or tinker.

That asymmetry is what turns "these differ" into "here is why". If it ever stops being true —
if a seeder run can write an `rbac` row — every diagnosis below inverts and the command must be
redesigned, not patched.

## Reading the output

Run **Section A first**; it interprets everything under it.

| Section | What it is |
| --- | --- |
| **A — catalog** | `Permission` enum vs the `permissions` rows, both directions. The seeder creates and prunes permission rows on every run, so a non-empty catalog diff means **`rbac:sync` has not been run on this database** — and in that state every Section B `missing` may be explained by that alone. The command banners this and still prints B. |
| **B/C — grants** | Per global role (`school_id IS NULL`, `guard_name = 'web'`), `missing` and `extra` reported **separately** — they have opposite causes and opposite remedies — each with its diagnosis, the log row id, the event, the timestamp and whether `causer_id` is null. |
| **structural** | Roles in `grantsMap()` with no global row; global roles **absent** from `grantsMap()` (the `rogue_platform` shape — reported, never skipped). |
| **footer** | School-scoped `web` role rows: **counted, never diffed**. `grantsMap()` has no school dimension, so there is no expectation to diff them against. A non-zero count does not change the exit code; it is worth a ticket. |

### The six diagnoses

| Code | State | What to do |
| --- | --- | --- |
| `SYNC_ADD_GAP` | `missing`, no rbac row | **The defect.** `grantsMap()` gained it after the permission already existed. Needs a convergence migration. |
| `DELIBERATE_REVOKE` | `missing`, latest row is a detach | Someone revoked it at runtime — C6 local authority, or a convergence migration whose map was never updated. **Human decision. Do not converge.** |
| `ATTACHED_THEN_LOST` | `missing`, latest row is an **attach** | The attach was logged, the removal was not. A raw `syncPermissions` detach (fires no event) or an out-of-app write. **Investigate.** |
| `MATRIX_GRANT` | `extra`, latest row is an attach | A runtime matrix grant. **C6 local authority. Legitimate — do not revoke.** The non-destructive contract exists to preserve exactly this. |
| `MAP_REMOVAL_GAP` | `extra`, no rbac row | The mirror gap: it was in `grantsMap()` when it was seeded and has since been removed from the map with no revoke migration. |
| `DETACHED_THEN_REGAINED` | `extra`, latest row is a **detach** | The detach was logged, the re-grant was not. **Investigate.** |

`ATTACHED_THEN_LOST` and `DETACHED_THEN_REGAINED` are not in the original design. They are the two
states the four-way table does not cover, and they are reachable: `HasPermissions::syncPermissions`
detaches raw and fires no event, so an unlogged removal is a real shape in this codebase, not a
hypothetical.

### `super_admin`

Diffed like any other role — `grantsMap()['super_admin']` **is** `SUPER_ADMIN_PLATFORM`. A finding
there means the self-heal block at
[RbacSeeder.php:506-512](../../database/seeders/RbacSeeder.php#L506-L512) did not run, which is a
different and more serious problem than the sync-ADD gap. The command says so in the output when it
fires.

## Procedure

```bash
# 1. Section A must be clean before Section B means anything.
php artisan rbac:diff-grants

# 2. If Section A reported a catalog difference, sync and re-run — do NOT use --fresh,
#    which discards runtime matrix edits and makes the copy stop being ground truth.
php artisan rbac:sync
php artisan rbac:diff-grants

# 3. Capture the enumeration for a convergence brief.
php artisan rbac:diff-grants --json > /tmp/grants-diff.json
```

**Never run `rbac:sync --fresh` on the production copy to "fix" a finding.** It resets every role's
grants to the seeded defaults and destroys exactly the C6 edits a `MATRIX_GRANT` finding is telling
you to preserve.

**Never convert a finding into a repair inside this command.** A reconciler that repairs is a
reconciler that can destroy a legitimate C6 edit before anyone has read the diff. The remedy for a
`SYNC_ADD_GAP` is a named convergence migration, reviewed like any other governance act — see
`2026_08_03_100000_converge_finance_change_grants.php` for the shape.

## Privacy

Role names, permission names, counts and log-row metadata only. No user rows, no emails, no names.
When quoting output into a report or a brief, that constraint travels with it.
