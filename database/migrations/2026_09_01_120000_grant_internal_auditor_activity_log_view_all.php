<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grant `activity_log.view_all` to `internal_auditor` — the seat could not read anybody's acts but
 * its own.
 *
 * @converges internal_auditor activity_log.view_all
 *
 * ─── WHAT WAS MEASURED ───────────────────────────────────────────────────────────────────────────
 *
 * `internal_auditor` held exactly `activity_log.view` and `activity_log.export` in the seeder's
 * grants map. `ActivityLogQueryService::baseQuery` narrows a viewer without
 * `activity_log.view_all` to `causer_id = self`:
 *
 *     if (! $user->can('activity_log.view_all')) {
 *         $query->where('causer_type', User::class)->where('causer_id', $user->id);
 *     }
 *
 * An auditor reads OTHER people's acts by definition, so the seat was reading an empty feed by
 * construction. This is not a discovery — `2026_08_04_100000_revoke_internal_auditor_cross_school`
 * says it in its own docblock, as the reason `view_cross_school` was "armed rather than safe": what
 * bounded that grant "was :55-57 of the same file, restricting to self-caused rows unless the holder
 * also has `activity_log.view_all`, which IA does not hold". The bound was noticed; that it also
 * emptied the seat was not.
 *
 * ─── WHY THIS IS NOT THE GRANT THAT WAS REVOKED ─────────────────────────────────────────────────
 *
 * `view_cross_school` drops the SCHOOL predicate entirely (baseQuery:42-52) and is the sole member
 * of `PermissionEnum::ISOLATION_CROSSING`; ADR 0036 makes isolation un-bypassable by role, and it
 * stays revoked. `view_all` drops only the SELF predicate. With `view_cross_school` gone, an
 * auditor holding `view_all` reads their own school's activity and nothing else — bounded twice
 * over, by baseQuery's school clause and by `SchoolScope`.
 *
 * It is granted WITHOUT `activity_log.view_sensitive`, deliberately. The auditor sees acts; the
 * entries `config/activity_log_sensitive.php` marks confidential — role grants, impersonation,
 * password resets — stay hidden. That is also why the bank-account and settlement events this
 * migration's branch adds are NOT listed as sensitive: an audit trail the auditor cannot see is the
 * thing `activity_log_sensitive.php` already refuses to build for student-record reads.
 *
 * ─── WHY A MIGRATION AND NOT JUST THE SEEDER LINE ────────────────────────────────────────────────
 *
 * `RbacSeeder::sync()` is non-destructive: for a role that ALREADY EXISTS it grants only permissions
 * CREATED IN THAT SAME RUN — the `$toGrant` branch inside
 * database/seeders/RbacSeeder.php:723 (toGrant), in `syncLogged()`. `activity_log.view_all` is pre-existing — it
 * has been granted to `admin` since the activity-log module shipped — so adding the line to
 * that map lands on fresh installs and does NOTHING on the production copy. That is precisely the
 * defect `bin/ci-grants-convergence-lint.php` guards, and the `@converges` marker above is how this
 * file declares the pair it closes.
 *
 * No forcing migration governs `activity_log.` on `internal_auditor` — the forcing targets in this
 * repository are all `finance.` namespaces (`2026_08_02_100000`, `2026_08_06_100000`), and
 * `ForcingMigrationsDoNotStripLaterGrantsTest` is the gate that keeps that true rather than this
 * sentence.
 *
 * ─── A MIGRATION IS A DATED ACT (ADR 0052) ───────────────────────────────────────────────────────
 *
 * The role and permission below are FROZEN literals, not read from a map that will move. Nothing
 * here aborts: it adds one permission to one role and can create no both-sides state, so every
 * surprise is reported and stepped past. Same posture, and the same report/skip shape, as
 * `2026_08_04_100000_revoke_internal_auditor_cross_school`, which this file mirrors deliberately —
 * it is that migration's counterpart, closing what the revoke left the seat unable to do.
 *
 * The grant goes through Spatie's event so `LogRbacChange` records it in `activity_log`; it is a
 * governance act and must be visible. `givePermissionTo`, never `syncPermissions`, whose detach is
 * raw and fires no event.
 */
return new class extends Migration
{
    private const PERMISSION = 'activity_log.view_all';

    private const ROLE = 'internal_auditor';

    public function up(): void
    {
        // Fresh-install guard, keyed on the PERMISSION substrate rather than the role row. `migrate`
        // runs BEFORE any seeding, and permissions are seeder-owned, so on migrate-from-zero this
        // permission does not exist even though role rows created by earlier migrations may. When it
        // is absent the RBAC substrate is unseeded and there is nothing to converge — the seeder will
        // write the post-edit map directly. Keying on the ROLE row would be the mistake: the role can
        // exist without the permission and vice versa.
        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', RbacSeeder::GUARD)
            ->first();

        if ($permission === null) {
            echo '  grant-ia-view-all: RBAC substrate unseeded ['.self::PERMISSION."] absent — nothing to converge.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $role = Role::query()
            ->where('name', self::ROLE)
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->first();

        if ($role === null) {
            echo '  grant-ia-view-all SKIPPED: global role ['.self::ROLE."] does not exist — nothing to converge.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Idempotency: already granted ⇒ clean no-op, no second activity row.
        if ($role->permissions->pluck('name')->contains(self::PERMISSION)) {
            echo '  grant-ia-view-all: ['.self::ROLE.'] already holds ['.self::PERMISSION
                ."] — no grant changed, no activity row written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // The school-scoped footprint: C6 per-school configuration, reported and left alone. A
        // school that has locally configured its own internal_auditor row is deliberate local
        // authority, not drift, and this migration governs the GLOBAL row only.
        $schoolScoped = DB::table('roles')
            ->where('name', self::ROLE)
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNotNull('school_id')
            ->count();

        echo '  grant-ia-view-all: school-scoped ['.self::ROLE."] role rows (UNTOUCHED): {$schoolScoped}\n";

        DB::transaction(function () use ($role): void {
            $role->givePermissionTo(self::PERMISSION);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        echo '  grant-ia-view-all: granted ['.self::PERMISSION.'] to global role ['.self::ROLE."].\n";
    }

    /**
     * DELIBERATE no-op.
     *
     * Rolling this back would restore a seat that holds `activity_log.view` and can read nothing —
     * an audit role that silently returns an empty feed, which is worse than a role that plainly
     * lacks the permission, because it looks like it works. It would also leave
     * the seeder's grants map claiming a grant the database does not have, which is the exact
     * drift this file exists to close. Roll FORWARD with a new named migration if the decision
     * changes. Same posture as `2026_08_02_100000_realign_finance_governance_grants`,
     * `2026_08_03_100000_converge_finance_change_grants` and the revoke this mirrors.
     */
    public function down(): void
    {
        // intentionally empty — see the docblock above.
    }
};
