<?php

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianService;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// The `guardian` role has to exist: the successful login-enable path assigns it, and
// spatie throws RoleDoesNotExist rather than creating one.
beforeEach(fn () => (new RbacSeeder)->run());

/**
 * `can_login = true` ⟹ the account has a deliverable email.
 *
 * Login is email-only, so a login-enabled guardian without a real address is an
 * account nobody can enter and nobody can send a password to — and the flip reported
 * SUCCESS, so a parent is recorded as having portal access they do not have.
 *
 * Enforced at creation and NOT at the transition, which is why this is a bugfix
 * against the current system rather than a guard for a hazard introduced later.
 */
function gli_guardian(int $schoolId, string $email): Guardian
{
    $user = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'Guardian '.Str::random(5),
        'email' => $email,
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
        'email_verified_at' => now(),
    ]);

    return al_makeGuardian($schoolId, $user->id);
}

function gli_student(int $schoolId): Student
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

it('refuses to attach a guardian with login when their email is synthetic', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    expect(fn () => app(GuardianService::class)->attachToStudent($guardian, $student, 'parent', true, true))
        ->toThrow(ValidationException::class);
});

it('allows the same attach without login', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    // The synthetic address exists precisely FOR this case — a guardian on record with
    // no login. The invariant constrains can_login, not the guardian.
    app(GuardianService::class)->attachToStudent($guardian, $student, 'parent', true, false);

    expect($guardian->students()->count())->toBe(1);
});

/**
 * THE TRANSITION — the gap that was live in production.
 *
 * PivotUpdateRequest validates `can_login` as a bare boolean, and GuardianController's
 * email requirement applies only to a NEW guardian, so false→true had no check at all.
 * enableLogin then regenerated the password, notifyGuardian early-returned on the
 * undeliverable address, and the flip succeeded silently.
 */
it('refuses to flip can_login true when the email is synthetic', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    $service = app(GuardianService::class);
    $service->attachToStudent($guardian, $student, 'parent', true, false);

    expect(fn () => $service->updatePivot($student, $guardian, ['can_login' => true]))
        ->toThrow(ValidationException::class);
});

it('allows the flip once the guardian has a real email', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    $service = app(GuardianService::class);
    $service->attachToStudent($guardian, $student, 'parent', true, false);

    $guardian->user->forceFill(['email' => 'parent@example.test'])->save();
    $guardian->unsetRelation('user');

    // School context: the successful path reaches enableLogin, which assigns the
    // `guardian` role — and a School-scoped role refuses a null team (Constitution 13).
    ActiveSchool::runFor(
        $school->id,
        fn () => $service->updatePivot($student, $guardian, ['can_login' => true]),
    );

    expect((bool) $student->guardians()->first()->pivot->can_login)->toBeTrue();
});

it('leaves an unrelated pivot change alone when login is already off', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    $service = app(GuardianService::class);
    $service->attachToStudent($guardian, $student, 'parent', false, false);

    // The guard fires on `can_login = true`, not on every pivot write — a relationship
    // edit for a non-login guardian must not be blocked by an email they never needed.
    $service->updatePivot($student, $guardian, ['relationship' => 'guardian']);

    expect($student->guardians()->first()->pivot->relationship)->toBe('guardian');
});

/**
 * THE DEAD BRANCH IS GONE.
 *
 * enableLogin's Scenario 1 minted a synthetic email and created a LOGIN-ENABLED
 * account with it — a direct contradiction of this invariant, parked behind
 * `if (! $user)` and labelled "shouldn't happen". An exception cannot live inside the
 * method whose job is to have none; if that state ever becomes reachable the correct
 * response is to fail loudly, not to manufacture an account nobody can log into.
 */
it('fails loudly rather than minting an account when a guardian has no user', function () {
    $school = al_makeSchool();
    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);

    // `guardians.user_id` is NOT NULL, so this state is unreachable through the schema —
    // simulated on the instance to prove the branch's replacement.
    $guardian->setRelation('user', null);

    expect(fn () => app(GuardianService::class)->enableLogin($guardian))
        ->toThrow(ValidationException::class);
});

/**
 * THE CARDINALITY PIN — enumeration that does not decay.
 *
 * `can_login` is written to the pivot in exactly two places, both in GuardianService,
 * both behind the guard. This does not rely on today's grep having found them all: a
 * third writer appearing anywhere else fails CI the moment it lands.
 */
it('writes can_login to the pivot in exactly one class', function () {
    $writers = [];

    $appFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($appFiles as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // A pivot WRITE, not a read or a validation rule: the two Eloquent pivot
        // mutators plus a raw update against the join table.
        $writesPivot = preg_match('/updateExistingPivot|syncWithoutDetaching/', $source) === 1
            || preg_match("/DB::table\\('guardian_student'\\)[^;]*->update\\(/s", $source) === 1;

        if ($writesPivot && str_contains($source, "'can_login'")) {
            $writers[] = basename($file->getPathname());
        }
    }

    expect(array_unique($writers))->toBe(['GuardianService.php']);
});

/*
|--------------------------------------------------------------------------
| The data half — the forward guard does not heal existing violations
|--------------------------------------------------------------------------
*/

it('reports a login-enabled guardian whose address cannot receive', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    // Planted through the pivot directly, bypassing the guard — this is a row that
    // predates the enforcement, which is exactly the population the audit exists for.
    $guardian->students()->attach($student->id, ['relationship' => 'parent', 'can_login' => true]);

    $this->artisan('guardians:audit-login-invariant')->assertFailed();
});

it('passes when every login-enabled guardian has a real address', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, 'parent@example.test');
    $student = gli_student($school->id);

    $guardian->students()->attach($student->id, ['relationship' => 'parent', 'can_login' => true]);

    // The assert-zero the cutover's reset-safety argument depends on: code-invariant
    // (the guard) plus data-invariant (this) — complete only when both hold.
    $this->artisan('guardians:audit-login-invariant')->assertSuccessful();
});

it('ignores a guardian who is not login-enabled', function () {
    $school = al_makeSchool();
    $guardian = gli_guardian($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);
    $student = gli_student($school->id);

    $guardian->students()->attach($student->id, ['relationship' => 'parent', 'can_login' => false]);

    // A synthetic address is CORRECT for a non-login guardian — that is what it is for.
    $this->artisan('guardians:audit-login-invariant')->assertSuccessful();
});
