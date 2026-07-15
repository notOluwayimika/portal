<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds exactly the permission set declared by the Permission enum', function () {
    $this->seed(DatabaseSeeder::class);

    $seeded = Permission::orderBy('name')->pluck('name')->all();
    $enum = collect(PermissionEnum::values())->sort()->values()->all();

    expect($seeded)->toEqual($enum);
});

it('produces identical role->permission grants after the enum refactor (parity)', function () {
    $this->seed(DatabaseSeeder::class);

    $actual = Role::with('permissions')->get()
        ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
        ->sortKeys()
        ->all();

    $expected = json_decode(
        file_get_contents(base_path('tests/fixtures/rbac-grants-baseline.json')),
        true,
    );

    expect($actual)->toEqual($expected);
});
