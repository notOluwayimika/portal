<?php

// 2026_08_05_100000_converge_finance_access_grants — the `finance.access` instance of the
// non-destructive-sync ADD GAP (`rbac:diff-grants` diagnosis SYNC_ADD_GAP). Proves the convergence,
// that the four already-aligned governed roles are left byte-identical, that a second run writes
// nothing, that the offender pre-flight bites — and, first, that the planted drift is real, so arm 1
// cannot pass by running against an already-converged database.
//
// No duty-separation arm, deliberately: `finance.access` terminates in `access` (not approve/reject)
// and is not a `*.submit`, so `DutySeparation::pairs()` emits no pair containing it and converging it
// can neither create nor clear a both-sides violation. See the migration docblock.

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** One reused migration instance (the file returns `new class`; require caches per process). */
function faConvergeMigration(): object
{
    static $m = null;
    if ($m === null) {
        $m = require database_path('migrations/2026_08_05_100000_converge_finance_access_grants.php');
    }

    return $m;
}

function faGlobalRole(string $name): Role
{
    return Role::where('name', $name)->where('guard_name', 'web')->whereNull('school_id')->firstOrFail();
}

/** Does this global role currently hold the pivot row? Read from the pivot, not through the cache. */
function faHolds(string $role): bool
{
    return DB::table('role_has_permissions as rhp')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('rhp.role_id', faGlobalRole($role)->id)
        ->where('p.name', 'finance.access')
        ->exists();
}

/** The full grant set of a global role, sorted — for byte-identical before/after comparison. */
function faAllGrants(string $role): array
{
    return faGlobalRole($role)->load('permissions')->permissions->pluck('name')->sort()->values()->all();
}

/**
 * Plant the REAL drift the live diff reported: head_of_school (role#2) and principal (role#10) are
 * `grantsMap()` holders that never received the pivot row, because `finance.access` and both role
 * rows pre-date the map edit and `RbacSeeder::sync()` grants only permissions created in that run.
 *
 * @return list<string>
 */
function faPlantDrift(): array
{
    $drifted = ['head_of_school', 'principal'];
    foreach ($drifted as $role) {
        faGlobalRole($role)->revokePermissionTo('finance.access');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $drifted;
}

it('ARM 4 (bite-proof, runs first) — the planted drift is real: without the migration the roles genuinely lack the grant', function () {
    // A fresh seed takes the `: $permissions` branch, so all six start aligned — assert that, or the
    // revoke below could be a no-op and arm 1 would prove nothing.
    expect(faHolds('head_of_school'))->toBeTrue()
        ->and(faHolds('principal'))->toBeTrue();

    faPlantDrift();

    // Migration NOT run.
    expect(faHolds('head_of_school'))->toBeFalse()
        ->and(faHolds('principal'))->toBeFalse()
        ->and(faGlobalRole('head_of_school')->fresh()->hasPermissionTo('finance.access'))->toBeFalse()
        ->and(faGlobalRole('principal')->fresh()->hasPermissionTo('finance.access'))->toBeFalse();

    // The four aligned roles are untouched by the planting itself.
    foreach (['admin', 'accounts_officer', 'accounts_supervisor', 'finance_lead'] as $role) {
        expect(faHolds($role))->toBeTrue();
    }
});

it('ARM 1 — converges the drift, leaves the four aligned governed roles byte-identical', function () {
    $aligned = ['admin', 'accounts_officer', 'accounts_supervisor', 'finance_lead'];
    $before = [];
    foreach ($aligned as $role) {
        $before[$role] = faAllGrants($role);
    }

    $drifted = faPlantDrift();
    foreach ($drifted as $role) {
        expect(faHolds($role))->toBeFalse();
    }

    faConvergeMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The drifted roles gained it.
    foreach ($drifted as $role) {
        expect(faHolds($role))->toBeTrue()
            ->and(faGlobalRole($role)->fresh()->hasPermissionTo('finance.access'))->toBeTrue();
    }

    // The aligned roles' ENTIRE grant sets are unchanged — not just finance.access.
    foreach ($aligned as $role) {
        expect(faAllGrants($role))->toBe($before[$role]);
    }

    // internal_auditor still does not hold it (RbacSeeder.php:377-391 — DECIDED, UNIMPLEMENTED).
    expect(faHolds('internal_auditor'))->toBeFalse()
        ->and(faHolds('super_admin'))->toBeFalse();
});

it('ARM 2 — idempotent: a second up() changes no grant and writes no activity row', function () {
    faPlantDrift();
    faConvergeMigration()->up();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $rbacBefore = DB::table('activity_log')->where('log_name', 'rbac')->count();
    $maxIdBefore = DB::table('activity_log')->max('id');

    faConvergeMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->where('log_name', 'rbac')->count())->toBe($rbacBefore)
        // MAX(id) unmoved, not just the count: a written-then-deleted row leaves the count equal.
        ->and(DB::table('activity_log')->max('id'))->toBe($maxIdBefore)
        ->and(faHolds('head_of_school'))->toBeTrue()
        ->and(faHolds('principal'))->toBeTrue();
});

it('ARM 3 — offender pre-flight bites: a global role outside the six holding finance.access aborts, no grant changes', function () {
    faPlantDrift();

    $rogue = Role::create(['name' => 'rogue_finance', 'guard_name' => 'web']);
    $rogue->givePermissionTo('finance.access');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();

    expect(fn () => faConvergeMigration()->up())->toThrow(RuntimeException::class, 'rogue_finance');

    // Aborted before any write — the drift is still there.
    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(faHolds('head_of_school'))->toBeFalse()
        ->and(faHolds('principal'))->toBeFalse();
});
