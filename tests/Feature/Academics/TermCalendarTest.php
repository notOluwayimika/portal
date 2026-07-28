<?php

use App\Models\AcademicSession;
use App\Models\Term;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Term calendar hardening.
 *
 * Term dates are load-bearing for money since S1 commit 2: `finance_fee_schedules.term_id` is a
 * RESTRICT foreign key, so a term's window prices a fee schedule and a priced term cannot be
 * deleted. Everything below exists because a weak term calendar now reaches money.
 *
 * The database-level proofs deliberately bypass the application: a FormRequest binds one code
 * path, and seeders, jobs, tinker and the TermSeeder invoked from inside a migration all write
 * terms without touching it.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->school = al_makeSchool();
    setPermissionsTeamId($this->school->id);

    $this->admin = al_makeUser($this->school->id);
    $this->admin->grantSchoolAccess($this->school, 'admin');
    $this->admin->flushSchoolAccessCache();

    $this->session = ActiveSchool::runFor($this->school->id, fn () => AcademicSession::create([
        'school_id' => $this->school->id,
        'name' => 'Session '.Str::random(5),
        'slug' => Str::slug(Str::random(8)),
        'is_current' => true,
    ]));
});

function tc_termPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Term '.Str::random(4),
        'order' => 1,
        'start_date' => '2027-01-08',
        'end_date' => '2027-04-02',
    ], $overrides);
}

function tc_post($test, array $payload)
{
    return $test->actingAs($test->admin)->withSession(['school_id' => $test->school->id])
        ->postJson("/api/sessions/{$test->session->uuid}/terms", $payload);
}

// ── PROOF 1: the DATABASE refuses a backwards term ─────────────────────────

it('refuses end_date before start_date at the DATABASE, with no application validation involved', function () {
    // Written with the query builder, not the endpoint: this proves the CHECK constraint, not the
    // FormRequest. If someone deletes the rule from TermController this still bites.
    expect(fn () => DB::table('terms')->insert([
        'uuid' => (string) Str::uuid(),
        'academic_session_id' => $this->session->id,
        'school_id' => $this->school->id,
        'name' => 'Backwards',
        'slug' => 'backwards-'.Str::random(4),
        'order' => 9,
        'start_date' => '2027-06-01',
        'end_date' => '2027-01-01',   // before the start
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses an equal start and end at the database — a term must have duration', function () {
    expect(fn () => DB::table('terms')->insert([
        'uuid' => (string) Str::uuid(),
        'academic_session_id' => $this->session->id,
        'school_id' => $this->school->id,
        'name' => 'Zero length',
        'slug' => 'zero-'.Str::random(4),
        'order' => 8,
        'start_date' => '2027-06-01',
        'end_date' => '2027-06-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('still accepts a well-ordered term', function () {
    $id = DB::table('terms')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'academic_session_id' => $this->session->id,
        'school_id' => $this->school->id,
        'name' => 'Fine',
        'slug' => 'fine-'.Str::random(4),
        'order' => 7,
        'start_date' => '2027-01-01',
        'end_date' => '2027-03-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
});

// ── PROOF 2: `status` is not settable through this endpoint ────────────────

it('ignores a status supplied on create', function () {
    $response = tc_post($this, tc_termPayload(['status' => 'completed']))->assertCreated();

    $term = Term::withoutGlobalScopes()->find($response->json('id'));

    // ->value, NOT the enum object: `status` is cast to TermStatusEnum, so comparing the cast
    // attribute to a string can never fail and the proof would be vacuous. Caught by planting the
    // mass-assignment spread back and watching this test stay green.
    expect($term->status->value)->not->toBe('completed')
        ->and($term->status->value)->toBe('upcoming');   // the column default
});

it('ignores a status supplied on update', function () {
    $created = tc_post($this, tc_termPayload())->assertCreated();
    $term = Term::withoutGlobalScopes()->find($created->json('id'));
    $before = $term->status->value;

    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->putJson("/api/sessions/{$this->session->uuid}/terms/{$term->uuid}",
            tc_termPayload(['status' => 'completed']))
        ->assertOk();

    expect($term->fresh()->status->value)->toBe($before);
});

// ── PROOF 3: a foreign school_id cannot move the term ──────────────────────

it('does not move a term to a school named in the body', function () {
    $otherSchool = al_makeSchool();

    $response = tc_post($this, tc_termPayload(['school_id' => $otherSchool->id]))->assertCreated();

    $term = Term::withoutGlobalScopes()->find($response->json('id'));

    // Parentage comes from the route-bound session, never the request body.
    expect((int) $term->school_id)->toBe((int) $this->school->id)
        ->and((int) $term->academic_session_id)->toBe((int) $this->session->id);
});

it('does not reparent a term to another session named in the body', function () {
    $created = tc_post($this, tc_termPayload())->assertCreated();
    $term = Term::withoutGlobalScopes()->find($created->json('id'));

    $otherSession = ActiveSchool::runFor($this->school->id, fn () => AcademicSession::create([
        'school_id' => $this->school->id,
        'name' => 'Other '.Str::random(5),
        'slug' => Str::slug(Str::random(8)),
        'is_current' => false,
    ]));

    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->putJson("/api/sessions/{$this->session->uuid}/terms/{$term->uuid}",
            tc_termPayload(['academic_session_id' => $otherSession->id]))
        ->assertOk();

    expect((int) $term->fresh()->academic_session_id)->toBe((int) $this->session->id);
});

// ── PROOF 4: validation failures are 422 with fields, not 500 ──────────────

it('returns 422 with field errors for a non-date, not a 500', function () {
    // "banana" was accepted by `required|string`, and once the rule tightened the old
    // `catch (\Throwable)` would have turned the ValidationException into a 500 with no field.
    tc_post($this, tc_termPayload(['start_date' => 'banana']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_date');
});

it('returns 422 naming end_date when the window is backwards', function () {
    tc_post($this, tc_termPayload(['start_date' => '2027-06-01', 'end_date' => '2027-01-01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');
});

it('returns 422 naming the missing field', function () {
    $payload = tc_termPayload();
    unset($payload['name']);

    tc_post($this, $payload)->assertStatus(422)->assertJsonValidationErrors('name');
});

// ── PROOF 5: one current session per school, enforced by the index ─────────

it('refuses a second current session for one school', function () {
    // Raw insert: this proves the unique index on the generated column, not any model logic.
    expect(fn () => DB::table('academic_sessions')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $this->school->id,
        'name' => 'Second current',
        'slug' => Str::slug(Str::random(8)),
        'is_current' => true,   // the session created in beforeEach is already current
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows many NON-current sessions for one school', function () {
    // The NULL exemption is the whole point of the generated-column idiom: only current rows
    // occupy the index.
    foreach (range(1, 3) as $ignored) {
        DB::table('academic_sessions')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $this->school->id,
            'name' => 'Archive '.Str::random(5),
            'slug' => Str::slug(Str::random(8)),
            'is_current' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('academic_sessions')->where('school_id', $this->school->id)->count())->toBe(4);
});

it('allows another school to have its own current session', function () {
    $other = al_makeSchool();

    DB::table('academic_sessions')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $other->id,
        'name' => 'Their current',
        'slug' => Str::slug(Str::random(8)),
        'is_current' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('academic_sessions')->where('is_current', true)->count())->toBeGreaterThanOrEqual(2);
});

// ── PROOF 6: a term from another session is 404, not 500 ───────────────────

it('404s an update for a term belonging to a different session', function () {
    $foreignSession = ActiveSchool::runFor($this->school->id, fn () => AcademicSession::create([
        'school_id' => $this->school->id,
        'name' => 'Foreign '.Str::random(5),
        'slug' => Str::slug(Str::random(8)),
        'is_current' => false,
    ]));

    $foreignTerm = ActiveSchool::runFor($this->school->id, fn () => Term::create([
        'academic_session_id' => $foreignSession->id,
        'school_id' => $this->school->id,
        'name' => 'Foreign term',
        'slug' => 'foreign-'.Str::random(4),
        'order' => 1,
        'start_date' => '2027-01-01',
        'end_date' => '2027-03-01',
    ]));

    // Routed under OUR session, with a term uuid from another one. Was a 500 via
    // `$session->terms()->find($term->id)` returning null.
    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->putJson("/api/sessions/{$this->session->uuid}/terms/{$foreignTerm->uuid}", tc_termPayload())
        ->assertNotFound();
});

it('404s a delete for a term belonging to a different session', function () {
    $foreignSession = ActiveSchool::runFor($this->school->id, fn () => AcademicSession::create([
        'school_id' => $this->school->id,
        'name' => 'Foreign '.Str::random(5),
        'slug' => Str::slug(Str::random(8)),
        'is_current' => false,
    ]));

    $foreignTerm = ActiveSchool::runFor($this->school->id, fn () => Term::create([
        'academic_session_id' => $foreignSession->id,
        'school_id' => $this->school->id,
        'name' => 'Foreign term',
        'slug' => 'foreign-'.Str::random(4),
        'order' => 1,
        'start_date' => '2027-01-01',
        'end_date' => '2027-03-01',
    ]));

    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->deleteJson("/api/sessions/{$this->session->uuid}/terms/{$foreignTerm->uuid}")
        ->assertNotFound();

    // And it is still there — a 404 must not be a silent delete.
    expect(Term::withoutGlobalScopes()->find($foreignTerm->id))->not->toBeNull();
});

// ── The update response body ───────────────────────────────────────────────

it('returns the updated term, not the boolean update() returns', function () {
    $created = tc_post($this, tc_termPayload())->assertCreated();
    $term = Term::withoutGlobalScopes()->find($created->json('id'));

    $response = $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->putJson("/api/sessions/{$this->session->uuid}/terms/{$term->uuid}",
            tc_termPayload(['name' => 'Renamed term']))
        ->assertOk();

    // The endpoint used to answer literally `true`.
    expect($response->json())->toBeArray()
        ->and($response->json('name'))->toBe('Renamed term');
});
