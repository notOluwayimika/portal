<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance seat realignment (2026-08-01, docs/rbac/finance-seat-realignment.md) — the role-ROW side.
 * `RbacSeeder` defines the grants; this migration carries the stored `roles.name` data those grants
 * hang off, because a role row exists on any environment where the seeder has run even with zero holders.
 *
 *   - RENAME `finance_director` → `accounts_supervisor`. UPDATE, not drop+create, so the role_id is
 *     preserved and every model_has_roles / role_has_permissions row follows the rename with no reassignment
 *     and no holder loss. (Production returned 0 holders; a stale local copy had 1 — the UPDATE carries it.)
 *   - DELETE `finance_void_approver` (role row + its role_has_permissions). Brookstone has no such seat; it
 *     had 0 holders in production. Its only purpose was seeding the access oracle's single-side-checker case.
 *
 * PRE-FLIGHT, before any write:
 *   - If an `accounts_supervisor` web-guard role already exists for a team that also has a `finance_director`
 *     one, the rename would collide on `roles_team_name_guard_unique (IFNULL(school_id,0), name, guard)`
 *     (1062). Refuse and name it rather than force.
 *   - If any model_has_roles row references the `finance_void_approver` role, STOP — deleting it would strip
 *     a real holder. Zero holders is what production reported; this proves it on the DB being migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collision pre-flight: a team holding BOTH names would make the rename UPDATE trip the unique index.
        $collisions = DB::table('roles as fd')
            ->join('roles as as_', function ($j) {
                $j->on(DB::raw('IFNULL(fd.school_id,0)'), '=', DB::raw('IFNULL(as_.school_id,0)'))
                    ->where('as_.name', 'accounts_supervisor')->where('as_.guard_name', 'web');
            })
            ->where('fd.name', 'finance_director')->where('fd.guard_name', 'web')
            ->count();
        if ($collisions > 0) {
            throw new RuntimeException(
                'Rename aborted: an accounts_supervisor role already exists for a team that also has finance_director '
                .'— the UPDATE would collide on roles_team_name_guard_unique (1062). Resolve the duplicate first.'
            );
        }

        // Holder pre-flight for the DELETED role: refuse rather than strip a real grant.
        $voidRoleIds = DB::table('roles')->where('name', 'finance_void_approver')->where('guard_name', 'web')->pluck('id');
        if ($voidRoleIds->isNotEmpty()) {
            $holders = DB::table('model_has_roles')->whereIn('role_id', $voidRoleIds)->count();
            if ($holders > 0) {
                throw new RuntimeException(
                    "Delete aborted: finance_void_approver has {$holders} holder(s) in model_has_roles. "
                    .'Production reported zero; reassign them before this migration runs.'
                );
            }
        }

        DB::table('roles')->where('name', 'finance_director')->where('guard_name', 'web')
            ->update(['name' => 'accounts_supervisor']);

        DB::table('role_has_permissions')->whereIn('role_id', $voidRoleIds)->delete();
        DB::table('roles')->whereIn('id', $voidRoleIds)->delete();
    }

    /**
     * Reverse both: rename accounts_supervisor back to finance_director, and recreate finance_void_approver
     * with its original three grants (finance.access + void approve/reject). Guarded so a re-run is safe.
     */
    public function down(): void
    {
        DB::table('roles')->where('name', 'accounts_supervisor')->where('guard_name', 'web')
            ->update(['name' => 'finance_director']);

        $exists = DB::table('roles')->where('name', 'finance_void_approver')->where('guard_name', 'web')->exists();
        if (! $exists) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'finance_void_approver', 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $perms = DB::table('permissions')
                ->whereIn('name', ['finance.access', 'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject'])
                ->where('guard_name', 'web')->pluck('id');
            foreach ($perms as $pid) {
                DB::table('role_has_permissions')->insert(['permission_id' => $pid, 'role_id' => $roleId]);
            }
        }
    }
};
