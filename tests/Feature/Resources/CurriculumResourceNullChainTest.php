<?php

use App\Http\Resources\CurriculumResource;
use App\Models\Curriculum;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * CurriculumResource::full_name chained six raw dereferences and guarded only
 * `stream`, so one missing link threw "Attempt to read property on null" and 500'd
 * every response carrying a curriculum — the guardian students list included. Same
 * null-deref family as the `GuardianController@students` bug.
 *
 * Deliberately a RESOURCE-LEVEL test, not an HTTP one. Driving this through the
 * endpoint needs a fully-populated curriculum (academicSession + classLevelArm +
 * classLevel + arm + examType + term), and no factory state builds one — a gap
 * already deferred as gold-plating. Rendering the resource directly exercises the
 * exact line at a fraction of the cost, and `Curriculum::factory()` leaves those
 * relations null, which IS the null path.
 */
uses(RefreshDatabase::class);

it('renders full_name instead of 500ing when the relation chain is incomplete', function () {
    $school = School::factory()->create();
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    // Every relation in the chain is absent — the worst case.
    expect($curriculum->academicSession)->toBeNull()
        ->and($curriculum->classLevelArm)->toBeNull()
        ->and($curriculum->examType)->toBeNull();

    $payload = CurriculumResource::make($curriculum)->withoutSubjects()->toArray(request());

    // Degrades to a shorter label, never a fatal — and absent parts are omitted
    // rather than left as empty strings, so no run of stray spaces.
    expect($payload['full_name'])->toBeString()
        ->and($payload['full_name'])->not->toContain('  ');
});

it('includes each present part and omits absent ones', function () {
    $school = School::factory()->create();
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id, 'is_ccm' => true]);

    // is_ccm is the one part of the chain reachable without the 5-table fixture,
    // so it is the discriminating half: proves the builder still EMITS parts and
    // has not been null-guarded into always returning an empty string.
    expect(CurriculumResource::make($curriculum)->withoutSubjects()->toArray(request())['full_name'])
        ->toBe('(CCM)');
});
