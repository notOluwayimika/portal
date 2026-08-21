<?php

use App\Models\ClassLevel;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use App\Models\ExamType;
use App\Models\Permission;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Services\ProgressionGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function clp_admin(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);

    $permission = Permission::where('name', 'academic_setup.manage')->where('guard_name', 'web')->first()
        ?? Permission::create(['name' => 'academic_setup.manage', 'guard_name' => 'web']);

    setPermissionsTeamId($school->id);
    $user->givePermissionTo($permission);

    return $user;
}

function clp_level(School $school, string $name, int $order, array $attrs = []): ClassLevel
{
    return ClassLevel::forceCreate(array_merge([
        'school_id' => $school->id, 'name' => $name, 'order' => $order,
    ], $attrs));
}

function clp_examType(School $school, string $name): ExamType
{
    return ExamType::create([
        'school_id' => $school->id, 'name' => $name, 'slug' => 'et-'.Str::random(8),
    ]);
}

/** Two levels, no pointers yet — a deliberately ACYCLIC starting graph. */
function clp_world(): array
{
    $school = al_makeSchool();
    $admin = clp_admin($school);
    $a = clp_level($school, 'Year 7', 1);
    $b = clp_level($school, 'Year 8', 2);

    return compact('school', 'admin', 'a', 'b');
}

function clp_put(array $w, ClassLevel $level, array $payload)
{
    return test()->actingAs($w['admin'])->putJson(
        "/api/class-levels/{$level->uuid}/progression",
        array_merge(['arm_distribution_strategy' => 'round_robin'], $payload)
    );
}

// ---------------------------------------------------------------------------
// The shared walk — both entry points, one implementation
// ---------------------------------------------------------------------------

it('rejects a pointer that WOULD close a ring, while the stored graph is still acyclic', function () {
    // THE DISTINGUISHING TEST. A -> B is saved, so the stored graph is a clean chain. The operator
    // then proposes B -> A. Nothing in the database is cyclic at this moment — the command below
    // passes on this very graph — so a checker that only ever walked PERSISTED edges would accept
    // the write and leave the ring for the rollover to discover. The request asks about the graph as
    // it WOULD BE with the candidate edge applied, which is the only question worth asking here.
    $w = clp_world();

    clp_put($w, $w['a'], ['next_class_level_id' => $w['b']->uuid])->assertOk();

    // The stored graph is acyclic — asserted, not assumed, because the whole point is that the
    // rejection below happens WITHOUT a stored cycle.
    expect(ProgressionGraph::findCycle((int) $w['school']->id))->toBeNull();
    test()->artisan('academics:validate-progression', ['--school' => $w['school']->id])->assertExitCode(0);

    $response = clp_put($w, $w['b'], ['next_class_level_id' => $w['a']->uuid]);

    $response->assertStatus(422)->assertJsonValidationErrors('next_class_level_id');
    expect($response->json('errors.next_class_level_id.0'))->toContain('Year 8')
        ->and($response->json('errors.next_class_level_id.0'))->toContain('Year 7');

    // And nothing was written.
    expect($w['b']->fresh()->next_class_level_id)->toBeNull();
});

it('the COMMAND rejects a ring that is already stored — the same walk, the other entry point', function () {
    // The command's question: the graph AS STORED. Planted directly, because the request would have
    // refused to create it — which is the division of labour the two entry points exist for.
    $w = clp_world();
    $w['a']->update(['next_class_level_id' => $w['b']->id]);
    $w['b']->update(['next_class_level_id' => $w['a']->id]);

    test()->artisan('academics:validate-progression', ['--school' => $w['school']->id])
        ->expectsOutputToContain('CYCLE')
        ->assertExitCode(1);

    expect(ProgressionGraph::findCycle((int) $w['school']->id))->not->toBeNull();
});

it('a candidate edge that CLEARS a pointer can never create a ring', function () {
    // Passing null must actually clear the edge in the walked map — if the override were skipped for
    // null, the request would evaluate a graph still holding the old edge and could refuse a change
    // that REMOVES a cycle.
    $w = clp_world();
    $w['a']->update(['next_class_level_id' => $w['b']->id]);
    $w['b']->update(['next_class_level_id' => $w['a']->id]);

    expect(ProgressionGraph::cycleIfPointed((int) $w['school']->id, (int) $w['b']->id, null))->toBeNull();

    clp_put($w, $w['b'], ['next_class_level_id' => null])->assertOk();
    expect($w['b']->fresh()->next_class_level_id)->toBeNull();
});

it('refuses a level pointing at itself, mirroring the database trigger', function () {
    $w = clp_world();

    clp_put($w, $w['a'], ['next_class_level_id' => $w['a']->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('next_class_level_id');

    expect($w['a']->fresh()->next_class_level_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// The rest of the DB-mirroring rules
// ---------------------------------------------------------------------------

it('refuses a next level from another school, mirroring the composite FK', function () {
    $w = clp_world();
    $foreign = clp_level(al_makeSchool(), 'Their Year 8', 2);

    clp_put($w, $w['a'], ['next_class_level_id' => $foreign->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('next_class_level_id');

    expect($w['a']->fresh()->next_class_level_id)->toBeNull();
});

it('refuses a distribution strategy outside the two the trigger allows', function () {
    $w = clp_world();

    clp_put($w, $w['a'], ['arm_distribution_strategy' => 'random'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('arm_distribution_strategy');
});

it('saves a valid progression and reports is_terminal for a level with no next', function () {
    $w = clp_world();
    $waec = clp_examType($w['school'], 'WAEC Grading');

    $response = clp_put($w, $w['a'], [
        'next_class_level_id' => $w['b']->uuid,
        'default_exam_type_id' => $waec->uuid,
        'arm_distribution_strategy' => 'explicit_only',
    ])->assertOk();

    expect($response->json('progression.is_terminal'))->toBeFalse();
    expect($response->json('progression.next_class_level.name'))->toBe('Year 8');
    expect($response->json('progression.arm_distribution_strategy'))->toBe('explicit_only');

    // Uuids, never ids — the frontend submits these back.
    expect($response->json('progression.default_exam_type.id'))->toBe($waec->uuid);

    expect(test()->actingAs($w['admin'])
        ->getJson("/api/class-levels/{$w['b']->uuid}/progression")
        ->json('progression.is_terminal'))->toBeTrue();
});

it('refuses a duplicate term slot, mirroring the participation unique', function () {
    $w = clp_world();

    test()->actingAs($w['admin'])
        ->postJson("/api/class-levels/{$w['a']->uuid}/participation", ['term_order' => 1])
        ->assertStatus(201);

    test()->actingAs($w['admin'])
        ->postJson("/api/class-levels/{$w['a']->uuid}/participation", ['term_order' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('term_order');

    expect(ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $w['a']->id)->count())->toBe(1);
});

it('creates participation NON-CCM in v1', function () {
    $w = clp_world();

    $response = test()->actingAs($w['admin'])
        ->postJson("/api/class-levels/{$w['a']->uuid}/participation", ['term_order' => 2])
        ->assertStatus(201);

    expect($response->json('progression.participation.0.is_ccm'))->toBeFalse();
    expect($response->json('progression.participation.0.term_order'))->toBe(2);
});

it('refuses to remove a term slot belonging to a different level', function () {
    // Nested-route integrity: both are School-scoped, so this closes the remaining same-school
    // mismatch — a slot of Year 8 cannot be deleted through Year 7's URL.
    $w = clp_world();

    test()->actingAs($w['admin'])
        ->postJson("/api/class-levels/{$w['b']->uuid}/participation", ['term_order' => 1])
        ->assertStatus(201);

    $slot = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $w['b']->id)->firstOrFail();

    test()->actingAs($w['admin'])
        ->deleteJson("/api/class-levels/{$w['a']->uuid}/participation/{$slot->uuid}")
        ->assertStatus(404);

    expect(ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)->count())->toBe(1);
});

it('syncs the exam-type set, adding and removing to match what was sent', function () {
    $w = clp_world();
    $bss = clp_examType($w['school'], 'BSS Grading');
    $waec = clp_examType($w['school'], 'WAEC Grading');

    test()->actingAs($w['admin'])
        ->putJson("/api/class-levels/{$w['a']->uuid}/exam-types", ['exam_type_ids' => [$bss->uuid, $waec->uuid]])
        ->assertOk();

    expect(ClassLevelExamType::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $w['a']->id)->count())->toBe(2);

    // A sync REPLACES: dropping one from the payload removes it.
    test()->actingAs($w['admin'])
        ->putJson("/api/class-levels/{$w['a']->uuid}/exam-types", ['exam_type_ids' => [$waec->uuid]])
        ->assertOk();

    expect(ClassLevelExamType::withoutGlobalScope(SchoolScope::class)
        ->where('class_level_id', $w['a']->id)->pluck('exam_type_id')->all())->toBe([$waec->id]);
});

it('refuses an exam type from another school, mirroring the composite FK', function () {
    $w = clp_world();
    $foreign = clp_examType(al_makeSchool(), 'Their Exam');

    test()->actingAs($w['admin'])
        ->putJson("/api/class-levels/{$w['a']->uuid}/exam-types", ['exam_type_ids' => [$foreign->uuid]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('exam_type_ids');

    expect(ClassLevelExamType::withoutGlobalScope(SchoolScope::class)->count())->toBe(0);
});

it('warns — without blocking — when a level can resolve no exam type at all', function () {
    // Allowed configuration, but MoveToNextYearJob::resolveExamType hard-stops on it and leaves every
    // pupil of the level unplaced with only a log line. The screen has to say so.
    $w = clp_world();

    $response = test()->actingAs($w['admin'])
        ->putJson("/api/class-levels/{$w['a']->uuid}/exam-types", ['exam_type_ids' => []])
        ->assertOk();

    expect($response->json('warnings'))->not->toBeEmpty();
    expect($response->json('warnings.0'))->toContain('unplaced');
});

it('404s on another schools class level rather than editing it', function () {
    $w = clp_world();
    $foreign = clp_level(al_makeSchool(), 'Their Year 7', 1);

    test()->actingAs($w['admin'])
        ->getJson("/api/class-levels/{$foreign->uuid}/progression")
        ->assertStatus(404);

    clp_put($w, $foreign, ['arm_distribution_strategy' => 'explicit_only'])->assertStatus(404);

    expect($foreign->fresh()->arm_distribution_strategy)->toBe('round_robin');
});

it('requires the academic_setup.manage permission', function () {
    $w = clp_world();
    $outsider = User::factory()->create(['school_id' => $w['school']->id]);

    test()->actingAs($outsider)
        ->getJson("/api/class-levels/{$w['a']->uuid}/progression")
        ->assertStatus(403);
});
