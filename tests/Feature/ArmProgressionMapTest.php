<?php

use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmProgression;
use App\Models\Permission;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture — the real Y11 -> Y12 shape: Y11 has B,S,I,P,H and Y12 has B,S,I,P.
// 11H has no label match, which is exactly why an explicit map exists.
// ---------------------------------------------------------------------------

function apm_admin(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);

    $permission = Permission::where('name', 'academic_setup.manage')->where('guard_name', 'web')->first()
        ?? Permission::create(['name' => 'academic_setup.manage', 'guard_name' => 'web']);

    setPermissionsTeamId($school->id);
    $user->givePermissionTo($permission);

    return $user;
}

function apm_level(School $school, string $name, int $order, array $attrs = []): ClassLevel
{
    return ClassLevel::forceCreate(array_merge([
        'school_id' => $school->id, 'name' => $name, 'order' => $order,
    ], $attrs));
}

/** @return array<string, ClassLevelArm> */
function apm_arms(School $school, ClassLevel $level, array $labels): array
{
    $arms = [];

    foreach ($labels as $label) {
        $arms[$label] = ClassLevelArm::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => $label])->id,
        ]);
    }

    return $arms;
}

function apm_world(): array
{
    $school = al_makeSchool();
    $admin = apm_admin($school);

    $y12 = apm_level($school, 'Year 12', 12);
    $y11 = apm_level($school, 'Year 11', 11, ['next_class_level_id' => $y12->id]);

    $y11Arms = apm_arms($school, $y11, ['B', 'S', 'I', 'P', 'H']);
    $y12Arms = apm_arms($school, $y12, ['B', 'S', 'I', 'P']);

    return compact('school', 'admin', 'y11', 'y12', 'y11Arms', 'y12Arms');
}

function apm_sync(array $w, array $mappings, ?ClassLevel $level = null)
{
    $level ??= $w['y11'];

    return test()->actingAs($w['admin'])
        ->putJson("/api/class-levels/{$level->uuid}/arm-map", ['mappings' => $mappings]);
}

function apm_get(array $w, ?ClassLevel $level = null)
{
    $level ??= $w['y11'];

    return test()->actingAs($w['admin'])->getJson("/api/class-levels/{$level->uuid}/arm-map");
}

// ---------------------------------------------------------------------------
// The rule no foreign key enforces
// ---------------------------------------------------------------------------

it('REFUSES a target arm outside the level these pupils move into', function () {
    // THE LOAD-BEARING RULE. Every FK is satisfied by this write — both arms exist, both are in the
    // same school — so the database accepts it. MoveToNextYearJob then refuses the mapped target and
    // leaves the pupil UNRESOLVED with only a log line, which is a cohort that quietly does not move.
    // This validation is the only place that gap can be closed.
    $w = apm_world();
    $y9 = apm_level($w['school'], 'Year 9', 9);
    $y9Arms = apm_arms($w['school'], $y9, ['B']);

    apm_sync($w, [[
        'source_arm_id' => $w['y11Arms']['H']->uuid,
        'target_arm_id' => $y9Arms['B']->uuid,
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings.0.target_arm_id');

    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('accepts a target arm that IS in the progression target level', function () {
    // The 11H case: no 12H exists, so an explicit map is the only way to place them deliberately.
    $w = apm_world();

    apm_sync($w, [[
        'source_arm_id' => $w['y11Arms']['H']->uuid,
        'target_arm_id' => $w['y12Arms']['P']->uuid,
    ]])->assertOk();

    $row = ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->firstOrFail();
    expect((int) $row->source_class_level_arm_id)->toBe($w['y11Arms']['H']->id);
    expect((int) $row->target_class_level_arm_id)->toBe($w['y12Arms']['P']->id);
});

it('refuses the whole map for a terminal level, since there is nowhere to point', function () {
    $w = apm_world();
    $w['y11']->update(['next_class_level_id' => null]);

    apm_sync($w, [[
        'source_arm_id' => $w['y11Arms']['H']->uuid,
        'target_arm_id' => $w['y12Arms']['P']->uuid,
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings');

    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('refuses a source arm that is not an arm of this level', function () {
    $w = apm_world();

    apm_sync($w, [[
        'source_arm_id' => $w['y12Arms']['B']->uuid, // a TARGET-level arm as the source
        'target_arm_id' => $w['y12Arms']['P']->uuid,
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings.0.source_arm_id');
});

it('refuses the same source arm mapped twice, mirroring the source unique', function () {
    $w = apm_world();

    apm_sync($w, [
        ['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => $w['y12Arms']['P']->uuid],
        ['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => $w['y12Arms']['B']->uuid],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings.1.source_arm_id');

    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('refuses an arm from another school', function () {
    $w = apm_world();
    $theirSchool = al_makeSchool();
    $theirLevel = apm_level($theirSchool, 'Their Year 12', 12);
    $theirArms = apm_arms($theirSchool, $theirLevel, ['B']);

    apm_sync($w, [[
        'source_arm_id' => $w['y11Arms']['H']->uuid,
        'target_arm_id' => $theirArms['B']->uuid,
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings.0.target_arm_id');
});

// ---------------------------------------------------------------------------
// Invalidation — the failure mode nothing in the database notices
// ---------------------------------------------------------------------------

it('reports every mapping as STALE once the progression target changes', function () {
    // THE INVALIDATION CASE. The rows are untouched and every FK still holds — both arms exist, both
    // are in the same school — but they now point into a level these pupils no longer move to.
    // Nothing in the schema notices; the rollover refuses them one pupil at a time, silently.
    $w = apm_world();

    apm_sync($w, [
        ['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => $w['y12Arms']['P']->uuid],
        ['source_arm_id' => $w['y11Arms']['B']->uuid, 'target_arm_id' => $w['y12Arms']['B']->uuid],
    ])->assertOk();

    expect(apm_get($w)->json('mappings.*.is_stale'))->not->toContain(true);

    // The operator repoints Year 11 somewhere else entirely.
    $y13 = apm_level($w['school'], 'Year 13', 13);
    apm_arms($w['school'], $y13, ['A']);
    $w['y11']->update(['next_class_level_id' => $y13->id]);

    $response = apm_get($w)->assertOk();

    // Both existing rows are now stale — asserted by COUNT, so a partial detection fails.
    $stale = collect($response->json('mappings'))->where('is_stale', true);
    expect($stale)->toHaveCount(2);

    expect($response->json('warnings.0'))->toContain('Year 13');
    // And the rows are still THERE — staleness is a report, not a silent deletion.
    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(2);
});

it('clears the whole map in one action, the recovery for a changed target level', function () {
    $w = apm_world();

    apm_sync($w, [
        ['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => $w['y12Arms']['P']->uuid],
        ['source_arm_id' => $w['y11Arms']['B']->uuid, 'target_arm_id' => $w['y12Arms']['B']->uuid],
    ])->assertOk();

    test()->actingAs($w['admin'])
        ->deleteJson("/api/class-levels/{$w['y11']->uuid}/arm-map")
        ->assertOk();

    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('clearing one levels map leaves another levels rows alone', function () {
    // destroyAll is scoped to THIS level's source arms — a school-wide delete would take out a map
    // the operator never asked about.
    $w = apm_world();
    $y10 = apm_level($w['school'], 'Year 10', 10, ['next_class_level_id' => $w['y11']->id]);
    $y10Arms = apm_arms($w['school'], $y10, ['B']);

    apm_sync($w, [['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => $w['y12Arms']['P']->uuid]])->assertOk();
    apm_sync($w, [['source_arm_id' => $y10Arms['B']->uuid, 'target_arm_id' => $w['y11Arms']['B']->uuid]], $y10)->assertOk();

    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(2);

    test()->actingAs($w['admin'])
        ->deleteJson("/api/class-levels/{$w['y11']->uuid}/arm-map")
        ->assertOk();

    $remaining = ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->get();
    expect($remaining)->toHaveCount(1);
    expect((int) $remaining->first()->source_class_level_arm_id)->toBe($y10Arms['B']->id);
});

// ---------------------------------------------------------------------------
// Sync semantics and advisories
// ---------------------------------------------------------------------------

it('treats a null target as an explicit removal, not an omission', function () {
    $w = apm_world();

    apm_sync($w, [['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => $w['y12Arms']['P']->uuid]])->assertOk();
    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(1);

    apm_sync($w, [['source_arm_id' => $w['y11Arms']['H']->uuid, 'target_arm_id' => null]])->assertOk();
    expect(ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('offers only the target levels arms as choices', function () {
    // Constraining the PICKER is the primary defence; the request rule is the backstop.
    $w = apm_world();

    $labels = collect(apm_get($w)->json('target_arms'))->pluck('label')->sort()->values()->all();

    expect($labels)->toBe(['B', 'I', 'P', 'S']); // Year 12 has no H
});

it('warns under explicit_only when arms are left unmapped', function () {
    // Under round_robin an unmapped arm is normal — distribution covers it. Under explicit_only it
    // means those pupils are left unplaced, which the operator has to be told.
    $w = apm_world();
    $w['y11']->update(['arm_distribution_strategy' => 'explicit_only']);

    $warnings = apm_get($w)->json('warnings');

    expect(collect($warnings)->filter(fn ($warning) => str_contains($warning, 'unmapped')))->not->toBeEmpty();
});

it('does not warn about unmapped arms under round_robin', function () {
    $w = apm_world();

    $warnings = apm_get($w)->json('warnings');

    expect(collect($warnings)->filter(fn ($warning) => str_contains($warning, 'unmapped')))->toBeEmpty();
});

it('404s on another schools class level', function () {
    $w = apm_world();
    $foreign = apm_level(al_makeSchool(), 'Their Year 11', 11);

    apm_get($w, $foreign)->assertStatus(404);
});

it('requires the academic_setup.manage permission', function () {
    $w = apm_world();
    $outsider = User::factory()->create(['school_id' => $w['school']->id]);

    test()->actingAs($outsider)
        ->getJson("/api/class-levels/{$w['y11']->uuid}/arm-map")
        ->assertStatus(403);
});
