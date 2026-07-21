<?php

use App\Models\Guardian;
use App\Models\Role;
use App\Models\Student;
use App\Services\GuardianImportService;
use App\Services\GuardianService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// C2 (role:->permission: swap): routes now authorize by GRANTS, not role
// names, so the locally-fabricated roles need the canonical grant map to
// reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

it('resolves a per-school Guardian for the same User when attaching an existing cross-school guardian', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'guardian', 'guard_name' => 'web']);

    $user = al_makeUser($schoolA->id);
    $user->grantSchoolAccess($schoolA, 'guardian');
    $guardianA = Guardian::withoutGlobalScopes()->create([
        'school_id' => $schoolA->id,
        'user_id' => $user->id,
        'first_name' => 'Cross',
        'last_name' => 'School',
        'phone' => '+2348011112222',
        'status' => 'active',
    ]);

    $resolved = app(GuardianService::class)
        ->resolveExistingGuardianForAttachment(['guardian_id' => $guardianA->uuid], $schoolB->id);

    // A distinct per-School Guardian row, same shared User.
    expect((int) $resolved->school_id)->toBe((int) $schoolB->id)
        ->and($resolved->id)->not->toBe($guardianA->id)
        ->and((int) $resolved->user_id)->toBe((int) $user->id)
        ->and(Guardian::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(2);

    // Attaching that Guardian to a School-B student is same-School (trigger allows).
    $student = Student::factory()->create(['school_id' => $schoolB->id]);
    $student->guardians()->attach($resolved->id, ['relationship' => 'parent']);

    expect(DB::table('guardian_student')->count())->toBe(1);
});

it('creates a per-school Guardian sharing the User when importing across schools', function () {
    $sourceSchool = al_makeSchool();
    $destinationSchool = al_makeSchool();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'guardian', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $guardianUser = al_makeUser($sourceSchool->id);
    $guardianUser->grantSchoolAccess($sourceSchool, 'guardian');
    $guardian = Guardian::withoutGlobalScopes()->create([
        'school_id' => $sourceSchool->id,
        'user_id' => $guardianUser->id,
        'first_name' => 'Shared',
        'last_name' => 'Guardian',
        'phone' => '+2348000000000',
        'status' => 'active',
    ]);

    $student = Student::withoutGlobalScopes()->create([
        'school_id' => $destinationSchool->id,
        'first_name' => 'Destination',
        'last_name' => 'Student',
        'gender' => 'male',
        'admission_number' => 'DEST-001',
    ]);

    $result = app(GuardianImportService::class)->processRow([
        'admission_number' => $student->admission_number,
        'relationship' => 'father',
        'is_primary' => 'yes',
        'first_name' => 'Shared',
        'last_name' => 'Guardian',
        'phone' => '+2348000000000',
        'email' => $guardianUser->email,
        'can_login' => 'yes',
    ], $destinationSchool->id, false);

    // A SECOND Guardian row was created in the destination School for the SAME
    // User (§6.2: one human = one User; one Guardian record per School).
    $destGuardian = Guardian::withoutGlobalScopes()
        ->where('school_id', $destinationSchool->id)
        ->where('user_id', $guardianUser->id)
        ->first();

    expect($result['status'])->toBe('success')
        ->and(Guardian::withoutGlobalScopes()->count())->toBe(2)
        ->and($destGuardian)->not->toBeNull()
        ->and($destGuardian->id)->not->toBe($guardian->id)
        // The link is same-School: destination Guardian <-> destination Student.
        ->and(DB::table('guardian_student')
            ->where('guardian_id', $destGuardian->id)
            ->where('student_id', $student->id)
            ->exists())->toBeTrue()
        // No cross-School link to the source-School Guardian.
        ->and(DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->where('student_id', $student->id)
            ->exists())->toBeFalse()
        // The shared User has access to the destination School.
        ->and(DB::table('school_user')
            ->where('user_id', $guardianUser->id)
            ->where('school_id', $destinationSchool->id)
            ->exists())->toBeTrue();

    setPermissionsTeamId($destinationSchool->id);
    $guardianUser->unsetRelation('roles');
    expect($guardianUser->hasRole('guardian'))->toBeTrue();

    $admin = al_makeUser($destinationSchool->id);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['school_id' => $destinationSchool->id])
        ->getJson('/api/guardians')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $destGuardian->uuid)
        ->assertJsonPath('data.0.students_count', 1);
});
