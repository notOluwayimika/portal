<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Events\RoleDetachedEvent;

uses(RefreshDatabase::class);

/**
 * C5 — the school-admin Users module, guard by guard (c5-brief D1–D5).
 * This is the first HUMAN-driven role write (everything before was a seeder),
 * so the audit-attribution and null-team invariants run for real here.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->school = al_makeSchool();
    $this->otherSchool = al_makeSchool();

    $this->admin = al_makeUser($this->school->id);
    $this->admin->grantSchoolAccess($this->school, 'admin');
    $this->admin->flushSchoolAccessCache();

    // A member of the active school with a starter role: the module lists
    // from model_has_roles (the S7 single source), so membership here IS
    // holding a role in the School's team.
    $this->target = al_makeUser($this->school->id);
    $this->target->grantSchoolAccess($this->school, 'guardian');
    $this->target->flushSchoolAccessCache();
});

function sum_superAdmin(): User
{
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $user->flushSchoolAccessCache();

    return $user;
}

function sum_put($test, User $actor, User $target, array $roles, ?int $schoolId = null)
{
    return $test->actingAs($actor)
        ->withSession(['school_id' => $schoolId ?? $test->school->id])
        ->put("/setup/users/{$target->uuid}/roles", ['roles' => $roles]);
}

// ── D5: the permission gates page and write ────────────────────────────────

it('D5 — admin reaches the page via its seeded grant; a teacher is denied', function () {
    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')->assertOk();

    $teacher = al_makeUser($this->school->id);
    $teacher->grantSchoolAccess($this->school, 'teacher');
    $teacher->flushSchoolAccessCache();

    $this->actingAs($teacher)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')->assertForbidden();
});

it('D5 — super_admin reaches the page through the bypass, holding no explicit grant', function () {
    config(['auth.gate_before_superadmin' => true]);
    $superAdmin = sum_superAdmin();
    $superAdmin->schools()->syncWithoutDetaching([$this->school->id]);

    expect($superAdmin->getAllPermissions()->pluck('name'))
        ->not->toContain('rbac.manage_users'); // precondition: bypass, not grant

    $this->actingAs($superAdmin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')->assertOk();
});

// ── D1: never super_admin ──────────────────────────────────────────────────

it('D1 — a super_admin target is untouchable even by another super_admin with the bypass ON', function () {
    // The load-bearing case: with the flag on the bypass grants EVERY
    // permission, so a permission-shaped guard would pass. The denial holding
    // here proves the rule is structural (target identity), not a permission.
    config(['auth.gate_before_superadmin' => true]);

    $actingSuper = sum_superAdmin();
    $targetSuper = sum_superAdmin();
    $targetSuper->schools()->syncWithoutDetaching([$this->school->id]);

    sum_put($this, $actingSuper, $targetSuper, ['teacher'])->assertForbidden();

    // And an ordinary admin is denied the same target, of course.
    sum_put($this, $this->admin, $targetSuper, ['teacher'])->assertForbidden();
});

it('D1 — super_admin is never in the assignable payload, for any actor', function () {
    config(['auth.gate_before_superadmin' => true]);

    // Web validation failures redirect back with errors (this app's
    // ValidationException renderable), so the assertion is session-shaped.
    sum_put($this, sum_superAdmin(), $this->target, ['super_admin'])
        ->assertRedirect()
        ->assertSessionHasErrors('roles');

    setPermissionsTeamId(null);
    expect($this->target->fresh()->hasRole('super_admin'))->toBeFalse();
});

// ── D2: admin assignable only by super_admin ───────────────────────────────

it('D2 — an admin granting `admin` is rejected; a super_admin doing it succeeds', function () {
    sum_put($this, $this->admin, $this->target, ['admin'])
        ->assertRedirect()
        ->assertSessionHasErrors('roles');

    setPermissionsTeamId($this->school->id);
    expect($this->target->fresh()->hasRole('admin'))->toBeFalse();

    config(['auth.gate_before_superadmin' => true]);
    sum_put($this, sum_superAdmin(), $this->target, ['guardian', 'admin'])->assertStatus(302); // back()

    setPermissionsTeamId($this->school->id);
    expect($this->target->fresh()->hasRole('admin'))->toBeTrue();
});

// ── D3: no self-modification ───────────────────────────────────────────────

it('D3 — an admin editing their own roles is denied (closes demote-self-then-locked-out)', function () {
    sum_put($this, $this->admin, $this->admin, ['teacher'])->assertForbidden();

    setPermissionsTeamId($this->school->id);
    expect($this->admin->fresh()->hasRole('admin'))->toBeTrue(); // nothing changed
});

// ── D4: team-context assignment ────────────────────────────────────────────

it('D4 — the grant lands in the ACTIVE school\'s team and no other', function () {
    sum_put($this, $this->admin, $this->target, ['guardian', 'teacher'])->assertStatus(302);

    $roleId = DB::table('roles')->where('name', 'teacher')->where('guard_name', 'web')->value('id');
    $rows = DB::table('model_has_roles')
        ->where('model_id', $this->target->id)
        ->where('role_id', $roleId)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->school_id)->toBe($this->school->id);

    setPermissionsTeamId($this->otherSchool->id);
    expect($this->target->fresh()->hasRole('teacher'))->toBeFalse();
});

// ── Isolation + list scoping ───────────────────────────────────────────────

it('denies a target who is not a member of the active school (cross-school uuid)', function () {
    $foreign = al_makeUser($this->otherSchool->id);
    $foreign->grantSchoolAccess($this->otherSchool, 'teacher');
    $foreign->flushSchoolAccessCache();

    sum_put($this, $this->admin, $foreign, ['teacher'])->assertForbidden();
});

it('lists only the active school\'s members — never a global user dump', function () {
    $foreign = al_makeUser($this->otherSchool->id);
    $foreign->grantSchoolAccess($this->otherSchool, 'teacher');
    $foreign->flushSchoolAccessCache();

    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/index')
            ->where('users', fn ($users) => collect($users)->pluck('uuid')->contains($this->target->uuid)
                && ! collect($users)->pluck('uuid')->contains($foreign->uuid)),
        );
});

it('offers `admin` in assignable_roles only to a super_admin actor (mirrors D2)', function () {
    $this->actingAs($this->admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assignable_roles', fn ($roles) => ! collect($roles)->contains('admin')),
        );

    config(['auth.gate_before_superadmin' => true]);
    $superAdmin = sum_superAdmin();
    $superAdmin->schools()->syncWithoutDetaching([$this->school->id]);

    $this->actingAs($superAdmin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assignable_roles', fn ($roles) => collect($roles)->contains('admin')),
        );
});

// ── The audit consequence: first human-driven role write ───────────────────

// ── Atomicity: an edit must never be able to LOCK A USER OUT ───────────────

it('keeps the original roles when the sync fails between detach and attach (atomic sync)', function () {
    // spatie's syncRoles holds no transaction (vendor-read, 7.4.1): with
    // events enabled it is removeRole(current) then assignRole(new).
    // RoleDetachedEvent fires AFTER the detach is written and BEFORE the
    // attach begins — throwing there is a failure injected exactly BETWEEN
    // the two halves. Unwrapped, the detach persists and the attach never
    // runs: the user is left with ZERO roles — zero access — by the very
    // edit meant to adjust them. (Verified: without the transaction this
    // test fails showing roles = [], the lockout itself.)
    Event::listen(
        RoleDetachedEvent::class,
        function (): void {
            throw new RuntimeException('between-halves failure injected');
        },
    );

    sum_put($this, $this->admin, $this->target, ['guardian', 'teacher'])
        ->assertStatus(500);

    // The transaction must have rolled the detach back with the failed
    // attach: the target still holds their original role set.
    setPermissionsTeamId($this->school->id);
    expect($this->target->fresh()->getRoleNames()->all())->toEqual(['guardian']);

    // …and no half-written audit rows survive the rollback either.
    expect(DB::table('activity_log')->where('log_name', 'rbac')
        ->where('subject_id', $this->target->id)
        ->where('event', 'role_detached')->count())->toBe(0);
});

it('audits every human-driven sync, fully attributed (detach + attach pair)', function () {
    // Scoped to role events: C7's enforcement-flag transition also logs to
    // 'rbac' and would otherwise ride into this window.
    $before = DB::table('activity_log')->where('log_name', 'rbac')
        ->whereIn('event', ['role_attached', 'role_detached'])->count();

    // syncRoles is detach-all-then-attach (probed, not assumed), so ONE sync
    // yields exactly TWO rbac rows: the detach of the prior set and the attach
    // of the new one. Both must carry the acting admin as causer and the
    // active School as team — this is the first role write whose causer is a
    // HUMAN rather than a seeder, so the attribution path runs for real here.
    sum_put($this, $this->admin, $this->target, ['guardian', 'teacher'])->assertStatus(302);

    $rows = DB::table('activity_log')->where('log_name', 'rbac')
        ->whereIn('event', ['role_attached', 'role_detached'])
        ->offset($before)->limit(100)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('event')->sort()->values()->all())->toEqual(['role_attached', 'role_detached']);

    foreach ($rows as $row) {
        $properties = json_decode($row->properties, true);

        expect((int) $row->causer_id)->toBe($this->admin->id)      // the acting admin, not a seeder
            ->and((int) $row->subject_id)->toBe($this->target->id)
            ->and((int) $properties['team_school_id'])->toBe($this->school->id);
    }

    $attach = $rows->firstWhere('event', 'role_attached');
    expect(json_decode($attach->properties, true)['roles'])
        ->toContain('teacher')
        ->toContain('guardian');
});
