<?php

// 2026_08_02_100000_realign_finance_governance_grants — THE FIRST TEST THIS MIGRATION HAS EVER HAD.
//
// It shipped unarmed on 2026-08-01 and stayed unarmed through three later convergence migrations that
// all copied its shape. That mattered on 2026-08-04: the seat move made it abort, and because it is
// FIRST in filename order it is the migration that actually stopped `migrate` — while the suite
// reported six red arms in two other files and said nothing at all about this one. An unarmed
// migration is not a passing migration; it is an unwatched one.
//
// Under ADR 0052 its target is frozen at its adding commit (`f143b40`, 2026-08-01) and every abort it
// carried is now a report or a skip. The arms below pin both halves: what it converges, and that the
// surprises it used to die on now leave the database in exactly the state they found it.

use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** One reused migration instance (the file returns `new class`; require caches per process). */
function rfgMigration(): object
{
    static $m = null;
    if ($m === null) {
        $m = require database_path('migrations/2026_08_02_100000_realign_finance_governance_grants.php');
    }

    return $m;
}

function rfgGlobalRole(string $name): ?Role
{
    return Role::where('name', $name)->where('guard_name', 'web')->whereNull('school_id')->first();
}

/** The role's grants inside the two governed namespaces, read from the PIVOT and sorted. */
function rfgNsGrants(string $name): array
{
    $role = rfgGlobalRole($name);

    if ($role === null) {
        return [];
    }

    return DB::table('role_has_permissions as rhp')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('rhp.role_id', $role->id)
        ->where(fn ($q) => $q
            ->where('p.name', 'like', 'finance.discount-policy.change.%')
            ->orWhere('p.name', 'like', 'finance.fee-schedule.change.%'))
        ->orderBy('p.name')->pluck('p.name')->all();
}

/**
 * The four checker permissions the frozen target puts on `head_of_school`, WRITTEN OUT HERE as
 * literals rather than read from the migration's own const. Two copies of the literal is the point:
 * a test that reads the constant it is checking proves only that PHP can read a constant.
 *
 * @return list<string>
 */
function rfgFrozenHosTarget(): array
{
    return [
        'finance.discount-policy.change.approve',
        'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.approve',
        'finance.fee-schedule.change.reject',
    ];
}

/**
 * Plant the production drift this migration was written for: `principal` keeps the four approve/reject
 * grants the realignment removed, and `head_of_school` keeps the two submits it removed while not
 * having gained the approve sides — the both-sides state a bare deploy would leave.
 */
function rfgPlantDrift(): void
{
    rfgGlobalRole('principal')->givePermissionTo(rfgFrozenHosTarget());

    $hos = rfgGlobalRole('head_of_school');
    foreach (rfgFrozenHosTarget() as $permission) {
        $hos->revokePermissionTo($permission);
    }
    $hos->givePermissionTo([
        'finance.discount-policy.change.submit',
        'finance.fee-schedule.change.submit',
    ]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('ARM 0 (bite-proof, runs first) — the planted drift is real, so the arms below cannot pass vacuously', function () {
    // A fresh seed already writes the realigned map, so without the plant this migration has nothing
    // to do and every arm below would be green against a no-op.
    expect(rfgNsGrants('principal'))->toBe([])
        ->and(rfgNsGrants('head_of_school'))->toBe(rfgFrozenHosTarget());

    rfgPlantDrift();

    expect(rfgNsGrants('principal'))->toBe(rfgFrozenHosTarget())
        ->and(rfgNsGrants('head_of_school'))->toBe([
            'finance.discount-policy.change.submit',
            'finance.fee-schedule.change.submit',
        ]);
});

it('ARM 1 — converges to the FROZEN target: principal to nothing, head_of_school to the four checker sides', function () {
    rfgPlantDrift();

    rfgMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Asserted against the literals above, not against self::TARGET. Under the old design this arm
    // would have asserted whatever the live seeder map happened to say today, which is exactly how a
    // 2026-08-01 act came to depend on a 2026-08-04 decision.
    expect(rfgNsGrants('principal'))->toBe([])
        ->and(rfgNsGrants('head_of_school'))->toBe(rfgFrozenHosTarget());

    // And nothing outside the two namespaces moved: HoS keeps finance.access, which this migration
    // does not govern.
    expect(rfgGlobalRole('head_of_school')->fresh()->hasPermissionTo('finance.access'))->toBeTrue();
});

it('ARM 2 — idempotent: a second up() changes no grant and writes no activity row', function () {
    rfgPlantDrift();
    rfgMigration()->up();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $rbacBefore = DB::table('activity_log')->where('log_name', 'rbac')->count();
    $maxIdBefore = DB::table('activity_log')->max('id');

    rfgMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->where('log_name', 'rbac')->count())->toBe($rbacBefore)
        // MAX(id) unmoved, not just the count: a written-then-deleted row leaves the count equal.
        ->and(DB::table('activity_log')->max('id'))->toBe($maxIdBefore)
        ->and(rfgNsGrants('head_of_school'))->toBe(rfgFrozenHosTarget());
});

it('ARM 3 — a global role outside the allow-list is REPORTED, not fatal, and the realignment still runs', function () {
    // THE ARM THIS MIGRATION MOST NEEDED AND NEVER HAD. This is the exact shape that bricked
    // `migrate` on 2026-08-04: a new global role (executive_director) holding one of the six
    // governed permissions by design. Under ADR 0052 it must report and continue — this migration
    // governs principal and head_of_school and cannot touch a third role, so an outside holder is
    // information, never danger.
    rfgPlantDrift();

    $rogue = Role::create(['name' => 'rogue_platform', 'guard_name' => 'web']);
    $rogue->givePermissionTo('finance.fee-schedule.change.approve');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    rfgMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('REPORT')
        ->and($output)->toContain('rogue_platform')
        // ...and the realignment completed anyway. A "report" that quietly returned early would
        // leave head_of_school holding both sides, which is the state this migration exists to end.
        ->and(rfgNsGrants('head_of_school'))->toBe(rfgFrozenHosTarget())
        ->and(rfgNsGrants('principal'))->toBe([])
        // The outside role keeps its grant — untouchable by construction.
        ->and($rogue->fresh()->hasPermissionTo('finance.fee-schedule.change.approve'))->toBeTrue();
});

it('ARM 4 — a missing governed role is SKIPPED and reported; the other governed role still converges', function () {
    rfgPlantDrift();

    // Snapshot AFTER the delete: the cascade that drops principal's own grant rows is this arm's
    // setup, not the migration's doing.
    rfgGlobalRole('principal')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    rfgMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('principal')
        // The skip is per-role, not a bail-out: head_of_school still converged. That is the half that
        // distinguishes "skip and continue" from the abort this replaced.
        ->and(rfgNsGrants('head_of_school'))->toBe(rfgFrozenHosTarget());
});

it('ARM 5 — a target permission with no row is SKIPPED and reported, and nothing is written', function () {
    // A frozen target names permissions by string. If the enum drops one, the row goes with it, and
    // the migration must not die on a 2026-08-01 act because 2026-08-20 renamed a case.
    rfgPlantDrift();

    Permission::query()->where('name', 'finance.fee-schedule.change.approve')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    rfgMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('finance.fee-schedule.change.approve')
        // The other three still land — the skip is per-permission.
        ->and(rfgNsGrants('head_of_school'))->toBe([
            'finance.discount-policy.change.approve',
            'finance.discount-policy.change.reject',
            'finance.fee-schedule.change.reject',
        ]);
});

it('ARM 6 — fresh install (no finance-change permission rows) is a quiet green, not a throw', function () {
    // `migrate` runs before any seeding, so on migrate-from-zero none of the six exist. This guard is
    // the only reason RefreshDatabase's own migrate succeeds, which makes it load-bearing for the
    // whole suite rather than for this arm.
    Permission::query()->where('name', 'like', 'finance.discount-policy.change.%')->delete();
    Permission::query()->where('name', 'like', 'finance.fee-schedule.change.%')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $logMaxBefore = DB::table('activity_log')->max('id');

    // Returning at all is half the assertion: a throw here fails the arm.
    rfgMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->max('id'))->toBe($logMaxBefore);
});

it('ARM 7 — school-scoped rows are C6 local authority: counted, reported, never written', function () {
    rfgPlantDrift();

    // The same role NAME at school scope. Inserted raw because spatie's Role::create refuses a
    // duplicate (name, guard) regardless of team, while the table's unique index is on
    // (IFNULL(school_id,0), name, guard_name) and permits this row. Same name on purpose: it proves
    // the migration filters on `school_id IS NULL` and not on the role name.
    $school = School::factory()->create();
    $scopedId = DB::table('roles')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'head_of_school',
        'guard_name' => 'web',
        'school_id' => $school->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_has_permissions')->insert(
        Permission::query()->whereIn('name', ['finance.fee-schedule.change.submit', 'finance.discount-policy.change.submit'])
            ->where('guard_name', 'web')
            ->pluck('id')->map(fn (int $id) => ['role_id' => $scopedId, 'permission_id' => $id])->all()
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $scopedBefore = DB::table('role_has_permissions')->where('role_id', $scopedId)->orderBy('permission_id')->pluck('permission_id')->all();
    expect($scopedBefore)->toHaveCount(2);

    rfgMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(DB::table('role_has_permissions')->where('role_id', $scopedId)->orderBy('permission_id')->pluck('permission_id')->all())
        ->toBe($scopedBefore)
        // ...while the GLOBAL row of the same name WAS converged, so this cannot pass on a migration
        // that did nothing at all.
        ->and(rfgNsGrants('head_of_school'))->toBe(rfgFrozenHosTarget());
});
