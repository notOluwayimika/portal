<?php

use App\Models\Role;
use App\Models\User;
use App\Support\ApprovalAbility;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Events\PermissionDetachedEvent;

uses(RefreshDatabase::class);

/**
 * C6 — the super-admin RBAC matrix, guard by guard (c6-brief D1–D5).
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    setPermissionsTeamId(null);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');
    $this->superAdmin->flushSchoolAccessCache();
});

function sam_put($test, User $actor, string $roleName, array $permissions)
{
    return $test->actingAs($actor)
        ->put("/super-admin/rbac/roles/{$roleName}/permissions", ['permissions' => $permissions]);
}

function sam_rolePermissions(string $roleName): array
{
    return Role::where('name', $roleName)->where('guard_name', RbacSeeder::GUARD)
        ->whereNull('school_id')->firstOrFail()
        ->permissions()->pluck('name')->sort()->values()->all();
}

// ── Access: the one deliberate role gate ───────────────────────────────────

it('admits super_admin and denies an admin (role:super_admin group)', function () {
    $this->actingAs($this->superAdmin)->get('/super-admin/rbac')->assertOk();

    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $admin->grantSchoolAccess($school, 'admin');
    $admin->flushSchoolAccessCache();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->get('/super-admin/rbac')->assertForbidden();
});

// ── D1: the super_admin row is immutable ───────────────────────────────────

it('D1 — the super_admin row cannot be edited, even by a super_admin with the bypass ON', function () {
    config(['auth.gate_before_superadmin' => true]);

    $before = sam_rolePermissions('super_admin');

    sam_put($this, $this->superAdmin, 'super_admin', ['activity_log.view'])
        ->assertForbidden();

    expect(sam_rolePermissions('super_admin'))->toEqual($before)
        ->and($before)->toHaveCount(15); // the probe precondition survives
});

// ── D2: grant-time SoD by convention ───────────────────────────────────────

it('D2 — rejects a resulting set holding a checker with its matching maker', function () {
    // head_of_school legitimately holds approve/reject; ADDING result.submit
    // would give one role both sides of the pair.
    $wanted = [...sam_rolePermissions('head_of_school'), 'result.submit'];

    sam_put($this, $this->superAdmin, 'head_of_school', $wanted)
        ->assertRedirect()
        ->assertSessionHasErrors('permissions');

    expect(sam_rolePermissions('head_of_school'))->not->toContain('result.submit');
});

it('D2 — the convention derives the pair, not a name list', function () {
    expect(ApprovalAbility::matchingMakerFor('result.approve'))->toBe('result.submit')
        ->and(ApprovalAbility::matchingMakerFor('result.reject'))->toBe('result.submit')
        ->and(ApprovalAbility::matchingMakerFor('finance.invoice.approve'))->toBe('finance.invoice.submit')
        ->and(ApprovalAbility::matchingMakerFor('approve'))->toBe('submit')
        ->and(ApprovalAbility::matchingMakerFor('result.view_scores'))->toBeNull()
        ->and(ApprovalAbility::matchingMakerFor('result.submit'))->toBeNull();
});

it('D2 — a checker-free edit to the same role passes (the rule is the pair, not the role)', function () {
    $wanted = [...sam_rolePermissions('teacher'), 'guardian.view'];

    sam_put($this, $this->superAdmin, 'teacher', $wanted)->assertStatus(302);

    expect(sam_rolePermissions('teacher'))->toContain('guardian.view');
});

// ── D4: the enum is code ───────────────────────────────────────────────────

it('D4 — an unknown permission name is a validation failure, never a creation', function () {
    sam_put($this, $this->superAdmin, 'teacher', ['made.up_permission'])
        ->assertRedirect()
        ->assertSessionHasErrors();

    expect(DB::table('permissions')->where('name', 'made.up_permission')->exists())->toBeFalse();
});

it('D4 — an unknown role 404s (roles are the nine seeded globals)', function () {
    sam_put($this, $this->superAdmin, 'made_up_role', ['guardian.view'])
        ->assertNotFound();
});

// ── D3: atomicity at the true between-halves point ─────────────────────────

it('D3 — keeps the original grants when the edit fails between revoke and give (atomic)', function () {
    // PermissionDetachedEvent fires AFTER revokePermissionTo's detach write
    // and BEFORE givePermissionTo runs (vendor-read, c6-brief step 0) —
    // throwing here injects a failure exactly between the halves. Unwrapped,
    // the revocations persist and the additions never apply: at ROLE scope,
    // that strips the role's holders in every School at once.
    Event::listen(PermissionDetachedEvent::class, function (): void {
        throw new RuntimeException('between-halves failure injected');
    });

    $before = sam_rolePermissions('registrar');

    // Swap one grant for another: forces BOTH a revoke and a give.
    $wanted = collect($before)->reject(fn ($p) => $p === 'guardian.view')
        ->push('guardian.export')->values()->all();

    sam_put($this, $this->superAdmin, 'registrar', $wanted)->assertStatus(500);

    expect(sam_rolePermissions('registrar'))->toEqual($before);
});

// ── The audit consequence: removals must leave a trace ─────────────────────

it('audits BOTH halves of a swap — the detach row syncPermissions would have silently skipped', function () {
    // Scoped to permission events: C7's enforcement-flag transition also
    // logs to 'rbac' and would otherwise ride into this window.
    $beforeCount = DB::table('activity_log')->where('log_name', 'rbac')
        ->whereIn('event', ['permission_attached', 'permission_detached'])->count();

    $before = sam_rolePermissions('registrar');
    $wanted = collect($before)->reject(fn ($p) => $p === 'guardian.view')
        ->push('guardian.export')->values()->all();

    sam_put($this, $this->superAdmin, 'registrar', $wanted)->assertStatus(302);

    $rows = DB::table('activity_log')->where('log_name', 'rbac')
        ->whereIn('event', ['permission_attached', 'permission_detached'])
        ->offset($beforeCount)->limit(100)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('event')->sort()->values()->all())
        ->toEqual(['permission_attached', 'permission_detached']);

    foreach ($rows as $row) {
        expect((int) $row->causer_id)->toBe($this->superAdmin->id);
    }

    $detached = $rows->firstWhere('event', 'permission_detached');
    $attached = $rows->firstWhere('event', 'permission_attached');

    expect(json_decode($detached->properties, true)['permissions'])->toContain('guardian.view')
        ->and(json_decode($attached->properties, true)['permissions'])->toContain('guardian.export');
});

// ── D5: runtime edits survive rbac:sync ────────────────────────────────────

it('D5 — a matrix-made grant AND a matrix-made revoke both survive rbac:sync', function () {
    $before = sam_rolePermissions('teacher');
    $wanted = collect($before)->reject(fn ($p) => $p === 'student_subject.view') // revoke a seeded default
        ->push('guardian.view')                                                  // grant beyond the map
        ->values()->all();

    sam_put($this, $this->superAdmin, 'teacher', $wanted)->assertStatus(302);

    (new RbacSeeder)->run();

    $after = sam_rolePermissions('teacher');
    expect($after)->toContain('guardian.view')
        ->and($after)->not->toContain('student_subject.view');
});
