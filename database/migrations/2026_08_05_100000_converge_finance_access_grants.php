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
 * `finance.access` grant convergence — the same defect as 2026_08_03_100000, a different permission.
 *
 * `RbacSeeder::sync()` is non-destructive for GRANTS in both directions: for a role that already
 * exists it applies only the permissions CREATED in that same run (`RbacSeeder.php:478`, `:494-496`).
 * `finance.access` (permission row created 2026-07-23) and all six role rows pre-date every
 * environment on which the seeder has run, so any role added to `grantsMap()['<role>']` afterwards
 * never receives it and no later `rbac:sync` closes the gap. `php artisan rbac:diff-grants` diagnoses
 * exactly this as SYNC_ADD_GAP, and reported it on the live copy for `head_of_school` (role#2) and
 * `principal` (role#10): both are listed as holders in `grantsMap()` (`RbacSeeder.php:230`, `:286`)
 * and neither holds the pivot row. `finance.access` is the group gate on the finance page shells and
 * the six GET reads in `routes/endpoints/finance.php`, so the effect is that the approver seat (HoS)
 * cannot reach the surface it approves on.
 *
 * GOVERNED SET — all six holders per `grantsMap()` (`RbacSeeder.php:199`, `:230`, `:286`, `:336`,
 * `:359`, `:373`): admin, head_of_school, principal, accounts_officer, accounts_supervisor,
 * finance_lead. Governing all six rather than only the two that drifted makes the permission CONVERGE
 * to the map for every global finance role, so drift in EITHER direction cannot hide: the four
 * already-aligned roles contribute nothing, which is the point. The role SET is written out here (a
 * migration is a fixed historical act and must not re-shape itself if the map moves later); the
 * GRANTS are derived from `grantsMap()` and never hardcoded.
 *
 * `internal_auditor` is deliberately NOT governed and must NOT receive `finance.access`
 * (`RbacSeeder.php:377-391` records the grant as DECIDED and UNIMPLEMENTED — Phase 2, and per-school,
 * not cross-school). If it — or any other global role outside the six — currently holds the row, the
 * offender pre-flight ABORTS rather than this migration silently revoking a human decision.
 *
 * WHY THERE IS NO POST-WRITE DutySeparation WALK (the one deliberate deviation from
 * 2026_08_03_100000, which carries one). `DutySeparation::pairs()` emits a pair only for an ability
 * whose terminal segment is `approve`/`reject` (`ApprovalAbility::CHECKER_SEGMENTS`,
 * `isExcludedFromSuperAdminBypass`), and its maker side is always that ability's prefix + `submit`
 * (`ApprovalAbility::matchingMakerFor`). `finance.access` terminates in `access`, so it is not a
 * checker, and it is not a `*.submit`, so it is not a maker of anything: it appears in NO pair, and
 * therefore in no `enforcedPairs()` entry. Granting or revoking it can neither create nor clear a
 * both-sides violation, so the walk would be pure cost with no reachable finding. The omission is
 * reasoned, not forgotten. (2026_08_03_100000 needed it because it moved `*.submit` maker abilities,
 * which do sit inside enforced pairs.)
 *
 * The pairs this migration converges, in `bin/ci-grants-convergence-lint.php`'s `@converges` syntax.
 *
 * RECORDED FOR THE READER, UNREADABLE BY THE GATE — and permanently so, not pending. Exemption 3
 * reads markers only on migrations the diff ADDS (`--diff-filter=A`), because a migration already on
 * the base has already run and a marker on it would declare a convergence nothing performed. This
 * file predates the lint and is on `staging`, so no future `base...head` will ever mark it `A`. From
 * here on, a pair needing exemption gets a NEW convergence migration and declares it there; do not
 * copy this file expecting these lines to do work.
 *
 * They are kept because they state precisely what the prose above cannot: that prose names roles this
 * migration EXCLUDES (`internal_auditor`) and roles it merely mentions (`registrar cache flushed
 * after`), which is exactly why the gate stopped reading prose. Only the two roles that actually
 * drifted are listed; the other four governed roles were already aligned and this migration
 * converges nothing for them.
 *
 * @converges head_of_school finance.access
 * @converges principal finance.access
 *
 * Everything else follows 2026_08_03_100000: fresh-install guard keyed on the permission substrate;
 * target DERIVED from `grantsMap()`; school-scoped rows counted, never written (C6 local authority
 * stays put); idempotent, short-circuiting BEFORE any activity row; diff-based revoke+give inside one
 * transaction so both Spatie events reach `LogRbacChange` and every delta is audited (NOT
 * `syncPermissions`, which detaches RAW with no event); registrar cache flushed after; BEFORE/AFTER
 * holder counts per school; `down()` a deliberate no-op.
 */
return new class extends Migration
{
    /** The single governed permission. Matched by EQUALITY everywhere — never a substring test. */
    private const PERMISSION = 'finance.access';

    /** @var list<string> All six `grantsMap()` holders of {@see self::PERMISSION}. */
    private array $governed = [
        'admin',
        'head_of_school',
        'principal',
        'accounts_officer',
        'accounts_supervisor',
        'finance_lead',
    ];

    public function up(): void
    {
        // Fresh-install guard, keyed on the PERMISSION substrate (seeder-owned, absent at
        // migrate-from-zero). No finance permission rows at all ⇒ the seeder has not run here and
        // will write the correct map directly — there is nothing to converge. Deliberately keyed on
        // the whole `finance.` namespace rather than on `finance.access` alone: `finance.access`
        // missing while the rest of the namespace exists is NOT a fresh install, it is a broken
        // substrate, and the pre-flight below must abort on it instead of returning a quiet green.
        $financeSubstrate = Permission::query()
            ->where('guard_name', RbacSeeder::GUARD)
            ->where('name', 'like', 'finance.%')
            ->exists();

        // AND IT IS LOAD-BEARING FOR THE WHOLE SUITE, not for one arm: `RefreshDatabase` migrates an
        // empty database on every run, so this guard is the only reason `migrate` succeeds there.
        // Disabling it errors all six arms of FinanceAccessGrantConvergenceTest, not just the
        // fresh-install one. Touch it with that in mind.
        if (! $financeSubstrate) {
            echo "  converge-finance-access-grants: finance RBAC substrate unseeded (no finance.* permissions) — nothing to converge.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Target per governed role, DERIVED from the seeder map. Equality, not str_starts_with: of
        // the 79 enum values `finance.access` is a prefix of nothing today, but a future
        // `finance.access_audit` would silently join the governed set under a prefix test.
        $map = RbacSeeder::grantsMap();
        $target = [];
        foreach ($this->governed as $roleName) {
            $target[$roleName] = in_array(self::PERMISSION, $map[$roleName] ?? [], true)
                ? [self::PERMISSION]
                : [];
        }

        // Pre-flight: the permission we must GRANT has to exist (run rbac:sync first otherwise).
        $mustGrant = collect($target)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $mustGrant)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();
        $missing = array_values(array_diff($mustGrant, $present));
        if ($missing !== []) {
            throw new RuntimeException(
                'converge-finance-access-grants ABORTED: target permission(s) absent from the permissions table — '
                .'run `php artisan rbac:sync` first, then re-migrate: '.implode(', ', $missing)
            );
        }

        // Pre-flight: no OTHER global role (outside the six) grants it — internal_auditor above all.
        // Reported with each offender's holder count so the operator knows what a revoke would cost.
        $offenders = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->where('p.name', self::PERMISSION)->whereNotIn('r.name', $this->governed)
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
                'converge-finance-access-grants ABORTED: unexpected global role(s) grant ['.self::PERMISSION.']: '
                .$detail.'. internal_auditor holding it is a DECIDED-but-UNIMPLEMENTED grant (RbacSeeder.php:377-391) — '
                .'investigate before widening this migration; do not let it silently revoke a human decision.'
            );
        }

        // Pre-flight: every governed role exists as a global row.
        $roles = [];
        foreach ($this->governed as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();
            if ($role === null) {
                throw new RuntimeException("converge-finance-access-grants ABORTED: global role [{$roleName}] is missing.");
            }
            $roles[$roleName] = $role;
        }

        // School-scoped footprint (no action — C6 local authority stays put).
        $schoolScoped = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNotNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->where('p.name', self::PERMISSION)
            ->distinct()->count(DB::raw('CONCAT(r.id, "-", p.id)'));
        echo '  converge-finance-access-grants: school-scoped role rows carrying ['.self::PERMISSION."] (UNTOUCHED): {$schoolScoped}\n";

        // Idempotency: already converged ⇒ clean no-op, no second batch of activity rows.
        $needsWork = false;
        foreach ($this->governed as $roleName) {
            $current = $this->currentGrant($roles[$roleName]);
            if ($current !== $target[$roleName]) {
                $needsWork = true;
            }
        }

        $this->report('BEFORE');

        if (! $needsWork) {
            echo "  converge-finance-access-grants: already aligned — no grants changed, no activity rows written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke + give, atomically. revoke fires PermissionDetachedEvent, give fires
        // PermissionAttachedEvent — both reach LogRbacChange. (syncPermissions detaches RAW, no event.)
        DB::transaction(function () use ($roles, $target) {
            foreach ($this->governed as $roleName) {
                $role = $roles[$roleName];
                $current = $this->currentGrant($role);
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

        $this->report('AFTER');
    }

    /**
     * DELIBERATE no-op. Rolling back would re-open the gap this closes — the approver seat locked out
     * of the finance surface it approves on — and `rbac:sync` would not re-close it (that is the whole
     * defect). Roll FORWARD with a new named migration instead.
     */
    public function down(): void
    {
        // intentionally empty — see the docblock above.
    }

    /**
     * The governed permission as this role currently holds it: `[self::PERMISSION]` or `[]`.
     * Reads the relation fresh so a give/revoke earlier in the same run is visible.
     *
     * @return list<string>
     */
    private function currentGrant(Role $role): array
    {
        return $role->load('permissions')->permissions
            ->pluck('name')
            ->filter(fn (string $p) => $p === self::PERMISSION)
            ->values()->all();
    }

    /**
     * Per-school holder counts for the governed permission — counts, school ids and the permission
     * name only, no user names/emails. Holders derived as CheckStaffingReadiness does (raw grant, so
     * super_admin's Gate::before bypass never inflates the count).
     */
    private function report(string $label): void
    {
        echo "  converge-finance-access-grants [{$label}] holders per school:\n";

        foreach (School::query()->orderBy('id')->get() as $school) {
            $userIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('school_id', $school->id)
                ->pluck('model_id')->unique();
            $users = User::query()->whereIn('id', $userIds)->get();

            $count = $users->filter(
                fn (User $u) => DutySeparation::holdsViaGrant($u, (int) $school->id, self::PERMISSION)
            )->count();
            echo "    school#{$school->id}  ".self::PERMISSION."  holders={$count}\n";
        }

        setPermissionsTeamId(null);
    }
};
