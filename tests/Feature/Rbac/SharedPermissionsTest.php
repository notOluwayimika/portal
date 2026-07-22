<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\EffectivePermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * C4 — the shared permission set means EFFECTIVE authority (can()), not the
 * granted grant table (c4-brief D1). These pins are the reason to prefer one
 * payload over the other: only the effective one matches what the Gate does.
 */

// ── D1: effective, not granted ─────────────────────────────────────────────

it('shares an ordinary role its granted set exactly', function () {
    $school = al_makeSchool();
    $teacher = al_makeUser($school->id);
    $teacher->grantSchoolAccess($school, 'teacher');
    $teacher->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);

    $shared = EffectivePermissions::for($teacher);
    $granted = Role::where('name', 'teacher')->where('guard_name', 'web')
        ->firstOrFail()->permissions->pluck('name')->values()->all();

    sort($shared);
    sort($granted);

    // For a non-super-admin the two coincide — the interesting divergence is
    // super_admin below. This pins that we did not accidentally over- or
    // under-share for ordinary users.
    expect($shared)->toEqual($granted);
});

it('shares super_admin its EFFECTIVE authority (the bypass), far beyond its 15 grants', function () {
    config(['auth.gate_before_superadmin' => true]);

    setPermissionsTeamId(null);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    $shared = EffectivePermissions::for($superAdmin);
    $granted = Role::where('name', 'super_admin')->where('guard_name', 'web')
        ->firstOrFail()->permissions->pluck('name');

    // Granted is exactly 15 (C1); effective is nearly the whole enum. A granted
    // payload would render super_admin unable to do what the bypass allows.
    expect($granted)->toHaveCount(15)
        ->and(count($shared))->toBeGreaterThan(40)
        ->and(count($shared))->toBeGreaterThan($granted->count());
});

// ── D1: ADR 0040's checker exclusion surfaces at the presentation layer ─────

it('excludes checker abilities from super_admin\'s shared set (ADR 0040 surfaces in the UI)', function () {
    config(['auth.gate_before_superadmin' => true]);

    setPermissionsTeamId(null);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    $shared = EffectivePermissions::for($superAdmin);

    // The approve/reject buttons must hide for super_admin — the one role the
    // backend will actually deny (ApprovalAbility). A non-checker ability the
    // bypass grants is present, proving the exclusion is selective, not a hole.
    expect($shared)->not->toContain('result.approve')
        ->and($shared)->not->toContain('result.reject')
        ->and($shared)->toContain('student_curriculum.promote');
});

// ── D3: scoped to the current user in the ACTIVE school's team ──────────────

it('resolves the set in the active school\'s team, never cross-school', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $user = al_makeUser($schoolA->id);
    $user->grantSchoolAccess($schoolA, 'admin');
    $user->grantSchoolAccess($schoolB, 'teacher');
    $user->flushSchoolAccessCache();

    $inA = ActiveSchool::runFor($schoolA->id, fn () => EffectivePermissions::for($user->fresh()));
    $inB = ActiveSchool::runFor($schoolB->id, fn () => EffectivePermissions::for($user->fresh()));

    // admin_area.access is an admin grant only. It must appear under School A
    // (admin there) and NOT under School B (teacher there) — the same permission
    // name resolving differently per team. A global dump would leak it into B.
    expect($inA)->toContain('admin_area.access')
        ->and($inB)->not->toContain('admin_area.access');
});

// ── The wire: the middleware actually ships permissions, and not rolesFull ──

it('ships permissions on the Inertia auth prop and no longer ships rolesFull', function () {
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $admin->grantSchoolAccess($school, 'admin');
    $admin->flushSchoolAccessCache();

    $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('auth.permissions')
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('admin_area.access'))
            ->missing('auth.rolesFull'),
        );
});
