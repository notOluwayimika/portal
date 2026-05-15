<?php

use App\Models\Curriculum;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuardianAccountCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'guardian', 'guard_name' => 'web']);
    Notification::fake();
});

function makeAdmin(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole('admin');
    return [$school, $admin];
}

function curriculumFor(School $school): Curriculum
{
    // Adjust factory chain to match real Curriculum requirements;
    // kept terse here because shape depends on the project's CurriculumFactory.
    return Curriculum::factory()->create(['school_id' => $school->id]);
}

function basePayload(int $curriculumId, array $guardians): array
{
    return [
        'first_name'    => 'Test',
        'last_name'     => 'Student',
        'gender'        => 'male',
        'curriculum_id' => $curriculumId,
        'guardians'     => $guardians,
    ];
}

it('registers a student with one new guardian (Case A)', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    $payload = basePayload($curriculum->id, [[
        'mode'         => 'new',
        'relationship' => 'father',
        'is_primary'   => true,
        'can_login'    => true,
        'first_name'   => 'John',
        'last_name'    => 'Doe',
        'phone'        => '08011112222',
        'email'        => 'john.doe@example.test',
    ]]);

    $response = $this->actingAs($admin)->postJson('/api/students', $payload);
    $response->assertCreated();

    $guardianUser = User::where('email', 'john.doe@example.test')->first();
    expect($guardianUser)->not->toBeNull();
    expect($guardianUser->hasRole('guardian'))->toBeTrue();

    $guardian = Guardian::where('user_id', $guardianUser->id)->first();
    expect($guardian)->not->toBeNull();

    $student = Student::where('first_name', 'Test')->where('last_name', 'Student')->first();
    expect($student)->not->toBeNull();

    $pivot = DB::table('guardian_student')
        ->where('student_id', $student->id)
        ->where('guardian_id', $guardian->id)
        ->first();

    expect($pivot)->not->toBeNull();
    expect($pivot->relationship)->toBe('father');
    expect((bool) $pivot->is_primary)->toBeTrue();
    expect((bool) $pivot->can_login)->toBeTrue();

    Notification::assertSentTo($guardianUser, GuardianAccountCreatedNotification::class);
});

it('registers a student with an existing guardian (Case B) without creating a duplicate', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    // Pre-existing guardian linked to a sibling.
    $sibling          = Student::factory()->create(['school_id' => $school->id]);
    $existingUser     = User::factory()->create([
        'school_id' => $school->id,
        'email'     => 'existing.guardian@example.test',
    ]);
    $existingUser->assignRole('guardian');
    $existingGuardian = Guardian::factory()->create([
        'school_id' => $school->id,
        'user_id'   => $existingUser->id,
        'phone'     => '09099887766',
    ]);
    $sibling->guardians()->attach($existingGuardian->id, [
        'relationship' => 'mother',
        'is_primary'   => true,
        'can_login'    => false,
    ]);

    $countUsersBefore     = User::count();
    $countGuardiansBefore = Guardian::count();

    $payload = basePayload($curriculum->id, [[
        'mode'         => 'existing',
        'relationship' => 'mother',
        'is_primary'   => true,
        'can_login'    => false,
        'identifier'   => 'existing.guardian@example.test',
    ]]);

    $response = $this->actingAs($admin)->postJson('/api/students', $payload);
    $response->assertCreated();

    expect(User::count())->toBe($countUsersBefore);
    expect(Guardian::count())->toBe($countGuardiansBefore);

    $newStudent = Student::where('first_name', 'Test')->where('last_name', 'Student')->first();
    expect($newStudent)->not->toBeNull();

    $pivot = DB::table('guardian_student')
        ->where('student_id', $newStudent->id)
        ->where('guardian_id', $existingGuardian->id)
        ->first();
    expect($pivot)->not->toBeNull();
});

it('registers a student with mixed guardians (one new, one existing)', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    $sibling          = Student::factory()->create(['school_id' => $school->id]);
    $existingUser     = User::factory()->create(['school_id' => $school->id, 'email' => 'mom@example.test']);
    $existingUser->assignRole('guardian');
    $existingGuardian = Guardian::factory()->create([
        'school_id' => $school->id,
        'user_id'   => $existingUser->id,
        'phone'     => '08000000001',
    ]);
    $sibling->guardians()->attach($existingGuardian->id, [
        'relationship' => 'mother',
        'is_primary'   => true,
        'can_login'    => false,
    ]);

    $payload = basePayload($curriculum->id, [
        [
            'mode'         => 'existing',
            'relationship' => 'mother',
            'is_primary'   => false,
            'can_login'    => false,
            'identifier'   => 'mom@example.test',
        ],
        [
            'mode'         => 'new',
            'relationship' => 'father',
            'is_primary'   => true,
            'can_login'    => true,
            'first_name'   => 'Dad',
            'last_name'    => 'Doe',
            'phone'        => '08000000002',
            'email'        => 'dad@example.test',
        ],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/students', $payload);
    $response->assertCreated();

    $student = Student::where('first_name', 'Test')->where('last_name', 'Student')->first();
    expect($student->guardians()->count())->toBe(2);
    expect(User::where('email', 'dad@example.test')->exists())->toBeTrue();
});

it('returns 404 from lookup for a guardian in another school', function () {
    [$schoolA, $adminA] = makeAdmin();
    $schoolB            = School::factory()->create();
    $userB              = User::factory()->create(['school_id' => $schoolB->id, 'email' => 'other.school@example.test']);
    $guardianB          = Guardian::factory()->create([
        'school_id' => $schoolB->id,
        'user_id'   => $userB->id,
        'phone'     => '08099999999',
    ]);

    $response = $this->actingAs($adminA)
        ->getJson('/api/guardians/lookup?identifier=other.school@example.test');

    $response->assertStatus(404);
});

it('rejects registration when no guardian is marked primary', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    $payload = basePayload($curriculum->id, [[
        'mode'         => 'new',
        'relationship' => 'father',
        'is_primary'   => false,
        'can_login'    => false,
        'first_name'   => 'John',
        'last_name'    => 'Doe',
        'phone'        => '08011112222',
    ]]);

    $response = $this->actingAs($admin)->postJson('/api/students', $payload);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['guardians']);
});

it('rejects registration when more than one guardian is primary', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    $payload = basePayload($curriculum->id, [
        [
            'mode'         => 'new',
            'relationship' => 'father',
            'is_primary'   => true,
            'can_login'    => false,
            'first_name'   => 'A',
            'last_name'    => 'A',
            'phone'        => '08000000001',
        ],
        [
            'mode'         => 'new',
            'relationship' => 'mother',
            'is_primary'   => true,
            'can_login'    => false,
            'first_name'   => 'B',
            'last_name'    => 'B',
            'phone'        => '08000000002',
        ],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/students', $payload);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['guardians']);
});

it('assigns guardian role and only sends notification when can_login is true', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    $payload = basePayload($curriculum->id, [
        [
            'mode'         => 'new',
            'relationship' => 'father',
            'is_primary'   => true,
            'can_login'    => false,
            'first_name'   => 'NoLogin',
            'last_name'    => 'Parent',
            'phone'        => '08011110000',
        ],
    ]);

    $this->actingAs($admin)->postJson('/api/students', $payload)->assertCreated();

    $guardian = Guardian::where('first_name', 'NoLogin')->first();
    expect($guardian)->not->toBeNull();
    expect($guardian->user->hasRole('guardian'))->toBeTrue();

    Notification::assertNothingSentTo($guardian->user);
});

it('rolls back the student when a guardian processing failure occurs', function () {
    [$school, $admin] = makeAdmin();
    $curriculum       = curriculumFor($school);

    // First guardian is fine, second points at a non-existent existing guardian.
    $payload = basePayload($curriculum->id, [
        [
            'mode'         => 'new',
            'relationship' => 'father',
            'is_primary'   => true,
            'can_login'    => false,
            'first_name'   => 'Good',
            'last_name'    => 'Parent',
            'phone'        => '08077770000',
        ],
        [
            'mode'         => 'existing',
            'relationship' => 'mother',
            'is_primary'   => false,
            'can_login'    => false,
            'identifier'   => 'does-not-exist@nowhere.test',
        ],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/students', $payload);
    $response->assertStatus(422);

    expect(Student::where('first_name', 'Test')->exists())->toBeFalse();
    expect(Guardian::where('first_name', 'Good')->exists())->toBeFalse();
});
