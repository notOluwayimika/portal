<?php

use App\Models\Guardian;
use App\Models\Role;
use App\Models\Student;
use App\Services\GuardianImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses a guardian across schools and lists them through school access', function () {
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

    expect($result['status'])->toBe('success')
        ->and(Guardian::withoutGlobalScopes()->count())->toBe(1)
        ->and(DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->where('student_id', $student->id)
            ->exists())->toBeTrue()
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
        ->assertJsonPath('data.0.id', $guardian->uuid)
        ->assertJsonPath('data.0.students_count', 1);
});
