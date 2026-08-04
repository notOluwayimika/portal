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
 * environment on which the seeder has run, so any role added to the seeder's grants map afterwards
 * never receives it and no later `rbac:sync` closes the gap. `php artisan rbac:diff-grants` diagnoses
 * exactly this as SYNC_ADD_GAP, and reported it on the live copy for `head_of_school` (role#2) and
 * `principal` (role#10): both were listed as holders in the seeder's grants map at the time
 * and neither holds the pivot row. `finance.access` is the group gate on the finance page shells and
 * the six GET reads in `routes/endpoints/finance.php`, so the effect is that the approver seat (HoS)
 * cannot reach the surface it approves on.
 *
 * GOVERNED SET — all six holders per the seeder's grants map as it stood on 2026-08-03:
 * admin, head_of_school, principal, accounts_officer, accounts_supervisor,
 * finance_lead. Governing all six rather than only the two that drifted makes the permission CONVERGE
 * to the map for every global finance role, so drift in EITHER direction cannot hide: the four
 * already-aligned roles contribute nothing, which is the point.
 *
 * A MIGRATION IS A DATED ACT, NOT A LIVE QUERY (ADR 0052). This file used to say that the role SET
 * was written out here while the GRANTS were read from the seeder's map at run time — and called
 * that a design.
 * It was the defect: the two halves moved in opposite directions, so a later map edit silently
 * rewrote what this already-shipped migration does on replay. Authored to GRANT `finance.access` to
 * `head_of_school`, it would, against the 2026-08-04 map, REVOKE it — same filename, same batch row,
 * opposite act. Both halves are frozen now; see {@see self::TARGET}.
 *
 * `internal_auditor` is deliberately NOT governed and must NOT receive `finance.access`
 * (`RbacSeeder.php:377-394` records the grant as DECIDED and UNIMPLEMENTED — Phase 2, and per-school,
 * not cross-school). If it — or any other global role outside the six — holds the row, that is
 * REPORTED and the migration continues: it governs six named roles and cannot touch a seventh, so an
 * "offender" is information, never danger (ADR 0052's corollary).
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
 * The pairs this migration converges, in the marker syntax `bin/ci-grants-convergence-lint.php` reads.
 *
 * WHETHER THE GATE READS THESE LINES IS A PROPERTY OF THE BASE, NOT OF THIS FILE. Exemption 3
 * collects markers only from migrations the diff ADDS (`--diff-filter=A`), because a migration
 * already present on the base has already RUN and a marker on it would declare a convergence nothing
 * performed. So these lines are INERT over any base that already contains this file — the per-push
 * `staging` base, today — and LIVE over any base that predates it. That is not hypothetical:
 * `bin/quality-promote:79` runs `./bin/quality origin/main`, a wider range than the per-push one, and
 * a convergence migration sits on `staging` for a whole milestone before it reaches `main`.
 *
 * "Permanently inert" is not a property a file can carry. To know which side of it you are on, ask
 * the base rather than this comment: `git cat-file -e <base>:<path to this file>`.
 *
 * Either way this file is not a template for the marker. A NEW convergence migration is ADDED by its
 * own diff, so its markers are read on the range where they matter; these were backfilled.
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
 * target FROZEN at the adding commit; school-scoped rows counted, never written (C6 local authority
 * stays put); idempotent, short-circuiting BEFORE any activity row; diff-based revoke+give inside one
 * transaction so both Spatie events reach `LogRbacChange` and every delta is audited (NOT
 * `syncPermissions`, which detaches RAW with no event); registrar cache flushed after; BEFORE/AFTER
 * holder counts per school; `down()` a deliberate no-op.
 */
return new class extends Migration
{
    /** The single governed permission. Matched by EQUALITY everywhere — never a substring test. */
    private const PERMISSION = 'finance.access';

    /**
     * The grants this migration was written to establish, FROZEN at the commit that added it:
     * `af9db7ac395bb5891d99e4392e7af0b69092be4f`, 2026-08-03. Transcribed from
     * `git show af9db7a:database/seeders/RbacSeeder.php`.
     *
     * PLAIN STRINGS, not `PermissionEnum::` constants: an enum case can be renamed or deleted, and a
     * frozen historical act must not depend on today's enum any more than on today's map.
     *
     * @var array<string, list<string>>
     */
    private const TARGET = [
        'admin' => ['finance.access'],
        'head_of_school' => ['finance.access'],
        'principal' => ['finance.access'],
        'accounts_officer' => ['finance.access'],
        'accounts_supervisor' => ['finance.access'],
        'finance_lead' => ['finance.access'],
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

        $skipped = 0;

        // Target per governed role, read from the FROZEN literal. A frozen target may name a
        // permission row that no longer exists; that is the world moving on, not a danger, so the
        // permission is skipped with a line naming it rather than aborting the run (ADR 0052).
        $wanted = collect(self::TARGET)->flatten()->unique()->values()->all();
        $present = Permission::query()->whereIn('name', $wanted)
            ->where('guard_name', RbacSeeder::GUARD)->pluck('name')->all();

        $target = [];
        foreach (self::TARGET as $roleName => $permissions) {
            foreach (array_diff($permissions, $present) as $absent) {
                echo "  converge-finance-access-grants SKIPPED: permission [{$absent}] has no row — not granted to [{$roleName}].\n";
                $skipped++;
            }
            $target[$roleName] = collect($permissions)->intersect($present)->sort()->values()->all();
        }

        // A global role outside the frozen six holding it is INFORMATION, never danger: this
        // migration governs six named roles and cannot touch a seventh, so a grant it reports here is
        // not one it could have written. Holder counts kept — they are the useful part.
        $offenders = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereNull('r.school_id')->where('r.guard_name', RbacSeeder::GUARD)
            ->where('p.name', self::PERMISSION)->whereNotIn('r.name', array_keys(self::TARGET))
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
            echo '  converge-finance-access-grants REPORT: global role(s) outside this migration\'s scope also '
                .'grant ['.self::PERMISSION."]: {$detail}. Not an error — this migration governs "
                .implode(', ', array_keys(self::TARGET)).' only and cannot touch them. '
                ."internal_auditor holding it would be a DECIDED-but-UNIMPLEMENTED grant; that is \n"
                ."    `php artisan rbac:diff-grants`'s question, not this migration's.\n";
        }

        // A governed role row that does not exist cannot hold a grant that needs converging. Skip it,
        // say so, continue.
        $roles = [];
        foreach (array_keys($target) as $roleName) {
            $role = Role::query()->where('name', $roleName)
                ->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->first();

            if ($role === null) {
                echo "  converge-finance-access-grants SKIPPED: global role [{$roleName}] does not exist — nothing to converge for it.\n";
                $skipped++;
                unset($target[$roleName]);

                continue;
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
        foreach ($target as $roleName => $wantedForRole) {
            if ($this->currentGrant($roles[$roleName]) !== $wantedForRole) {
                $needsWork = true;
            }
        }

        $this->report('BEFORE', $skipped);

        if (! $needsWork) {
            echo "  converge-finance-access-grants: already aligned — no grants changed, no activity rows written.\n";
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        // Diff-based revoke + give, atomically. revoke fires PermissionDetachedEvent, give fires
        // PermissionAttachedEvent — both reach LogRbacChange. (syncPermissions detaches RAW, no event.)
        DB::transaction(function () use ($roles, $target) {
            foreach ($target as $roleName => $wantedForRole) {
                $role = $roles[$roleName];
                $current = $this->currentGrant($role);
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

        $this->report('AFTER', $skipped);
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
    private function report(string $label, int $skipped): void
    {
        echo "  converge-finance-access-grants [{$label}] holders per school (skipped={$skipped}):\n";

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
