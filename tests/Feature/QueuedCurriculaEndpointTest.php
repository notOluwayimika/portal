<?php

use App\Jobs\MoveFromCcmJob;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Permission;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * GET /api/curricula/queued — the route that did not exist.
 *
 * CurriculumController::queuedCurriculums had been written along with the CCM and backfill screens
 * that call it, but was never routed. So the request fell through to `GET /curricula/{curriculum:uuid}`,
 * route-model binding looked for a curriculum whose uuid is the literal string "queued", and both
 * screens reported "Resource not found" — a 404 that reads like a missing record rather than a
 * missing route, which is why it survived.
 */
uses(RefreshDatabase::class);

function qc_admin(School $school): User
{
    $user = User::factory()->create(['school_id' => $school->id]);

    $permission = Permission::where('name', 'admin_area.access')->where('guard_name', 'web')->first()
        ?? Permission::create(['name' => 'admin_area.access', 'guard_name' => 'web']);

    setPermissionsTeamId($school->id);
    $user->givePermissionTo($permission);

    return $user;
}

function qc_curriculum(School $school, bool $isCcm = true): Curriculum
{
    return ActiveSchool::runFor($school->id, function () use ($school, $isCcm) {
        $level = ClassLevel::forceCreate(['school_id' => $school->id, 'name' => 'Year 3', 'order' => 3]);
        $arm = ClassLevelArm::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => 'B'])->id,
        ]);

        return Curriculum::create([
            'school_id' => $school->id,
            'term_id' => null,
            'class_level_arm_id' => $arm->id,
            'exam_type_id' => ExamType::create([
                'school_id' => $school->id, 'name' => 'Internal', 'slug' => 'et-'.Str::random(8),
            ])->id,
            'status' => 'active',
            'is_ccm' => $isCcm,
            'min_subjects' => 1,
        ]);
    });
}

/**
 * Push a REAL job onto the database queue rather than hand-writing a payload. The endpoint parses
 * `jobs.payload` with a regex against the serialised command, so a fabricated payload would prove the
 * test's own formatting, not that the endpoint reads what Laravel actually writes.
 */
function qc_queue(Curriculum $curriculum, User $user): void
{
    config(['queue.default' => 'database']);

    ActiveSchool::runFor((int) $curriculum->school_id, fn () => MoveFromCcmJob::dispatch(
        $curriculum, (int) $user->id, (int) $curriculum->school_id
    ));
}

it('resolves /api/curricula/queued to the endpoint, not to route-model binding on "queued"', function () {
    // THE REGRESSION. Before the route existed this returned 404 "Resource not found" — binding
    // hunting for a curriculum with uuid "queued". Asserting the SHAPE of the body, not just a 200,
    // because a 200 from the wrong handler would still be wrong.
    $school = al_makeSchool();
    $admin = qc_admin($school);

    $response = $this->actingAs($admin)->getJson('/api/curricula/queued');

    $response->assertOk();
    expect($response->json())->toHaveKey('curriculum_uuids');
    expect($response->json('curriculum_uuids'))->toBeArray();
});

it('returns the uuid of a curriculum with a migration job actually on the queue', function () {
    $school = al_makeSchool();
    $admin = qc_admin($school);
    $curriculum = qc_curriculum($school);

    qc_queue($curriculum, $admin);

    $response = $this->actingAs($admin)->getJson('/api/curricula/queued');

    $response->assertOk();
    expect($response->json('curriculum_uuids'))->toContain($curriculum->uuid);
});

it('omits a curriculum with nothing queued', function () {
    $school = al_makeSchool();
    $admin = qc_admin($school);
    $queued = qc_curriculum($school);
    $idle = qc_curriculum($school);

    qc_queue($queued, $admin);

    $uuids = $this->actingAs($admin)->getJson('/api/curricula/queued')->json('curriculum_uuids');

    expect($uuids)->toContain($queued->uuid)
        ->and($uuids)->not->toContain($idle->uuid);
});

it('never reports another schools queued curriculum', function () {
    // The endpoint scans the `jobs` table GLOBALLY — it has no school column to filter on — and is
    // kept honest only by resolving those ids back through the School-scoped Curriculum query.
    // Worth pinning: a future "optimisation" that returned the ids straight from the payload would
    // leak another school's curricula.
    $mine = al_makeSchool();
    $theirs = al_makeSchool();

    $myAdmin = qc_admin($mine);
    $theirAdmin = qc_admin($theirs);

    $myCurriculum = qc_curriculum($mine);
    $theirCurriculum = qc_curriculum($theirs);

    qc_queue($myCurriculum, $myAdmin);
    qc_queue($theirCurriculum, $theirAdmin);

    // Both jobs are on the queue.
    expect(Curriculum::withoutGlobalScope(SchoolScope::class)->count())->toBe(2);

    $uuids = $this->actingAs($myAdmin)->getJson('/api/curricula/queued')->json('curriculum_uuids');

    expect($uuids)->toContain($myCurriculum->uuid)
        ->and($uuids)->not->toContain($theirCurriculum->uuid);
});
