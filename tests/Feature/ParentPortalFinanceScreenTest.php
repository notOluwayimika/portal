<?php

/*
 * The parent portal's FEES PAGE route — `GET /parent/finance`.
 *
 * SCOPE, STATED: this file guards the page route, not the data. What the endpoint returns, and that
 * it returns one guardian's wards and nobody else's, is
 * tests/Feature/Finance/ParentPortalFinanceReadTest.php's job and is already pinned there as an
 * exact key set. Re-asserting it here would be a second spelling of one contract.
 *
 * WHAT IS LEFT FOR THIS FILE IS THE SEAM: the page and the endpoint it calls must be gated on the
 * SAME ability. If they drift, one of two things happens and both are silent — a parent who can open
 * the page gets an empty screen because the API refuses them, or a user who cannot open the page can
 * still read the data by calling the API directly. The pairing is the thing worth a test.
 */

use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * A user whose ACTIVE school is their own `school_id`, holding EXACTLY the abilities named and no
 * others — so a gate below cannot pass on some ability that arrived with a role.
 *
 * IT DOES NOT USE `User::grantSchoolAccess()`, and that is the point rather than an oversight: that
 * method assigns the **admin** role by default — `grantSchoolAccess` (app/Models/User.php:421) —
 * which would hand this
 * fixture a pile of abilities nobody asked for and make "holds exactly what is named" false. The
 * negative arms below would then be proving something about admin rather than about
 * `parent_portal.access`. Attaching the school directly is what keeps the fixture's degrees of
 * freedom collapsed onto the one axis under test.
 *
 * The team-context dance is not noise either: `roles`/`permissions` are team-scoped, so
 * `setPermissionsTeamId` must be set before the grant and the stale relation dropped after it, or
 * spatie answers from a cache keyed to the wrong team.
 */
function pfsUser(array $abilities = []): User
{
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);

    $user->schools()->syncWithoutDetaching([$school->id]);

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');

    foreach ($abilities as $ability) {
        $user->givePermissionTo($ability);
    }

    $user->unsetRelation('roles')->unsetRelation('permissions');

    return $user;
}

it('the fixture grants ONLY what it is asked for — otherwise every negative arm below is vacuous', function () {
    // A negative arm proves nothing if the fixture user quietly holds a role. Pinned here so the
    // three refusals underneath are known to be refusing on the named ability and nothing else.
    $bare = pfsUser();

    expect($bare->getAllPermissions()->pluck('name')->all())->toBe([])
        ->and($bare->getRoleNames()->all())->toBe([])
        ->and(pfsUser(['parent_portal.access'])->getAllPermissions()->pluck('name')->all())
        ->toBe(['parent_portal.access']);
});

it('renders the fees page for a guardian who holds parent_portal.access', function () {
    $user = pfsUser(['parent_portal.access']);

    $this->actingAs($user)
        ->get('/parent/finance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('parent/finance'));
});

it('refuses the fees page to a signed-in user without parent_portal.access', function () {
    // NOT a 404 and not an empty page — the middleware refuses. A signed-in user without the ability
    // must not reach a screen about somebody's fees at all.
    $this->actingAs(pfsUser())
        ->get('/parent/finance')
        ->assertForbidden();
});

it('refuses the fees page to a guest', function () {
    $this->get('/parent/finance')->assertRedirect('/login');
});

it('gates the PAGE and its ENDPOINT on the same ability — the seam that would drift silently', function () {
    // THE ARM THIS FILE EXISTS FOR. Two failure directions, both quiet:
    //   · page open / API closed  -> the parent sees a permanently empty screen and reports nothing;
    //   · page closed / API open  -> the data is readable by calling the endpoint directly.
    // Asserting both halves for ONE user is what keeps them paired.
    $withAbility = pfsUser(['parent_portal.access']);
    $withoutAbility = pfsUser();

    $this->actingAs($withAbility)->get('/parent/finance')->assertOk();
    $this->actingAs($withAbility)->getJson('/api/parent/finance/wards')->assertOk();

    $this->actingAs($withoutAbility)->get('/parent/finance')->assertForbidden();
    $this->actingAs($withoutAbility)->getJson('/api/parent/finance/wards')->assertForbidden();
});

it('carries no route parameter — there is no ward id for a caller to edit', function () {
    // The endpoint derives the wards from the authenticated user precisely so this surface has no
    // IDOR shape. A `parent/finance/{student}` page would reopen it, and would do so in a file
    // nobody would think to re-review. Asserted structurally rather than trusted to reviewers.
    $uri = app('router')->getRoutes()->getByName('parent.finance')->uri();

    expect($uri)->toBe('parent/finance')
        ->and($uri)->not->toContain('{');
});
