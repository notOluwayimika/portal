<?php

use App\Enums\StudentStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Services\CurriculumEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Withdraw is a SOFT-END, never a delete. The enrollment row is the durable
 * referent §9 invoice cancellation requires (Finance FK target), and the old
 * delete cascaded behavioral/psychomotor assessments away. Terminal-status
 * vocabulary follows docs/enrollment-option-b-design.md.
 */
uses(RefreshDatabase::class);

function softEndSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    return [$school, $admin, $student, $enrollment];
}

/** Raw assessment rows tied to the enrollment — the CASCADE victims of the old delete. */
function attachAssessments(School $school, User $assessor, StudentCurriculum $enrollment): array
{
    $sessionId = DB::table('academic_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => '2026/2027', 'slug' => 's-'.Str::random(6),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $termId = DB::table('terms')->insertGetId([
        'uuid' => (string) Str::uuid(), 'academic_session_id' => $sessionId, 'school_id' => $school->id,
        'name' => 'First Term', 'slug' => 't-'.Str::random(6), 'order' => 1,
        'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $behavioralId = DB::table('behavioral_assessments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'student_curriculum_id' => $enrollment->id,
        'assessed_by' => $assessor->id, 'assessment_term_id' => $termId,
        'punctuality' => 3, 'mental_alertness' => 3, 'respect' => 3, 'neatness' => 3,
        'politeness' => 3, 'honesty' => 3, 'relationship_with_peers' => 3, 'teamwork' => 3,
        'perseverance' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $psychomotorId = DB::table('psychomotor_skills')->insertGetId([
        'uuid' => (string) Str::uuid(), 'student_curriculum_id' => $enrollment->id,
        'assessed_by' => $assessor->id, 'assessment_term_id' => $termId,
        'drawing_colouring' => 3, 'cutting_pasting' => 3, 'puzzles_building' => 3,
        'climbing_sliding' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$behavioralId, $psychomotorId];
}

it('withdrawal soft-ends: the row PERSISTS with terminal status, ended_at and ended_by', function () {
    [$school, $admin, , $enrollment] = softEndSetup();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->patchJson("/api/student-curricula/{$enrollment->uuid}", ['status' => 'withdrawn'])
        ->assertOk();

    $row = StudentCurriculum::withoutGlobalScopes()->find($enrollment->id);
    expect($row)->not->toBeNull()                                       // never deleted
        ->and($row->status)->toBe(StudentStatusEnum::WITHDRAWN)          // terminal status
        ->and($row->ended_at)->not->toBeNull()                           // ended_at populated
        ->and($row->ended_by_user_id)->toBe($admin->id);                 // ended_by populated
});

it('behavioral and psychomotor assessments SURVIVE withdrawal and remain readable', function () {
    [$school, $admin, , $enrollment] = softEndSetup();
    [$behavioralId, $psychomotorId] = attachAssessments($school, $admin, $enrollment);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->patchJson("/api/student-curricula/{$enrollment->uuid}", ['status' => 'withdrawn'])
        ->assertOk();

    // The old delete CASCADEd these away. They must survive…
    expect(DB::table('behavioral_assessments')->find($behavioralId))->not->toBeNull()
        ->and(DB::table('psychomotor_skills')->find($psychomotorId))->not->toBeNull();

    // …and still be readable THROUGH the (persisting) enrollment.
    $fresh = StudentCurriculum::withoutGlobalScopes()->find($enrollment->id);
    expect($fresh->behavioralAssessments()->count())->toBe(1)
        ->and($fresh->psychomotorSkills()->count())->toBe(1);
});

it('withdrawing an already-ended enrollment returns 409, not a second end', function () {
    [$school, $admin, , $enrollment] = softEndSetup();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->patchJson("/api/student-curricula/{$enrollment->uuid}", ['status' => 'withdrawn'])
        ->assertOk();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->patchJson("/api/student-curricula/{$enrollment->uuid}", ['status' => 'withdrawn'])
        ->assertStatus(409);
});

it('unenroll() routes through the same soft-end: terminal status is now set too', function () {
    [, $admin, , $enrollment] = softEndSetup();

    app(CurriculumEnrollmentService::class)->unenroll($enrollment, $admin, 'left the school');

    $row = $enrollment->fresh();
    expect($row->status)->toBe(StudentStatusEnum::WITHDRAWN) // previously stayed ACTIVE with ended_at set
        ->and($row->ended_at)->not->toBeNull()
        ->and($row->ended_by_user_id)->toBe($admin->id)
        ->and($row->end_reason)->toBe('left the school');
});

it('an ended enrollment is no longer the student\'s currentCurriculum', function () {
    [, $admin, $student, $enrollment] = softEndSetup();

    expect($student->fresh()->currentCurriculum?->id)->toBe($enrollment->id);

    app(CurriculumEnrollmentService::class)->unenroll($enrollment, $admin);

    // Soft-end sets the terminal status, so the hasOne(status=active) accessor
    // correctly excludes it (previously an ended row still read as "current").
    expect($student->fresh()->currentCurriculum)->toBeNull();
});

it('softEnd rejects active as a terminal status and double-ends with BusinessRuleException', function () {
    [, $admin, , $enrollment] = softEndSetup();
    $service = app(CurriculumEnrollmentService::class);

    expect(fn () => $service->softEnd($enrollment, $admin, StudentStatusEnum::ACTIVE))
        ->toThrow(InvalidArgumentException::class);

    $service->softEnd($enrollment, $admin, StudentStatusEnum::WITHDRAWN);
    expect(fn () => $service->softEnd($enrollment->fresh(), $admin, StudentStatusEnum::WITHDRAWN))
        ->toThrow(BusinessRuleException::class);
});
