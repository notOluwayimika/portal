<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The C1 load-bearing guard (c1-brief D2): "defined" and "seeded" are two
 * different numbers, and an enum case no role can ever hold is wallpaper.
 *
 * Every permission the enum defines must be granted to at least one WEB-guard
 * role by the seeder — except entries listed below, each carrying its
 * justification. The list is currently empty: writing this test found that the
 * two candidates (activity_log.view_system / view_cross_school) were in fact
 * granted to super_admin(web) all along, masked by the parity test's guard
 * name-collision (C1 F1), and the third (curriculum_subject.force_delete) was
 * dead in both directions and deleted. An exception that stops being true
 * fails from the other direction, so the list cannot go stale silently.
 */
const INTENTIONALLY_UNMAPPED = [];

it('grants every seeded permission to at least one web-guard role', function () {
    $this->seed(DatabaseSeeder::class);

    $granted = Role::with('permissions')
        ->where('guard_name', 'web')
        ->get()
        ->flatMap(fn ($r) => $r->permissions->pluck('name'))
        ->unique()
        ->values();

    $unmapped = collect(PermissionEnum::values())
        ->diff($granted)
        ->sort()
        ->values()
        ->all();

    expect($unmapped)->toEqual(
        collect(INTENTIONALLY_UNMAPPED)->sort()->values()->all(),
        'Permissions granted to NO web-guard role (wallpaper unless documented in INTENTIONALLY_UNMAPPED): '
            .implode(', ', array_diff($unmapped, INTENTIONALLY_UNMAPPED)),
    );
});

it('keeps the exception list honest — every listed exception is truly role-less yet seeded', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (INTENTIONALLY_UNMAPPED as $name) {
        $holders = Role::where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->where('name', $name))
            ->pluck('name');

        expect($holders)->toBeEmpty(
            "'{$name}' is listed as intentionally unmapped but is granted to: {$holders->implode(', ')} — remove it from the exception list.",
        );

        expect(Permission::where('name', $name)->exists())->toBeTrue(
            "'{$name}' is listed as an exception but is not seeded at all — stale entry.",
        );
    }
});

it('never duplicates a (name, guard, team) role row', function () {
    $this->seed(DatabaseSeeder::class);
    // Re-run to prove idempotency at the row level too.
    (new Database\Seeders\RbacSeeder)->run();

    $dupes = Role::query()
        ->selectRaw('name, guard_name, school_id, COUNT(*) as n')
        ->groupBy('name', 'guard_name', 'school_id')
        ->havingRaw('COUNT(*) > 1')
        ->get();

    expect($dupes)->toBeEmpty(
        'Duplicate role rows (NULL team defeats the MySQL unique index): '
            .$dupes->map(fn ($d) => "{$d->name}/{$d->guard_name}")->implode(', '),
    );
});

it('never seeds maker and checker to the same role (ADR 0044 / ADR 0040 SoD)', function () {
    $this->seed(DatabaseSeeder::class);

    $offenders = Role::where('guard_name', 'web')
        ->whereHas('permissions', fn ($q) => $q->where('name', 'result.submit'))
        ->whereHas('permissions', fn ($q) => $q->whereIn('name', ['result.approve', 'result.reject']))
        ->pluck('name');

    expect($offenders)->toBeEmpty(
        'Roles holding BOTH result.submit (maker) and approve/reject (checker) — defeats SoD: '
            .$offenders->implode(', '),
    );
});

it('is non-destructive on re-run: runtime grant and revoke edits survive rbac sync', function () {
    $this->seed(DatabaseSeeder::class);

    $teacher = Role::where('name', 'teacher')->where('guard_name', 'web')->first();

    // Runtime edits a super admin might make in the matrix:
    $teacher->givePermissionTo('guardian.view');          // grant not in the map
    $teacher->revokePermissionTo('student_subject.view'); // revoke of a mapped grant

    (new Database\Seeders\RbacSeeder)->run();

    $names = $teacher->fresh()->permissions->pluck('name');

    expect($names)->toContain('guardian.view')
        ->and($names)->not->toContain('student_subject.view');
});
