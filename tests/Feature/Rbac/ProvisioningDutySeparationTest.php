<?php

use App\Exceptions\DutySeparationViolationException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\DutySeparation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * ADR 0040 mechanism 2 at the PROVISIONING surface — maker ≠ checker refused at ASSIGNMENT time,
 * before anything is written.
 *
 * This file exists because the trigger is not enough, and saying why is the point. The database
 * `CHECK (submitted_by <> decided_by)` catches a person approving their OWN request; it is
 * absolute and it stays. What it cannot see is a person holding both CAPABILITIES — a setup that
 * reads as segregated and lets one operator approve a colleague's work in both directions, with
 * every individual act passing the CHECK. That is a grant-time problem, so it is refused at grant
 * time; the two layers are both asserted below, mirroring MakerCheckerSeparationTest's structure.
 *
 * THE ARMS BELOW SEPARATE TWO CLAIMS THAT ARE EASY TO CONFLATE. "The request refuses" and "nothing
 * was written" are different facts and a passing refusal proves only the first — a 422 returned
 * after a partial loop write would satisfy an assertion that only checked the status. Every refusal
 * arm therefore asserts the row counts too.
 */
function pds_actor(): User
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->assignRole('super_admin');

    return $user;
}

function pds_post(User $actor, array $payload)
{
    return test()->actingAs($actor)->post('/super-admin/admins', array_merge([
        'first_name' => 'Test', 'last_name' => 'Seat',
        'email' => Str::uuid().'@example.test',
        'password' => 'correct-horse-battery',
    ], $payload));
}

/** An enforced finance pair, taken from the derived set rather than typed out. */
function pds_pair(): array
{
    $pairs = DutySeparation::enforcedPairs();
    expect(count($pairs))->toBeGreaterThan(0, 'no enforced pairs would make every arm here vacuous');

    // finance.credit-note.approve ↔ finance.credit-note.submit: executive_director holds the
    // checker, accounts_officer and finance_lead the maker.
    foreach ($pairs as $pair) {
        if ($pair['checker'] === 'finance.credit-note.approve') {
            return $pair;
        }
    }

    return $pairs[0];
}

it('refuses a create that assigns a maker role together with a checker role, and writes nothing', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pds_actor();
    $school = al_makeSchool();
    $email = Str::uuid().'@example.test';
    $pair = pds_pair();

    $before = DB::table('model_has_roles')->count();

    $response = pds_post($actor, [
        'email' => $email,
        // accounts_officer is the maker on the credit-note pair; executive_director the checker.
        'roles' => ['accounts_officer', 'executive_director'],
        'schools' => [$school->uuid],
    ]);

    $response->assertSessionHasErrors('roles');

    // NAME THE MECHANISM, not "it was refused" — several guards on this path could refuse, and a
    // loose assertion passes when the wrong one fires.
    $message = session('errors')->get('roles')[0];
    expect($message)->toContain($pair['checker'])
        ->and($message)->toContain($pair['maker'])
        ->and($message)->toContain('Segregation of duties');

    expect(User::withoutGlobalScope(SchoolScope::class)->where('email', $email)->exists())
        ->toBeFalse('no user row')
        ->and(DB::table('model_has_roles')->count())
        ->toBe($before, 'no role row')
        ->and(DB::table('school_user')->where('school_id', $school->id)->count())
        ->toBe(0, 'no school-access row');
});

it('refuses adding a maker role to an EXISTING checker-side user, leaving their roles untouched', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pds_actor();
    $school = al_makeSchool();

    $ed = al_makeUser($school->id);
    $ed->grantSchoolAccess($school, 'executive_director');   // the checker side, already held

    $rolesBefore = DB::table('model_has_roles')
        ->where('model_type', User::class)->where('model_id', $ed->id)
        ->orderBy('role_id')->pluck('role_id')->all();

    pds_post($actor, [
        'email' => $ed->email,
        'roles' => ['accounts_officer'],                     // the maker side
        'schools' => [$school->uuid],
    ])->assertSessionHasErrors('roles');

    $rolesAfter = DB::table('model_has_roles')
        ->where('model_type', User::class)->where('model_id', $ed->id)
        ->orderBy('role_id')->pluck('role_id')->all();

    // The SET, not the count — a count cannot tell "these roles" from "some other roles".
    expect($rolesAfter)->toEqual($rolesBefore, 'an existing user\'s roles are untouched by a refusal');
});

it('permits the same two roles when they are seated in DIFFERENT schools', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pds_actor();
    [$a, $b] = [al_makeSchool(), al_makeSchool()];

    $user = al_makeUser($a->id);
    $user->grantSchoolAccess($a, 'executive_director');

    // Duty separation is scoped PER SCHOOL (spatie teams): a checker at A and a maker at B share no
    // record on which both apply. Without this arm, a guard that refused unconditionally — the
    // broken-closed failure — would pass every arm above.
    pds_post($actor, [
        'email' => $user->email,
        'roles' => ['accounts_officer'],
        'schools' => [$b->uuid],
    ])->assertSessionHasNoErrors();

    $aoId = Role::where('name', 'accounts_officer')->whereNull('school_id')->value('id');

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->where('role_id', $aoId)
        ->where('school_id', $b->id)->exists())->toBeTrue();
});

/**
 * ─── THE CONFLICT IS DERIVED FROM PERMISSION SETS, NOT FROM A LIST OF ROLE NAMES ──────────────────
 *
 * The arm the design rests on: two THROWAWAY roles, invented in this test and named nowhere in app
 * code, carrying one side of a pair each. The same guard refuses their combination with no edit to
 * ProvisionUserRequest, DutySeparation or ApprovalAbility. A guard keyed on role names would pass
 * them happily; this one refuses because `assertRoleSetAllowed()` resolves each role to its
 * PERMISSIONS and compares against `enforcedPairs()`.
 *
 * WHAT THIS ARM CANNOT DO, STATED RATHER THAN GLOSSED. The brief asked for a synthetic PERMISSION
 * pair. That is not constructible at runtime and the reason is worth recording: `pairs()` iterates
 * `App\Enums\Permission::cases()` (DutySeparation:76) — the ENUM, not the `permissions` table — so
 * a row inserted here is invisible to the derivation. It was tried first and the arm red on exactly
 * that, which is how the limit was measured rather than assumed. The enum is code and a new case is
 * a code change by definition, so "a pair coined later is covered with no edit to the guard" is
 * pinned one level up instead, by `GrantsMapSeparationTest`'s arms over `pairs()` and
 * `MAKER_OVERRIDES`.
 *
 * So what is synthetic here is the ROLE side, which is the side this guard could plausibly have
 * hardcoded and did not. The permissions are real enum members, and the roles are given ONLY those
 * two abilities so that nothing else about them can explain the refusal.
 */
it('refuses a pair split across two throwaway roles, proving the conflict is read from the permission sets', function () {
    $this->seed(DatabaseSeeder::class);

    $school = al_makeSchool();
    $pair = pds_pair();

    setPermissionsTeamId(null);

    $makerRole = Role::create(['name' => 'sandbox_maker', 'guard_name' => 'web', 'school_id' => null]);
    $checkerRole = Role::create(['name' => 'sandbox_checker', 'guard_name' => 'web', 'school_id' => null]);
    $makerRole->givePermissionTo($pair['maker']);
    $checkerRole->givePermissionTo($pair['checker']);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Neither name appears in any guard — assert that, rather than trusting it, since the whole
    // claim is that the refusal cannot be coming from a name.
    foreach ([
        app_path('Support/DutySeparation.php'),
        app_path('Support/ApprovalAbility.php'),
        app_path('Http/Requests/ProvisionUserRequest.php'),
    ] as $guard) {
        expect(file_get_contents($guard))
            ->not->toContain('sandbox_maker')
            ->not->toContain('sandbox_checker');
    }

    // NAME THE EXCEPTION AND THE ABILITY, not "it threw" — a NullTeamRoleAssignment or a missing
    // role would satisfy a looser assertion just as well.
    expect(fn () => DutySeparation::assertRoleSetAllowed(
        'sandbox@example.test',
        (int) $school->id,
        ['sandbox_maker', 'sandbox_checker'],
    ))->toThrow(DutySeparationViolationException::class, $pair['checker']);

    // Each side ALONE is fine — the known-negative. Without it, a guard that refused every role set
    // would satisfy the arm above and look like strictness.
    DutySeparation::assertRoleSetAllowed('sandbox@example.test', (int) $school->id, ['sandbox_maker']);
    DutySeparation::assertRoleSetAllowed('sandbox@example.test', (int) $school->id, ['sandbox_checker']);

    expect(true)->toBeTrue('each side alone was accepted — no exception reached here');
});

/**
 * ─── THE ARM THAT DISTINGUISHES LAYER 3 FROM LAYER 2, AND WHY IT HAD TO BE WRITTEN ───────────────
 *
 * Every refusal arm above passes with `ProvisionUserRequest`'s duty-separation check DELETED.
 * Measured, not suspected: removing the call left this file 12/12 green. `bootstrap/app.php:145 (DutySeparationViolationException)`
 * renders `DutySeparationViolationException` as a redirect-back-with-errors keyed on `roles` —
 * byte-identical in shape to the request's own refusal — and the controller's `DB::transaction`
 * rolls back the loop's writes, so "it was refused" and "nothing was written" are BOTH satisfied by
 * layer 2 alone. The arms were true and blind to the axis they were meant to cover.
 *
 * This is the one observable difference: layer 3 decides BEFORE the controller runs, so `User` is
 * never inserted. Layer 2 inserts it and rolls it back — and a rolled-back insert still FIRES the
 * `created` model event, because Eloquent fires it after the INSERT and before the COMMIT. So the
 * event is the discriminator, and it is the reason this arm listens for one instead of counting
 * rows: a row count cannot tell "never written" from "written and rolled back", which is exactly
 * the pair of states in question.
 */
it('refuses before the controller runs — no user is created and rolled back', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pds_actor();
    $school = al_makeSchool();

    $created = 0;
    User::created(function () use (&$created) {
        $created++;
    });

    // KNOWN POSITIVE FIRST. Without it, an arm asserting "no event fired" passes if the listener
    // was never wired — the broken-closed failure that reads as strictness.
    pds_post($actor, [
        'email' => Str::uuid().'@example.test',
        'roles' => ['admin_viewer'],
        'schools' => [$school->uuid],
    ])->assertSessionHasNoErrors();

    expect($created)->toBe(1, 'the listener is wired and a clean provision does create a user');

    // Now the conflicting one. Layer 3 refuses in the FormRequest, so store() is never entered.
    pds_post($actor, [
        'email' => Str::uuid().'@example.test',
        'roles' => ['accounts_officer', 'executive_director'],
        'schools' => [$school->uuid],
    ])->assertSessionHasErrors('roles');

    expect($created)->toBe(1,
        'a conflicting request must not reach the controller at all — a second `created` event '
        .'means the refusal came from User::assignRole inside a transaction that was rolled back, '
        .'which is layer 2 doing layer 3\'s job');
});

/**
 * DEFENCE IN DEPTH. The request refuses the CAPABILITY; the database refuses the ACT. Neither
 * subsumes the other, and this arm is here so removing one is visible rather than silent.
 *
 * `User::assignRole` (:440) is the layer between them: it throws before the spatie write on every
 * path — HTTP, programmatic, seeder — so a call site that skips ProvisionUserRequest entirely still
 * cannot create a both-sides user.
 */
it('still refuses at the assignRole layer when the request is bypassed entirely', function () {
    $this->seed(DatabaseSeeder::class);

    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'executive_director');

    $pair = pds_pair();

    expect(fn () => $user->grantSchoolAccess($school, 'accounts_officer'))
        ->toThrow(DutySeparationViolationException::class, $pair['maker']);

    $aoId = Role::where('name', 'accounts_officer')->whereNull('school_id')->value('id');

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->where('role_id', $aoId)->exists())
        ->toBeFalse('the guard refuses BEFORE the write — nothing lands');
});
