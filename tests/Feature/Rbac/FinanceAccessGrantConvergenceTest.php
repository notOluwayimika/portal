<?php

// 2026_08_05_100000_converge_finance_access_grants — the `finance.access` instance of the
// non-destructive-sync ADD GAP (`rbac:diff-grants` diagnosis SYNC_ADD_GAP). Proves the convergence,
// that the four already-aligned governed roles are left byte-identical, that a second run writes
// nothing, that the offender pre-flight bites — and, first, that the planted drift is real, so arm 1
// cannot pass by running against an already-converged database.
//
// ARMS 5 and 6 are the two exits that shipped unproven: the fresh-install QUIET green, and the
// broken-substrate ABORT that is the only thing keeping that quiet green honest. `:169` (a governed
// role missing) throws loudly and stays unarmed — a ticket, not a hole.
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

    // internal_auditor still does not hold it (RbacSeeder.php:377-394 — DECIDED, UNIMPLEMENTED).
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

it('ARM 5 — fresh install: no finance.* permission rows at all is a QUIET GREEN — no throw, no grant, no activity row', function () {
    // The fresh-install guard (`:93-113`). At migrate-from-zero the seeder has not run, so there is
    // nothing to converge and the seeder will write the correct map directly. Pairs with ARM 6: this
    // arm pins that the guard returns quietly, ARM 6 pins the boundary of what it returns quietly ON.
    //
    // Deleting the permission rows cascades through role_has_permissions (both pivots carry ON DELETE
    // CASCADE on permissions.id), which is why the grant count is captured AFTER the delete.
    DB::table('permissions')->where('name', 'like', 'finance.%')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $logBefore = DB::table('activity_log')->count();
    $maxIdBefore = DB::table('activity_log')->max('id');

    // Returning at all is half the assertion: a throw here fails the arm.
    faConvergeMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->count())->toBe($logBefore)
        // MAX(id) unmoved, not just the count — a written-then-deleted row leaves the count equal.
        ->and(DB::table('activity_log')->max('id'))->toBe($maxIdBefore);
});

it('ARM 6 — broken substrate: finance.access absent is SKIPPED and reported, and nothing is written', function () {
    // The guard is keyed on the whole `finance.` namespace deliberately: `finance.access` missing
    // while the rest of the namespace exists is not a fresh install, it is a broken substrate, so the
    // fresh-install path must NOT swallow it.
    //
    // It used to abort here. Under ADR 0052 it reports and continues: a frozen target naming a
    // permission row that no longer exists is the world moving on, not a condition this migration's
    // own writes could create. What still matters — and is what this arm asserts — is that nothing is
    // written and the operator is told which permission was skipped, by name.
    DB::table('permissions')->where('name', 'finance.access')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The precondition the arm is about: the rest of the namespace is still there, so the guard has
    // a real decision to make rather than passing vacuously.
    expect(DB::table('permissions')->where('name', 'like', 'finance.%')->count())->toBeGreaterThan(0);

    $grantsBefore = DB::table('role_has_permissions')->count();
    $logMaxBefore = DB::table('activity_log')->max('id');

    ob_start();
    faConvergeMigration()->up();
    $output = (string) ob_get_clean();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('finance.access')
        ->and(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->max('id'))->toBe($logMaxBefore);
});

it('ARM 3 — a global role outside the six holding finance.access is REPORTED, not fatal, and the convergence still runs', function () {
    // This arm used to assert a throw, and it was GREEN BY ACCIDENT once `executive_director` existed:
    // it matched on 'rogue_finance' while the abort message also named ED, so it would have passed on
    // a migration that was bricking every migrate:fresh for an entirely different reason.
    //
    // Under ADR 0052's corollary it must not throw at all. This migration governs six named roles and
    // cannot touch a seventh, so an outside holder is INFORMATION: report it with its holder count and
    // carry on. The arm asserts the report names the role AND that the convergence completed — the
    // second half is what stops a future "report" that quietly returns early.
    $drifted = faPlantDrift();

    $rogue = Role::create(['name' => 'rogue_finance', 'guard_name' => 'web']);
    $rogue->givePermissionTo('finance.access');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    faConvergeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('REPORT')
        ->and($output)->toContain('rogue_finance');

    // ...and the drift was converged anyway, which is the half a silent early return would break.
    foreach ($drifted as $role) {
        expect(faHolds($role))->toBeTrue();
    }

    // The outside role keeps its grant — this migration cannot and must not touch it.
    expect(DB::table('role_has_permissions as rhp')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('rhp.role_id', $rogue->id)->where('p.name', 'finance.access')->exists())->toBeTrue();
});

it('ARM 7 — a missing governed role is SKIPPED and reported; the other governed roles still converge', function () {
    $drifted = faPlantDrift();

    Role::where('name', 'finance_lead')->where('guard_name', 'web')->whereNull('school_id')->first()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    faConvergeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('finance_lead');

    // Per-role, not a bail-out — the drifted roles still converged.
    foreach ($drifted as $role) {
        expect(faHolds($role))->toBeTrue();
    }
});
