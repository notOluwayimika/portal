<?php

use App\Models\Guardian;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuardianAccountCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['admin', 'guardian', 'registrar'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
    foreach ([
        'guardian.view',
        'guardian.update',
        'guardian.update_credentials',
        'guardian.detach',
        'guardian.enable_login',
    ] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::findByName('admin')->givePermissionTo([
        'guardian.view', 'guardian.update', 'guardian.update_credentials', 'guardian.detach', 'guardian.enable_login',
    ]);
    Role::findByName('registrar')->givePermissionTo(['guardian.view', 'guardian.update', 'guardian.detach']);

    Notification::fake();
});

function actingAsAdmin(School $school): User
{
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');

    return $admin;
}

function actingAsRegistrar(School $school): User
{
    $reg = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    $reg->assignRole('registrar');

    return $reg;
}

function setupGuardianLinkedToTwoStudents(School $school): array
{
    $studentA = Student::factory()->create(['school_id' => $school->id]);
    $studentB = Student::factory()->create(['school_id' => $school->id]);

    $user = User::factory()->create(['school_id' => $school->id, 'email' => 'shared.guardian@example.test']);
    setPermissionsTeamId($school->id);
    $user->assignRole('guardian');
    $guardian = Guardian::factory()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'phone' => '08055555555',
    ]);

    $studentA->guardians()->attach($guardian->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => true]);
    $studentB->guardians()->attach($guardian->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => true]);

    return [$guardian, $studentA, $studentB];
}

it('updating guardian details applies to all linked students (single record)', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    [$guardian] = setupGuardianLinkedToTwoStudents($school);

    $this->actingAs($admin)
        ->putJson("/api/guardians/{$guardian->uuid}", ['occupation' => 'Engineer', 'city' => 'Lagos'])
        ->assertOk()
        ->assertJsonPath('data.affected_student_count', 2);

    $guardian->refresh();
    expect($guardian->occupation)->toBe('Engineer');
    expect($guardian->city)->toBe('Lagos');
});

it('updating pivot fields on student A does not affect student B', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    [$guardian, $studentA, $studentB] = setupGuardianLinkedToTwoStudents($school);

    $this->actingAs($admin)
        ->putJson("/api/students/{$studentA->uuid}/guardians/{$guardian->uuid}", ['relationship' => 'guardian'])
        ->assertOk();

    $pivotA = DB::table('guardian_student')->where('student_id', $studentA->id)->first();
    $pivotB = DB::table('guardian_student')->where('student_id', $studentB->id)->first();
    expect($pivotA->relationship)->toBe('guardian');
    expect($pivotB->relationship)->toBe('father');
});

it('lists all students linked to a guardian', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    [$guardian, $studentA, $studentB] = setupGuardianLinkedToTwoStudents($school);

    $res = $this->actingAs($admin)->getJson("/api/guardians/{$guardian->uuid}/students");
    $res->assertOk()->assertJsonCount(2, 'data');

    // Bug #2 null-guard: both linked students have NO current enrollment
    // (setupGuardianLinkedToTwoStudents creates bare students). Before the fix
    // this 500'd (`currentCurriculum->load()` on null); now each renders with a
    // null current_class and the list succeeds.
    foreach ($res->json('data') as $row) {
        expect($row['current_class'])->toBeNull();
    }
});

it('detaching the only guardian is blocked', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $user = User::factory()->create(['school_id' => $school->id]);
    $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $user->id]);
    $student->guardians()->attach($guardian->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => false]);

    $this->actingAs($admin)
        ->deleteJson("/api/students/{$student->uuid}/guardians/{$guardian->uuid}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['guardian_id']);
});

it('detaching primary without replacement is blocked', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $u1 = User::factory()->create(['school_id' => $school->id]);
    $g1 = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $u1->id]);
    $u2 = User::factory()->create(['school_id' => $school->id]);
    $g2 = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $u2->id]);

    $student->guardians()->attach($g1->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => false]);
    $student->guardians()->attach($g2->id, ['relationship' => 'mother', 'is_primary' => false, 'can_login' => false]);

    $this->actingAs($admin)
        ->deleteJson("/api/students/{$student->uuid}/guardians/{$g1->uuid}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['replacement_primary_guardian_uuid']);
});

it('detaching primary with valid replacement promotes the replacement', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $u1 = User::factory()->create(['school_id' => $school->id]);
    $g1 = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $u1->id]);
    $u2 = User::factory()->create(['school_id' => $school->id]);
    $g2 = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $u2->id]);

    $student->guardians()->attach($g1->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => false]);
    $student->guardians()->attach($g2->id, ['relationship' => 'mother', 'is_primary' => false, 'can_login' => false]);

    $this->actingAs($admin)
        ->deleteJson("/api/students/{$student->uuid}/guardians/{$g1->uuid}", [
            'replacement_primary_guardian_uuid' => $g2->uuid,
        ])
        ->assertStatus(204);

    $pivot = DB::table('guardian_student')->where('student_id', $student->id)->first();
    expect((bool) $pivot->is_primary)->toBeTrue();
    expect($pivot->guardian_id)->toBe($g2->id);
});

it('demoting can_login on the last pivot disables the user account', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $u1 = User::factory()->create(['school_id' => $school->id]);
    $u1->assignRole('guardian');
    $g1 = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $u1->id]);
    $u2 = User::factory()->create(['school_id' => $school->id]);
    $g2 = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $u2->id]);

    $student->guardians()->attach($g1->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => true]);
    $student->guardians()->attach($g2->id, ['relationship' => 'mother', 'is_primary' => false, 'can_login' => false]);

    $this->actingAs($admin)
        ->putJson("/api/students/{$student->uuid}/guardians/{$g1->uuid}", ['can_login' => false])
        ->assertOk();

    expect($u1->fresh()->disabled_at)->not->toBeNull();
});

it('demoting can_login on one of many pivots keeps the user active', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    [$guardian, $studentA, $studentB] = setupGuardianLinkedToTwoStudents($school);

    $this->actingAs($admin)
        ->putJson("/api/students/{$studentA->uuid}/guardians/{$guardian->uuid}", ['can_login' => false])
        ->assertOk();

    expect($guardian->user->fresh()->disabled_at)->toBeNull();
});

it('explicit enable-login on a disabled guardian re-enables and notifies', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $user = User::factory()->create([
        'school_id' => $school->id,
        'disabled_at' => now(),
    ]);
    $user->assignRole('guardian');
    $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $user->id]);
    $student->guardians()->attach($guardian->id, ['relationship' => 'father', 'is_primary' => true, 'can_login' => false]);

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/enable-login")
        ->assertOk();

    expect($user->fresh()->disabled_at)->toBeNull();
    Notification::assertSentTo($user->fresh(), GuardianAccountCreatedNotification::class);
});

it('registrar cannot change a login-enabled guardians email (credential permission required)', function () {
    $school = School::factory()->create();
    $registrar = actingAsRegistrar($school);
    [$guardian] = setupGuardianLinkedToTwoStudents($school);

    $this->actingAs($registrar)
        ->putJson("/api/guardians/{$guardian->uuid}", [
            'email' => 'new.email@example.test',
            'occupation' => 'Doctor', // allowed
        ])
        ->assertOk();

    $guardian->refresh()->load('user');
    expect($guardian->occupation)->toBe('Doctor');
    expect($guardian->user->email)->toBe('shared.guardian@example.test'); // unchanged
});

it('admin can change a login-enabled guardians email with credential permission', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    [$guardian] = setupGuardianLinkedToTwoStudents($school);

    $this->actingAs($admin)
        ->putJson("/api/guardians/{$guardian->uuid}", ['email' => 'new.admin.changed@example.test'])
        ->assertOk();

    expect($guardian->user->fresh()->email)->toBe('new.admin.changed@example.test');
});

it('audit log records guardian updates and pivot events', function () {
    $school = School::factory()->create();
    $admin = actingAsAdmin($school);
    [$guardian, $studentA] = setupGuardianLinkedToTwoStudents($school);

    $this->actingAs($admin)
        ->putJson("/api/guardians/{$guardian->uuid}", ['occupation' => 'Doctor'])
        ->assertOk();
    $this->actingAs($admin)
        ->putJson("/api/students/{$studentA->uuid}/guardians/{$guardian->uuid}", ['relationship' => 'guardian'])
        ->assertOk();

    $entries = DB::table('activity_log')->where('subject_id', $guardian->id)->get();
    expect($entries->count())->toBeGreaterThanOrEqual(2);
    expect($entries->pluck('event'))->toContain('updated', 'pivot_updated');
});
