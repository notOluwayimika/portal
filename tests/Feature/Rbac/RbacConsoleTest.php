<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\RbacOverview;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The super-admin RBAC console: the READ payload and the 2FA toggle.
 *
 * The write path (D1–D5) is SuperAdminMatrixTest's contract and is deliberately not re-tested
 * here — if this redesign had needed those cases changed, the redesign would have been wrong.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    setPermissionsTeamId(null);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');
    $this->superAdmin->flushSchoolAccessCache();
});

// ── The payload ────────────────────────────────────────────────────────────

it('groups every permission in the catalog exactly once', function () {
    $payload = RbacOverview::build();

    $named = collect($payload['groups'])
        ->flatMap(fn (array $group) => array_column($group['permissions'], 'name'));

    expect($named->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all())
        ->and($named->duplicates())->toBeEmpty();
});

it('reports which roles hold a permission, from the inverted grant map', function () {
    $payload = RbacOverview::build();

    $permission = collect($payload['groups'])
        ->flatMap(fn (array $group) => $group['permissions'])
        ->firstWhere('name', 'academic_setup.manage');

    $expected = Role::whereHas('permissions', fn ($q) => $q->where('name', 'academic_setup.manage'))
        ->whereNull('school_id')->where('guard_name', RbacSeeder::GUARD)
        ->orderBy('name')->pluck('name')->all();

    expect($permission['roles'])->toBe($expected)
        ->and($permission['roleCount'])->toBe(count($expected))
        ->and($permission['unused'])->toBeFalse();
});

it('flags a permission no role holds as unused', function () {
    // Strip a permission from every role, then it should read as unused rather than silently
    // looking the same as one that is granted.
    DB::table('role_has_permissions')->whereIn(
        'permission_id',
        DB::table('permissions')->where('name', 'guardian.import')->pluck('id')
    )->delete();

    app()->forgetInstance(PermissionRegistrar::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permission = collect(RbacOverview::build()['groups'])
        ->flatMap(fn (array $group) => $group['permissions'])
        ->firstWhere('name', 'guardian.import');

    expect($permission['unused'])->toBeTrue()
        ->and($permission['roleCount'])->toBe(0)
        ->and(RbacOverview::build()['stats']['unusedPermissionCount'])->toBeGreaterThan(0);
});

it('counts PEOPLE, not pivot rows, when one user holds a role in two schools', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    $user->grantSchoolAccess($a, 'teacher');
    $user->grantSchoolAccess($b, 'teacher');
    $user->flushSchoolAccessCache();

    $teacher = collect(RbacOverview::build()['roles'])->firstWhere('name', 'teacher');

    // Two pivot rows, ONE person. Reporting assignments as holders would treble the apparent
    // blast radius of a grant change for anyone working across schools.
    expect($teacher['assignmentCount'])->toBe(2)
        ->and($teacher['holderCount'])->toBe(1)
        ->and($teacher['schoolCount'])->toBe(2);
});

it('renders super_admin read-only with its platform grants visible', function () {
    $role = collect(RbacOverview::build()['roles'])->firstWhere('name', 'super_admin');

    // Previously hidden behind a grey "locked" label, so nobody could see that the most powerful
    // role holds a handful of grants rather than everything.
    expect($role)->not->toBeNull()
        ->and($role['editable'])->toBeFalse()
        ->and($role['immutableReason'])->toContain('Gate::before')
        ->and($role['permissions'])->toBe(collect(RbacSeeder::SUPER_ADMIN_PLATFORM)->sort()->values()->all());
});

it('exposes two_factor_required, which the old payload omitted entirely', function () {
    $roles = collect(RbacOverview::build()['roles'])->keyBy('name');

    // The toggle endpoint existed and was routed, but the flag was absent from the payload — so
    // the control was unreachable from the UI. This is what makes it usable.
    expect($roles['admin']['twoFactorRequired'])->toBeTrue()
        ->and($roles['teacher']['twoFactorRequired'])->toBeFalse();
});

it('marks the maker and checker sides of a role', function () {
    $roles = collect(RbacOverview::build()['roles'])->keyBy('name');

    expect($roles['teacher']['holdsMaker'])->toBeTrue()
        ->and($roles['teacher']['holdsChecker'])->toBeFalse()
        ->and($roles['head_of_school']['holdsChecker'])->toBeTrue();
});

it('builds the whole payload in a handful of queries, whatever the catalog size', function () {
    // Extra data so a per-permission or per-role query would show up.
    foreach (range(1, 3) as $ignored) {
        $school = al_makeSchool();
        $user = al_makeUser($school->id);
        $user->grantSchoolAccess($school, 'teacher');
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    RbacOverview::build();

    // Four by design: roles, their permissions, the holder-count aggregate, the last-changed
    // aggregate. The permission→roles direction is inverted in PHP from the first result set —
    // asking per permission would be ~74 queries for data already in hand.
    expect($queries)->toBeLessThanOrEqual(6);
});

// ── The page ───────────────────────────────────────────────────────────────

it('serves the console with the requested tab', function () {
    $this->actingAs($this->superAdmin)
        ->get('/super-admin/rbac?tab=roles')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/rbac/index')
            ->where('tab', 'roles')
            ->has('groups')
            ->has('roles')
            ->has('sodPairs')
            ->has('stats.permissionCount'));
});

it('falls back to the catalog tab rather than trusting the query string', function () {
    $this->actingAs($this->superAdmin)
        ->get('/super-admin/rbac?tab=../etc/passwd')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'catalog'));
});

// ── The 2FA toggle (previously untested and unreachable) ───────────────────

it('flips two_factor_required for an editable role', function () {
    expect(Role::where('name', 'teacher')->whereNull('school_id')->value('two_factor_required'))->toBeFalsy();

    $this->actingAs($this->superAdmin)
        ->put('/super-admin/rbac/roles/teacher/two-factor', ['required' => true])
        ->assertRedirect();

    expect(Role::where('name', 'teacher')->whereNull('school_id')->value('two_factor_required'))->toBeTruthy();
});

it('audits the two-factor flip under the rbac log', function () {
    $this->actingAs($this->superAdmin)
        ->put('/super-admin/rbac/roles/teacher/two-factor', ['required' => true]);

    expect(DB::table('activity_log')
        ->where('log_name', 'rbac')
        ->where('subject_type', Role::class)
        ->count())->toBeGreaterThan(0);
});

it('refuses to toggle two-factor on super_admin', function () {
    $this->actingAs($this->superAdmin)
        ->put('/super-admin/rbac/roles/super_admin/two-factor', ['required' => false])
        ->assertForbidden();

    expect(Role::where('name', 'super_admin')->whereNull('school_id')->value('two_factor_required'))->toBeTruthy();
});

it('refuses the two-factor toggle to a non-super-admin', function () {
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $admin->grantSchoolAccess($school, 'admin');
    $admin->flushSchoolAccessCache();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->put('/super-admin/rbac/roles/teacher/two-factor', ['required' => true])
        ->assertForbidden();
});
