<?php

use App\Models\School;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * students.school_id is NOT NULL (the last nullable BelongsToSchool table; §5.2).
 * A create with no School context and no explicit school_id must fail at the
 * COLUMN, not persist a School-less (billable) student silently.
 */
uses(RefreshDatabase::class);

it('rejects a student row with a null school_id at the database', function () {
    // Bypass the model/auto-fill entirely — the DB column itself must reject it.
    expect(fn () => DB::table('students')->insert([
        'uuid' => (string) Str::orderedUuid(),
        'status' => 'active', 'first_name' => 'N', 'last_name' => 'U',
        'school_id' => null,
        'admission_number' => 'X/'.Str::random(6),
    ]))->toThrow(QueryException::class); // SQLSTATE 23000 — column cannot be null
});

it('a valid school_id still inserts', function () {
    $school = School::factory()->create();

    $id = DB::table('students')->insertGetId([
        'uuid' => (string) Str::orderedUuid(),
        'status' => 'active', 'first_name' => 'V', 'last_name' => 'K',
        'school_id' => $school->id,
        'admission_number' => 'OK/'.Str::random(6),
    ]);

    expect(DB::table('students')->where('id', $id)->value('school_id'))->toBe($school->id);
});
