<?php

use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The global QueryException handler in bootstrap/app.php maps a real database error to an HTTP status.
 * Before this commit it matched errorInfo[1] (the MySQL DRIVER numeric code) against SQLSTATEs and a SQL
 * Server code, so all three arms were dead and EVERY database error rendered 400 — including a CHECK
 * violation and an append-only ledger-tamper trigger, which ops never saw on a 5xx alert. This pins the
 * corrected mapping (1062/1451/1213/1205 → 409; 1452/1644/3819/default → 500) end to end.
 *
 * These exercise the GLOBAL handler, so each test registers a throwaway route whose closure performs the
 * failing write with no local catch, then asserts the RENDERED response. The 1452 arm exists because of the
 * documented production incident — see tests/Feature/Academics/StudentSubjectCommentAuthorTest.php and
 * 2026_07_27_140000_repair_student_subject_comment_authors.php. Constraint/trigger existence is asserted
 * from information_schema first, so a proof cannot pass green because the guard it needs was silently absent.
 */
uses(RefreshDatabase::class);

function dberrHasTrigger(string $name): bool
{
    return DB::table('information_schema.TRIGGERS')
        ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
        ->where('TRIGGER_NAME', $name)
        ->exists();
}

function dberrHasCheck(string $name): bool
{
    return DB::table('information_schema.TABLE_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
        ->where('CONSTRAINT_NAME', $name)
        ->where('CONSTRAINT_TYPE', 'CHECK')
        ->exists();
}

/** A body must never leak schema internals. */
function dberrAssertNoLeak(string $body): void
{
    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('student_curricula')
        ->and($body)->not->toContain('finance_ledger_transactions')
        ->and($body)->not->toContain('promoted_requires_link')
        ->and(strtolower($body))->not->toContain('insert into')
        ->and(strtolower($body))->not->toContain('update ');
}

// ── X-1 — a CHECK violation (3819) renders 500, not 400 ──────────────────────

it('X-1 — a CHECK violation (3819) renders 500 with a generic body', function () {
    expect(dberrHasCheck('student_curricula_promoted_requires_link'))->toBeTrue();

    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    Route::get('/api/__dberr/check', fn () => DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(), 'student_id' => $student->id, 'school_id' => $school->id,
        'curriculum_id' => $curriculum->id, 'status' => 'promoted', 'promoted_to_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $res = $this->getJson('/api/__dberr/check');
    $res->assertStatus(500);
    expect($res->json('message'))->toBe('A database error occurred.'); // NOTE: watched red by restoring the old handler — see the PR body (X-1 alone falls to default either way).
    dberrAssertNoLeak($res->getContent());
});

// ── X-2 — a ledger append-only trigger (1644) renders 500 (THE finding: a tamper must reach a 5xx alert) ──

it('X-2 — an append-only ledger tamper (1644) renders 500, not a 400 "Database error"', function () {
    expect(dberrHasTrigger('finance_ledger_transactions_no_update'))->toBeTrue();

    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $id = DB::table('finance_ledger_transactions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'student_id' => $student->id,
        'type' => 'charge', 'amount_minor' => 100000, 'amount_currency' => 'NGN',
        'source_type' => 'invoice', 'source_id' => 1, 'narration' => 'seed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Route::get('/api/__dberr/tamper', fn () => DB::table('finance_ledger_transactions')->where('id', $id)->update(['narration' => 'tampered']));

    $res = $this->getJson('/api/__dberr/tamper');
    $res->assertStatus(500); // PLANT (watched red): restore the old handler → this same tamper renders 400 "Database error".
    expect($res->json('message'))->toBe('A database error occurred.');
    dberrAssertNoLeak($res->getContent());
});

// ── X-3 — a duplicate key (1062) renders 409, from an arm dead since it was written ──

it('X-3 — a duplicate key (1062) renders 409 with the intended body', function () {
    $existing = User::factory()->create();

    Route::get('/api/__dberr/dup', fn () => DB::table('users')->insert([
        'uuid' => (string) Str::uuid(), 'first_name' => 'Dup', 'last_name' => 'User',
        'email' => $existing->email, 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
    ]));

    $res = $this->getJson('/api/__dberr/dup');
    $res->assertStatus(409); // PLANT (watched red): remove the 1062 arm → falls to default → 500.
    expect($res->json('message'))->toBe('Duplicate entry detected.');
});

// ── X-4 — a RESTRICT refusal (1451) through an uncaught path renders 409 ──────

it('X-4 — a RESTRICT FK refusal (1451) renders 409 (uncaught path — the promotion-link target FK)', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $target = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id, 'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id, 'status' => 'active',
    ]));
    ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id, 'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'promoted', 'promoted_to_id' => $target->id,
    ]));

    // Raw-delete the promotion TARGET: the source row references it via student_curricula_promoted_to_student_school_foreign
    // (ON DELETE RESTRICT). No local catch, so the global handler renders it.
    Route::get('/api/__dberr/restrict', fn () => DB::table('student_curricula')->where('id', $target->id)->delete());

    $res = $this->getJson('/api/__dberr/restrict');
    $res->assertStatus(409); // PLANT (watched red): remove the 1451 arm → falls to default → 500.
    expect($res->json('message'))->toBe('This record has dependent records and cannot be changed.');
});

// ── X-5 — a child referencing a missing parent (1452) renders 500 (the documented incident's code) ──

it('X-5 — a child row with a bogus parent (1452) renders 500, not 400', function () {
    // The 1452 arm exists for the production incident in StudentSubjectCommentAuthorTest — a child insert
    // whose parent did not exist, whose opaque "Database error" 400 is "how the bug was finally noticed".
    $school = School::factory()->create();
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);

    Route::get('/api/__dberr/missingparent', fn () => DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(), 'student_id' => 999999999, 'school_id' => $school->id, // no such student
        'curriculum_id' => $curriculum->id, 'status' => 'active', 'promoted_to_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $res = $this->getJson('/api/__dberr/missingparent');
    $res->assertStatus(500);
    expect($res->json('message'))->toBe('A database error occurred.');
    dberrAssertNoLeak($res->getContent());
});

// ── X-7 — the default arm still catches an unclassified failure as 500 ───────

it('X-7 — an unclassified failure (nonexistent table) renders 500 (regression floor)', function () {
    Route::get('/api/__dberr/unknown', fn () => DB::table('this_table_does_not_exist_at_all')->count());

    $res = $this->getJson('/api/__dberr/unknown');
    $res->assertStatus(500);
    expect($res->json('message'))->toBe('A database error occurred.');
});
