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
 * the seeder's grants map never leaves an environment where the role already exists. On production:
 *   - `principal` keeps the four finance approve/reject grants the realignment removed;
 *   - `head_of_school` keeps the two *.change.submit grants the realignment removed AND does not gain
 *     the approve sides (those permissions already exist, so they are not "new"), so after a bare
 *     deploy HoS would hold BOTH sides of a matching pair — a DutySeparation violation.
 * The rename migration (2026_08_01_130000) handled the role ROWS; nothing handles the grant deltas.
 *
 * This migration forces the grants of the GLOBAL (school_id IS NULL) `principal` and
 * `head_of_school` rows, WITHIN two namespaces only, to exactly match {@see self::TARGET}:
 *   - finance.discount-policy.change.*
 *   - finance.fee-schedule.change.*
 * Anything outside those namespaces on those roles, and every other role, is untouched. School-scoped
 * (school_id IS NOT NULL) rows are C6 per-school configuration (deliberate local authority, not
 * drift) and are never written — only counted and reported.
 *
 * A MIGRATION IS A DATED ACT, NOT A LIVE QUERY (ADR 0052). This file used to read the seeder's
 * grants map at run time while freezing its governed role set as a literal, and its
 * docblock described that split as a design. It was the defect: the two halves moved in opposite
 * directions, so every later map edit silently rewrote what this already-shipped migration does on
 * replay — and the 2026-08-04 seat move turned it into a hard stop on every `migrate:fresh`. The
 * target below is frozen, and the corollary applies: this migration aborts only on a condition its
 * own writes would create. Everything else it reports and continues past.
 *
 * This is a governance act, not seeding: the revoke/give go through Spatie's events so LogRbacChange
 * records them in activity_log (NOT wrapped in withoutLogs, unlike RbacSeeder::sync). Diff-based
 * revoke+give — never syncPermissions, whose raw detach fires no event and would be invisible to the
 * audit listener (CLAUDE.md, the C6 vendor lesson) — inside a transaction for atomicity.
 */
return new class extends Migration
{
    /**
     * The grants this migration was written to establish, FROZEN at the commit that added it:
     * `f143b40363724a1262420b53c5aadfae1c3b83f1`, 2026-08-01. Transcribed from
     * `git show f143b40:database/seeders/RbacSeeder.php`, sliced to the two governed namespaces.
     *
     * PLAIN STRINGS, not `PermissionEnum::` constants, and that is deliberate: an enum case can be
     * renamed or deleted, and a frozen historical act must not depend on today's enum any more than
     * on today's map.
     *
     * `principal` is `[]` because the realignment removed its four approve/reject grants; its
     * `finance.access` lies outside these namespaces and is not governed here.
     *
     * @var array<string, list<string>>
     */
    private const TARGET = [
        'principal' => [],
        'head_of_school' => [
            'finance.discount-policy.change.approve',
            'finance.discount-policy.change.reject',
            'finance.fee-schedule.change.approve',
            'finance.fee-schedule.change.reject',
        ],
    ];

    /** @var list<string> */
    private array $namespaces = [
        'finance.discount-policy.change.',
        'finance.fee-schedule.change.',
    ];

    public function up(): void
    {
        $inNs = fn (string $p): bool => str_starts_with($p, $this->namespaces[0])
            || str_starts_with($p, $this->namespaces[1]);

        // The six governed permission names, derived from the DB. This is NOT a target derivation:
        // it reads namespace MEMBERSHIP from the permissions table, which is a fact about the
        // substrate rather than a decision that can move.
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
        // will write the correct map directly. Safe under bin/quality-clean-db, the test suite's
        // migrate:fresh, and any fresh install.
        if ($sixNames === []) {
            echo "  realign-finance-grants: finance RBAC substrate unseeded (no finance-change permissions) — nothing to realign.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $skipped = 0;

        // A frozen target may name a permission row that no longer exists. That is the world moving
        // on, not a danger: skip the permission, say so, continue. It used to abort, which converted
        // a harmless surprise into a permanent brick on every future migrate:fresh (ADR 0052).
        $wanted = collect(self::TARGET)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $wanted)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();

        $target = [];
        foreach (self::TARGET as $roleName => $permissions) {
            foreach (array_diff($permissions, $present) as $absent) {
                echo "  realign-finance-grants SKIPPED: permission [{$absent}] has no row — not granted to [{$roleName}].\n";
                $skipped++;
            }
            $target[$roleName] = collect($permissions)->intersect($present)->sort()->values()->all();
        }

        // A global role outside the frozen allow-list holding one of the six is INFORMATION, never
        // danger: this migration cannot touch a role it does not govern, so a grant it reports here is
        // not one it could have written. Holder counts kept — they are the useful part.
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
            echo '  realign-finance-grants REPORT: global role(s) outside this migration\'s scope also grant the '
                ."governed permissions: {$detail}. Not an error — this migration governs "
                .implode(', ', array_keys(self::TARGET))." only and cannot touch them.\n";
        }

        // A governed role row that does not exist cannot hold a grant that needs converging. Skip it,
        // say so, continue.
        $roles = [];
        foreach (array_keys($target) as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();

            if ($role === null) {
                echo "  realign-finance-grants SKIPPED: global role [{$roleName}] does not exist — nothing to realign for it.\n";
                $skipped++;
                unset($target[$roleName]);

                continue;
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
        foreach ($target as $roleName => $wantedForRole) {
            $current = $roles[$roleName]->permissions->pluck('name')->filter($inNs)->sort()->values()->all();
            if ($current !== $wantedForRole) {
                $needsWork = true;
            }
        }

        $this->report('BEFORE', $sixNames, $skipped);

        if (! $needsWork) {
            echo "  realign-finance-grants: already aligned — no grants changed, no activity rows written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke + give, atomically. revoke fires PermissionDetachedEvent, give fires
        // PermissionAttachedEvent — both reach LogRbacChange, so each removal/addition is one
        // audit row. (syncPermissions would detach RAW with no event: invisible to the listener.)
        DB::transaction(function () use ($roles, $target, $inNs) {
            foreach ($target as $roleName => $wantedForRole) {
                $role = $roles[$roleName];
                $current = $role->permissions->pluck('name')->filter($inNs)->values()->all();
                $revoke = array_values(array_diff($current, $wantedForRole));
                $grant = array_values(array_diff($wantedForRole, $current));

                foreach ($revoke as $perm) {
                    $role->revokePermissionTo($perm);
                }
                if ($grant !== []) {
                    $role->givePermissionTo($grant);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->report('AFTER', $sixNames, $skipped);
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
    private function report(string $label, array $sixNames, int $skipped): void
    {
        echo "  realign-finance-grants [{$label}] holders per school per governed permission (skipped={$skipped}):\n";

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
