<?php

use App\Http\Requests\ProvisionUserRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\DutySeparation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The super-admin user-provisioning flow.
 *
 * The CRUD is the small half. What is actually load-bearing is that this surface cannot become a
 * way around the two invariants it sits next to: `super_admin` is not assignable through a UI
 * (ADR 0045 / SyncUserRolesRequest D1), and one user does not end up holding both sides of a
 * maker-checker pair (ADR 0040). Both are asserted here against the ROUTE, not against the
 * FormRequest in isolation, because a guard that is not wired is the same as no guard.
 */
function pu_superAdmin(): User
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->assignRole('super_admin');
    $user->flushSchoolAccessCache();

    return $user;
}

function pu_payload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Provisioned',
        'last_name' => 'Seat '.Str::random(4),
        'email' => Str::uuid().'@example.test',
        'password' => 'correct-horse-battery',
        'roles' => ['admin'],
        'schools' => [],
    ], $overrides);
}

/** Does $user hold $role in $school's team — read from the pivot, not from a cache. */
function pu_holdsIn(User $user, School $school, string $role): bool
{
    $roleId = Role::where('name', $role)->whereNull('school_id')->value('id');

    return DB::table('model_has_roles')
        ->where('model_type', User::class)
        ->where('model_id', $user->id)
        ->where('role_id', $roleId)
        ->where('school_id', $school->id)
        ->exists();
}

// ─────────────────────────────────────────────────────────────────── the happy paths

it('seats every assignable role in the granted school, and in that school only', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    $granted = al_makeSchool();
    $other = al_makeSchool();

    // Every assignable role, not a sample — so a seat that cannot actually be provisioned reds
    // here rather than being discovered when somebody needs it.
    foreach (ProvisionUserRequest::assignableRoles() as $role) {
        $email = Str::uuid().'@example.test';

        $this->actingAs($actor)
            ->post('/super-admin/admins', pu_payload([
                'email' => $email,
                'roles' => [$role],
                'schools' => [$granted->uuid],
            ]))
            ->assertSessionHasNoErrors();

        $user = User::withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)
            ->where('email', $email)->firstOrFail();

        expect(pu_holdsIn($user, $granted, $role))->toBeTrue("[{$role}] must be held in the granted school")
            ->and(pu_holdsIn($user, $other, $role))->toBeFalse("[{$role}] must NOT leak into another school");
    }
});

it('grants a cross-school seat in both schools and in no third', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    [$a, $b, $c] = [al_makeSchool(), al_makeSchool(), al_makeSchool()];
    $email = Str::uuid().'@example.test';

    // internal_auditor and executive_director are the seats the business runs across schools.
    $this->actingAs($actor)
        ->post('/super-admin/admins', pu_payload([
            'email' => $email,
            'roles' => ['internal_auditor'],
            'schools' => [$a->uuid, $b->uuid],
        ]))
        ->assertSessionHasNoErrors();

    $user = User::withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)->where('email', $email)->firstOrFail();

    expect(pu_holdsIn($user, $a, 'internal_auditor'))->toBeTrue()
        ->and(pu_holdsIn($user, $b, 'internal_auditor'))->toBeTrue()
        ->and(pu_holdsIn($user, $c, 'internal_auditor'))->toBeFalse('school C was never granted');
});

it('attaches to an EXISTING account rather than minting a duplicate', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    $home = al_makeSchool();
    $second = al_makeSchool();

    $staffer = al_makeUser($home->id);
    $staffer->grantSchoolAccess($home, 'teacher');
    $originalId = $staffer->id;
    $originalPassword = $staffer->fresh()->password;

    $this->actingAs($actor)
        ->post('/super-admin/admins', pu_payload([
            'first_name' => 'Ignored', 'last_name' => 'Ignored',
            'email' => $staffer->email,
            'password' => null,
            'roles' => ['accounts_officer'],
            'schools' => [$second->uuid],
        ]))
        ->assertSessionHasNoErrors();

    expect(User::withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)->where('email', $staffer->email)->count())
        ->toBe(1, 'the existing address must not produce a second account');

    $staffer = $staffer->fresh();

    expect($staffer->id)->toBe($originalId)
        ->and($staffer->password)->toBe($originalPassword, 'an existing account\'s password is never reset here')
        ->and(pu_holdsIn($staffer, $second, 'accounts_officer'))->toBeTrue('the new seat landed')
        ->and(pu_holdsIn($staffer, $home, 'teacher'))->toBeTrue('their existing seat is untouched');
});

it('leaves a 2FA-required seat in the enrolment flow rather than locked out', function () {
    $this->seed(DatabaseSeeder::class);
    config(['rbac.two_factor_enforced' => true]);

    $actor = pu_superAdmin();
    $school = al_makeSchool();
    $email = Str::uuid().'@example.test';

    $this->actingAs($actor)->post('/super-admin/admins', pu_payload([
        'email' => $email,
        'roles' => ['executive_director'],   // in RbacSeeder::TWO_FACTOR_REQUIRED
        'schools' => [$school->uuid],
    ]))->assertSessionHasNoErrors();

    $seated = User::withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)->where('email', $email)->firstOrFail();

    expect($seated->two_factor_confirmed_at)->toBeNull('pre-confirming would satisfy 2FA with nobody enrolled')
        ->and($seated->email_verified_at)->not->toBeNull(
            'without this the 2FA redirect lands on `verified` and bounces to a verification notice '
            .'no mail was ever sent for — a lockout wearing an enrolment redirect\'s clothes');

    // WALK THE CHAIN, do not assert one hop. "Redirected to enrolment" is satisfied by a redirect
    // into a loop just as well as by a reachable page, and the loop is the failure mode.
    $this->actingAs($seated)->get('/dashboard')->assertRedirect(route('security.edit'));

    // security.edit sits behind `password.confirm`, so the next hop is the confirmation screen —
    // which EnsureTwoFactorEnrolled::EXEMPT_PATTERNS lists precisely so an unenrolled user can
    // reach it. That is the difference between a step and a wall.
    $this->actingAs($seated)->get('/settings/security')->assertRedirect(route('password.confirm'));
    $this->actingAs($seated)->get('/user/confirm-password')->assertSuccessful();

    // Confirm, then the enrolment page itself loads — the loop closes.
    $this->actingAs($seated)->post('/user/confirm-password', ['password' => 'correct-horse-battery'])
        ->assertRedirect();
    $this->actingAs($seated)->get('/settings/security')->assertSuccessful();
});

// ─────────────────────────────────────────────────────────────────── the actor gate

it('refuses a non-super_admin actor', function () {
    $this->seed(DatabaseSeeder::class);

    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $admin->grantSchoolAccess($school, 'admin');   // holds rbac.manage_users, and it is not enough

    $this->actingAs($admin)
        ->post('/super-admin/admins', pu_payload(['roles' => ['teacher'], 'schools' => [$school->uuid]]))
        ->assertForbidden();

    expect(User::withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)->count())->toBe(2);
});

it('cannot mint a super_admin through the flow', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    $school = al_makeSchool();
    $email = Str::uuid().'@example.test';

    $this->actingAs($actor)
        ->post('/super-admin/admins', pu_payload([
            'email' => $email,
            'roles' => ['super_admin'],
            'schools' => [$school->uuid],
        ]))
        ->assertSessionHasErrors('roles.0');

    expect(User::withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)->where('email', $email)->exists())
        ->toBeFalse('a refused request writes nothing');
});

/*
 * ─── RE-SCOPING A SEAT'S SCHOOLS ─────────────────────────────────────────────────────────────────
 *
 * `syncSchools` grants and revokes ONE named role's school set. Before this change it always meant
 * `admin` — `grantSchoolAccess`/`revokeSchoolAccess` both default that way — so the moment the
 * screen listed a non-admin user, saving their schools would have granted them `admin` and revoked
 * a seat they never held. The arm below is aimed squarely at that, on `internal_auditor` because it
 * is the cross-school seat whose revocation semantics are pinned elsewhere
 * (InternalAuditorCrossSchoolRevocationTest) and must keep going through `revokeSchoolAccess`.
 */
it('syncs the NAMED seat\'s schools and leaves the person\'s other roles alone', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    [$a, $b] = [al_makeSchool(), al_makeSchool()];

    $auditor = al_makeUser($a->id);
    $auditor->grantSchoolAccess($a, 'internal_auditor');
    $auditor->grantSchoolAccess($a, 'admin_viewer');   // a second, unrelated seat in the same school

    // Move the auditor seat from A to B.
    $this->actingAs($actor)
        ->put("/super-admin/admins/{$auditor->uuid}/schools", [
            'schools' => [$b->uuid],
            'role' => 'internal_auditor',
        ])
        ->assertSessionHasNoErrors();

    expect(pu_holdsIn($auditor, $b, 'internal_auditor'))->toBeTrue('granted in the new school')
        ->and(pu_holdsIn($auditor, $a, 'internal_auditor'))->toBeFalse('revoked in the old one')
        // THE POINT OF THE ARM: the other seat is untouched, and no `admin` appeared.
        ->and(pu_holdsIn($auditor, $a, 'admin_viewer'))->toBeTrue('an unrelated seat is not collateral')
        ->and(pu_holdsIn($auditor, $a, 'admin'))->toBeFalse('the default role must not be granted')
        ->and(pu_holdsIn($auditor, $b, 'admin'))->toBeFalse();
});

it('refuses to re-scope a super_admin\'s access', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    $victim = pu_superAdmin();
    $school = al_makeSchool();

    $this->actingAs($actor)
        ->put("/super-admin/admins/{$victim->uuid}/schools", ['schools' => [$school->uuid]])
        ->assertForbidden();
});

it('pins the assignable set in both directions', function () {
    // super_admin absent — the D1 mirror.
    expect(ProvisionUserRequest::assignableRoles())->not->toContain('super_admin');

    // and every OTHER seeded role present, so a role cannot be seeded into an unassignable limbo
    // the way executive_director was.
    expect(ProvisionUserRequest::assignableRoles())
        ->toEqual(array_values(array_diff(RbacSeeder::ROLES, ['super_admin'])));

    expect(ProvisionUserRequest::assignableRoles())
        ->toContain('admin')->toContain('admin_viewer')
        ->toContain('executive_director')->toContain('internal_auditor');
})->group('arch');
