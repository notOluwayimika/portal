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
    //
    // The literal list is the seat as it stands today, and it moved on 2026-09-01: the seat gained
    // `activity_log.view_all` (2026_09_01_120000). Without it `ActivityLogQueryService::baseQuery`
    // restricted the auditor to rows it caused ITSELF, which for an audit-only seat is the empty
    // set — and that is the same clause this file's own header names as what BOUNDED the
    // cross-school grant. Both halves of the pair are asserted so a resync that widened or narrowed
    // the seat reds here rather than only in the access map.
    //
    // It moved again on 2026-09-02: the seat gained `finance.invoice.approve`, the IA review
    // slice's release ability (Brookstone 31 August §2/§6). That grant is NEW rather than
    // pre-existing, so `rbac:sync` writes it and no convergence migration is owed — the literal
    // below is the only place the seat's shape is pinned by name, which is why it is updated here
    // in the same commit rather than left to be discovered by a resync.
    expect($iaAfter)->toBe(collect($iaBefore)->reject(fn ($p) => $p === IA_CROSS)->values()->all())
        ->and($iaAfter)->toBe([
            'activity_log.export',
            'activity_log.view',
            'activity_log.view_all',
            'finance.invoice.approve',
        ]);

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

it('ARM C — an unaccounted global holder is REPORTED, not fatal, and the revocation still runs', function () {
    iaPlantGrant();

    // A global role outside {internal_auditor, super_admin} holding the permission is a grant nobody
    // has accounted for. It used to abort. Under ADR 0052's corollary it must not: this migration
    // governs `internal_auditor` alone and cannot touch another role, so an outside holder is
    // INFORMATION — reported with its holder count, and the revocation carries on.
    $rogue = Role::create(['name' => 'rogue_platform', 'guard_name' => 'web']);
    $rogue->givePermissionTo(IA_CROSS);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ob_start();
    revokeMigration()->up();
    $output = (string) ob_get_clean();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($output)->toContain('REPORT')
        ->and($output)->toContain('rogue_platform')
        // ...and the act completed: IA lost it, while the two roles this migration does not govern
        // kept it. A "report" that quietly returned early would fail the second line.
        ->and(iaGrants('internal_auditor'))->not->toContain(IA_CROSS)
        ->and(iaGrants('super_admin'))->toContain(IA_CROSS)
        ->and(iaGrants('rogue_platform'))->toContain(IA_CROSS);
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
    // string. This arm pins that wiring: if the constant stopped naming this permission, both other
    // consumers would silently stop guarding it.
    //
    // WHAT CHANGED UNDER ADR 0052, because this comment described two aborts that no longer exist.
    // The migration used to carry a second guard that ABORTED if the seeder's live map still granted
    // the permission to internal_auditor. That guard is gone: whether TODAY's map re-grants it is
    // `php artisan rbac:diff-grants`'s question, not a 2026-08-02 act's, and conditioning a dated
    // act on a map that moves is the defect the ADR names. Its premise check on
    // ISOLATION_CROSSING now REPORTS rather than aborts, for the same reason — the act it performs
    // is unchanged either way.
    //
    // So this assertion no longer backstops an abort. It pins something narrower and still worth
    // pinning: the constant the OTHER two consumers depend on still names this permission. A change
    // that unwires them fails HERE, by name, rather than surfacing as a silent loss of guarding.
    expect(PermissionEnum::ISOLATION_CROSSING)->toContain(IA_CROSS);
});

it('ARM F — a missing global internal_auditor row is SKIPPED and reported, and nothing is written', function () {
    // A role row that does not exist cannot hold a grant that needs revoking. It used to abort; under
    // ADR 0052 it reports and returns cleanly. The property that actually matters is unchanged and is
    // what this arm asserts: nothing is written, and super_admin's sanctioned holding (ADR 0045 A3) is
    // untouched — a skip is not a partial run.
    //
    // Watched red for the underlying guard is still available: removing the null check fails this arm
    // with `Attempt to read property "permissions" on null` rather than a clean skip.
    iaGlobalRole('internal_auditor')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Snapshot AFTER the delete: the cascade that removes the role's own grant rows is the arm's
    // setup, not the migration's doing. What is asserted is that up() moved nothing further.
    $grantsBefore = DB::table('role_has_permissions')->count();
    $logMaxBefore = DB::table('activity_log')->max('id');

    ob_start();
    revokeMigration()->up();
    $output = (string) ob_get_clean();

    expect($output)->toContain('SKIPPED')
        ->and($output)->toContain('internal_auditor')
        ->and(DB::table('role_has_permissions')->count())->toBe($grantsBefore)
        ->and(DB::table('activity_log')->max('id'))->toBe($logMaxBefore)
        ->and(iaGrants('super_admin'))->toContain(IA_CROSS);
});
