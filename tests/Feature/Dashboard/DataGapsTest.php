<?php

use App\Services\Dashboard\DataGapsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function insertStudent(int $schoolId, int $userId): int
{
    return DB::table('students')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'user_id' => $userId,
        'first_name' => 'Student',
        'last_name' => Str::random(5),
        'admission_number' => 'ADM' . Str::random(5),
        'gender' => 'female',
        'date_of_birth' => '2010-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertGuardian(int $schoolId, int $userId): int
{
    return DB::table('guardians')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'user_id' => $userId,
        'first_name' => 'Guardian',
        'last_name' => 'Test',
        'phone' => '0800123456' . random_int(0, 9),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('students_without_guardian detects unlinked students', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    $studentId = insertStudent($school->id, $user->id);

    $service = new DataGapsService((int) $school->id);
    $gaps = $service->detect();

    $gap = collect($gaps)->firstWhere('type', 'students_without_guardian');
    expect($gap)->not->toBeNull();
    expect($gap['count'])->toBeGreaterThanOrEqual(1);
});

test('students_without_guardian count is zero when all students have guardian', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $guardianUser = al_makeUser($school->id);

    $studentId = insertStudent($school->id, $user->id);
    $guardianId = insertGuardian($school->id, $guardianUser->id);

    DB::table('guardian_student')->insert([
        'guardian_id' => $guardianId,
        'student_id' => $studentId,
        'relationship' => 'parent',
        'is_primary' => true,
        'can_login' => true,
    ]);

    $service = new DataGapsService((int) $school->id);
    $gaps = $service->detect();

    $gap = collect($gaps)->firstWhere('type', 'students_without_guardian');
    expect($gap)->toBeNull();
});

test('data gaps only include items with count > 0', function () {
    $school = al_makeSchool();
    // School with no data at all
    $service = new DataGapsService((int) $school->id);
    $gaps = $service->detect();

    foreach ($gaps as $gap) {
        expect($gap['count'])->toBeGreaterThan(0, "Gap '{$gap['type']}' with count=0 should not be included");
    }
});

test('each gap has required structure', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    // Insert a student without guardian to ensure at least one gap
    insertStudent($school->id, $user->id);

    $service = new DataGapsService((int) $school->id);
    $gaps = $service->detect();

    foreach ($gaps as $gap) {
        expect($gap)->toHaveKeys(['type', 'count', 'severity', 'resolution_path']);
        expect($gap['severity'])->toBeIn(['info', 'warning', 'critical']);
        expect($gap['count'])->toBeInt();
    }
});

test('gap detection does not include students from other schools', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $userA = al_makeUser($schoolA->id);
    $userB = al_makeUser($schoolB->id);

    // Add a student to school B (without guardian)
    insertStudent($schoolB->id, $userB->id);

    // School A has no students
    $service = new DataGapsService((int) $schoolA->id);
    $gaps = $service->detect();

    $gap = collect($gaps)->firstWhere('type', 'students_without_guardian');
    expect($gap)->toBeNull(); // School A has no students, so no gap
});
