<?php

// 2026_08_06_100000_move_head_of_school_finance_to_executive_director — the first REMOVAL-ONLY
// convergence. `rbac:sync` lands the ED adds on its own (a new role takes the `: $permissions`
// branch) and revokes nothing, ever, so the entire reason that migration exists is the nine
// revokes: five off head_of_school, four off accounts_supervisor.
//
// The arms below match what both predecessor convergences carry, plus the abort branch. Each one
// names the property it guards, because a convergence migration whose `down()` is a deliberate
// no-op gets exactly one chance to be right.
//
// ARM 5 is the reason this file is not just "does it converge": the migration REFUSES to create the
// executive_director role row when `rbac:sync` has not run. `two_factor_required` is applied only at
// role creation (RbacSeeder::sync's firstOrCreate defaults), so a row created here would carry the
// flag FALSE permanently, on the one seat that can approve money leaving four different ways.

use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** One reused migration instance (the file returns `new class`; require caches per process). */
function edMigration(): object
{
    static $m = null;
    if ($m === null) {
        $m = require database_path('migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php');
    }

    return $m;
}

function edGlobalRole(string $name): ?Role
{
    return Role::where('name', $name)->where('guard_name', 'web')->whereNull('school_id')->first();
}

/** The role's finance grants, read from the PIVOT and sorted — never through the permission cache. */
function edFinanceGrants(string $name): array
{
    $role = edGlobalRole($name);

    if ($role === null) {
        return [];
    }

    return DB::table('role_has_permissions as rhp')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('rhp.role_id', $role->id)
        ->where('p.name', 'like', 'finance.%')
        ->orderBy('p.name')->pluck('p.name')->all();
}

/**
 * Plant the pre-move state: put the nine grants back where they were before 2026-08-04, and take
 * them off ED. A fresh seed already writes the POST-move map, so without this the migration has
 * nothing to converge and every arm below would pass vacuously.
 *
 * @return array{head_of_school: list<string>, accounts_supervisor: list<string>}
 */
function edPlantPreMoveState(): array
{
    $hos = ['finance.access',
        'finance.fee-schedule.change.approve', 'finance.fee-schedule.change.reject',
        'finance.discount-policy.change.approve', 'finance.discount-policy.change.reject'];
    $as = ['finance.credit-note.approve', 'finance.credit-note.reject',
        'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject'];

    edGlobalRole('head_of_school')->givePermissionTo($hos);
    edGlobalRole('accounts_supervisor')->givePermissionTo($as);

    $ed = edGlobalRole('executive_director');
    foreach ($ed->permissions->pluck('name') as $permission) {
        $ed->revokePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return ['head_of_school' => $hos, 'accounts_supervisor' => $as];
}

it('ARM 0 (bite-proof, runs first) — the planted pre-move state is real, so the arms below cannot pass vacuously', function () {
    // A fresh seed writes the POST-move map: assert that first, or the plant below could be a no-op.
    expect(edFinanceGrants('head_of_school'))->toBe([])
        ->and(edFinanceGrants('executive_director'))->toHaveCount(9);

    edPlantPreMoveState();

    expect(edFinanceGrants('head_of_school'))->toHaveCount(5)
        ->and(edFinanceGrants('accounts_supervisor'))->toHaveCount(6)
        ->and(edFinanceGrants('executive_director'))->toBe([]);
});

it('ARM 1 — converges: HoS ends with no finance grant, AS with two, ED with nine', function () {
    edPlantPreMoveState();

    // principal is NOT governed by this migration and must come out unchanged — the 2026-08-04 answer
    // was that the Principal keeps finance view. It is deliberately absent from TARGET (the migration
    // says so at :121-124: "governing a role means FORCING it"), and this arm is what proves the
    // absence holds rather than merely being intended. Do not read the assertion as evidence that
    // principal belongs in TARGET — adding it there would invert the distinction the whole file rests
    // on. Captured before, compared after.
    $principalBefore = edFinanceGrants('principal');
    expect($principalBefore)->toBe(['finance.access']);

    edMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(edFinanceGrants('head_of_school'))->toBe([]);

    expect(edFinanceGrants('accounts_supervisor'))->toBe([
        'finance.access',
        'finance.fee-schedule.change.submit',
    ]);

    expect(edFinanceGrants('executive_director'))->toBe([
        'finance.access',
        'finance.credit-note.approve',
        'finance.credit-note.reject',
        'finance.discount-policy.change.approve',
        'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.approve',
        'finance.fee-schedule.change.reject',
        'finance.invoice.void-request.approve',
        'finance.invoice.void-request.reject',
    ]);

    // Stated separately from the list: an equality assertion stops being about submits the moment
    // someone edits the expected array, and "ED holds no maker side" is the property that matters.
    expect(collect(edFinanceGrants('executive_director'))->filter(fn (string $p) => str_ends_with($p, '.submit')))
        ->toBeEmpty();

    expect(edFinanceGrants('principal'))->toBe($principalBefore);
});

it('ARM 2 — idempotent: a second up() changes no grant and writes no activity row', function () {
    // MEASURED WHILE BITE-PROVING THIS ARM, and worth knowing before anyone "simplifies" the
    // migration: idempotency here is guaranteed TWICE OVER, and no single mutation turns this red.
    // Disabling the `$needsWork` short-circuit alone leaves it green, because the diff is empty on a
    // second run and an empty diff writes nothing. Making the writes unconditional
    // (revoke-all-then-give) alone leaves it green, because the short-circuit returns first. Only
    // killing BOTH produces the regression (measured: +13 activity rows). Belt and braces, not a
    // weak arm — but if you remove one of the two, this arm will no longer notice.
    edPlantPreMoveState();
    edMigration()->up();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $rbacBefore = DB::table('activity_log')->where('log_name', 'rbac')->count();
    $maxIdBefore = DB::table('activity_log')->max('id');

    edMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->where('log_name', 'rbac')->count())->toBe($rbacBefore)
        // MAX(id) unmoved, not just the count: a written-then-deleted row leaves the count equal.
        ->and(DB::table('activity_log')->max('id'))->toBe($maxIdBefore)
        ->and(edFinanceGrants('head_of_school'))->toBe([]);
});

it('ARM 3 — the audit choice: revoke+give EMITS activity rows, which syncPermissions would not', function () {
    // WHY THIS ARM EXISTS. `syncPermissions` would produce the same end state in one call and is the
    // obvious simplification for anyone tidying this migration later. It must not be used:
    // `HasPermissions::syncPermissions` detaches RAW, with no event, so its REMOVALS are invisible to
    // the rbac audit listener — and this migration's whole content is removals. Nine grants would
    // move with no audit trail of the nine that left.
    //
    // The migration therefore diffs and calls revokePermissionTo / givePermissionTo, which fire
    // PermissionDetachedEvent / PermissionAttachedEvent and reach LogRbacChange. This arm pins the
    // consequence — rows exist, and detach-side rows specifically — so the simplification cannot land
    // silently.
    edPlantPreMoveState();

    $watermark = (int) (DB::table('activity_log')->max('id') ?? 0);

    edMigration()->up();

    $events = DB::table('activity_log')->where('log_name', 'rbac')->where('id', '>', $watermark)
        ->pluck('event')->countBy();

    // NINE detach events, one per revoked grant — five off HoS, four off AS. Asserted as an exact
    // count on the `event` column, not a substring of a description: under syncPermissions this is
    // ZERO (measured), because its `$this->permissions()->detach()` fires nothing, and the arm has to
    // fail on that and not on some incidental wording.
    expect($events['permission_detached'] ?? 0)->toBe(9);

    // And the attach side still lands, so the arm cannot pass on a migration that only revokes.
    expect($events['permission_attached'] ?? 0)->toBeGreaterThan(0);
});

it('ARM 4 — school-scoped rows are counted, never written: a planted one survives byte-identical', function () {
    // C6 local authority stays put. The migration governs GLOBAL rows only (school_id IS NULL); a
    // school-scoped role row carrying the same finance grants must come out untouched, or a
    // per-school matrix edit is silently reverted by a platform migration.
    $school = School::factory()->create();

    // Deliberately the SAME NAME as a governed global role: that is what proves the migration
    // filters on `school_id IS NULL` and not on the role name. Inserted raw because spatie's
    // Role::create refuses a duplicate (name, guard) regardless of team, while the table's own
    // unique index is on (IFNULL(school_id,0), name, guard_name) and permits this row.
    $scopedId = DB::table('roles')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'head_of_school',
        'guard_name' => 'web',
        'school_id' => $school->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scopedGrants = ['finance.access', 'finance.fee-schedule.change.approve', 'finance.credit-note.approve'];
    DB::table('role_has_permissions')->insert(
        Permission::query()->whereIn('name', $scopedGrants)->where('guard_name', 'web')
            ->pluck('id')->map(fn (int $id) => ['role_id' => $scopedId, 'permission_id' => $id])->all()
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $scoped = (object) ['id' => $scopedId];

    $scopedBefore = DB::table('role_has_permissions as rhp')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('rhp.role_id', $scoped->id)
        ->orderBy('p.name')->pluck('p.name')->all();

    expect($scopedBefore)->toHaveCount(3);

    edPlantPreMoveState();
    edMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $scopedAfter = DB::table('role_has_permissions as rhp')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('rhp.role_id', $scoped->id)
        ->orderBy('p.name')->pluck('p.name')->all();

    expect($scopedAfter)->toBe($scopedBefore)
        // ...while the GLOBAL row of the same name was converged, so the arm cannot pass by the
        // migration having done nothing at all.
        ->and(edFinanceGrants('head_of_school'))->toBe([]);
});

it('ARM 5 — ED role row missing ABORTS naming rbac:sync, and writes NOTHING', function () {
    // The ordering case. `migrate` is not ordered with respect to `rbac:sync`, so on an environment
    // that migrates before syncing, the executive_director row does not exist yet. The migration
    // refuses rather than creating it: two_factor_required is applied only at role creation, so a row
    // created here would carry the flag FALSE permanently.
    //
    // "Writes nothing" is the half that matters. An abort AFTER the revokes would leave HoS stripped
    // with no seat able to approve — the worst outcome available to this change.
    $planted = edPlantPreMoveState();

    edGlobalRole('executive_director')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $logMaxBefore = DB::table('activity_log')->max('id');

    expect(fn () => edMigration()->up())->toThrow(RuntimeException::class, 'rbac:sync');

    // Nothing landed: the pre-move grants are all still where they were.
    expect(edFinanceGrants('head_of_school'))->toBe(collect($planted['head_of_school'])->sort()->values()->all())
        ->and(edFinanceGrants('accounts_supervisor'))->toHaveCount(6)
        ->and(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->max('id'))->toBe($logMaxBefore);
});

it('ARM 6 — fresh install (no finance.* permission rows) is a quiet green, not a throw', function () {
    // The guard is also on the hot path of the whole suite: RefreshDatabase migrates an empty
    // database on every run, so it is the only reason `migrate` succeeds there.
    Permission::query()->where('name', 'like', 'finance.%')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $logMaxBefore = DB::table('activity_log')->max('id');

    // Returning at all is half the assertion: a throw here fails the arm.
    edMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->max('id'))->toBe($logMaxBefore);
});

it('ARM 7 — the duty-separation walk rolls back, from a state rbac:sync cannot produce', function () {
    // ┌───────────────────────────────────────────────────────────────────────────────────────────┐
    // │ READ THIS BEFORE COUNTING THIS ARM AS COVERAGE.                                           │
    // │                                                                                            │
    // │ THIS ARM PROVES THE BRANCH EXECUTES. IT DOES NOT PROVE THE BRANCH GUARDS. The only reason  │
    // │ the throw is reachable here is edPlantPreMoveState() below, which REVOKES every            │
    // │ executive_director permission first (:80-83). `rbac:sync` never produces that state: ED is │
    // │ new in RbacSeeder::ROLES, so it takes the whole-slice branch (RbacSeeder.php:542-544) and  │
    // │ already holds all nine before `migrate` runs. `$grantedThisRun` is therefore EMPTY on      │
    // │ every real sequence, every finding is out of scope, and this abort CANNOT FIRE.            │
    // │                                                                                            │
    // │ Measured on a production-shaped throwaway database: one user holding executive_director +  │
    // │ accounts_officer produced EIGHT both-sides findings — all four ED pairs, both directions — │
    // │ every one reported as out of scope, `migrate` exit 0. See the retraction box in the        │
    // │ migration's walk comment and ADR 0052 § "what the narrowing costs".                        │
    // │                                                                                            │
    // │ What actually covers the ED direction is DutySeparation::assertAssignmentAllowed at grant  │
    // │ time (app/Models/User.php:412) — which is precisely why this pairing has to be planted raw │
    // │ below — with `finance:audit-duty-separation` as the detector for pairings predating it.    │
    // │                                                                                            │
    // │ The arm is KEPT because the throw is kept: it costs nothing and guards a path nobody has   │
    // │ enumerated, and an unexercised throw is how a future reachable path finds a broken one.    │
    // │ ARM 8 is the arm that reflects production — the run grants nothing and every finding is    │
    // │ reported rather than thrown on.                                                            │
    // └───────────────────────────────────────────────────────────────────────────────────────────┘
    //
    // The walk runs INSIDE the transaction, so a finding must leave the database exactly as it was —
    // not half-converged. Planted raw, because grant-time enforcement refuses this through the spatie
    // API: a user holding executive_director (checker on four pairs) AND accounts_officer (maker on
    // all four) in the same school.
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'accounts_officer');

    edPlantPreMoveState();

    DB::table('model_has_roles')->insert([
        'role_id' => edGlobalRole('executive_director')->id,
        'model_type' => User::class,
        'model_id' => $user->id,
        'school_id' => $school->id,
    ]);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();

    expect(fn () => edMigration()->up())
        ->toThrow(RuntimeException::class, 'ROLLED BACK');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The whole transaction reverted: HoS still holds its five, ED still holds none.
    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(edFinanceGrants('head_of_school'))->toHaveCount(5)
        ->and(edFinanceGrants('executive_director'))->toBe([]);
});

it('ARM 8 — a both-sides user this run did NOT create is REPORTED, not rolled back', function () {
    // THE NARROWING, ARMED. Here the run grants NOTHING: ED already holds its nine and AS its two, so
    // the only work left is stripping head_of_school. A revoke can only CLEAR a both-sides state,
    // never create one, so nothing this run writes can be a side of any violation — every finding is
    // out of scope by construction, which is the cleanest possible statement of the rule.
    //
    // AND THIS ARM, NOT ARM 7, IS THE PRODUCTION ONE. The setup below — ED already at its nine —
    // is exactly what `rbac:sync` leaves behind before `migrate` runs, so this is the only sequence
    // a real deploy takes. ARM 7 reaches the throw only from a state `rbac:sync` cannot produce; see
    // the box on it. Both are kept: ARM 7 keeps the retained throw exercised, this one pins what
    // actually happens.
    edPlantPreMoveState();

    // Put ED and AS back at their frozen target, leaving only the HoS strip to do.
    edGlobalRole('executive_director')->givePermissionTo([
        'finance.access',
        'finance.credit-note.approve', 'finance.credit-note.reject',
        'finance.discount-policy.change.approve', 'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.approve', 'finance.fee-schedule.change.reject',
        'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject',
    ]);
    edGlobalRole('accounts_supervisor')->revokePermissionTo([
        'finance.credit-note.approve', 'finance.credit-note.reject',
        'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $school = School::factory()->create();
    $dual = User::factory()->create(['school_id' => $school->id]);
    $dual->grantSchoolAccess($school, 'accounts_officer');
    DB::table('model_has_roles')->insert([
        'role_id' => edGlobalRole('executive_director')->id,
        'model_type' => User::class,
        'model_id' => $dual->id,
        'school_id' => $school->id,
    ]);
    $dual->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Precondition: head_of_school still has finance to strip, so the run has real work.
    expect(edFinanceGrants('head_of_school'))->toHaveCount(5);

    ob_start();
    edMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('did NOT create')
        ->and($output)->toContain("user#{$dual->id} @ school#{$school->id}")
        ->and($output)->toContain('finance:audit-duty-separation')
        // ...and the migration COMMITTED: head_of_school was stripped. A rollback would leave it
        // holding the checker sides ED now owns, which is the regression this arm exists to catch.
        ->and(edFinanceGrants('head_of_school'))->toBe([]);
});
