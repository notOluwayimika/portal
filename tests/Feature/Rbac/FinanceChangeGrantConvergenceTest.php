<?php

// 2026_08_03_100000_converge_finance_change_grants — the grant-GAIN mirror of #186. Proves the
// convergence, that it leaves what #186 settled untouched, and that BOTH aborts bite: a global role
// outside the governed five (role-scoped), and a user wearing two conflicting hats (user-scoped —
// the production hazard a grant-map change creates by retroactively turning a legal role pair into a
// both-sides violation with no assignment-time guard to catch it).

use App\Models\Permission;
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

    // STRONGER THAN THE ASSERTION THIS REPLACED, and map-independent. It used to say principal and
    // head_of_school came out "untouched" — a comparison against whatever the live seeder map had put
    // there, which stopped being true when the 2026-08-04 seat move emptied HoS's finance slice while
    // this 2026-08-02 migration's behaviour did not change at all.
    //
    // Under the freeze the honest claim is the whole target: after up(), EVERY governed role's
    // namespace slice equals the frozen literal. Written out here as literals rather than read from
    // the migration's const — two copies is the point; a test that reads the constant it checks
    // proves only that PHP can read a constant.
    expect(ccNsGrants('principal'))->toBe([])
        ->and(ccNsGrants('head_of_school'))->toBe([
            'finance.discount-policy.change.approve',
            'finance.discount-policy.change.reject',
            'finance.fee-schedule.change.approve',
            'finance.fee-schedule.change.reject',
        ])
        ->and(ccNsGrants('accounts_officer'))->toBe([
            'finance.discount-policy.change.submit',
            'finance.fee-schedule.change.submit',
        ])
        ->and(ccNsGrants('accounts_supervisor'))->toBe(['finance.fee-schedule.change.submit'])
        ->and(ccNsGrants('finance_lead'))->toBe(['finance.discount-policy.change.submit']);

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

it('ARM 3 — a global role outside the five holding one of the six is REPORTED, not fatal, and the convergence still runs', function () {
    // This arm used to assert a throw, and it was GREEN BY ACCIDENT once `executive_director` existed:
    // it matched on 'rogue_finance' while the abort message also named ED, so it would have passed on
    // a migration that was bricking every migrate:fresh for an entirely different reason.
    //
    // Under ADR 0052's corollary it must not throw. This migration governs five named roles and cannot
    // touch a sixth, so an outside holder is INFORMATION. The arm asserts the report names the role AND
    // that the convergence completed — the second half is what stops a "report" that returns early.
    ccPlantDrift();
    $rogue = Role::create(['name' => 'rogue_finance', 'guard_name' => 'web']);
    $rogue->givePermissionTo('finance.fee-schedule.change.submit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    convergeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('REPORT')
        ->and($output)->toContain('rogue_finance')
        // ...and the drift was converged anyway.
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue()
        // The outside role keeps its grant — this migration cannot and must not touch it.
        ->and($rogue->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue();
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

it('ARM 5 — a target permission with no row is SKIPPED and reported; the rest still converge', function () {
    // A frozen target names permissions by string, so an enum case dropped later takes its row with
    // it. A 2026-08-02 act must not die because a later release renamed something (ADR 0052).
    ccPlantDrift();

    Permission::query()->where('name', 'finance.fee-schedule.change.submit')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    convergeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('finance.fee-schedule.change.submit')
        // The skip is per-permission: accounts_officer's OTHER submit still landed.
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.discount-policy.change.submit'))->toBeTrue();
});

it('ARM 6 — a missing governed role is SKIPPED and reported; the other governed roles still converge', function () {
    ccPlantDrift();

    ccGlobalRole('finance_lead')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    convergeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('finance_lead')
        // Per-role, not a bail-out — which is the whole difference from the abort this replaced.
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue();
});

it('ARM 7 — a both-sides user this run did NOT create is REPORTED, not rolled back', function () {
    // THE NARROWING, ARMED. This migration grants the three `*.change.submit` maker sides. A user
    // holding both sides of the CREDIT-NOTE pair involves neither of them — it is a both-sides state
    // that existed before this run and that this run cannot have caused. Under ADR 0052 the walk
    // reports it and commits; before the narrowing it rolled the whole migration back for it.
    //
    // ARM 4 is the other half of this pair of arms and must stay green: there the violation IS created
    // by this run's own grant of the fee-schedule maker, so it still throws and still rolls back.
    ccPlantDrift();

    $school = School::factory()->create();
    $dual = User::factory()->create(['school_id' => $school->id]);

    // A bespoke maker seat holding ONLY finance.credit-note.submit. Deliberately not
    // accounts_officer or finance_lead: both of those also hold a `*.change.submit` that THIS RUN
    // grants, which would make the user in-scope through a second pair and prove nothing about the
    // narrowing. The credit-note pair is the one this migration touches neither side of.
    $maker = Role::create(['name' => 'credit_note_maker_only', 'guard_name' => 'web']);
    $maker->givePermissionTo('finance.credit-note.submit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Both assigned RAW: grant-time enforcement refuses this pairing through the spatie API, which is
    // exactly why a migration is the only thing that can meet it already in place.
    foreach ([$maker->id, ccGlobalRole('executive_director')->id] as $roleId) {
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $dual->id,
            'school_id' => $school->id,
        ]);
    }
    $dual->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    convergeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('did NOT create')
        ->and($output)->toContain("user#{$dual->id} @ school#{$school->id}")
        ->and($output)->toContain('finance:audit-duty-separation')
        // ...and the migration COMMITTED: the three maker grants landed. A rollback would leave them
        // stripped, which is the regression this arm exists to catch.
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue()
        ->and(ccGlobalRole('accounts_officer')->fresh()->hasPermissionTo('finance.discount-policy.change.submit'))->toBeTrue()
        ->and(ccGlobalRole('accounts_supervisor')->fresh()->hasPermissionTo('finance.fee-schedule.change.submit'))->toBeTrue();
});
