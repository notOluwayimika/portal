<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\DutySeparation;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Revoke `activity_log.view_cross_school` from `internal_auditor` — the REVOCATION side the seeder
 * map cannot deliver.
 *
 * a0ab3d7 granted it in the seeder's grants map. v10 §7.2
 * (docs/Finance Module — Implementation Master Plan - v10.md:375, DECIDED 2026-07-29) says of that
 * exact permission that it "is read-shaped, is in scope, and must not be granted": it is a
 * CROSS-School read, and ADR 0036 makes isolation un-bypassable by role.
 *
 * The grant is not a narrow widening. ActivityLogQueryService::baseQuery:42-52 drops the school
 * predicate ENTIRELY for a holder — there is no narrower cross-school path. What bounds it today is
 * :55-57 of the same file, restricting to self-caused rows unless the holder also has
 * `activity_log.view_all`, which IA does not hold. The planned Phase 2 auditor derivation grants
 * every read segment and `view_all` IS a read segment, so this is armed rather than safe, and it is
 * revoked BEFORE that derivation lands.
 *
 * Why a migration at all: `rbac:sync` is non-destructive (RbacSeeder::syncLogged) — for a role that
 * already exists it grants only permissions created in that same run and revokes NOTHING. So
 * deleting the line from the seeder map lands on fresh installs and changes nothing on any environment
 * where the `internal_auditor` role row already exists, including the production copy. Same shape as
 * 2026_08_02_100000_realign_finance_governance_grants, which this follows closely.
 *
 * Scope: the GLOBAL (school_id IS NULL) `internal_auditor` row, and the single permission
 * `activity_log.view_cross_school`. Every other role — `super_admin` above all, which holds it
 * legitimately via RbacSeeder::SUPER_ADMIN_PLATFORM (ADR 0045 A3) — every other permission, and
 * every school-scoped (school_id IS NOT NULL) row is untouched; school-scoped rows are C6 per-school
 * configuration (deliberate local authority, not drift) and are counted and reported, never written.
 *
 * A MIGRATION IS A DATED ACT, NOT A LIVE QUERY (ADR 0052). The target below is FROZEN at the commit
 * that added this file; it used to be read from the seeder's map at run time, which made an
 * already-shipped migration re-shape itself on every later map edit. The corollary applies too, and
 * in this file it applies totally: NOTHING here aborts. There is no condition this migration's own
 * writes could create — it revokes one permission from one role and creates no both-sides state — so
 * every surprise it meets is reported and stepped past. `grep -c "throw new" ` on this file is 0, and
 * that is the intended state, not an omission.
 *
 * This is a governance act, not seeding: the revoke goes through Spatie's event so LogRbacChange
 * records it in activity_log (NOT wrapped in withoutLogs, unlike RbacSeeder::sync). Diff-based
 * revokePermissionTo — never syncPermissions, whose detach is raw, fires no event and would be
 * invisible to the audit listener (CLAUDE.md, the C6 vendor lesson) — inside a transaction.
 */
return new class extends Migration
{
    private const PERMISSION = 'activity_log.view_cross_school';

    private const ROLE = 'internal_auditor';

    /** The one global role that legitimately holds the permission (ADR 0045 A3). */
    private const SANCTIONED = 'super_admin';

    public function up(): void
    {
        // The premise check: the string above should still be an isolation-crossing member. If the
        // enum has moved on, that is the world moving on — reported, not fatal. The act itself is
        // unchanged either way: revoke this permission from this role (ADR 0052).
        if (! in_array(self::PERMISSION, PermissionEnum::ISOLATION_CROSSING, true)) {
            echo '  revoke-ia-cross-school REPORT: ['.self::PERMISSION.'] is no longer a member of '
                ."PermissionEnum::ISOLATION_CROSSING — the premise has changed since 2026-08-02. The\n"
                ."    revocation below still runs; whether it is still the right act is a question for a\n"
                ."    new migration, not this one.\n";
        }

        // Fresh-install guard, keyed on the PERMISSION substrate (not the role row). `migrate` runs
        // BEFORE any seeding (RbacSeeder / rbac:sync run after), and permissions are seeder-owned —
        // so on migrate-from-zero this permission does not exist even though role rows created by
        // earlier migrations may. When it is absent the RBAC substrate is unseeded and there is
        // nothing to revoke: the seeder will write the correct (post-edit) map directly. No-op
        // rather than abort, so this is safe under bin/quality-clean-db, the suite's migrate:fresh
        // and any fresh install. Keying this on the ROLE row instead would be the mistake: the role
        // can exist without the permission, and vice versa.
        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', RbacSeeder::GUARD)
            ->first();

        if ($permission === null) {
            echo '  revoke-ia-cross-school: RBAC substrate unseeded ['.self::PERMISSION."] absent — nothing to revoke.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Whether TODAY's seeder map re-grants this permission is `php artisan rbac:diff-grants`'s
        // question, not this migration's: a 2026-08-02 act cannot be conditioned on a map that moves.
        // This file needs no frozen TARGET const and does not have one: its act is already a pair of
        // literals — revoke {@see self::PERMISSION} from {@see self::ROLE} — and adding a const that
        // no code reads would assert a wiring that does not exist (ADR 0052).

        // The governed role should exist as a global row: we are past the fresh guard, so the
        // substrate is seeded and a missing row here is a real anomaly rather than a fresh DB. It is
        // still only an anomaly — a role that does not exist cannot hold a grant that needs revoking —
        // so it is reported and skipped, not fatal (ADR 0052).
        $role = Role::query()
            ->where('name', self::ROLE)
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->first();

        if ($role === null) {
            echo '  revoke-ia-cross-school SKIPPED: global role ['.self::ROLE.'] does not exist — nothing to revoke.'."\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Report, do not act on, any OTHER global role holding the permission. Expected and
        // sanctioned: `super_admin`, alone. A third holder is a grant nobody has accounted for and is
        // worth more than this migration — but this migration governs [internal_auditor] and cannot
        // touch it, so naming it with its holder count is the whole of what it can honestly do
        // (ADR 0052). It used to abort here; that turned an unaccounted grant into a permanent brick.
        $otherHolders = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->whereNull('r.school_id')
            ->where('r.guard_name', RbacSeeder::GUARD)
            ->where('rhp.permission_id', $permission->id)
            ->whereNotIn('r.name', [self::ROLE, self::SANCTIONED])
            ->distinct()->pluck('r.name')->all();

        if ($otherHolders !== []) {
            $detail = collect($otherHolders)->map(function (string $name): string {
                $holders = DB::table('model_has_roles as mhr')
                    ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                    ->where('mhr.model_type', User::class)
                    ->where('r.name', $name)
                    ->where('r.guard_name', RbacSeeder::GUARD)
                    ->distinct()->count('mhr.model_id');

                return "{$name} (holders={$holders})";
            })->implode(', ');

            echo '  revoke-ia-cross-school REPORT: global role(s) outside this migration\'s scope also hold ['
                .self::PERMISSION."]: {$detail}. Not an error — this migration governs [".self::ROLE
                .'] only and cannot touch them. Only ['.self::SANCTIONED.'] is sanctioned (ADR 0045 A3); '
                ."anything else here is `php artisan rbac:diff-grants`'s question.\n";
        }

        // The school-scoped footprint: C6 per-school configuration, reported and left alone.
        $schoolScoped = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->whereNotNull('r.school_id')
            ->where('r.guard_name', RbacSeeder::GUARD)
            ->where('rhp.permission_id', $permission->id)
            ->distinct()->count('r.id');

        echo '  revoke-ia-cross-school: school-scoped role rows carrying ['.self::PERMISSION."] (UNTOUCHED): {$schoolScoped}\n";

        $this->report('BEFORE');

        // Idempotency: already revoked ⇒ clean no-op, no second activity row.
        $holdsIt = $role->permissions->pluck('name')->contains(self::PERMISSION);

        if (! $holdsIt) {
            echo '  revoke-ia-cross-school: ['.self::ROLE.'] does not hold ['.self::PERMISSION
                ."] — no grant changed, no activity row written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke, atomically. revokePermissionTo fires PermissionDetachedEvent, which
        // reaches LogRbacChange — one audit row for the removal. (syncPermissions would detach RAW
        // with no event: invisible to the listener, and this is a governance act that must be seen.)
        DB::transaction(function () use ($role): void {
            $role->revokePermissionTo(self::PERMISSION);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->report('AFTER');
    }

    /**
     * DELIBERATE no-op. Restoring this grant would re-grant `internal_auditor` a CROSS-School read
     * that v10:375 forbids and that ADR 0036 makes un-bypassable by role — the exact breach this
     * migration exists to close. A rollback that re-opens an isolation boundary is worse than no
     * rollback: roll FORWARD with a new named migration instead. (Same posture as
     * 2026_08_02_100000_realign_finance_governance_grants and 2026_08_03_100000_converge_finance_change_grants.)
     */
    public function down(): void
    {
        // intentionally empty — see the docblock above.
    }

    /**
     * Per-school holder count for the governed permission — counts and school ids only, no names.
     * Holders derived exactly as the realign migration's report() does (DutySeparation::holdsViaGrant),
     * so the two agree.
     *
     * SCOPE, so a zero is not misread: this counts holders WITHIN a school's team, and `super_admin`
     * can never appear in it. Twice over — the model_has_roles query below filters on the school's
     * `school_id`, and `holdsViaGrant` sets the team id before reading `roles()`, which Spatie
     * constrains with `wherePivot(<teams key>, <team id>)`. `super_admin` is assigned at team NULL,
     * so it is outside both. Its holding is therefore NOT what this report evidences either side of
     * the revoke; that is asserted directly, on the role's grants, by
     * tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php ARM A.
     */
    private function report(string $label): void
    {
        echo "  revoke-ia-cross-school [{$label}] holders of [".self::PERMISSION."] per school:\n";

        foreach (School::query()->orderBy('id')->get() as $school) {
            $userIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('school_id', $school->id)
                ->pluck('model_id')->unique();
            $users = User::query()->whereIn('id', $userIds)->get();

            $count = $users->filter(
                fn (User $u): bool => DutySeparation::holdsViaGrant($u, (int) $school->id, self::PERMISSION)
            )->count();

            echo "    school#{$school->id}  ".self::PERMISSION."  holders={$count}\n";
        }

        setPermissionsTeamId(null);
    }
};
