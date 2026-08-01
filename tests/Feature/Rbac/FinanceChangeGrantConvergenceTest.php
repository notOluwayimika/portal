<?php

// 2026_08_03_100000_converge_finance_change_grants — the grant-GAIN mirror of #186. Proves the
// convergence, that it leaves what #186 settled untouched, and that BOTH aborts bite: a global role
// outside the governed five (role-scoped), and a user wearing two conflicting hats (user-scoped —
// the production hazard a grant-map change creates by retroactively turning a legal role pair into a
// both-sides violation with no assignment-time guard to catch it).

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\DutySeparation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** One reused migration instance (the file returns `new class`; require caches per process). */
function convergeMigration(): object
{
    static $m = null;
    if ($m === null) {
        $m = require database_path('migrations/2026_08_03_100000_converge_finance_change_grants.php');
    }

    return $m;
}

function ccGlobalRole(string $name): Role
{
    return Role::where('name', $name)->where('guard_name', 'web')->whereNull('school_id')->firstOrFail();
}

/** The two-namespace grants a global finance role currently holds, sorted. */
function ccNsGrants(string $role): array
{
    $inNs = fn (string $p) => str_starts_with($p, 'finance.discount-policy.change.') || str_starts_with($p, 'finance.fee-schedule.change.');

    return ccGlobalRole($role)->permissions->pluck('name')->filter($inNs)->sort()->values()->all();
}

/** Plant the real drift: strip the maker submits the realignment ADDED (that non-destructive sync never applied). */
function ccPlantDrift(): void
{
    $ao = ccGlobalRole('accounts_officer');
    $ao->revokePermissionTo('finance.discount-policy.change.submit');
    $ao->revokePermissionTo('finance.fee-schedule.change.submit');
    ccGlobalRole('accounts_supervisor')->revokePermissionTo('finance.fee-schedule.change.submit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** CheckStaffingReadiness's own coverage test: two DISTINCT users cover maker + checker. */
function ccPairCovered(int $schoolId, string $maker, string $checker): bool
{
    $userIds = DB::table('model_has_roles')->where('model_type', User::class)->where('school_id', $schoolId)->pluck('model_id')->unique();
    $users = User::whereIn('id', $userIds)->get();
    $makers = $users->filter(fn (User $u) => DutySeparation::holdsViaGrant($u, $schoolId, $maker));
    $checkers = $users->filter(fn (User $u) => DutySeparation::holdsViaGrant($u, $schoolId, $checker));
    setPermissionsTeamId(null);

    return $makers->isNotEmpty() && $checkers->isNotEmpty()
        && $makers->pluck('id')->merge($checkers->pluck('id'))->unique()->count() >= 2;
}

function ccStaff(School $school, string $role): User
{
    $u = User::factory()->create(['school_id' => $school->id]);
    $u->grantSchoolAccess($school, $role);
    $u->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

it('ARM 1 — converges the drift, leaves principal/head_of_school untouched, closes the GAP', function () {
    $principalBefore = ccNsGrants('principal');
    $hosBefore = ccNsGrants('head_of_school');

    // Staff a maker seat (AO) and a checker seat (HoS) in one school — distinct users.
    $school = School::factory()->create();
    ccStaff($school, 'accounts_officer');
    ccStaff($school, 'head_of_school');

    ccPlantDrift();

    // With the maker grant stripped, the discount + fee pairs are a GAP (the maker side is empty).
    expect(ccPairCovered($school->id, 'finance.discount-policy.change.submit', 'finance.discount-policy.change.approve'))->toBeFalse()
        ->and(ccPairCovered($school->id, 'finance.fee-schedule.change.submit', 'finance.fee-schedule.change.approve'))->toBeFalse();

    convergeMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The three grants are back.
    expect(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.discount-policy.change.submit'))->toBeTrue()
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue()
        ->and(ccGlobalRole('accounts_supervisor')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue();

    // #186's settlement is byte-identical — widening $governed to five disturbed nothing.
    expect(ccNsGrants('principal'))->toBe($principalBefore)
        ->and(ccNsGrants('head_of_school'))->toBe($hosBefore);

    // GAP is gone.
    expect(ccPairCovered($school->id, 'finance.discount-policy.change.submit', 'finance.discount-policy.change.approve'))->toBeTrue()
        ->and(ccPairCovered($school->id, 'finance.fee-schedule.change.submit', 'finance.fee-schedule.change.approve'))->toBeTrue();
});

it('ARM 2 — idempotent: a second up() changes no grant and writes no activity row', function () {
    ccPlantDrift();
    convergeMigration()->up();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $rbacBefore = DB::table('activity_log')->where('log_name', 'rbac')->count();

    convergeMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->where('log_name', 'rbac')->count())->toBe($rbacBefore);
});

it('ARM 3 — role-scoped pre-flight bites: a global role outside the five granting one of the six aborts, no grant changes', function () {
    ccPlantDrift();
    $rogue = Role::create(['name' => 'rogue_finance', 'guard_name' => 'web']);
    $rogue->givePermissionTo('finance.fee-schedule.change.submit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();

    expect(fn () => convergeMigration()->up())->toThrow(RuntimeException::class, 'rogue_finance');

    // Aborted before any write.
    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeFalse();
});

it('ARM 4 — user-scoped pre-flight bites: a user holding accounts_supervisor + head_of_school aborts the convergence, then converges once resolved', function () {
    // Plant the drift FIRST — this is the production timeline. While accounts_supervisor holds no
    // fee-schedule maker, assigning it alongside head_of_school is LEGAL (the assignment-time guard
    // passes). The convergence then ADDS the maker and retroactively creates the both-sides state —
    // exactly the hazard the assignment-time guard cannot catch, because there is no assignment.
    ccPlantDrift();

    $school = School::factory()->create();
    $dual = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $dual->grantSchoolAccess($school, 'accounts_supervisor');
    $dual->grantSchoolAccess($school, 'head_of_school');
    $dual->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    setPermissionsTeamId(null);

    $grantsBefore = DB::table('role_has_permissions')->count();

    // Granting accounts_supervisor the fee-schedule maker would give this ONE user both sides.
    expect(fn () => convergeMigration()->up())
        ->toThrow(RuntimeException::class, 'user#'.$dual->id);

    // Total rollback — no grant landed despite the give running before the guard.
    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(ccGlobalRole('accounts_supervisor')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeFalse();

    // Resolve the conflict — drop the second role — and it converges.
    setPermissionsTeamId($school->id);
    $dual->removeRole('head_of_school');
    $dual->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    setPermissionsTeamId(null);

    convergeMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(ccGlobalRole('accounts_supervisor')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue();
});
