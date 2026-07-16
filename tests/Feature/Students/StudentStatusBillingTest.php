<?php

use App\Enums\StudentMembershipStatus;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a new student to active membership', function () {
    $school = al_makeSchool();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($student->status)->toBe(StudentMembershipStatus::ACTIVE);
});

it('answers "active students at a School" with one indexed predicate, no enrollment join', function () {
    $school = al_makeSchool();
    Student::factory()->count(3)->create(['school_id' => $school->id]);
    Student::factory()->count(2)->withdrawn()->create(['school_id' => $school->id]);

    $activeCount = Student::withoutGlobalScopes()
        ->where('school_id', $school->id)
        ->active()
        ->count();

    expect($activeCount)->toBe(3);
});

it('excludes a withdrawn student from billing without deleting the record', function () {
    $school = al_makeSchool();
    $student = Student::factory()->withdrawn()->create(['school_id' => $school->id]);

    // Not billable...
    $billable = Student::withoutGlobalScopes()
        ->where('school_id', $school->id)
        ->active()
        ->pluck('id');
    expect($billable)->not->toContain($student->id);

    // ...but the record (and its financial-history reference) still exists.
    expect(Student::withoutGlobalScopes()->find($student->id))->not->toBeNull()
        ->and($student->fresh()->left_at)->not->toBeNull();
});
