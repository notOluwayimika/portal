<?php

// `rbac:diff-grants` — the read-only reconciler for RbacSeeder::grantsMap() vs the live grants.
//
// WHAT THESE ARMS HAVE TO WORK AROUND, and why the setup looks the way it does. On a fresh seed the
// map and the database agree BY CONSTRUCTION: RbacSeeder's `$existingRoles` is empty, so every role
// takes the `: $permissions` branch (RbacSeeder.php:494-496) and receives its full set. That is
// exactly why this defect class is invisible to every existing test — so each arm below PLANTS the
// drift it wants to see, reconstructing the already-seeded environment (including the production
// copy) the command exists for.
//
// The plants are deliberately made in two flavours, and the difference IS the subject of the most
// important arm: `activity()->withoutLogs(...)` reproduces a grant that was never made / a removal
// that left no trace (the sync gaps), and a plain call reproduces a runtime mutation that
// LogRbacChange recorded (the matrix / deliberate-revoke cases). The command tells them apart from
// activity_log alone, which is the whole value of it.

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** Run the command in JSON mode; return [exitCode, decodedReport]. */
function dgRun(): array
{
    $exit = Artisan::call('rbac:diff-grants', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray("--json did not emit parseable JSON:\n".Artisan::output());

    return [$exit, $decoded];
}

function dgGlobalRole(string $name): Role
{
    return Role::where('name', $name)->where('guard_name', RbacSeeder::GUARD)->whereNull('school_id')->firstOrFail();
}

function dgForget(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** A permission the given role IS mapped to hold — derived, never hardcoded. */
function dgMappedPermission(string $role): string
{
    return RbacSeeder::grantsMap()[$role][0];
}

/** A permission the given role is NOT mapped to hold — derived, never hardcoded. */
function dgUnmappedPermission(string $role): string
{
    return array_values(array_diff(PermissionEnum::values(), RbacSeeder::grantsMap()[$role]))[0];
}

it('is clean on a freshly seeded database and exits 0', function () {
    // The baseline the other arms move away from. It also pins the construction fact above: a fresh
    // seed cannot show this defect class, which is why the lint half of this change is diff-aware.
    [$exit, $report] = dgRun();

    expect($report['catalog']['missing_rows'])->toBe([])
        ->and($report['catalog']['extra_rows'])->toBe([])
        ->and($report['roles'])->toBe([])
        ->and($report['mapped_roles_with_no_global_row'])->toBe([])
        ->and($report['unmapped_global_roles'])->toBe([])
        ->and($report['verdict'])->toBe('CLEAN')
        ->and($exit)->toBe(0);
});

it('reports a revoked mapped permission under `missing` for that role alone, and exits non-zero', function () {
    $permission = dgMappedPermission('internal_auditor');

    activity()->withoutLogs(fn () => dgGlobalRole('internal_auditor')->revokePermissionTo($permission));
    dgForget();

    [$exit, $report] = dgRun();

    expect(array_keys($report['roles']))->toBe(['internal_auditor'])
        ->and(array_column($report['roles']['internal_auditor']['missing'], 'permission'))->toBe([$permission])
        ->and($report['roles']['internal_auditor']['extra'])->toBe([])
        ->and($report['totals']['missing_grants'])->toBe(1)
        ->and($report['totals']['extra_grants'])->toBe(0)
        ->and($exit)->toBe(1);
});

it('reports a map-absent grant under `extra`, and exits non-zero', function () {
    $permission = dgUnmappedPermission('internal_auditor');

    activity()->withoutLogs(fn () => dgGlobalRole('internal_auditor')->givePermissionTo($permission));
    dgForget();

    [$exit, $report] = dgRun();

    expect(array_keys($report['roles']))->toBe(['internal_auditor'])
        ->and($report['roles']['internal_auditor']['missing'])->toBe([])
        ->and(array_column($report['roles']['internal_auditor']['extra'], 'permission'))->toBe([$permission])
        ->and($exit)->toBe(1);
});

it('THE CLASSIFICATION — a `missing` with no rbac row is the sync-ADD gap; the same pair with a permission_detached row is a deliberate revoke', function () {
    // The arm that matters most. The classification is the entire value of the command, and it is the
    // part most likely to be silently wrong: both states below look IDENTICAL in `role_has_permissions`
    // — the same role, missing the same permission. Only activity_log separates them, and only because
    // RbacSeeder::sync():432 wraps the whole of syncLogged() in activity()->withoutLogs(), so a seeder
    // run can never be the author of an rbac row. If that ever stops being true this arm is the one
    // that goes red.
    $permission = dgMappedPermission('internal_auditor');

    // (a) The grant was never made — no trace anywhere. The non-destructive-sync ADD gap.
    activity()->withoutLogs(fn () => dgGlobalRole('internal_auditor')->revokePermissionTo($permission));
    dgForget();

    [, $report] = dgRun();
    $finding = $report['roles']['internal_auditor']['missing'][0];

    expect($finding['permission'])->toBe($permission)
        ->and($finding['diagnosis'])->toBe('SYNC_ADD_GAP')
        ->and($finding['log'])->toBeNull();

    // (b) Same observable state, one logged revoke. Restore the grant silently, then revoke it FOR
    // REAL so LogRbacChange writes the permission_detached row — the diagnosis must flip, and the
    // command must show the evidence it flipped on, not just the verdict.
    activity()->withoutLogs(fn () => dgGlobalRole('internal_auditor')->givePermissionTo($permission));
    dgForget();
    dgGlobalRole('internal_auditor')->revokePermissionTo($permission);
    dgForget();

    [$exit, $report] = dgRun();
    $finding = $report['roles']['internal_auditor']['missing'][0];

    expect($finding['permission'])->toBe($permission)
        ->and($finding['diagnosis'])->toBe('DELIBERATE_REVOKE')
        ->and($finding['log'])->not->toBeNull()
        ->and($finding['log']['event'])->toBe('permission_detached')
        ->and($finding['log']['id'])->toBeInt()
        ->and($exit)->toBe(1);
});

it('classifies an `extra` with a permission_attached row as a legitimate C6 matrix grant', function () {
    // The mirror of the arm above, and the one whose verdict must NEVER become an instruction to
    // revoke: the seeder's non-destructive contract exists specifically to preserve these.
    $permission = dgUnmappedPermission('internal_auditor');

    dgGlobalRole('internal_auditor')->givePermissionTo($permission);
    dgForget();

    [$exit, $report] = dgRun();
    $finding = $report['roles']['internal_auditor']['extra'][0];

    expect($finding['diagnosis'])->toBe('MATRIX_GRANT')
        ->and($finding['log']['event'])->toBe('permission_attached')
        ->and($finding['summary'])->toContain('do not revoke')
        ->and($exit)->toBe(1);
});

it('does not diff a school-scoped role — it appears only in the footer count', function () {
    // grantsMap() has no school dimension, so a school-scoped role cannot be diffed against it without
    // inventing an expectation. This arm pins that the divergence is INVISIBLE to Sections B/C rather
    // than merely tolerated: a school-scoped row carrying a wildly different set changes nothing but
    // the footer.
    $school = School::query()->first() ?? School::factory()->create();

    // Role::query()->create(), NOT Role::create(). Spatie's static override cannot make this row at
    // all: findByParam (vendor Models/Role.php:186-188) matches `school_id IS NULL OR school_id = <team>`,
    // so an existing GLOBAL row of the same name makes it throw RoleAlreadyExists regardless of team
    // context — even though the unique index roles_team_name_guard_unique on
    // (IFNULL(school_id,0), name, guard_name) permits the pair. Eloquent's create is the honest way to
    // plant the row this arm is about; the vendor limitation is reported separately, not worked around
    // in the command.
    $scoped = Role::query()->create(['name' => 'internal_auditor', 'guard_name' => RbacSeeder::GUARD, 'school_id' => $school->id]);
    activity()->withoutLogs(fn () => $scoped->givePermissionTo(dgUnmappedPermission('internal_auditor')));
    dgForget();

    [$exit, $report] = dgRun();

    expect($report['roles'])->toBe([])
        ->and($report['unmapped_global_roles'])->toBe([])
        ->and($report['school_scoped_role_rows'])->toBe(1)
        // Exit stays 0: the footer is a COUNT, not a section, and the command has no expectation to
        // diff a school-scoped row against. It warns loudly instead. An operator command that exits
        // non-zero on a condition with no action attached is one people learn to ignore.
        ->and($exit)->toBe(0);
});

it('reports a global role that is absent from grantsMap() rather than skipping it', function () {
    // The `rogue_platform` shape. Skipping unmapped roles is exactly how an unaccounted grant would
    // survive a reconciler, so it gets its own list.
    $rogue = Role::create(['name' => 'rogue_platform', 'guard_name' => RbacSeeder::GUARD]);
    activity()->withoutLogs(fn () => $rogue->givePermissionTo(PermissionEnum::values()[0]));
    dgForget();

    [$exit, $report] = dgRun();

    expect(array_keys($report['unmapped_global_roles']))->toBe(['rogue_platform'])
        ->and($report['unmapped_global_roles']['rogue_platform']['grants'])->toBe([PermissionEnum::values()[0]])
        ->and($report['roles'])->toBe([])
        ->and($exit)->toBe(1);
});

it('reports a catalog difference, banners Section B as uninterpretable, and still exits non-zero', function () {
    // Section A is a PRE-CHECK, not a nicety: the seeder creates and prunes permission rows every run,
    // so a catalog difference means rbac:sync has not been run here — and in that state every
    // Section B `missing` may be explained by that alone.
    $permission = dgMappedPermission('internal_auditor');
    Permission::where('name', $permission)->where('guard_name', RbacSeeder::GUARD)->delete();
    dgForget();

    [$exit, $report] = dgRun();

    expect($report['catalog']['missing_rows'])->toBe([$permission])
        ->and($report['catalog_interpretable'])->toBeFalse()
        ->and($exit)->toBe(1);
});

it('--json carries the same findings as the table, and both exit the same way', function () {
    $permission = dgMappedPermission('internal_auditor');
    activity()->withoutLogs(fn () => dgGlobalRole('internal_auditor')->revokePermissionTo($permission));
    dgForget();

    [$jsonExit, $report] = dgRun();

    $tableExit = Artisan::call('rbac:diff-grants');
    $table = Artisan::output();

    expect($jsonExit)->toBe($tableExit)
        ->and($tableExit)->toBe(1)
        // The table must carry the same three facts the JSON does: the role, the permission, the verdict.
        ->and($table)->toContain('internal_auditor')
        ->and($table)->toContain($permission)
        ->and($table)->toContain('SYNC_ADD_GAP')
        ->and($report['roles']['internal_auditor']['missing'][0]['diagnosis'])->toBe('SYNC_ADD_GAP');
});

it('writes nothing — the grant rows and the activity rows are identical either side of a run', function () {
    // The non-negotiable posture, asserted rather than asserted-in-a-docblock. It is run against the
    // production copy; a reconciler that repairs is one that can destroy a legitimate C6 edit before
    // anyone has read the diff.
    //
    // ROWS, NOT COUNTS. The first version of this arm compared three count()s and called the result
    // "byte-identical", which it was not: a delete-and-reinsert of the same pivot pair, a revoke of
    // one grant paired with a grant of another, or any swap that preserves cardinality passes a
    // count comparison unchanged. The oracle below is what the title claims —
    //
    //   · the ordered ROW CONTENT of role_has_permissions and roles, so a swap is visible;
    //   · MAX(id) on activity_log as well as its count, because that is the part a count cannot
    //     fake — an insert paired with a delete holds the count still, but the auto-increment only
    //     ever goes up. Any write that fires PermissionAttached/Detached lands an activity row and
    //     moves MAX(id), so this also catches a mutation made through the Spatie API.
    $permission = dgMappedPermission('internal_auditor');
    activity()->withoutLogs(fn () => dgGlobalRole('internal_auditor')->revokePermissionTo($permission));
    dgForget();

    $snapshot = fn (): array => [
        'grants' => DB::table('role_has_permissions')
            ->orderBy('role_id')->orderBy('permission_id')->get()->toJson(),
        'roles' => DB::table('roles')->orderBy('id')->get()->toJson(),
        'permissions' => DB::table('permissions')->orderBy('id')->get()->toJson(),
        'activity_count' => DB::table('activity_log')->count(),
        'activity_max_id' => (int) DB::table('activity_log')->max('id'),
    ];

    $before = $snapshot();

    Artisan::call('rbac:diff-grants');
    Artisan::call('rbac:diff-grants', ['--json' => true]);

    expect($snapshot())->toBe($before);
});

it('the read-only oracle is not vacuous — a delete-and-reinsert of the same pivot pair moves it', function () {
    // BITE-PROOF for the arm above. A count-only oracle passes this mutation, which is exactly why
    // the arm above was strengthened; without this, a future simplification back to count()s would
    // look like a tidy-up rather than a regression. The mutation here is deliberately the SMALLEST
    // one a count cannot see: remove a pivot row and put the identical row back.
    $role = dgGlobalRole('internal_auditor');
    $permission = dgMappedPermission('internal_auditor');

    $snapshot = fn (): array => [
        'grants' => DB::table('role_has_permissions')
            ->orderBy('role_id')->orderBy('permission_id')->get()->toJson(),
        'activity_count' => DB::table('activity_log')->count(),
        'activity_max_id' => (int) DB::table('activity_log')->max('id'),
    ];

    $before = $snapshot();
    $countBefore = DB::table('role_has_permissions')->count();

    // Through the Spatie API, so it is the shape a stray write in the command would actually take.
    $role->revokePermissionTo($permission);
    $role->givePermissionTo($permission);
    dgForget();

    // The count is restored — a count-only oracle sees nothing at all here.
    expect(DB::table('role_has_permissions')->count())->toBe($countBefore);

    // The oracle the read-only arm uses does see it: the two logged events moved MAX(id).
    expect($snapshot())->not->toBe($before)
        ->and((int) DB::table('activity_log')->max('id'))->toBeGreaterThan($before['activity_max_id']);
});
