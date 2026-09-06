<?php

use App\Http\Requests\ProvisionUserRequest;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
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

        $user = User::withoutGlobalScope(SchoolScope::class)
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

    $user = User::withoutGlobalScope(SchoolScope::class)->where('email', $email)->firstOrFail();

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

    expect(User::withoutGlobalScope(SchoolScope::class)->where('email', $staffer->email)->count())
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

    $seated = User::withoutGlobalScope(SchoolScope::class)->where('email', $email)->firstOrFail();

    expect($seated->two_factor_confirmed_at)->toBeNull('pre-confirming would satisfy 2FA with nobody enrolled');

    // Positive form, so the sentence survives: Pest discards a message passed to a NEGATED
    // expectation (tests/Feature/Quality/PestNegatedExpectationMessagesTest).
    expect($seated->email_verified_at !== null)->toBeTrue(
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

    expect(User::withoutGlobalScope(SchoolScope::class)->count())->toBe(2);
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

    expect(User::withoutGlobalScope(SchoolScope::class)->where('email', $email)->exists())
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

/*
 * ─── THE LISTING: SEARCH, FILTERS, PAGINATION ────────────────────────────────────────────────────
 *
 * Asserted on the SET of rows returned, never on the count. A count cannot tell "these two people"
 * from "some other two", and every filter here is exactly the kind of thing that returns a
 * plausible number of wrong rows.
 */
function pu_listedEmails($response): array
{
    $emails = collect($response->viewData('page')['props']['admins'])->pluck('email')->all();
    sort($emails);

    return $emails;
}

it('filters by role, by school and by search term — on the rows, not the count', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    [$lagos, $abuja] = [al_makeSchool(), al_makeSchool()];

    $bursar = al_makeUser($lagos->id);
    $bursar->forceFill(['first_name' => 'Ada', 'last_name' => 'Okonkwo', 'email' => 'ada.okonkwo@example.test'])->save();
    $bursar->grantSchoolAccess($lagos, 'accounts_officer');

    $auditor = al_makeUser($abuja->id);
    $auditor->forceFill(['first_name' => 'Bem', 'last_name' => 'Tersoo', 'email' => 'bem.tersoo@example.test'])->save();
    $auditor->grantSchoolAccess($abuja, 'internal_auditor');

    $both = al_makeUser($lagos->id);
    $both->forceFill(['first_name' => 'Chi', 'last_name' => 'Eze', 'email' => 'chi.eze@example.test'])->save();
    $both->grantSchoolAccess($abuja, 'accounts_officer');

    $get = fn (array $query) => $this->actingAs($actor)->get('/super-admin/admins?'.http_build_query($query));

    // ROLE — Ada and Chi are accounts officers; Bem is not.
    expect(pu_listedEmails($get(['role' => 'accounts_officer'])))
        ->toEqual(['ada.okonkwo@example.test', 'chi.eze@example.test']);

    // SCHOOL — filters on the SEAT's team. Chi is seated in Abuja despite a Lagos primary
    // school_id, which is the case a school_user-pivot filter would get wrong.
    expect(pu_listedEmails($get(['school' => $abuja->uuid])))
        ->toEqual(['bem.tersoo@example.test', 'chi.eze@example.test']);

    // ROLE + SCHOOL compose (AND), rather than the last one winning.
    expect(pu_listedEmails($get(['role' => 'accounts_officer', 'school' => $abuja->uuid])))
        ->toEqual(['chi.eze@example.test']);

    // SEARCH — surname, email fragment, and a FULL NAME that matches neither column alone.
    expect(pu_listedEmails($get(['q' => 'Okonkwo'])))->toEqual(['ada.okonkwo@example.test']);
    expect(pu_listedEmails($get(['q' => 'bem.tersoo@'])))->toEqual(['bem.tersoo@example.test']);
    expect(pu_listedEmails($get(['q' => 'Chi Eze'])))->toEqual(['chi.eze@example.test']);

    // A wildcard is a LITERAL, not an operator. Without the escaping this returns everybody, which
    // reads as a broken filter rather than as a match.
    expect(pu_listedEmails($get(['q' => '%'])))->toEqual([]);
});

it('pages with a stable order, and every row appears exactly once across the pages', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();
    $school = al_makeSchool();

    // Deliberately SHARE a first name across all seven. first_name is the sort key, so identical
    // values are exactly the case where an unstable order duplicates a row onto two pages and drops
    // another entirely — a bug a count-based assertion cannot see.
    $expected = [];
    foreach (range(1, 7) as $i) {
        $u = al_makeUser($school->id);
        $u->forceFill(['first_name' => 'Same', 'last_name' => 'Person'.$i, 'email' => "pager{$i}@example.test"])->save();
        $u->grantSchoolAccess($school, 'admin_viewer');
        $expected[] = "pager{$i}@example.test";
    }
    sort($expected);

    $seen = [];
    foreach ([1, 2, 3] as $page) {
        $response = $this->actingAs($actor)->get('/super-admin/admins?'.http_build_query([
            'per_page' => 10, 'role' => 'admin_viewer', 'page' => $page,
        ]));
        $seen = array_merge($seen, pu_listedEmails($response));
    }

    // per_page 10 over 7 rows: page 1 carries them all, pages 2 and 3 are empty. The UNION is what
    // matters, and it must contain each row ONCE.
    sort($seen);
    expect($seen)->toEqual($expected);

    // And with a page size that actually splits them, the two pages partition the set.
    $split = [];
    foreach ([1, 2] as $page) {
        $split = array_merge($split, pu_listedEmails(
            $this->actingAs($actor)->get('/super-admin/admins?'.http_build_query([
                'per_page' => 10, 'role' => 'admin_viewer', 'page' => $page,
            ]))
        ));
    }
    expect(array_unique($split))->toHaveCount(count($split), 'no row may appear on two pages');
});

it('refuses a per_page outside the offered set', function () {
    $this->seed(DatabaseSeeder::class);

    $actor = pu_superAdmin();

    // An unbounded per_page is a query-string-shaped way to ask for every row and every seat join.
    $this->actingAs($actor)->get('/super-admin/admins?per_page=100000')
        ->assertSessionHasErrors('per_page');

    $this->actingAs($actor)->get('/super-admin/admins?per_page=25')
        ->assertSessionHasNoErrors();
})->group('arch');

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
