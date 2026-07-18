<?php

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 1.4b investigation — bite-proofs for the two identifier generators
 * (HasAdmissionNumber on Student, HasStaffNumber on Teacher). Runs on MySQL
 * (portal_testing). Establishes, as executable evidence:
 *
 *  1. The AddUuid halting fix is real — the `creating` duplicate-detection hook
 *     now executes (it was silently halted before 1.3b.1). FUNCTIONAL defect,
 *     now fixed.
 *  2. The composite UNIQUE index is the actual correctness guarantee — a
 *     duplicate (school_id, number) is impossible at the DB level even if the
 *     application check is bypassed. So a race CANNOT produce a duplicate.
 *  3. The generator itself is racy — two reads of the same state compute the
 *     SAME next number (no lock, read-then-write). Under real concurrency the
 *     second write is rejected by the unique index, so the failure mode is a
 *     generation FAILURE (null number / error), never a duplicate.
 */
uses(RefreshDatabase::class);

/** Reflectively invoke the protected next-number generator on an unsaved model. */
function nextNumberFor(object $model, string $method): string
{
    $ref = new ReflectionMethod($model, $method);
    $ref->setAccessible(true);

    return $ref->invoke($model);
}

// ── 1. Functional: the creating duplicate check now executes (AddUuid fix) ──────

it('rejects a manual duplicate admission number — the creating hook runs (halting fix)', function () {
    $school = al_makeSchool();
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => 'GFA/2099/001']);

    expect(fn () => Student::factory()->create(['school_id' => $school->id, 'admission_number' => 'GFA/2099/001']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a manual duplicate staff number — the creating hook runs (halting fix)', function () {
    $school = al_makeSchool();
    $u1 = User::factory()->create(['school_id' => $school->id]);
    $u2 = User::factory()->create(['school_id' => $school->id]);
    $base = ['school_id' => $school->id, 'first_name' => 'T', 'last_name' => 'One', 'staff_number' => 'STF/2099/001'];
    Teacher::withoutGlobalScopes()->create($base + ['user_id' => $u1->id]);

    expect(fn () => Teacher::withoutGlobalScopes()->create($base + ['user_id' => $u2->id]))
        ->toThrow(InvalidArgumentException::class);
});

// ── 2. The DB composite UNIQUE index is the real guarantee (no duplicate possible) ──

it('the composite unique index makes a duplicate (school_id, admission_number) impossible', function () {
    $school = al_makeSchool();
    $row = fn () => [
        'uuid' => (string) Str::orderedUuid(), 'school_id' => $school->id, 'status' => 'active',
        'first_name' => 'A', 'last_name' => 'B', 'admission_number' => 'DUP/1',
    ];
    DB::table('students')->insert($row());

    // Bypass the model entirely — the DB itself must reject the duplicate.
    expect(fn () => DB::table('students')->insert($row()))
        ->toThrow(QueryException::class); // SQLSTATE 23000 unique violation
});

it('the same admission_number IS allowed across different schools (scope is per-School)', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    Student::factory()->create(['school_id' => $a->id, 'admission_number' => 'GFA/2099/007']);
    Student::factory()->create(['school_id' => $b->id, 'admission_number' => 'GFA/2099/007']);

    expect(Student::withoutGlobalScopes()->where('admission_number', 'GFA/2099/007')->count())->toBe(2);
});

// ── 3. The generator is racy: two reads of the same state yield the SAME number ──

it('admission-number generation is racy — concurrent reads compute the same next number', function () {
    $school = al_makeSchool();
    // Seed a baseline so a "max" exists.
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => Student::admissionNumberPrefix().'001']);

    // Two unsaved models sharing the same DB state (simulates two concurrent
    // transactions that have each read but not yet written).
    $m1 = new Student(['school_id' => $school->id]);
    $m2 = new Student(['school_id' => $school->id]);

    $n1 = nextNumberFor($m1, 'nextAdmissionNumber');
    $n2 = nextNumberFor($m2, 'nextAdmissionNumber');

    // RACE: no lock, so both compute the identical next value.
    expect($n1)->toBe($n2)
        ->and($n1)->toBe(Student::admissionNumberPrefix().'002');

    // Under real concurrency the second write would hit the unique index → the
    // second create FAILS (generation failure), it never duplicates:
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => $n1]);
    expect(fn () => DB::table('students')->insert([
        'uuid' => (string) Str::orderedUuid(), 'school_id' => $school->id, 'status' => 'active',
        'first_name' => 'R', 'last_name' => 'C', 'admission_number' => $n2,
    ]))->toThrow(QueryException::class);
});

it('sequential generation is gap-tolerant and monotonic (no race when not concurrent)', function () {
    $school = al_makeSchool();
    $a = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);
    $b = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);

    expect($a->refresh()->admission_number)->toBe(Student::admissionNumberPrefix().'001')
        ->and($b->refresh()->admission_number)->toBe(Student::admissionNumberPrefix().'002');
});
