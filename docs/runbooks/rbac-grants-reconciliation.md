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
```

**Step 2 depends on WHICH DIRECTION Section A is dirty in. They are not the same operation and
`rbac:sync` is only safe in one of them.** Read the two sub-sections below before running anything.

### 2a. Section A shows `missing_rows` only — `rbac:sync` is safe

`missing_rows` means the enum declares a permission that has no `permissions` row.
[`firstOrCreate`](../../database/seeders/RbacSeeder.php#L447-L449) creates it; it then lands in
`$newPermissions` ([:478](../../database/seeders/RbacSeeder.php#L478)) and is granted per the map.
Purely additive — no row is deleted and no existing grant is touched.

```bash
php artisan rbac:sync
php artisan rbac:diff-grants
```

### 2b. Section A shows any `extra_rows` — **STOP. Do not run `rbac:sync`.**

`extra_rows` means a `permissions` row exists that the enum no longer declares, and that is the
input to a **destructive** branch of the same command:

```php
// database/seeders/RbacSeeder.php:454-457
Permission::where('guard_name', self::GUARD)
    ->whereNotIn('name', $enumValues)
    ->get()
    ->each(fn (Permission $p) => $p->delete());
```

Three mechanisms compound:

1. **The row is hard-deleted.** Not soft-deleted, not skipped.
2. **The pivots cascade.** **Every `role_has_permissions` and `model_has_permissions` row for that
   permission goes with it** — including runtime C6 matrix grants and direct user permissions.
   Derived from the live schema, not from the create migration, because the original uuid foreign
   keys were rebuilt as integer ones by
   [`2026_04_29_000001_update_foreign_keys_to_integer_ids`](../../database/migrations/2026_04_29_000001_update_foreign_keys_to_integer_ids.php#L90-L96):

    ```sql
    SELECT rc.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS rc
    JOIN information_schema.KEY_COLUMN_USAGE k
      ON k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
    WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'permissions';

    -- model_has_permissions.permission_id -> permissions.id  ON DELETE CASCADE
    -- role_has_permissions.permission_id  -> permissions.id  ON DELETE CASCADE
    ```

3. **Nothing is logged.** The whole prune runs inside
   [`activity()->withoutLogs()`](../../database/seeders/RbacSeeder.php#L432), so the deletion leaves
   **no `activity_log` trace at all**. Afterwards `rbac:diff-grants` cannot even tell you it
   happened: the permission is gone from both the enum and the database, so it is not a finding in
   either direction.

That is the same destruction this runbook warns about for `--fresh`, reached by the command
presented as the safe alternative to it.

**The enum-rename case, explicitly**, because it is the likely one and it looks harmless in the
diff. A rename of `x.old` → `x.new` in `Permission.php` is one line. On the next `rbac:sync`:
`x.old` is no longer declared → its row is deleted → every grant of it is cascaded away, unlogged;
`x.new` is created → it is in `$newPermissions` → it is granted to every role the **map** names. Net
effect on the production copy: the mapped grants are restored and **every runtime matrix grant of
that permission is erased with no audit trail**. The map wins silently over C6 local authority,
which is precisely the inversion the non-destructive contract exists to prevent.

If Section A reports `extra_rows`, get a human. The decision — which of those rows are dead
enum-pruning and which carry live grants — is not one this runbook can make for you. Enumerate the
exposure first:

```sql
-- What would be destroyed. Counts and ids only (privacy rule).
SELECT p.id AS permission_id,
       (SELECT COUNT(*) FROM role_has_permissions r WHERE r.permission_id = p.id)  AS role_grants,
       (SELECT COUNT(*) FROM model_has_permissions m WHERE m.permission_id = p.id) AS direct_grants
FROM permissions p
WHERE p.guard_name = 'web'
  AND p.name NOT IN ( /* the current App\Enums\Permission values */ );
```

### 3. Capture the enumeration for a convergence brief

```bash
php artisan rbac:diff-grants --json > /tmp/grants-diff.json
```

### The third write `rbac:sync` makes, in every direction

Independent of Section A: the self-heal block at
[RbacSeeder.php:506-512](../../database/seeders/RbacSeeder.php#L506-L512) runs
`syncPermissions(self::SUPER_ADMIN_PLATFORM)` on the global `super_admin` row **unconditionally, on
every run** — outside the `$fresh` branch and outside the `$newPermissions` intersection. Any grant
on that row which is not in the const is removed. This is intended (ADR 0045 A3; the C6 matrix
cannot edit that row, so there is no runtime authority to preserve), but two properties are worth
knowing before you run the command on a production copy:

- `HasPermissions::syncPermissions` **detaches RAW and fires no event**, so the removals write no
  `permission_detached` row and are invisible to the rbac audit listener.
- It is therefore the one write `rbac:sync` performs that `rbac:diff-grants` can never diagnose
  after the fact.

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
