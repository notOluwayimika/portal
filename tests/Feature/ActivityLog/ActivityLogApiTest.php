<?php

use App\Jobs\ExportActivityLogJob;
use App\Models\Activity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLog\ActivitySensitiveService;
use App\Services\ActivityLog\ActivitySeverityService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

// C2 (role:->permission: swap): routes now authorize by GRANTS, not role
// names, so the locally-fabricated roles need the canonical grant map to
// reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

beforeEach(function () {
    foreach ([
        'activity_log.view', 'activity_log.view_all', 'activity_log.view_own',
        'activity_log.view_system', 'activity_log.view_cross_school',
        'activity_log.export', 'activity_log.view_sensitive',
    ] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    foreach (['admin', 'teacher'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function logRow(int|string $schoolId, ?User $causer = null, array $attrs = []): Activity
{
    return Activity::create(array_merge([
        'log_name' => 'guardian',
        'description' => 'did a thing',
        'event' => 'updated',
        'school_id' => $schoolId,
        'causer_type' => $causer ? User::class : null,
        'causer_id' => $causer?->id,
    ], $attrs));
}

function adminFor($school): User
{
    $u = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $u->assignRole('admin');
    Role::findByName('admin')->givePermissionTo([
        'activity_log.view', 'activity_log.view_all', 'activity_log.export', 'activity_log.view_sensitive',
    ]);

    return $u;
}

it('blocks users without activity_log.view', function () {
    $school = al_makeSchool();
    $u = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $u->assignRole('teacher');

    $this->actingAs($u)->getJson('/api/activity-logs')->assertStatus(403);
});

it('returns a paginated scoped feed', function () {
    $school = al_makeSchool();
    $admin = adminFor($school);
    logRow($school->id, $admin);
    logRow($school->id, $admin);

    $this->actingAs($admin)->getJson('/api/activity-logs')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'severity', 'causer', 'has_diff']], 'pagination' => ['total']])
        ->assertJsonPath('pagination.total', 2);
});

it('does not leak activity across schools', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $admin = adminFor($schoolA);
    logRow($schoolA->id, $admin);
    logRow($schoolB->id);

    $res = $this->actingAs($admin)->getJson('/api/activity-logs')->assertOk();
    expect($res->json('pagination.total'))->toBe(1);
});

it('restricts to own activity without view_all', function () {
    $school = al_makeSchool();
    $other = al_makeUser($school->id);
    $u = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $u->assignRole('teacher');
    Role::findByName('teacher')->givePermissionTo(['activity_log.view', 'activity_log.view_own']);

    logRow($school->id, $u);
    logRow($school->id, $other);

    $res = $this->actingAs($u)->getJson('/api/activity-logs')->assertOk();
    expect($res->json('pagination.total'))->toBe(1);
});

it('hides sensitive entries without view_sensitive', function () {
    $school = al_makeSchool();
    $u = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $u->assignRole('teacher');
    Role::findByName('teacher')->givePermissionTo(['activity_log.view', 'activity_log.view_all']);

    logRow($school->id, $u, ['log_name' => 'permissions', 'event' => 'role_assigned']);
    logRow($school->id, $u, ['log_name' => 'guardian', 'event' => 'updated']);

    $res = $this->actingAs($u)->getJson('/api/activity-logs')->assertOk();
    expect($res->json('pagination.total'))->toBe(1);
});

it('derives severity per the config map', function () {
    $sev = ActivitySeverityService::make();
    expect($sev->for('permissions', 'role_assigned'))->toBe('critical');
    expect($sev->for('auth', 'login_failed'))->toBe('warning');
    expect($sev->for('guardian', 'deleted'))->toBe('notice');
    expect($sev->for('guardian', 'updated'))->toBe('info');
});

it('masks sensitive fields in the detail diff', function () {
    $sensitive = ActivitySensitiveService::make();
    $masked = $sensitive->maskProperties(['attributes' => ['password' => 'secret', 'city' => 'Lagos']]);
    expect($masked['attributes']['password'])->toBe('***');
    expect($masked['attributes']['city'])->toBe('Lagos');
});

it('returns single activity detail with computed diff', function () {
    $school = al_makeSchool();
    $admin = adminFor($school);
    $row = logRow($school->id, $admin, [
        'properties' => ['old' => ['city' => 'Lagos'], 'attributes' => ['city' => 'Abuja']],
    ]);

    $this->actingAs($admin)->getJson("/api/activity-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.diff.0.field', 'city')
        ->assertJsonPath('data.diff.0.old', 'Lagos')
        ->assertJsonPath('data.diff.0.new', 'Abuja');
});

it('exports synchronously under 1000 rows', function () {
    $school = al_makeSchool();
    $admin = adminFor($school);
    logRow($school->id, $admin);

    $this->actingAs($admin)->get('/api/activity-logs/export')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('queues export over 1000 rows', function () {
    Bus::fake();
    $school = al_makeSchool();
    $admin = adminFor($school);
    for ($i = 0; $i < 1001; $i++) {
        logRow($school->id, $admin);
    }

    $this->actingAs($admin)->getJson('/api/activity-logs/export')
        ->assertStatus(202)
        ->assertJsonPath('queued', true);
    Bus::assertDispatched(ExportActivityLogJob::class);
});

it('returns filter options and stats', function () {
    $school = al_makeSchool();
    $admin = adminFor($school);
    logRow($school->id, $admin);

    $this->actingAs($admin)->getJson('/api/activity-logs/filters/options')
        ->assertOk()->assertJsonStructure(['data' => ['causers', 'subject_types', 'events', 'log_names']]);

    $this->actingAs($admin)->getJson('/api/activity-logs/stats')
        ->assertOk()->assertJsonStructure(['data' => ['events_today', 'by_severity', 'top_causers', 'heatmap']]);
});

it('saves and lists filter presets', function () {
    $school = al_makeSchool();
    $admin = adminFor($school);

    $this->actingAs($admin)->postJson('/api/activity-logs/saved-filters', [
        'name' => 'My preset', 'filters' => ['severity' => ['critical']],
    ])->assertStatus(201);

    $this->actingAs($admin)->getJson('/api/activity-logs/saved-filters')
        ->assertOk()
        ->assertJsonPath('data.saved.0.name', 'My preset')
        ->assertJsonStructure(['data' => ['saved', 'quick']]);
});
