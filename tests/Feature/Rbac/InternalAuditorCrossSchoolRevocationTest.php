<?php

// 2026_08_04_100000_revoke_internal_auditor_cross_school — the REVOCATION side grantsMap() cannot
// deliver (rbac:sync revokes nothing for a role that already exists, so deleting the map line lands
// on fresh installs only). Proves the revoke, that super_admin's sanctioned holding and IA's other
// grants are untouched, that the third-holder pre-flight bites, and — the arm that proves the
// mechanism rather than the outcome — that the removal is AUDITED, i.e. revokePermissionTo was used
// and not syncPermissions, whose raw detach fires no event and would be invisible to LogRbacChange.
//
// NOTE ON THE FIXTURE. The seeded map no longer grants the permission to internal_auditor, and the
// migration runs BEFORE seeding under migrate:fresh — so in this suite IA never holds it to begin
// with. Every arm therefore PLANTS the grant first, reconstructing the already-seeded environment
// (including the production copy) that the migration exists for. A test that skipped the plant would
// pass against a role that was already clean and prove nothing.

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const IA_CROSS = 'activity_log.view_cross_school';

/** One reused migration instance (the file returns `new class`; require caches per process). */
function revokeMigration(): object
{
    static $m = null;
    if ($m === null) {
        $m = require database_path('migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php');
    }

    return $m;
}

function iaGlobalRole(string $name): Role
{
    return Role::where('name', $name)->where('guard_name', 'web')->whereNull('school_id')->firstOrFail();
}

/** A global role's full grant set, sorted — the byte-identical comparison the arms make. */
function iaGrants(string $role): array
{
    return iaGlobalRole($role)->permissions()->pluck('name')->sort()->values()->all();
}

/** Reconstruct the pre-migration environment: a0ab3d7's grant, live on internal_auditor. */
function iaPlantGrant(): void
{
    iaGlobalRole('internal_auditor')->givePermissionTo(IA_CROSS);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function iaRbacRows(): int
{
    return DB::table('activity_log')->where('log_name', 'rbac')->count();
}

it('ARM A — revokes the grant from internal_auditor and touches nothing else', function () {
    iaPlantGrant();

    $superAdminBefore = iaGrants('super_admin');
    $iaBefore = iaGrants('internal_auditor');

    expect($iaBefore)->toContain(IA_CROSS)
        ->and($superAdminBefore)->toContain(IA_CROSS);

    revokeMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $iaAfter = iaGrants('internal_auditor');

    // The grant is gone. Named explicitly so a failure reads as "the permission survived", not
    // "false is not true".
    expect($iaAfter)->not->toContain(IA_CROSS);

    // IA's other grants are byte-identical — the revoke was one permission wide, not a resync.
    expect($iaAfter)->toBe(collect($iaBefore)->reject(fn ($p) => $p === IA_CROSS)->values()->all())
        ->and($iaAfter)->toBe(['activity_log.export', 'activity_log.view']);

    // super_admin's sanctioned holding (ADR 0045 A3) is byte-identical.
    expect(iaGrants('super_admin'))->toBe($superAdminBefore)
        ->and(iaGrants('super_admin'))->toContain(IA_CROSS);
});

it('ARM B — idempotent: a second up() changes no grant and writes no activity row', function () {
    iaPlantGrant();
    revokeMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();
    $rbacBefore = iaRbacRows();

    revokeMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(iaRbacRows())->toBe($rbacBefore);
});

it('ARM C — the third-holder pre-flight bites: an unaccounted global holder aborts, no grant changes', function () {
    iaPlantGrant();

    // A global role outside {internal_auditor, super_admin} holding the permission is a grant nobody
    // has accounted for — worth more than this migration, so it aborts rather than narrowing to IA.
    $rogue = Role::create(['name' => 'rogue_platform', 'guard_name' => 'web']);
    $rogue->givePermissionTo(IA_CROSS);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grantsBefore = DB::table('role_has_permissions')->count();

    expect(fn () => revokeMigration()->up())->toThrow(RuntimeException::class, 'rogue_platform');

    // Aborted before any write: IA still holds it, and no grant row moved.
    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(iaGrants('internal_auditor'))->toContain(IA_CROSS)
        ->and(iaGrants('super_admin'))->toContain(IA_CROSS);
});

it('ARM D — the revocation is AUDITED: exactly one rbac row, a permission_detached naming it', function () {
    // This is the arm that proves the MECHANISM. syncPermissions detaches RAW with no Spatie event,
    // so LogRbacChange never sees it and a governance act would leave no trace. Only diff-based
    // revokePermissionTo produces the row asserted here.
    iaPlantGrant();

    // The window is an id watermark, not an offset: OFFSET without ORDER BY has no guaranteed row
    // order in MySQL, so `offset($countBefore)` only happened to select the new rows under InnoDB
    // primary-key order. `id > $maxId` names the rows written by up() regardless of ordering.
    $maxId = (int) DB::table('activity_log')->max('id');

    revokeMigration()->up();

    $rows = DB::table('activity_log')->where('log_name', 'rbac')
        ->where('id', '>', $maxId)->orderBy('id')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->event)->toBe('permission_detached')
        ->and(json_decode($rows->first()->properties, true)['permissions'])->toContain(IA_CROSS);
});

it('ARM E — the permission this migration governs is the isolation-crossing member all three consumers share', function () {
    // The migration, the seeded-map pin (GrantsMapSeparationTest) and the runtime matrix guard
    // (SyncRolePermissionsRequest) all read PermissionEnum::ISOLATION_CROSSING rather than the
    // string. This pins that wiring: if the constant stopped naming this permission, the migration's
    // own opening guard would abort and both other consumers would silently stop guarding it.
    //
    // The migration's second guard — which aborts if RbacSeeder::grantsMap() still grants the
    // permission to internal_auditor — is NOT pinned by any arm in this file, and that is a
    // deliberate limit rather than an unexamined one. It HAS been watched red: restoring the map
    // line in RbacSeeder.php and running ARM A aborts with "RbacSeeder::grantsMap() still grants
    // [activity_log.view_cross_school] to [internal_auditor]" (report §"The watched red", red 3).
    // The reason it is not a committed test is not that grantsMap() lacks a seam — the mutation is
    // an on-disk edit to the seeder, exactly like the two other watched reds, and a test cannot
    // carry it: a committed arm would have to rewrite a source file mid-run, and the map edit it
    // would have to undo is the very thing this change ships. So the guard is proven on scratch and
    // recorded in the report, not asserted here.
    //
    // The migration's FIRST guard (:64, the one this arm's subject feeds) is the stricter case: it
    // compares two SOURCE constants — the migration's own self::PERMISSION against
    // PermissionEnum::ISOLATION_CROSSING — so NO database state reaches it and no committed test
    // ever can. This assertion is the closest a test gets: it pins the premise, so a change that
    // would arm that guard fails HERE, by name, instead of surfacing as an abort mid-migrate.
    expect(PermissionEnum::ISOLATION_CROSSING)->toContain(IA_CROSS);
});

it('ARM F — the missing-role pre-flight bites: no global internal_auditor row aborts, no grant changes', function () {
    // Pre-flight 1 (migration:113-115). Past the fresh-install guard the RBAC substrate IS seeded, so
    // a missing global role row is a real anomaly, not a fresh database — the migration names it
    // rather than dereferencing null.
    //
    // This is the ONLY throw in the migration reachable by pure database state that ARM C does not
    // already cover: :64 and :98 both compare source constants or read grantsMap(), so they need an
    // on-disk edit (see ARM E). No plant here — the arm deletes the very role a plant would grant
    // to, and reaching :113 does not depend on IA holding anything.
    iaGlobalRole('internal_auditor')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Snapshot AFTER the delete: the cascade that removes the role's own grant rows is the arm's
    // setup, not the migration's doing. What is asserted is that up() moved nothing further.
    $grantsBefore = DB::table('role_has_permissions')->count();

    // Named by role, so a failure reads as "the missing row was not caught" rather than
    // "RuntimeException was not thrown". Watched red: commenting the null check out fails this line
    // with `Attempt to read property "permissions" on null` — exactly the dereference at :160 that
    // the guard exists to convert into a named abort.
    expect(fn () => revokeMigration()->up())
        ->toThrow(RuntimeException::class, 'global role [internal_auditor] is missing');

    // Aborted before any write, and super_admin's sanctioned holding (ADR 0045 A3) is untouched —
    // the abort is not a partial run.
    expect(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(iaGrants('super_admin'))->toContain(IA_CROSS);
});
