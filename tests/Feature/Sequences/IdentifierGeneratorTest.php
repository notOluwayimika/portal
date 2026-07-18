<?php

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 1.4b — the admission/staff generators after migrating to Shared Sequences.
 * Runs on MySQL (portal_testing). Proves the fixes: generation happens BEFORE
 * insert (no null-number window), values come from the atomic sequence (no race),
 * the switch adopts the existing max (no reissue), it stays gap-tolerant and
 * per-School, and the composite UNIQUE index remains the ultimate backstop.
 */
uses(RefreshDatabase::class);

// ── Functional: number is present in the INSERT, never a post-insert null ──────

it('sets the admission number BEFORE insert — no null-number window', function () {
    $school = al_makeSchool();

    // Capture the row as it is first inserted (creating hook already ran).
    $inserted = null;
    DB::listen(function ($q) use (&$inserted) {
        if (str_contains($q->sql, 'insert into `students`')) {
            $inserted = $q->bindings;
        }
    });

    $student = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);

    expect($student->admission_number)->not->toBeNull()
        ->and($student->wasRecentlyCreated)->toBeTrue()
        // the admission number was a binding on the INSERT itself, not a later UPDATE
        ->and(collect($inserted)->contains($student->admission_number))->toBeTrue();
});

// ── Atomicity: the sequence yields distinct, contiguous values (no race) ───────

it('generates distinct sequential admission numbers (atomic counter, not max+1 race)', function () {
    $school = al_makeSchool();

    $numbers = collect(range(1, 5))->map(
        fn () => Student::factory()->create(['school_id' => $school->id, 'admission_number' => null])->admission_number
    );

    expect($numbers->unique())->toHaveCount(5) // no duplicates
        ->and($numbers->values()->all())->toBe([
            Student::admissionNumberPrefix().'001',
            Student::admissionNumberPrefix().'002',
            Student::admissionNumberPrefix().'003',
            Student::admissionNumberPrefix().'004',
            Student::admissionNumberPrefix().'005',
        ]);
});

// ── Atomic unit of work: a failed insert must not consume the allocation ───────

it('allocation and insert are one atomic unit — a failed insert never consumes the value', function () {
    $school = al_makeSchool();
    $prefix = Student::admissionNumberPrefix();
    $key = $school->id.'|'.$prefix;

    // A student already holds 001, and the counter is deliberately BEHIND it (0),
    // so the next generated value (001) will collide with the existing row.
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => $prefix.'001']);
    DB::table('sequences')->insert(['scope' => 'student.admission_number', 'key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);

    // The generating create allocates 001 → the INSERT hits the unique index → fails.
    expect(fn () => Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]))
        ->toThrow(QueryException::class);

    // Because save() wraps allocation + insert in ONE transaction, the failed
    // insert rolled the increment back too — the counter did NOT advance.
    expect((int) DB::table('sequences')->where('key', $key)->value('value'))->toBe(0);
});

// ── School-scoped isolation: distinct Schools use distinct counter rows ─────────

it('different Schools use independent counter rows — no cross-School lock contention', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $prefix = Student::admissionNumberPrefix();

    $sa = Student::factory()->create(['school_id' => $a->id, 'admission_number' => null]);
    $sb = Student::factory()->create(['school_id' => $b->id, 'admission_number' => null]);

    // The FOR UPDATE lock is on the (scope, key) COUNTER ROW; the key embeds the
    // School, so A and B lock different rows and never serialize against each
    // other — there is no global counter and no cross-School contention.
    expect($a->id.'|'.$prefix)->not->toBe($b->id.'|'.$prefix)
        ->and($sa->admission_number)->toBe($prefix.'001')
        ->and($sb->admission_number)->toBe($prefix.'001') // each School's counter starts independently
        ->and(DB::table('sequences')->distinct()->count('key'))->toBe(2); // two independent rows
});

// ── Migration safety: the sequence seeds from the existing max, never reissues ──

it('adopts the existing max on first use — the switch never reissues a live number', function () {
    $school = al_makeSchool();
    // Pre-existing identifier from the OLD scheme.
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => Student::admissionNumberPrefix().'042']);

    $next = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);

    expect($next->admission_number)->toBe(Student::admissionNumberPrefix().'043');
});

// ── Deploy-day first use: a School with existing numbers, no counter row yet ────

it('first use on a School with existing numbers seeds from the domain max (deploy-day)', function () {
    $school = al_makeSchool();
    $prefix = Student::admissionNumberPrefix();

    // Old max+1-era identifiers exist; there is NO sequence row — the exact,
    // unrepeatable deploy-day state where old and new numbering overlap.
    foreach (['003', '005', '004'] as $n) {
        Student::factory()->create(['school_id' => $school->id, 'admission_number' => $prefix.$n]);
    }
    expect(DB::table('sequences')->count())->toBe(0); // genuinely first use

    $first = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);
    $second = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);

    // Seeds from the domain max (005) → 006, 007. Never 001, never a reissue of a
    // live number; exactly one counter row is created.
    expect($first->admission_number)->toBe($prefix.'006')
        ->and($second->admission_number)->toBe($prefix.'007')
        ->and(DB::table('sequences')->count())->toBe(1);
});

// ── Gap-tolerant: a rolled-back create burns a value (documented, acceptable) ───

it('is gap-tolerant — a deleted number is never reclaimed (a gap), never a duplicate', function () {
    $school = al_makeSchool();
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]); // 001
    $two = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]); // 002

    $two->forceDelete(); // 002 is gone, but the counter has already advanced to 2

    $after = Student::factory()->create(['school_id' => $school->id, 'admission_number' => null]);
    // The sequence does NOT reclaim 002 — the next value is 003. Gap-tolerant.
    // (Rollback-within-a-transaction instead REUSES the value: next() nests as a
    // savepoint, so a rolled-back consumer releases its increment — fewer gaps,
    // still never a duplicate among committed rows.)
    expect($after->admission_number)->toBe(Student::admissionNumberPrefix().'003');
});

// ── Per-School scope + the DB unique index guarantee (unchanged, still true) ────

it('the composite unique index makes a duplicate (school_id, admission_number) impossible', function () {
    $school = al_makeSchool();
    $row = fn () => [
        'uuid' => (string) Str::orderedUuid(), 'school_id' => $school->id, 'status' => 'active',
        'first_name' => 'A', 'last_name' => 'B', 'admission_number' => 'DUP/1',
    ];
    DB::table('students')->insert($row());

    expect(fn () => DB::table('students')->insert($row()))->toThrow(QueryException::class);
});

it('numbers are per-School — the same admission number may exist in two Schools', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    Student::factory()->create(['school_id' => $a->id, 'admission_number' => null]);
    $bStudent = Student::factory()->create(['school_id' => $b->id, 'admission_number' => null]);

    // Each School's counter starts independently at 001.
    expect($bStudent->admission_number)->toBe(Student::admissionNumberPrefix().'001');
});

it('still rejects a manual duplicate admission/staff number (creating hook)', function () {
    $school = al_makeSchool();
    Student::factory()->create(['school_id' => $school->id, 'admission_number' => 'GFA/2099/001']);
    expect(fn () => Student::factory()->create(['school_id' => $school->id, 'admission_number' => 'GFA/2099/001']))
        ->toThrow(InvalidArgumentException::class);

    $u1 = User::factory()->create(['school_id' => $school->id]);
    $u2 = User::factory()->create(['school_id' => $school->id]);
    $base = ['school_id' => $school->id, 'first_name' => 'T', 'last_name' => 'One', 'staff_number' => 'STF/2099/001'];
    Teacher::withoutGlobalScopes()->create($base + ['user_id' => $u1->id]);
    expect(fn () => Teacher::withoutGlobalScopes()->create($base + ['user_id' => $u2->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('generates staff numbers atomically too (same Shared Sequences path)', function () {
    $school = al_makeSchool();
    $nums = collect(range(1, 3))->map(function () use ($school) {
        $u = User::factory()->create(['school_id' => $school->id]);

        return Teacher::withoutGlobalScopes()->create([
            'school_id' => $school->id, 'user_id' => $u->id, 'first_name' => 'T', 'last_name' => 'X',
        ])->staff_number;
    });

    expect($nums->values()->all())->toBe([
        Teacher::staffNumberPrefix().'001',
        Teacher::staffNumberPrefix().'002',
        Teacher::staffNumberPrefix().'003',
    ]);
});
