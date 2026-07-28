<?php

use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\SchoolRbacOverview;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The school-admin RBAC console (/setup/users) — the read payload behind the four tabs.
 *
 * The C5 guards themselves (D1 super_admin, D2 admin-is-super-admin-only, D3 self, D5 permission,
 * isolation) are SchoolUserModuleTest's contract and are not re-tested here. What IS tested is
 * that the payload MIRRORS them, so the UI never offers a control the server would refuse.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->school = al_makeSchool();
    $this->otherSchool = al_makeSchool();

    $this->admin = al_makeUser($this->school->id);
    $this->admin->grantSchoolAccess($this->school, 'admin');
    $this->admin->flushSchoolAccessCache();

    setPermissionsTeamId($this->school->id);
});

function src_member(object $school, string $role, string $first = 'Member'): User
{
    $user = al_makeUser($school->id);
    $user->forceFill(['first_name' => $first])->save();
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

function src_build(object $school, ?User $actor = null, ?string $search = null, ?string $role = null, int $page = 1): array
{
    return ActiveSchool::runFor(
        $school->id,
        fn () => SchoolRbacOverview::build($school, $actor, $search, $role, $page, 25),
    );
}

// ── Scoping ────────────────────────────────────────────────────────────────

it('lists only users holding a role in this school', function () {
    $mine = src_member($this->school, 'teacher', 'Mine');
    $theirs = src_member($this->otherSchool, 'teacher', 'Theirs');

    $uuids = collect(src_build($this->school)['users']['data'])->pluck('uuid');

    expect($uuids)->toContain($mine->getRouteKey())
        ->and($uuids)->not->toContain($theirs->getRouteKey());
});

it('counts role holders within this school only', function () {
    src_member($this->school, 'teacher');
    src_member($this->school, 'teacher');
    src_member($this->otherSchool, 'teacher');

    $roles = collect(src_build($this->school)['roles'])->keyBy('name');

    // A role held widely elsewhere reads as its LOCAL count — the useful truth for a school
    // admin deciding whether anyone here is covering a duty.
    expect($roles['teacher']['holderCount'])->toBe(2);
});

// ── Search, filter, pagination ─────────────────────────────────────────────

it('searches users by name on the server', function () {
    src_member($this->school, 'teacher', 'Findme');
    src_member($this->school, 'teacher', 'Somebodyelse');

    $found = src_build($this->school, search: 'findme')['users'];

    expect($found['pagination']['total'])->toBe(1)
        ->and($found['data'][0]['name'])->toContain('Findme');
});

it('filters users by role', function () {
    src_member($this->school, 'teacher');
    src_member($this->school, 'registrar');

    $filtered = src_build($this->school, role: 'registrar')['users'];

    expect($filtered['pagination']['total'])->toBe(1)
        ->and($filtered['data'][0]['roles'])->toBe(['registrar']);
});

it('paginates rather than shipping every user', function () {
    foreach (range(1, 30) as $ignored) {
        src_member($this->school, 'guardian');
    }

    $page = src_build($this->school)['users'];

    expect($page['pagination']['total'])->toBeGreaterThan(25)
        ->and($page['data'])->toHaveCount(25)
        ->and($page['pagination']['last_page'])->toBeGreaterThan(1);
});

// ── The payload mirrors the server's guards ────────────────────────────────
//
// Note these pass the ACTOR explicitly. With no actor the payload fails closed — nothing is
// editable — because "everyone is editable" is the wrong default for a hint the UI acts on.

it('marks your own row not editable, mirroring D3', function () {
    $self = collect(src_build($this->school, $this->admin)['users']['data'])
        ->firstWhere('uuid', $this->admin->getRouteKey());

    // The old page did this too; keeping it is the point — the UI must not offer a write the
    // server refuses, and D3 is a structural target rule, not a permission check.
    expect($self['editable'])->toBeFalse()
        ->and($self['lockReason'])->toContain('your own roles');
});

it('marks a super admin not editable, mirroring D1', function () {
    setPermissionsTeamId(null);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    setPermissionsTeamId($this->school->id);
    $superAdmin->grantSchoolAccess($this->school, 'teacher');
    $superAdmin->flushSchoolAccessCache();

    $row = collect(src_build($this->school, $this->admin)['users']['data'])
        ->firstWhere('uuid', $superAdmin->getRouteKey());

    expect($row['editable'])->toBeFalse()
        ->and($row['lockReason'])->toContain('Super admin');
});

it('offers only the roles this actor may assign, mirroring D2', function () {
    $payload = src_build($this->school, $this->admin);
    $roles = collect($payload['roles'])->keyBy('name');

    expect($payload['assignableRoles'])->not->toContain('admin')
        ->and($payload['assignableRoles'])->not->toContain('super_admin')
        ->and($payload['assignableRoles'])->toContain('teacher')
        ->and($roles['admin']['assignable'])->toBeFalse()
        ->and($roles['admin']['unassignableReason'])->toContain('super admin')
        ->and($roles['super_admin']['assignable'])->toBeFalse()
        ->and($roles['super_admin']['unassignableReason'])->toContain('Platform role');
});

it('fails closed with no acting user rather than marking everyone editable', function () {
    src_member($this->school, 'teacher');

    // An off-request caller has nobody to be. Defaulting to editable would make a job or command
    // render a page offering writes the server refuses.
    $rows = src_build($this->school)['users']['data'];

    expect(collect($rows)->pluck('editable')->unique()->all())->toBe([false])
        ->and(src_build($this->school)['assignableRoles'])->not->toContain('admin');
});

// ── Read-only context ──────────────────────────────────────────────────────

it('ships the same permission catalogue the super-admin console renders', function () {
    $groups = src_build($this->school)['groups'];

    // One shared builder, so the two consoles cannot disagree about what a permission is.
    expect($groups)->toHaveCount(count(PermissionGroup::cases()))
        ->and(collect($groups)->flatMap(fn ($g) => array_column($g['permissions'], 'name')))
        ->toHaveCount(count(Permission::values()));
});

it('shows what each role grants, so an assignment is an informed one', function () {
    $roles = collect(src_build($this->school)['roles'])->keyBy('name');

    expect($roles['teacher']['permissionCount'])->toBeGreaterThan(0)
        ->and($roles['teacher']['permissions'])->toContain('score.manage');
});

// ── Cost ───────────────────────────────────────────────────────────────────

it('does not query per user — the page it replaces did', function () {
    foreach (range(1, 30) as $ignored) {
        src_member($this->school, 'guardian');
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    src_build($this->school);

    // The previous implementation called getRoleNames() AND isSuperAdmin() per user — on the
    // first school of the dev database that was 847 queries for the list alone. Both are now
    // prepared in one query each, so the count is flat in the number of users.
    expect($queries)->toBeLessThanOrEqual(10);
});

// ── The page ───────────────────────────────────────────────────────────────

it('serves the console with the requested tab', function () {
    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users?tab=roles')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->where('tab', 'roles')
            ->has('users.data')
            ->has('users.pagination')
            ->has('roles')
            ->has('groups')
            ->has('assignableRoles')
            ->has('stats.userCount'));
});

it('falls back to the users tab rather than trusting the query string', function () {
    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users?tab=../../etc/passwd')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'users'));
});
