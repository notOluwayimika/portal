<?php

use App\Http\Controllers\CurriculumController;
use App\Http\Requests\StudentRequest;
use App\Http\Resources\StudentCurriculumResource;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Services\StudentService;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S1 commit 5 — promotion-record durability. `promoted_to_id` is the OUTPUT of a promotion (a
 * student_curricula id), never a client attribute; a promotion link is now a same-student, same-school
 * database fact, and the two silent status-clear defects are closed. Proofs 1–7 per the brief's Part 6;
 * proof 7b lives in MoveFromCcmJobTest (it reuses that job's setup); proof 8 (the CHECK) is skipped because
 * Part 0's Q3 found 366 status='promoted' rows with a NULL link; proof 9 (down/up reversibility) was
 * verified at the CLI (SHOW CREATE TABLE round-trips) — a DDL round-trip fights RefreshDatabase's wrapping
 * transaction, so it is not a suite test.
 */
uses(RefreshDatabase::class);

/** @return array{0: School, 1: Student, 2: Curriculum} */
function pmSetup(?School $school = null): array
{
    $school ??= School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    return [$school, $student, $curriculum];
}

/** A real episode via the model (school_id derived by the creating hook). */
function pmEpisode(Student $student, Curriculum $curriculum, array $extra = []): StudentCurriculum
{
    return ActiveSchool::runFor($student->school_id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
        ...$extra,
    ]));
}

/** A RAW episode insert — bypasses the model/Action/FormRequest, so ONLY the composite FK can refuse it. */
function pmRawEpisode(int $studentId, int $schoolId, int $curriculumId, ?int $promotedToId): void
{
    DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(),
        'student_id' => $studentId,
        'school_id' => $schoolId,
        'curriculum_id' => $curriculumId,
        'promoted_to_id' => $promotedToId,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Proof 1 — the client cannot set a promotion link through student create ──

it('proof 1 — a client-supplied promoted_to_id is not persisted (removed from the write path, not corrected)', function () {
    [$school, , $curriculum] = pmSetup();
    $other = pmEpisode(Student::factory()->create(['school_id' => $school->id]), $curriculum); // a real, existing episode id

    // The rule is GONE from StudentRequest, so a submitted value cannot even reach validated().
    expect(array_keys((new StudentRequest)->rules()))->not->toContain('promoted_to_id');

    // And the store→enroll path no longer threads it. Acting-as any user (store() reads auth()->user()
    // as the enroll performer). PLANT: restore the StudentService/enroll wiring → the link persists → red.
    $actor = User::factory()->create(['school_id' => $school->id]);
    $this->actingAs($actor);
    $created = ActiveSchool::runFor($school->id, fn () => app(StudentService::class)->store([
        'school_id' => $school->id,
        'first_name' => 'Ada', 'last_name' => 'Obi', 'gender' => 'female',
        'curriculum_id' => $curriculum->id,
        'promoted_to_id' => $other->id, // a valid, existing episode id — the exact input that passed before
    ]));

    $episode = StudentCurriculum::withoutGlobalScopes()->where('student_id', $created->id)->firstOrFail();
    expect($episode->promoted_to_id)->toBeNull();
});

// ── Proof 2 — the Excel import hole is closed (the only barrier it ever passed) ──

it('proof 2 — a promoted_to_id column in an imported row never reaches the database', function () {
    [$school, , $curriculum] = pmSetup();
    $other = pmEpisode(Student::factory()->create(['school_id' => $school->id]), $curriculum);
    $this->actingAs(User::factory()->create(['school_id' => $school->id]));

    // import() → preparedDto() → StudentDto::fromArray() → store(). PLANT: restore the StudentDto property +
    // fromArray line (and the store/enroll wiring) → the sheet value reaches the DB → red.
    $result = ActiveSchool::runFor($school->id, fn () => app(StudentService::class)->import([
        ['first_name' => 'Imported', 'last_name' => 'Row', 'gender' => 'male', 'promoted_to_id' => $other->id],
    ], $curriculum->id, $school->id));

    expect($result['saved'])->toBe(1);
    $episode = StudentCurriculum::withoutGlobalScopes()
        ->whereHas('student', fn ($q) => $q->where('first_name', 'Imported'))->firstOrFail();
    expect($episode->promoted_to_id)->toBeNull();
});

// ── Proof 3 — the promote response serves the EPISODE, not a colliding curriculum ──

it('proof 3 — promoted_to resolves to the target EPISODE (its uuid), not a curriculum', function () {
    [$school, $student, $cFrom] = pmSetup();
    $cTarget = Curriculum::factory()->create(['school_id' => $school->id]);

    $new = pmEpisode($student, $cTarget);
    $from = pmEpisode($student, $cFrom, ['status' => 'promoted', 'promoted_to_id' => $new->id]);

    // If a curriculum happens to share $new->id, the wrong relation serves THAT curriculum's uuid — the exact
    // production bug (a plausible, unrelated entity); otherwise it serves null. Either way ≠ the episode uuid.
    $collidingCurriculum = Curriculum::withoutGlobalScopes()->find($new->id);

    $payload = (new StudentCurriculumResource($from->load('promotedTo')))->toArray(request());

    // PLANT: revert promotedTo() to belongsTo(Curriculum::class) → promoted_to.id is a curriculum uuid (or the
    // relation is null and this errors) — never the target episode's uuid → red.
    expect(data_get($payload, 'promoted_to.id'))->toBe($new->uuid);
    if ($collidingCurriculum) {
        expect(data_get($payload, 'promoted_to.id'))->not->toBe($collidingCurriculum->uuid);
    }
});

// ── Proof 4 — the composite FK refuses a cross-student and a cross-school link ──

it('proof 4 — a promotion link to another student, or to another school, is refused by the database', function () {
    [$schoolA, $studentA, $curriculumA] = pmSetup();
    $studentA2 = Student::factory()->create(['school_id' => $schoolA->id]);
    $targetOfA2 = pmEpisode($studentA2, $curriculumA); // an episode belonging to a DIFFERENT student, same school

    // (a) same school, different student → refused.
    expect(fn () => pmRawEpisode($studentA->id, $schoolA->id, $curriculumA->id, $targetOfA2->id))
        ->toThrow(QueryException::class);

    // (b) same student, different school → refused. Build a target episode in school B for a same-uuid... no:
    // the target is studentA's own episode but we claim school B on the source. Construct a school-B episode
    // of a school-B student and point school-A's source at it.
    [, $studentB, $curriculumB] = pmSetup();
    $targetInB = pmEpisode($studentB, $curriculumB);
    expect(fn () => pmRawEpisode($studentA->id, $schoolA->id, $curriculumA->id, $targetInB->id))
        ->toThrow(QueryException::class);

    expect(StudentCurriculum::withoutGlobalScopes()->whereNotNull('promoted_to_id')->count())->toBe(0);
});

// ── Proof 5 — an unpromoted (NULL link) row still inserts freely ─────────────

it('proof 5 — a NULL promoted_to_id passes the composite FK unconditionally (ordinary enrollment unbroken)', function () {
    [$school, $student, $curriculum] = pmSetup();

    pmRawEpisode($student->id, $school->id, $curriculum->id, null); // must NOT throw
    expect(StudentCurriculum::withoutGlobalScopes()->where('student_id', $student->id)->count())->toBe(1);
});

// ── Proof 6a — a student delete is a soft delete; episodes survive ───────────

it('proof 6a — deleting a student soft-deletes it and leaves every episode present (no cascade)', function () {
    [$school, $student, $curriculum] = pmSetup();
    $target = pmEpisode($student, $curriculum);
    $from = pmEpisode($student, Curriculum::factory()->create(['school_id' => $school->id]), ['status' => 'promoted', 'promoted_to_id' => $target->id]);

    app(StudentService::class)->delete($student);

    expect(Student::withTrashed()->find($student->id)->trashed())->toBeTrue()
        ->and(StudentCurriculum::withoutGlobalScopes()->whereKey($from->id)->exists())->toBeTrue()
        ->and(StudentCurriculum::withoutGlobalScopes()->whereKey($target->id)->exists())->toBeTrue();
});

// ── Proof 6b — RESTRICT refuses deleting a curriculum promoted INTO; the earlier episode survives ──

it('proof 6b — hard-deleting the target episode\'s curriculum is refused, and the earlier episode is untouched', function () {
    [$school, $student, $cFrom] = pmSetup();
    $cTarget = Curriculum::factory()->create(['school_id' => $school->id]);
    $target = pmEpisode($student, $cTarget);
    $from = pmEpisode($student, $cFrom, ['status' => 'promoted', 'promoted_to_id' => $target->id]);

    // The DB refuses (RESTRICT reached through the curricula→episodes CASCADE). PLANT: change the FK to
    // ON DELETE CASCADE → this deletes, and the earlier $from episode vanishes with it → the survivor assert reds.
    expect(fn () => $cTarget->delete())->toThrow(QueryException::class);
    expect(StudentCurriculum::withoutGlobalScopes()->whereKey($from->id)->exists())->toBeTrue();

    // …and the controller surfaces it as a NAMED 409, not a 500.
    $response = ActiveSchool::runFor($school->id, fn () => app(CurriculumController::class)->destroy($cTarget->fresh()));
    expect($response->getStatusCode())->toBe(409);
});

// ── Proof 6c — a curriculum with no promotion targets / subjects / invoices still deletes ──

it('proof 6c — RESTRICT narrowed the door, not welded it: an unreferenced curriculum deletes cleanly', function () {
    [$school] = pmSetup();
    $lonely = Curriculum::factory()->create(['school_id' => $school->id]);

    $response = ActiveSchool::runFor($school->id, fn () => app(CurriculumController::class)->destroy($lonely));
    expect($response->getStatusCode())->toBe(204)
        ->and(Curriculum::withoutGlobalScopes()->whereKey($lonely->id)->exists())->toBeFalse();
});

// ── Proof 7 — StudentService::updateStatus clears the link when leaving 'promoted' ──

it('proof 7 — moving a linked latest episode off promoted via StudentService::updateStatus clears the link', function () {
    [$school, $student, $cTarget] = pmSetup();
    $target = pmEpisode($student, $cTarget);
    $latest = pmEpisode($student, Curriculum::factory()->create(['school_id' => $school->id]), ['status' => 'promoted', 'promoted_to_id' => $target->id]);

    ActiveSchool::runFor($school->id, fn () => app(StudentService::class)->updateStatus($student, 'withdrawn'));

    // PLANT: revert 5b (drop the clear) → promoted_to_id stays set → red.
    expect($latest->fresh()->status->value)->toBe('withdrawn')
        ->and($latest->fresh()->promoted_to_id)->toBeNull();
});
