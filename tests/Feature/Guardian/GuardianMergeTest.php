<?php

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * GuardianService::merge — collapsing duplicate guardian records.
 *
 * The duplicates are real and they are production-only: createGuardianWithUser
 * dedupes the USER by email and then calls Guardian::create() unconditionally, and
 * with no email `User::where('email', null)->first()` never matches under MySQL, so
 * an email-less submission mints a fresh user AND a fresh guardian every time. No
 * fixture or seeder in this repository produces that state, which is exactly why
 * every arm below plants it explicitly rather than driving the write path.
 */
function gm_user(int $schoolId, ?string $email = null): User
{
    return User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'Guardian '.Str::random(5),
        'email' => $email ?? Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
        'email_verified_at' => now(),
    ]);
}

function gm_guardian(int $schoolId, int $userId, array $overrides = []): Guardian
{
    return Guardian::forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'user_id' => $userId,
        'first_name' => 'Guardian',
        'last_name' => 'Test',
        'phone' => '0800'.random_int(1000000, 9999999),
        'status' => 'active',
    ], $overrides));
}

function gm_student(int $schoolId): Student
{
    return Student::create([
        'school_id' => $schoolId,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
        'status' => 'active',
    ]);
}

/**
 * Planted through the relation rather than the service: attachToStudent re-issues
 * credentials on a can_login false→true transition, and a fixture must not email
 * anybody. The pivot row is the thing under test either way.
 */
function gm_link(Guardian $guardian, Student $student, bool $isPrimary = false, bool $canLogin = false, string $relationship = 'parent'): void
{
    $guardian->students()->attach($student->id, [
        'relationship' => $relationship,
        'is_primary' => $isPrimary,
        'can_login' => $canLogin,
    ]);
}

function gm_pivot(Guardian $guardian, Student $student): ?object
{
    return DB::table('guardian_student')
        ->where('guardian_id', $guardian->id)
        ->where('student_id', $student->id)
        ->first();
}

function gm_merge(Guardian $keeper, array $absorbed, bool $apply = true): array
{
    return app(GuardianService::class)->merge($keeper, new Collection($absorbed), $apply);
}

/*
|--------------------------------------------------------------------------
| 1. The move — the absorbed row's student follows it to the keeper
|--------------------------------------------------------------------------
*/

it('re-points a student link the keeper does not already hold', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);

    $kept = gm_student($school->id);
    $moved = gm_student($school->id);
    gm_link($keeper, $kept, isPrimary: true);
    gm_link($absorbed, $moved, isPrimary: true, relationship: 'guardian');

    $plan = gm_merge($keeper, [$absorbed]);

    expect($plan['pivot_moves'])->toHaveCount(1)
        ->and($plan['pivot_collisions'])->toHaveCount(0)
        ->and($keeper->students()->pluck('students.id')->sort()->values()->all())
        ->toBe([$kept->id, $moved->id]);

    // The moved row keeps its own relationship — it describes that adult and that
    // child, and nothing about the merge changes who they are to each other.
    expect(gm_pivot($keeper, $moved)->relationship)->toBe('guardian')
        ->and($absorbed->fresh()->deleted_at)->not->toBeNull()
        ->and(Guardian::withoutGlobalScopes()->whereNull('deleted_at')->where('id', $absorbed->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 2. The collision — unique(guardian_id, student_id) makes a blind move fail
|--------------------------------------------------------------------------
*/

it('OR-merges a colliding link into the keeper row instead of raising a duplicate key', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);

    $shared = gm_student($school->id);
    gm_link($keeper, $shared, isPrimary: false, canLogin: false, relationship: 'mother');
    gm_link($absorbed, $shared, isPrimary: true, canLogin: true, relationship: 'guardian');

    $plan = gm_merge($keeper, [$absorbed]);

    expect($plan['pivot_moves'])->toHaveCount(0)
        ->and($plan['pivot_collisions'])->toHaveCount(1);

    $rows = DB::table('guardian_student')->where('student_id', $shared->id)->get();

    expect($rows)->toHaveCount(1);

    $pivot = $rows->first();

    expect((int) $pivot->guardian_id)->toBe($keeper->id)
        ->and((bool) $pivot->is_primary)->toBeTrue()
        ->and((bool) $pivot->can_login)->toBeTrue()
        // The keeper's own relationship survives; only the two booleans are OR-merged.
        ->and($pivot->relationship)->toBe('mother');
});

/*
|--------------------------------------------------------------------------
| 3. Single-primary — enforced in code only, so the merge has to re-assert it
|--------------------------------------------------------------------------
*/

it('leaves exactly one primary guardian when the moved link is primary and a third already is', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);
    $third = gm_guardian($school->id, gm_user($school->id)->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true);
    gm_link($third, $student, isPrimary: true);

    // The pre-state is itself a violation of single-primary — nothing at the schema
    // level forbids it — and the merge must not leave it standing.
    expect(DB::table('guardian_student')->where('student_id', $student->id)->where('is_primary', true)->count())->toBe(2);

    $plan = gm_merge($keeper, [$absorbed]);

    $primaries = DB::table('guardian_student')
        ->where('student_id', $student->id)
        ->where('is_primary', true)
        ->pluck('guardian_id');

    expect($primaries->all())->toBe([$keeper->id])
        ->and($plan['primary_demotions'])->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| 4. The invariant — can_login may not land on a keeper who cannot receive mail
|--------------------------------------------------------------------------
*/

it('aborts the whole merge rather than move login access onto an undeliverable keeper', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN)->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true, canLogin: true);

    expect(fn () => gm_merge($keeper, [$absorbed]))->toThrow(ValidationException::class);

    // NOT "an exception was thrown" — that a refusal wrote nothing is the claim.
    $pivot = gm_pivot($absorbed, $student);

    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->can_login)->toBeTrue()
        ->and(gm_pivot($keeper, $student))->toBeNull()
        ->and($absorbed->fresh()->deleted_at)->toBeNull();
});

it('allows the same merge once the keeper has a deliverable address', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id, 'real.parent@example.test')->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true, canLogin: true);

    gm_merge($keeper, [$absorbed]);

    expect((bool) gm_pivot($keeper, $student)->can_login)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 5. Back-fill — blanks only, and the keeper always wins
|--------------------------------------------------------------------------
*/

it('back-fills a blank field from the absorbed row and never overwrites a filled one', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id, [
        'occupation' => null,
        'employer_name' => 'Keeper Ltd',
    ]);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id, [
        'occupation' => 'Nurse',
        'employer_name' => 'Absorbed Ltd',
    ]);

    $plan = gm_merge($keeper, [$absorbed]);

    expect($keeper->fresh()->occupation)->toBe('Nurse')
        ->and($keeper->fresh()->employer_name)->toBe('Keeper Ltd')
        ->and(array_column($plan['backfilled'], 'field'))->toContain('occupation')
        ->and(array_column($plan['backfilled'], 'field'))->not->toContain('employer_name');
});

it('never copies identity or status off an absorbed row', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id, ['status' => 'active']);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id, ['status' => 'blocked']);

    $plan = gm_merge($keeper, [$absorbed]);

    // school_id and user_id are identity; status is a decision an operator made
    // about the keeper. None of the three is a blank to be filled.
    expect(array_column($plan['backfilled'], 'field'))
        ->not->toContain('status')
        ->not->toContain('user_id')
        ->not->toContain('school_id')
        ->and($keeper->fresh()->status)->toBe('active');
});

/*
|--------------------------------------------------------------------------
| 6. Isolation — school_id is the boundary, and a merge across it is not a merge
|--------------------------------------------------------------------------
*/

it('refuses a cross-school absorb before writing anything', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $keeper = gm_guardian($schoolA->id, gm_user($schoolA->id)->id);
    $foreign = gm_guardian($schoolB->id, gm_user($schoolB->id)->id);

    $student = gm_student($schoolB->id);
    gm_link($foreign, $student);

    expect(fn () => gm_merge($keeper, [$foreign]))->toThrow(ValidationException::class);

    expect($foreign->fresh()->deleted_at)->toBeNull()
        ->and(gm_pivot($foreign, $student))->not->toBeNull()
        ->and(DB::table('guardian_student')->where('guardian_id', $keeper->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 7. users are never touched, and neither are their rows in other schools
|--------------------------------------------------------------------------
*/

it('leaves users alone and leaves the same person\'s guardian row in another school standing', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $keeper = gm_guardian($schoolA->id, gm_user($schoolA->id)->id);

    // One human, two schools (§6.2): the same User backs a guardian record in each.
    $sharedUser = gm_user($schoolA->id);
    $absorbed = gm_guardian($schoolA->id, $sharedUser->id);
    $elsewhere = gm_guardian($schoolB->id, $sharedUser->id);

    $plan = gm_merge($keeper, [$absorbed]);

    // guardians.user_id is NOT NULL with cascadeOnDelete: deleting the user here
    // would hard-delete $elsewhere, in a school this merge has no business in.
    expect(User::find($sharedUser->id))->not->toBeNull()
        ->and($elsewhere->fresh()->deleted_at)->toBeNull()
        ->and($plan['orphaned_user_ids'])->not->toContain($sharedUser->id);
});

it('collapses the same-user-same-school duplicate without orphaning that user', function () {
    $school = al_makeSchool();
    $user = gm_user($school->id);

    // THE CERTAIN DUPLICATE, and the one a unique index on (user_id, school_id)
    // would reject: one User, one school, two live guardian rows — what
    // createGuardianWithUser produces when the same email is submitted twice.
    $keeper = gm_guardian($school->id, $user->id);
    $absorbed = gm_guardian($school->id, $user->id);
    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true);

    $plan = gm_merge($keeper, [$absorbed]);

    expect(Guardian::withoutGlobalScopes()->whereNull('deleted_at')->where('user_id', $user->id)->count())->toBe(1)
        // The keeper still backs a live row, so the shared user is not orphaned —
        // reporting it here would invite an operator to act on a live account.
        ->and($plan['orphaned_user_ids'])->toBe([])
        ->and(gm_pivot($keeper, $student))->not->toBeNull();
});

it('reports an absorbed user left backing no live guardian without acting on it', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $orphanUser = gm_user($school->id);
    $absorbed = gm_guardian($school->id, $orphanUser->id);

    $plan = gm_merge($keeper, [$absorbed]);

    expect($plan['orphaned_user_ids'])->toContain($orphanUser->id)
        ->and(User::find($orphanUser->id))->not->toBeNull()
        ->and(User::find($orphanUser->id)->deleted_at ?? null)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 8. The audit trail — this is what the guardian audit page renders
|--------------------------------------------------------------------------
*/

it('records one merged activity entry on the keeper naming the absorbed ids', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);
    $student = gm_student($school->id);
    gm_link($absorbed, $student);

    gm_merge($keeper, [$absorbed]);

    $entry = DB::table('activity_log')
        ->where('log_name', 'guardian')
        ->where('event', 'merged')
        ->where('subject_type', Guardian::class)
        ->where('subject_id', $keeper->id)
        ->first();

    expect($entry)->not->toBeNull();

    $properties = json_decode((string) $entry->properties, true);

    expect($properties['absorbed_guardian_ids'])->toBe([$absorbed->id])
        ->and($properties['pivots_moved'])->toBe(1)
        ->and($properties['pivot_collisions'])->toBe(0)
        ->and($properties['school_id'])->toBe($school->id);
});

/*
|--------------------------------------------------------------------------
| The dry run, and the two commands
|--------------------------------------------------------------------------
*/

it('writes nothing on a dry run and returns the same plan the apply executes', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id, ['occupation' => null]);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id, ['occupation' => 'Nurse']);
    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true);

    $dry = gm_merge($keeper, [$absorbed], apply: false);

    expect($dry['applied'])->toBeFalse()
        ->and(gm_pivot($keeper, $student))->toBeNull()
        ->and($absorbed->fresh()->deleted_at)->toBeNull()
        ->and($keeper->fresh()->occupation)->toBeNull();

    $applied = gm_merge($keeper, [$absorbed]);

    // Same shape, so the plan an operator inspected is the plan that ran.
    expect(array_keys($dry))->toBe(array_keys($applied))
        ->and($dry['pivot_moves'])->toBe($applied['pivot_moves'])
        ->and($dry['backfilled'])->toBe($applied['backfilled']);
});

it('merges through the console command and refuses without --apply', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_user($school->id)->id);
    $student = gm_student($school->id);
    gm_link($absorbed, $student);

    $this->artisan('guardians:merge', ['--keep' => $keeper->uuid, '--absorb' => [$absorbed->uuid]])
        ->assertSuccessful();

    expect($absorbed->fresh()->deleted_at)->toBeNull();

    $this->artisan('guardians:merge', ['--keep' => $keeper->uuid, '--absorb' => [$absorbed->uuid], '--apply' => true])
        ->assertSuccessful();

    expect($absorbed->fresh()->deleted_at)->not->toBeNull()
        ->and(gm_pivot($keeper, $student))->not->toBeNull();
});

it('exits non-zero from the merge command on a cross-school absorb', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $keeper = gm_guardian($schoolA->id, gm_user($schoolA->id)->id);
    $foreign = gm_guardian($schoolB->id, gm_user($schoolB->id)->id);

    $this->artisan('guardians:merge', ['--keep' => $keeper->uuid, '--absorb' => [$foreign->uuid], '--apply' => true])
        ->assertFailed();

    expect($foreign->fresh()->deleted_at)->toBeNull();
});

it('finds the certain duplicates and exits non-zero while any exist', function () {
    $school = al_makeSchool();
    $user = gm_user($school->id);

    gm_guardian($school->id, $user->id);
    gm_guardian($school->id, $user->id);

    // Category (1) is the exact set a unique index on (user_id, school_id) would
    // reject, so this doubles as that migration's pre-flight.
    $this->artisan('guardians:find-duplicates', ['--school' => $school->id])->assertFailed();
});

it('exits zero when a school has no same-user duplicates', function () {
    $school = al_makeSchool();

    gm_guardian($school->id, gm_user($school->id)->id, ['phone' => '+2348030000001']);
    gm_guardian($school->id, gm_user($school->id)->id, ['phone' => '+2348030000002']);

    $this->artisan('guardians:find-duplicates', ['--school' => $school->id])->assertSuccessful();
});

it('groups the email-less case by normalised phone without calling it certain', function () {
    $school = al_makeSchool();

    // The email-less duplicate: two users, two guardian rows, one human. Grouping
    // (1) cannot see it — only the phone ties them together, and the stored forms
    // differ because the normalisation boundary post-dates these rows.
    gm_guardian($school->id, gm_user($school->id)->id, ['phone' => '08031234567']);
    gm_guardian($school->id, gm_user($school->id)->id, ['phone' => '+234 803 123 4567']);

    $this->artisan('guardians:find-duplicates', ['--school' => $school->id])
        ->expectsOutputToContain('(2) LIKELY')
        // Likely is not certain: a shared household line is evidence, not proof,
        // so it must not fail the pre-flight on its own.
        ->assertSuccessful();
});
