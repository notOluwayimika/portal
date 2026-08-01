<?php

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
 * Finance seat realignment (a0ab3d7) — the GRANT-REVOCATION side the seeder map cannot deliver.
 *
 * `rbac:sync` is non-destructive: for a role that already exists it grants ONLY permissions created
 * in that same run and revokes NOTHING (RbacSeeder::sync, ~L462). So a grant REMOVED from
 * grantsMap() never leaves an environment where the role already exists. On production that means:
 *   - `principal` keeps the four finance approve/reject grants the realignment removed;
 *   - `head_of_school` keeps the two *.change.submit grants the realignment removed AND does not gain
 *     the approve sides (those permissions already exist, so they are not "new"), so after a bare
 *     deploy HoS would hold BOTH sides of a matching pair — a DutySeparation violation.
 * The rename migration (2026_08_01_130000) handled the role ROWS; nothing handles the grant deltas.
 *
 * This migration forces the grants of the GLOBAL (school_id IS NULL) `principal` and
 * `head_of_school` rows, WITHIN two namespaces only, to exactly match grantsMap():
 *   - finance.discount-policy.change.*
 *   - finance.fee-schedule.change.*
 * Anything outside those namespaces on those roles, and every other role, is untouched. The target
 * is DERIVED from grantsMap() — the six names are not hardcoded a second time. School-scoped
 * (school_id IS NOT NULL) rows are C6 per-school configuration (deliberate local authority, not
 * drift) and are never written — only counted and reported.
 *
 * This is a governance act, not seeding: the revoke/give go through Spatie's events so LogRbacChange
 * records them in activity_log (NOT wrapped in withoutLogs, unlike RbacSeeder::sync). Diff-based
 * revoke+give — never syncPermissions, whose raw detach fires no event and would be invisible to the
 * audit listener (CLAUDE.md, the C6 vendor lesson) — inside a transaction for atomicity.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $namespaces = [
        'finance.discount-policy.change.',
        'finance.fee-schedule.change.',
    ];

    /** @var list<string> */
    private array $governed = ['principal', 'head_of_school'];

    public function up(): void
    {
        $inNs = fn (string $p): bool => str_starts_with($p, $this->namespaces[0])
            || str_starts_with($p, $this->namespaces[1]);

        // The six governed permission names, derived from the DB (not a second hardcoded list).
        $sixNames = Permission::query()
            ->where('guard_name', RbacSeeder::GUARD)
            ->where(fn ($q) => $q
                ->where('name', 'like', $this->namespaces[0].'%')
                ->orWhere('name', 'like', $this->namespaces[1].'%'))
            ->pluck('name')->all();

        // Fresh-install guard, keyed on the PERMISSION substrate (not the roles). `migrate` runs
        // BEFORE any seeding (RbacSeeder / rbac:sync run after), and the finance-change permissions
        // are seeder-owned — so on migrate-from-zero NONE of the six exist even though some role rows
        // (e.g. `principal`) are already present from earlier migrations. When not one of the six
        // exists, the finance RBAC substrate is unseeded and there is nothing to realign — the seeder
        // will write the correct map directly. No-op rather than abort, so this is safe under
        // bin/quality-clean-db, the test suite's migrate:fresh, and any fresh install. (A PARTIALLY
        // seeded prod — submit present, approve/reject not synced — still trips pre-flight 3 below,
        // which is the real "run rbac:sync first" case the abort exists for.)
        if ($sixNames === []) {
            echo "  realign-finance-grants: finance RBAC substrate unseeded (no finance-change permissions) — nothing to realign.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Target grants per governed role, DERIVED from the seeder map, sliced to the two namespaces.
        $map = RbacSeeder::grantsMap();
        $target = [];
        foreach ($this->governed as $roleName) {
            $target[$roleName] = collect($map[$roleName] ?? [])->filter($inNs)->sort()->values()->all();
        }

        // Pre-flight 3: every permission we must GRANT has to exist (PR #182 finding 1 recurring —
        // staging served routes gated on permissions absent from its own table). rbac:sync must run
        // first. Checked before pre-flight 1/2 so a missing-permission env fails with the right cause.
        $mustGrant = collect($target)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $mustGrant)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();
        $missing = array_values(array_diff($mustGrant, $present));
        if ($missing !== []) {
            throw new RuntimeException(
                'realign-finance-grants ABORTED: target permission(s) absent from the permissions table — '
                .'run `php artisan rbac:sync` first, then re-migrate: '.implode(', ', $missing)
            );
        }

        // Pre-flight 1: no OTHER global role grants any of the six. The "4 makers all come from
        // head_of_school" story is an assumption; this turns it into a fact or names the exception.
        $allowed = ['principal', 'head_of_school', 'accounts_officer', 'accounts_supervisor', 'finance_lead'];
        $offenders = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->whereIn('p.name', $sixNames)->whereNotIn('r.name', $allowed)
            ->distinct()->pluck('r.name')->all();
        if ($offenders !== []) {
            $detail = collect($offenders)->map(function (string $name) {
                $holders = DB::table('model_has_roles as mhr')
                    ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                    ->where('mhr.model_type', User::class)
                    ->where('r.name', $name)->where('r.guard_name', RbacSeeder::GUARD)
                    ->distinct()->count('mhr.model_id');

                return "{$name} (holders={$holders})";
            })->implode(', ');
            throw new RuntimeException(
                'realign-finance-grants ABORTED: unexpected global role(s) grant the governed permissions: '
                .$detail.'. The maker source is not what the realignment assumed — investigate before widening this migration.'
            );
        }

        // Pre-flight 2: both governed roles must exist as global rows (we passed the fresh guard, so
        // the substrate is seeded; a governed role missing here is a real anomaly, not a fresh DB).
        $roles = [];
        foreach ($this->governed as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();
            if ($role === null) {
                throw new RuntimeException("realign-finance-grants ABORTED: global role [{$roleName}] is missing.");
            }
            $roles[$roleName] = $role;
        }

        // Report the school-scoped footprint (no action — C6 local authority stays put).
        $schoolScoped = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNotNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->whereIn('p.name', $sixNames)
            ->distinct()->count(DB::raw('CONCAT(r.id, "-", p.id)'));
        echo "  realign-finance-grants: school-scoped role rows carrying any of the six (UNTOUCHED): {$schoolScoped}\n";

        // Idempotency: already aligned ⇒ clean no-op, no second batch of activity rows.
        $needsWork = false;
        foreach ($this->governed as $roleName) {
            $current = $roles[$roleName]->permissions->pluck('name')->filter($inNs)->sort()->values()->all();
            if ($current !== $target[$roleName]) {
                $needsWork = true;
            }
        }

        $this->report('BEFORE', $sixNames);

        if (! $needsWork) {
            echo "  realign-finance-grants: already aligned — no grants changed, no activity rows written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke + give, atomically. revoke fires PermissionDetachedEvent, give fires
        // PermissionAttachedEvent — both reach LogRbacChange, so each removal/addition is one
        // audit row. (syncPermissions would detach RAW with no event: invisible to the listener.)
        DB::transaction(function () use ($roles, $target, $inNs) {
            foreach ($this->governed as $roleName) {
                $role = $roles[$roleName];
                $current = $role->permissions->pluck('name')->filter($inNs)->values()->all();
                $revoke = array_values(array_diff($current, $target[$roleName]));
                $grant = array_values(array_diff($target[$roleName], $current));

                foreach ($revoke as $perm) {
                    $role->revokePermissionTo($perm);
                }
                if ($grant !== []) {
                    $role->givePermissionTo($grant);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->report('AFTER', $sixNames);
    }

    /**
     * DELIBERATE no-op. Restoring these grants would silently re-grant `principal` a finance approval
     * authority Brookstone never sanctioned, and re-create the head_of_school both-sides SoD
     * violation. A rollback that re-introduces an unsanctioned authority is worse than no rollback —
     * roll FORWARD with a new named migration instead.
     */
    public function down(): void
    {
        // intentionally empty — see the docblock above.
    }

    /**
     * Per-school holder counts for each governed permission — counts and school ids only, no names.
     * Holders derived exactly as CheckStaffingReadiness does: raw grant via holdsViaGrant, over the
     * users carrying any role in that school's team.
     *
     * @param  list<string>  $sixNames
     */
    private function report(string $label, array $sixNames): void
    {
        echo "  realign-finance-grants [{$label}] holders per school per governed permission:\n";

        foreach (School::query()->orderBy('id')->get() as $school) {
            $userIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('school_id', $school->id)
                ->pluck('model_id')->unique();
            $users = User::query()->whereIn('id', $userIds)->get();

            foreach ($sixNames as $perm) {
                $count = $users->filter(
                    fn (User $u) => DutySeparation::holdsViaGrant($u, (int) $school->id, $perm)
                )->count();
                echo "    school#{$school->id}  {$perm}  holders={$count}\n";
            }
        }

        setPermissionsTeamId(null);
    }
};
