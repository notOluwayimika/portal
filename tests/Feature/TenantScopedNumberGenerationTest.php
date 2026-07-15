<?php

use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows the same admission number in different schools', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    foreach ([$schoolA, $schoolB] as $school) {
        Student::create([
            'school_id' => $school->id,
            'first_name' => 'Student',
            'last_name' => Str::random(6),
            'gender' => 'male',
            'admission_number' => 'SHARED-001',
        ]);
    }

    expect(Student::withoutGlobalScopes()->where('admission_number', 'SHARED-001')->count())->toBe(2);
});

it('allows the same staff number in different schools', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    foreach ([$schoolA, $schoolB] as $school) {
        Teacher::create([
            'school_id' => $school->id,
            'first_name' => 'Teacher',
            'last_name' => Str::random(6),
            'staff_number' => 'SHARED-001',
        ]);
    }

    expect(Teacher::withoutGlobalScopes()->where('staff_number', 'SHARED-001')->count())->toBe(2);
});

it('generates number sequences independently for each school', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $studentA = Student::create([
        'school_id' => $schoolA->id,
        'first_name' => 'Student',
        'last_name' => 'A',
        'gender' => 'male',
    ]);
    $studentB = Student::create([
        'school_id' => $schoolB->id,
        'first_name' => 'Student',
        'last_name' => 'B',
        'gender' => 'female',
    ]);

    expect($studentA->admission_number)->toEndWith('001')
        ->and($studentB->admission_number)->toEndWith('001');
});
