<?php

use App\Http\Requests\UpdateStudentCurriculumStatusRequest;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * S1 promotion-link closure — the manual-'Promoted' rule (2b) and the student_curricula_promoted_requires_link
 * CHECK (Part 4). Proofs 2, 3, 8, 10 live here; proof 1 (MoveFromCcmJob never births a promoted row) is in
 * MoveFromCcmJobTest, which owns the CCM setup. Proofs 4–7 (the backfill command) and proof 9 (drop the CHECK,
 * re-run 8) are NOT RefreshDatabase suite tests: the live CHECK forbids the promoted-with-NULL fixture the
 * backfill repairs, and dropping it is DDL that commits the wrapping transaction — the same limitation noted
 * for the fee-schedule index/trigger DDL plants. Those five are verified live (see the PR body).
 */
uses(RefreshDatabase::class);

/** @return array{0: School, 1: Student, 2: Curriculum} */
function plcSetup(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    return [$school, $student, $curriculum];
}

function plcEpisode(Student $student, Curriculum $curriculum, array $extra = []): StudentCurriculum
{
    return ActiveSchool::runFor($student->school_id, fn () => StudentCurriculum::create([
        'student_id' => $student->id, 'curriculum_id' => $curriculum->id, 'status' => 'active', ...$extra,
    ]));
}

// ── Proof 2 / 3 — the manual status route refuses 'promoted', still accepts the other three ──

it('proof 2/3 — the status rule rejects promoted (2b) and accepts active/repeated/withdrawn', function () {
    $rules = (new UpdateStudentCurriculumStatusRequest)->rules();

    // PLANT (2b): re-add 'promoted' to Rule::in → this fails-assertion reds.
    expect(Validator::make(['status' => 'promoted'], $rules)->fails())->toBeTrue();

    // Positive control (proof 3): the legitimate transitions still validate.
    foreach (['active', 'repeated', 'withdrawn'] as $status) {
        expect(Validator::make(['status' => $status], $rules)->fails())->toBeFalse();
    }
});

// ── Proof 8 — the CHECK refuses a raw status='promoted' with a NULL link ──

it('proof 8 — a raw insert of status=promoted with a NULL promoted_to_id is refused by the CHECK', function () {
    [$school, $student, $curriculum] = plcSetup();

    // PLANT (proof 9, verified live): drop student_curricula_promoted_requires_link → this insert SUCCEEDS,
    // and this proof reds. Proof 9 is what proves it is the CHECK refusing here and not the FK or a model event.
    expect(fn () => DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(), 'student_id' => $student->id, 'school_id' => $school->id,
        'curriculum_id' => $curriculum->id, 'promoted_to_id' => null, 'status' => 'promoted',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ── Proof 10 — a real promotion (promoted WITH a link) is accepted; the CHECK does not break it ──

it('proof 10 — a promoted episode carrying its link is accepted by the CHECK (real promotions unbroken)', function () {
    [$school, $student, $cFrom] = plcSetup();
    $target = plcEpisode($student, Curriculum::factory()->create(['school_id' => $school->id]));
    $from = plcEpisode($student, $cFrom);

    // The shape promote() produces — status='promoted' WITH a link — must pass (positive control).
    ActiveSchool::runFor($school->id, fn () => $from->update(['status' => 'promoted', 'promoted_to_id' => $target->id]));

    expect($from->fresh()->status->value)->toBe('promoted')
        ->and($from->fresh()->promoted_to_id)->toBe($target->id);
});

// ── B1 — the SECOND status route (PATCH /students/{uuid}/status) also refuses 'promoted' ──

function plcAdmin(School $school): User
{
    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'plc_admin', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'academic_setup.manage', 'guard_name' => 'web']);
    $role->syncPermissions(['academic_setup.manage']); // the group gating PATCH /students/{}/status
    setPermissionsTeamId(null);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'plc_admin');
    $user->flushSchoolAccessCache();

    return $user;
}

it('B-1 — PATCH /students/{uuid}/status with promoted is 422, not a 500 from the CHECK', function () {
    [$school, $student, $curriculum] = plcSetup();
    plcEpisode($student, $curriculum); // an active, link-less latest episode — what a manual 'promoted' would violate

    // PLANT (B-3): restore Rule::enum(StudentStatusEnum) without ->except([PROMOTED]) → the request validates,
    // updateStatus writes status='promoted' onto the link-less episode, the CHECK refuses it → 500. This 422
    // assertion reds.
    $this->actingAs(plcAdmin($school))->withSession(['school_id' => $school->id])
        ->patchJson("/api/students/{$student->uuid}/status", ['status' => 'promoted'])
        ->assertStatus(422);
});

it('B-2 — the same route still accepts active/repeated/withdrawn, and clears the link (unchanged)', function () {
    [$school, $student, $cFrom] = plcSetup();
    $target = plcEpisode($student, Curriculum::factory()->create(['school_id' => $school->id]));
    $latest = plcEpisode($student, $cFrom, ['status' => 'promoted', 'promoted_to_id' => $target->id]);
    $admin = plcAdmin($school);

    foreach (['active', 'repeated', 'withdrawn'] as $status) {
        $this->actingAs($admin)->withSession(['school_id' => $school->id])
            ->patchJson("/api/students/{$student->uuid}/status", ['status' => $status])
            ->assertOk();
        // Leaving 'promoted' clears the link (StudentService::updateStatus, 5b) — the transition still works.
        expect($latest->fresh()->promoted_to_id)->toBeNull();
        // Re-link for the next iteration so we are always moving OFF promoted.
        ActiveSchool::runFor($school->id, fn () => $latest->fresh()->forceFill(['status' => 'promoted', 'promoted_to_id' => $target->id])->save());
    }
});
