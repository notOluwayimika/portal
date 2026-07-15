<?php

use App\Models\Export;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'activity_log.export', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function exportOwner($school): User
{
    $u = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $u->assignRole('admin');
    Role::findByName('admin')->givePermissionTo('activity_log.export');

    return $u;
}

function makeExportFor($school, User $owner): Export
{
    $export = Export::factory()->create([
        'school_id' => $school->id,
        'user_id' => $owner->id,
        'file_path' => "exports/{$school->id}/{$owner->id}/".Str::uuid().'.csv',
    ]);
    Storage::disk('local')->put($export->file_path, "ID,Description\n1,test\n");

    return $export;
}

it('lets the owner download their own export', function () {
    Storage::fake('local');
    $school = al_makeSchool();
    $owner = exportOwner($school);
    $export = makeExportFor($school, $owner);

    $this->actingAs($owner)
        ->get("/api/activity-logs/exports/{$export->uuid}")
        ->assertOk();
});

it('blocks a non-owner in the same school (IDOR closed)', function () {
    Storage::fake('local');
    $school = al_makeSchool();
    $owner = exportOwner($school);
    $export = makeExportFor($school, $owner);

    $attacker = exportOwner($school); // same school, has the permission, but not the owner

    $this->actingAs($attacker)
        ->get("/api/activity-logs/exports/{$export->uuid}")
        ->assertStatus(403);
});

it('does not resolve an export from another school (404)', function () {
    Storage::fake('local');
    $schoolA = al_makeSchool();
    $owner = exportOwner($schoolA);
    $export = makeExportFor($schoolA, $owner);

    $outsider = exportOwner(al_makeSchool());

    $this->actingAs($outsider)
        ->get("/api/activity-logs/exports/{$export->uuid}")
        ->assertStatus(404);
});

it('rejects an expired export with 410', function () {
    Storage::fake('local');
    $school = al_makeSchool();
    $owner = exportOwner($school);
    $export = Export::factory()->expired()->create([
        'school_id' => $school->id,
        'user_id' => $owner->id,
        'file_path' => "exports/{$school->id}/{$owner->id}/".Str::uuid().'.csv',
    ]);
    Storage::disk('local')->put($export->file_path, 'x');

    $this->actingAs($owner)
        ->get("/api/activity-logs/exports/{$export->uuid}")
        ->assertStatus(410);
});

it('forbids a user without the export permission', function () {
    Storage::fake('local');
    $school = al_makeSchool();
    $owner = exportOwner($school);
    $export = makeExportFor($school, $owner);

    // Has school access (via users.school_id) but no role/permission granting activity_log.export.
    $noPerm = al_makeUser($school->id);
    setPermissionsTeamId($school->id);

    $this->actingAs($noPerm)
        ->get("/api/activity-logs/exports/{$export->uuid}")
        ->assertStatus(403);
});
