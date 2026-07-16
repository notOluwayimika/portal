<?php

use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects linking a guardian to a student in another school', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $guardian = al_makeGuardian($schoolA->id, al_makeUser($schoolA->id)->id);
    $student = Student::factory()->create(['school_id' => $schoolB->id]);

    expect(fn () => DB::table('guardian_student')->insert([
        'guardian_id' => $guardian->id,
        'student_id' => $student->id,
        'relationship' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(DB::table('guardian_student')->count())->toBe(0);
});

it('allows linking a guardian to a student in the same school', function () {
    $school = al_makeSchool();
    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);
    $student = Student::factory()->create(['school_id' => $school->id]);

    $student->guardians()->attach($guardian->id, ['relationship' => 'parent']);

    expect(DB::table('guardian_student')->count())->toBe(1);
});

it('defaults a School to the Africa/Lagos timezone', function () {
    expect(al_makeSchool()->fresh()->timezone)->toBe('Africa/Lagos');
});
