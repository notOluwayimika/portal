<?php

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuardianAccountCreatedNotification;
use App\Services\GuardianService;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

/**
 * A user that CANNOT authenticate — disabled, so Fortify's `isDisabled()` check
 * rejects it regardless of the password.
 *
 * The structural arms below (pivot moves, collisions, back-fill, audit) use this
 * for the absorbed side deliberately. An absorbed record whose account can still
 * sign in is a decision with a parent on the other end of it, and the merge now
 * refuses it unless the operator asks for consolidation — so an arm about where a
 * pivot row lands would otherwise be testing the login guard by accident and
 * proving nothing about the pivot.
 */
function gm_dormantUser(int $schoolId): User
{
    $user = gm_user($schoolId);
    $user->forceFill(['disabled_at' => now()])->save();

    return $user;
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

/**
 * merge() is off-request and refuses without a school context, so every call
 * establishes one the way the console command does — from the KEEPER's own
 * school_id, never from a user row and never from an actor. `$context` exists
 * only so one arm can drive it from the wrong school.
 */
function gm_merge(Guardian $keeper, array $absorbed, bool $apply = true, ?int $context = null, bool $consolidate = false): array
{
    return ActiveSchool::runFor(
        $context ?? (int) $keeper->school_id,
        fn (): array => app(GuardianService::class)->merge($keeper, new Collection($absorbed), $apply, $consolidate),
    );
}

/*
|--------------------------------------------------------------------------
| 1. The move — the absorbed row's student follows it to the keeper
|--------------------------------------------------------------------------
*/

it('re-points a student link the keeper does not already hold', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id);

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

    // ONE account behind both rows, deliverable — the certain duplicate. Both
    // booleans are then free to OR-merge: a `can_login` that changes rows without
    // changing ACCOUNTS strands nobody, which is the only reason this arm can
    // exercise the flag at all.
    $user = gm_user($school->id, 'shared.parent@example.test');
    $keeper = gm_guardian($school->id, $user->id);
    $absorbed = gm_guardian($school->id, $user->id);

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
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id);
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
| 4. Login — the account, not the pivot flag
|--------------------------------------------------------------------------
|
| Two guards, and neither subsumes the other.
|
| The first asks whether the absorbed record's ACCOUNT can sign in — derived from
| what Fortify checks (not disabled, password set), never from `can_login`, which
| authentication does not read. Absorbing such a record does not move its login;
| it strands it. Refused by default, consolidated on request.
|
| The second asks whether `can_login` can land on a keeper who cannot be mailed.
| That is the pre-existing invariant and it is orthogonal: a deliverable keeper
| satisfies it and tells you nothing about the first.
*/

it('refuses to absorb a record whose account can still sign in', function () {
    $school = al_makeSchool();

    // The 13-of-14 shape. NO `can_login` row anywhere — the flag the first version
    // of this guard keyed on is absent — and the account is nonetheless a working
    // login: enabled, password set, deliverable address.
    $keeper = gm_guardian($school->id, gm_user($school->id, 'keeper.parent@example.test')->id);
    $donorUser = gm_user($school->id, 'signs.in.with.this@example.test');
    $absorbed = gm_guardian($school->id, $donorUser->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true, canLogin: false);

    expect(fn () => gm_merge($keeper, [$absorbed]))->toThrow(ValidationException::class);

    $pivot = gm_pivot($absorbed, $student);

    expect($pivot)->not->toBeNull()
        ->and(gm_pivot($keeper, $student))->toBeNull()
        ->and($absorbed->fresh()->deleted_at)->toBeNull()
        // The account that would have been stranded: still enabled, still able to
        // sign in, and — had the merge run — backing nothing.
        ->and(User::find($donorUser->id)->disabled_at)->toBeNull();
});

it('still refuses when the absorbed record carries can_login on its own account', function () {
    $school = al_makeSchool();

    $keeper = gm_guardian($school->id, gm_user($school->id, 'keeper.parent@example.test')->id);
    $donorUser = gm_user($school->id, 'works@example.test');
    $absorbed = gm_guardian($school->id, $donorUser->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true, canLogin: true);

    expect(fn () => gm_merge($keeper, [$absorbed]))->toThrow(ValidationException::class);

    expect($absorbed->fresh()->deleted_at)->toBeNull()
        ->and(User::find($donorUser->id)->disabled_at)->toBeNull();
});

it('proceeds without the flag when the absorbed account cannot sign in', function () {
    $school = al_makeSchool();

    // Disabled donor: nothing to consolidate, because nobody can sign in with it.
    // This is what makes the guard a decision point rather than a blanket block.
    $keeper = gm_guardian($school->id, gm_user($school->id, 'keeper.parent@example.test')->id);
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student);

    $plan = gm_merge($keeper, [$absorbed]);

    expect($absorbed->fresh()->deleted_at)->not->toBeNull()
        ->and($plan['login_decision']['donors'][0]['can_authenticate'])->toBeFalse()
        ->and($plan['login_decision']['consolidating'])->toBeFalse()
        ->and($plan['login_decision']['will_notify'])->toBeFalse();
});

it('consolidates the login when asked: donor disabled, keeper enabled, parent notified once, after commit', function () {
    Notification::fake();
    (new RbacSeeder)->run();

    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id, 'keeper.parent@example.test')->id);
    $donorUser = gm_user($school->id, 'signs.in.with.this@example.test');
    $absorbed = gm_guardian($school->id, $donorUser->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true);

    $before = User::find($keeper->user_id)->password;

    $plan = gm_merge($keeper, [$absorbed], consolidate: true);

    expect($plan['login_decision']['consolidating'])->toBeTrue()
        ->and($plan['login_decision']['donors'][0]['action'])->toBe('disable')
        // The account that could sign in is ended; the survivor is enabled and
        // re-credentialled, so the password the parent is being sent is real.
        ->and(User::find($donorUser->id)->disabled_at)->not->toBeNull()
        ->and(User::find($keeper->user_id)->disabled_at)->toBeNull()
        ->and(User::find($keeper->user_id)->password)->not->toBe($before)
        ->and($absorbed->fresh()->deleted_at)->not->toBeNull();

    Notification::assertSentToTimes(User::find($keeper->user_id), GuardianAccountCreatedNotification::class, 1);
    Notification::assertNotSentTo(User::find($donorUser->id), GuardianAccountCreatedNotification::class);

    // Same vocabulary as every other login transition — not a merge-only event.
    expect(DB::table('activity_log')->where('event', 'login_disabled')->where('subject_id', $absorbed->id)->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'login_enabled')->where('subject_id', $keeper->id)->count())->toBe(1);
});

it('refuses to consolidate into a keeper the parent cannot be told about', function () {
    Notification::fake();

    $school = al_makeSchool();

    // Consolidating into an address nobody can receive is the original defect
    // wearing a flag: the donor's password stops working and the replacement
    // cannot be delivered.
    $keeper = gm_guardian($school->id, gm_user($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN)->id);
    $donorUser = gm_user($school->id, 'signs.in.with.this@example.test');
    $absorbed = gm_guardian($school->id, $donorUser->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student);

    expect(fn () => gm_merge($keeper, [$absorbed], consolidate: true))->toThrow(ValidationException::class);

    expect(User::find($donorUser->id)->disabled_at)->toBeNull()
        ->and($absorbed->fresh()->deleted_at)->toBeNull();

    Notification::assertNothingSent();
});

it('sends no notification when the merge rolls back mid-apply', function () {
    Notification::fake();
    (new RbacSeeder)->run();

    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id, 'keeper.parent@example.test')->id);
    $donorUser = gm_user($school->id, 'signs.in.with.this@example.test');
    $absorbed = gm_guardian($school->id, $donorUser->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student);

    // A genuine mid-apply failure, inside the transaction, AFTER the pivot writes:
    // the soft delete is the last state change applyMergePlan makes.
    Guardian::deleting(function () {
        throw new RuntimeException('planted mid-apply failure');
    });

    try {
        expect(fn () => gm_merge($keeper, [$absorbed], consolidate: true))->toThrow(RuntimeException::class);

        // Nothing committed…
        expect($absorbed->fresh()->deleted_at)->toBeNull()
            ->and(gm_pivot($keeper, $student))->toBeNull()
            ->and(gm_pivot($absorbed, $student))->not->toBeNull()
            ->and(User::find($donorUser->id)->disabled_at)->toBeNull();

        // …and no email in flight for a merge that did not happen. This is why the
        // notification is drained after the transaction returns rather than sent
        // where the password is written.
        Notification::assertNothingSent();
    } finally {
        Guardian::flushEventListeners();
    }
});

it('aborts the whole merge rather than move login access onto an undeliverable keeper', function () {
    $school = al_makeSchool();

    // ONE user, two guardian rows — the certain duplicate, and a shape the account
    // guard has nothing to say about: there is no second account to strand. What
    // is wrong is that the account cannot receive mail, which is the deliverability
    // invariant's question.
    $user = gm_user($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $keeper = gm_guardian($school->id, $user->id);
    $absorbed = gm_guardian($school->id, $user->id);

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

it('aborts on a collision that leaves an already-true flag on an undeliverable keeper', function () {
    $school = al_makeSchool();

    // The collision side of the deliverability guard, and the case a
    // transition-shaped check waves through: same account on both sides,
    // undeliverable, and the keeper's own row ALREADY carries the flag — so
    // nothing is raised. The write still leaves the flag true.
    $user = gm_user($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $keeper = gm_guardian($school->id, $user->id);
    $absorbed = gm_guardian($school->id, $user->id);

    $student = gm_student($school->id);
    gm_link($keeper, $student, canLogin: true);
    gm_link($absorbed, $student, canLogin: false);

    expect(fn () => gm_merge($keeper, [$absorbed]))->toThrow(ValidationException::class);

    expect(gm_pivot($absorbed, $student))->not->toBeNull()
        ->and($absorbed->fresh()->deleted_at)->toBeNull()
        ->and(DB::table('guardian_student')->where('student_id', $student->id)->count())->toBe(2);
});

it('allows a same-account login to move once that account is deliverable', function () {
    $school = al_makeSchool();

    // One human, one account, two guardian rows, a real address. The flag does not
    // change accounts, so nobody is stranded and no consolidation is needed.
    $user = gm_user($school->id, 'real.parent@example.test');
    $keeper = gm_guardian($school->id, $user->id);
    $absorbed = gm_guardian($school->id, $user->id);

    $student = gm_student($school->id);
    gm_link($absorbed, $student, isPrimary: true, canLogin: true);

    $plan = gm_merge($keeper, [$absorbed]);

    expect((bool) gm_pivot($keeper, $student)->can_login)->toBeTrue()
        ->and($plan['login_decision']['donors'][0]['same_user_as_keeper'])->toBeTrue()
        ->and($plan['login_decision']['donors'][0]['can_authenticate'])->toBeFalse()
        ->and($plan['login_decision']['consolidating'])->toBeFalse();
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
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id, [
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
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id, ['status' => 'blocked']);

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

it('refuses when the keeper is not in the active school, even though both rows match each other', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    // Two guardians that agree with each other perfectly — the cross-school check
    // has nothing to say about them. They are simply not in the school the caller
    // has context for, which is the shape applySchoolScope's `OR user_id has
    // access` branch hands a request-side caller.
    $keeper = gm_guardian($schoolB->id, gm_user($schoolB->id)->id);
    $absorbed = gm_guardian($schoolB->id, gm_dormantUser($schoolB->id)->id);
    $student = gm_student($schoolB->id);
    gm_link($absorbed, $student);

    expect(fn () => gm_merge($keeper, [$absorbed], context: $schoolA->id))
        ->toThrow(ValidationException::class);

    expect($absorbed->fresh()->deleted_at)->toBeNull()
        ->and(gm_pivot($absorbed, $student))->not->toBeNull();
});

it('sequences two absorbed records so the second one collides with what the first moved', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id, ['occupation' => null]);
    $first = gm_guardian($school->id, gm_dormantUser($school->id)->id, ['occupation' => 'Nurse']);
    $second = gm_guardian($school->id, gm_dormantUser($school->id)->id, ['occupation' => 'Doctor']);

    // Neither absorbed record is linked to a student the KEEPER holds, so against
    // the keeper's original rows both look like moves. They are not: the first
    // move gives the keeper this student, which makes the second a collision.
    // Classifying both as moves is a duplicate-key error, not a wrong answer.
    $shared = gm_student($school->id);
    gm_link($first, $shared, isPrimary: false, relationship: 'mother');
    gm_link($second, $shared, isPrimary: true, relationship: 'father');

    $plan = gm_merge($keeper, [$first, $second]);

    expect($plan['pivot_moves'])->toHaveCount(1)
        ->and($plan['pivot_collisions'])->toHaveCount(1)
        ->and(DB::table('guardian_student')->where('student_id', $shared->id)->count())->toBe(1);

    $pivot = gm_pivot($keeper, $shared);

    expect((int) $pivot->guardian_id)->toBe($keeper->id)
        // The surviving row is the one the FIRST move brought over, so its
        // relationship is the first's; the second's is_primary is OR-merged in.
        ->and($pivot->relationship)->toBe('mother')
        ->and((bool) $pivot->is_primary)->toBeTrue()
        // Back-fill takes the first non-blank in absorb order, and both records
        // are soft-deleted.
        ->and($keeper->fresh()->occupation)->toBe('Nurse')
        ->and($first->fresh()->deleted_at)->not->toBeNull()
        ->and($second->fresh()->deleted_at)->not->toBeNull();
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
    // Dormant, because the arm is about what the merge does to `users` rows and
    // their other schools — a live donor account is the login guard's question and
    // is refused before any of this is reached.
    $sharedUser = gm_dormantUser($schoolA->id);
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

it('refuses rather than orphan a user that can still sign in', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $orphanUser = gm_user($school->id);
    $absorbed = gm_guardian($school->id, $orphanUser->id);

    // THIS ARM USED TO ASSERT THE DEFECT AS CORRECT BEHAVIOUR. It reported the
    // orphan and let the merge stand — but an orphaned user that is enabled with a
    // password IS a working login, and after the merge it backs nothing, so the
    // parent signs in to an empty portal. "Report it, do not act on it" is the
    // right rule for the row; it is the wrong rule for the account.
    expect(fn () => gm_merge($keeper, [$absorbed]))->toThrow(ValidationException::class);

    expect($absorbed->fresh()->deleted_at)->toBeNull()
        ->and(User::find($orphanUser->id)->disabled_at)->toBeNull();
});

it('reports a dormant absorbed user left backing no live guardian without acting on it', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $orphanUser = gm_dormantUser($school->id);
    $absorbed = gm_guardian($school->id, $orphanUser->id);

    $plan = gm_merge($keeper, [$absorbed]);

    // Nobody can sign in with it, so nothing is stranded — but "this account
    // should go" is still a decision, not a consequence of a merge.
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
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id);
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
        ->and($properties['school_id'])->toBe($school->id)
        // The ids, not just the count: a colliding pivot row is hard-deleted, so
        // a count is the point at which "which child" stops having an answer.
        ->and($properties['moved_student_ids'])->toBe([$student->id])
        ->and($properties['collision_student_ids'])->toBe([]);
});

it('emits the same per-link events every other pivot writer emits', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id);
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id);

    $moved = gm_student($school->id);
    $shared = gm_student($school->id);
    gm_link($keeper, $shared);
    gm_link($absorbed, $moved, relationship: 'guardian');
    gm_link($absorbed, $shared, isPrimary: true, relationship: 'father');

    gm_merge($keeper, [$absorbed]);

    // The keeper GAINED a link — the transition attachToStudent was changed to
    // log because "who gave this adult access to this child, and when" had no
    // answer otherwise.
    $attached = DB::table('activity_log')
        ->where('event', 'attached')
        ->where('subject_id', $keeper->id)
        ->where('subject_type', Guardian::class)
        ->get();

    expect($attached)->toHaveCount(1)
        ->and(json_decode((string) $attached->first()->properties, true)['student_id'])->toBe($moved->id)
        ->and(json_decode((string) $attached->first()->properties, true)['via'])->toBe('merge');

    // The absorbed record LOST one, and the row itself is hard-deleted — this
    // entry is the only surviving record of what it held.
    $detached = DB::table('activity_log')
        ->where('event', 'detached')
        ->where('subject_id', $absorbed->id)
        ->where('subject_type', Guardian::class)
        ->get();

    expect($detached)->toHaveCount(1);

    $properties = json_decode((string) $detached->first()->properties, true);

    expect($properties['student_id'])->toBe($shared->id)
        ->and($properties['relationship'])->toBe('father')
        ->and($properties['is_primary'])->toBeTrue()
        ->and($properties['merged_into_guardian_id'])->toBe($keeper->id);
});

/*
|--------------------------------------------------------------------------
| The dry run, and the two commands
|--------------------------------------------------------------------------
*/

it('writes nothing on a dry run and returns the same plan the apply executes', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id)->id, ['occupation' => null]);
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id, ['occupation' => 'Nurse']);
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
    $absorbed = gm_guardian($school->id, gm_dormantUser($school->id)->id);
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

it('prints the account decision in the dry run, before anything can be applied', function () {
    $school = al_makeSchool();
    $keeper = gm_guardian($school->id, gm_user($school->id, 'keeper.parent@example.test')->id);
    $donorUser = gm_user($school->id, 'signs.in.with.this@example.test');
    $absorbed = gm_guardian($school->id, $donorUser->id);
    $student = gm_student($school->id);
    gm_link($absorbed, $student);

    // Refused without the flag. The refusal throws before a plan exists, so what
    // the operator gets here is the message naming the accounts rather than the
    // table — the table is on the path where a merge is actually on offer.
    $this->artisan('guardians:merge', ['--keep' => $keeper->uuid, '--absorb' => [$absorbed->uuid]])
        ->expectsOutputToContain('can sign in today')
        ->assertFailed();

    // With the flag, the plan renders and names the account it will disable.
    $this->artisan('guardians:merge', [
        '--keep' => $keeper->uuid,
        '--absorb' => [$absorbed->uuid],
        '--consolidate-login' => true,
    ])
        ->expectsOutputToContain('Portal accounts:')
        ->expectsOutputToContain('DISABLE this account')
        ->expectsOutputToContain('The parent WILL be emailed fresh credentials')
        ->assertSuccessful();

    // Still a dry run: nothing written, nobody disabled.
    expect($absorbed->fresh()->deleted_at)->toBeNull()
        ->and(User::find($donorUser->id)->disabled_at)->toBeNull();
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
