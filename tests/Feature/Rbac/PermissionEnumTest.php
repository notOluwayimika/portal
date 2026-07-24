<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds exactly the permission set declared by the Permission enum', function () {
    $this->seed(DatabaseSeeder::class);

    // Sort BOTH sides in PHP: MySQL's collation orders '.' vs '_' differently
    // from byte order, and the C2 names (result_review vs result.approve)
    // exposed the mismatch an SQL ORDER BY would reintroduce.
    $seeded = Permission::pluck('name')->sort()->values()->all();
    $enum = collect(PermissionEnum::values())->sort()->values()->all();

    expect($seeded)->toEqual($enum);
});

it('produces exactly the fixture grants map (web guard, no name-collision masking)', function () {
    $this->seed(DatabaseSeeder::class);

    // Scoped to the web guard, and keyed only after asserting name-uniqueness
    // within it: the old mapWithKeys keying collided super_admin's web and api
    // guard rows (last-wins = the empty api row), masking the web row's real
    // grants — the fixture wrongly recorded super_admin: [] for months (C1 F1).
    $webRoles = Role::with('permissions')
        ->where('guard_name', 'web')
        ->get();

    expect($webRoles->pluck('name')->duplicates()->all())
        ->toBeEmpty('duplicate web-guard role names would mask grants in this map');

    $actual = $webRoles
        ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
        ->sortKeys()
        ->all();

    $expected = json_decode(
        file_get_contents(base_path('tests/fixtures/rbac-grants-baseline.json')),
        true,
    );

    expect($actual)->toEqual($expected);
});

it('keeps the api-guard super_admin row grant-free (migration-owned guard pair)', function () {
    $this->seed(DatabaseSeeder::class);

    $api = Role::with('permissions')->where('guard_name', 'api')->get();

    expect($api->pluck('name')->all())->toEqual(['super_admin'])
        ->and($api->first()->permissions)->toBeEmpty();
});
