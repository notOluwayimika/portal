<?php

use App\Enums\StudentStatusEnum;
use App\Models\Curriculum;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The "Students" column on the curricula setup table.
 *
 * Two things are worth pinning rather than the happy path:
 *
 *  - ACTIVE ONLY. `promoted` enrolments are the historical record of a student who has since moved
 *    on. Counting them would report a class as fuller than it is and the number could only ever
 *    grow, so the count matches how the rest of the module reads "enrolled".
 *  - ABSENT, NOT ZERO, elsewhere. CurriculumResource renders from a dozen endpoints; the key is
 *    omitted unless the caller counted, because a default of 0 would be a lie everywhere else and
 *    counting unconditionally would put a subquery on all of them.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function csc_admin(int $schoolId): User
{
    $user = al_makeUser($schoolId);
    $user->grantSchoolAccess($user->school, 'admin');
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($schoolId);

    return $user;
}

function csc_curriculum(int $schoolId): Curriculum
{
    return ActiveSchool::runFor(
        $schoolId,
        fn () => Curriculum::factory()->create(['school_id' => $schoolId, 'status' => 'active'])
    );
}

function csc_enrol(Curriculum $curriculum, int $schoolId, StudentStatusEnum $status): StudentCurriculum
{
    return ActiveSchool::runFor($schoolId, function () use ($curriculum, $schoolId, $status) {
        $student = Student::factory()->create(['school_id' => $schoolId]);

        // A promoted episode must carry its link (student_curricula_promoted_requires_link). Give it a real
        // same-student target in a throwaway curriculum; the count under test is per-curriculum, so the
        // target (in a different curriculum) does not affect $curriculum's tally.
        $promotedTo = $status === StudentStatusEnum::PROMOTED
            ? StudentCurriculum::create([
                'student_id' => $student->id, 'school_id' => $schoolId,
                'curriculum_id' => Curriculum::factory()->create(['school_id' => $schoolId])->id,
                'status' => StudentStatusEnum::ACTIVE,
            ])->id
            : null;

        return StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $schoolId,
            'curriculum_id' => $curriculum->id,
            'status' => $status,
            'promoted_to_id' => $promotedTo,
        ]);
    });
}

/** The count the listing reports for $curriculum. */
function csc_reported(User $admin, Curriculum $curriculum): ?int
{
    $rows = test()->actingAs($admin)
        ->getJson('/api/curricula?per_page=100')
        ->assertOk()
        ->json('curricula');

    $row = collect($rows)->firstWhere('id', $curriculum->uuid);

    return $row['active_students_count'] ?? null;
}

it('reports the number of actively enrolled students', function () {
    $school = al_makeSchool();
    $admin = csc_admin($school->id);
    $curriculum = csc_curriculum($school->id);

    foreach (range(1, 3) as $ignored) {
        csc_enrol($curriculum, $school->id, StudentStatusEnum::ACTIVE);
    }

    expect(csc_reported($admin, $curriculum))->toBe(3);
});

it('reports zero for a curriculum nobody is enrolled on', function () {
    $school = al_makeSchool();
    $admin = csc_admin($school->id);

    expect(csc_reported($admin, csc_curriculum($school->id)))->toBe(0);
});

it('excludes promoted enrolments, which are history rather than registration', function () {
    $school = al_makeSchool();
    $admin = csc_admin($school->id);
    $curriculum = csc_curriculum($school->id);

    csc_enrol($curriculum, $school->id, StudentStatusEnum::ACTIVE);
    csc_enrol($curriculum, $school->id, StudentStatusEnum::PROMOTED);
    csc_enrol($curriculum, $school->id, StudentStatusEnum::PROMOTED);

    expect(csc_reported($admin, $curriculum))->toBe(1);
});

it('counts each curriculum separately', function () {
    $school = al_makeSchool();
    $admin = csc_admin($school->id);
    $busy = csc_curriculum($school->id);
    $quiet = csc_curriculum($school->id);

    csc_enrol($busy, $school->id, StudentStatusEnum::ACTIVE);
    csc_enrol($busy, $school->id, StudentStatusEnum::ACTIVE);
    csc_enrol($quiet, $school->id, StudentStatusEnum::ACTIVE);

    expect(csc_reported($admin, $busy))->toBe(2)
        ->and(csc_reported($admin, $quiet))->toBe(1);
});

it('cannot be polluted by a cross-school enrolment, because the database forbids one', function () {
    $mine = al_makeSchool();
    $theirs = al_makeSchool();
    $curriculum = csc_curriculum($mine->id);

    // The obvious way to poison this count is a `student_curricula` row on my curriculum carrying
    // another school's school_id. That is not merely scoped away — it cannot be WRITTEN: the
    // composite FK (curriculum_id, school_id) → curricula(id, school_id) rejects it, even via
    // raw SQL that skips every model and scope.
    $student = ActiveSchool::runFor(
        $theirs->id,
        fn () => Student::factory()->create(['school_id' => $theirs->id])
    );

    expect(fn () => DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(),
        'student_id' => $student->id,
        'school_id' => $theirs->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('counts only its own school\'s curricula', function () {
    $mine = al_makeSchool();
    $theirs = al_makeSchool();
    $admin = csc_admin($mine->id);

    $ours = csc_curriculum($mine->id);
    csc_enrol($ours, $mine->id, StudentStatusEnum::ACTIVE);

    $foreign = csc_curriculum($theirs->id);
    csc_enrol($foreign, $theirs->id, StudentStatusEnum::ACTIVE);
    csc_enrol($foreign, $theirs->id, StudentStatusEnum::ACTIVE);

    setPermissionsTeamId($mine->id);

    $rows = $this->actingAs($admin)
        ->getJson('/api/curricula?per_page=100')
        ->assertOk()
        ->json('curricula');

    // Their curriculum is not in the listing at all, and ours reports only our own enrolment.
    expect(collect($rows)->pluck('id'))->not->toContain($foreign->uuid)
        ->and(collect($rows)->firstWhere('id', $ours->uuid)['active_students_count'])->toBe(1);
});

it('omits the count entirely on endpoints that did not ask for it', function () {
    $school = al_makeSchool();
    $admin = csc_admin($school->id);
    $curriculum = csc_curriculum($school->id);

    csc_enrol($curriculum, $school->id, StudentStatusEnum::ACTIVE);

    $single = $this->actingAs($admin)
        ->getJson("/api/curricula/{$curriculum->uuid}")
        ->assertOk()
        ->json();

    // Absent, not 0 — the UI renders "—" for "not counted here" and would otherwise claim the
    // class is empty.
    expect($single)->not->toHaveKey('active_students_count');
});

it('adds no query per curriculum — the count is one subquery on the listing', function () {
    $school = al_makeSchool();
    $admin = csc_admin($school->id);

    foreach (range(1, 4) as $ignored) {
        $curriculum = csc_curriculum($school->id);
        csc_enrol($curriculum, $school->id, StudentStatusEnum::ACTIVE);
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $this->actingAs($admin)->getJson('/api/curricula?per_page=100')->assertOk();
    $withFour = $queries;

    foreach (range(1, 4) as $ignored) {
        $curriculum = csc_curriculum($school->id);
        csc_enrol($curriculum, $school->id, StudentStatusEnum::ACTIVE);
    }

    $queries = 0;
    $this->actingAs($admin)->getJson('/api/curricula?per_page=100')->assertOk();
    $withEight = $queries;

    // The listing has pre-existing per-curriculum queries from the resource's relations, so this
    // does not assert a flat count — it asserts that DOUBLING the curricula does not double
    // anything on account of the count. A per-row count would show up as +4 here.
    expect($withEight - $withFour)->toBeLessThan(8);
});
