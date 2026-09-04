<?php

use App\Enums\Permission as PermissionEnum;
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
        // B2: the precondition is now the PLATFORM set, member-by-name.
        ->and($before)->toEqual(collect(RbacSeeder::SUPER_ADMIN_PLATFORM)->sort()->values()->all());
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

it('D2 — the convention derives the pair, with a declared exception list', function () {
    // RENAMED, not re-expected. This test used to be "the convention derives the pair, NOT a name
    // list", and it pinned `finance.invoice.approve => finance.invoice.submit` as its example of
    // the derivation. That expectation was true only while no checker's maker predated the
    // convention. `finance.invoice.approve`'s maker is `finance.invoice.generate` — named before
    // the `.submit` convention existed — so the derivation produced a permission nobody could hold,
    // and the pair was listed by pairs(), counted by enforcedPairs(), and false for every user
    // forever. The old title was defending something real and the new one keeps it: the pair set is
    // still not an ad-hoc name list. What changed is that the list of EXCEPTIONS is now explicit,
    // declared in one place, and asserted.

    // THE PURE FUNCTION — the convention, unchanged, and still carrying every pair but one. These
    // arms exercise `matchingMakerFor` as pure string surgery over the terminal segment: a checker
    // segment maps to `<prefix>.submit`, a bare `approve` to a bare `submit`, and anything that is
    // not a checker segment to null. No override participates in any of them.
    expect(ApprovalAbility::matchingMakerFor('result.approve'))->toBe('result.submit')
        ->and(ApprovalAbility::matchingMakerFor('result.reject'))->toBe('result.submit')
        ->and(ApprovalAbility::matchingMakerFor('approve'))->toBe('submit')
        ->and(ApprovalAbility::matchingMakerFor('result.view_scores'))->toBeNull()
        ->and(ApprovalAbility::matchingMakerFor('result.submit'))->toBeNull();

    // THE DECLARED EXCEPTION — consulted BEFORE the derivation, so this checker never reaches the
    // string surgery above. The map is `ApprovalAbility::MAKER_OVERRIDES`; that it names only real
    // permissions on both sides is asserted by GrantsMapSeparationTest's "any maker-override map on
    // ApprovalAbility names only real permissions on BOTH sides", which shipped BEFORE the map and
    // reds on an unrecognised constant so a second map cannot arrive unasserted. Without that arm
    // this line would be the only thing standing between a renamed permission and an inert pair.
    expect(ApprovalAbility::matchingMakerFor('finance.invoice.approve'))->toBe('finance.invoice.generate')
        ->and(ApprovalAbility::MAKER_OVERRIDES)->toHaveKey('finance.invoice.approve');

    // The reject side, which joined on 2026-09-04. It names the SAME maker, because approve and
    // reject are the two checker sides of one act.
    expect(ApprovalAbility::matchingMakerFor('finance.invoice.reject'))->toBe('finance.invoice.generate')
        ->and(ApprovalAbility::MAKER_OVERRIDES)->toHaveKey('finance.invoice.reject');

    // AND THE EXCEPTION LIST IS SHORT ON PURPOSE. Asserted so that a new override cannot be added
    // without someone reading this test and the docblock on MAKER_OVERRIDES — the convention is
    // meant to carry the pairs, and a growing list of exceptions is the convention failing.
    //
    // THE COUNT MOVED 1 -> 2 AND THE ARM DID ITS JOB: adding `reject` red it, a human read it, and
    // the entry was argued rather than absorbed. It is NOT weakened to a range or a minimum — a
    // count that cannot red is the assertion deleted with extra steps.
    //
    // WHAT THE SECOND LINE PINS IS THE INVARIANT THE FIRST ONLY APPROXIMATES: the number of
    // DISTINCT MAKERS. `finance.invoice.generate` is the one maker in this codebase whose name
    // predates the `.submit` convention, and approve and reject both point at it — one exception
    // written twice, not two exceptions. A genuinely new exception would name a SECOND
    // pre-convention maker and move this number, which is the thing worth refusing.
    expect(ApprovalAbility::MAKER_OVERRIDES)->toHaveCount(2)
        ->and(array_unique(array_values(ApprovalAbility::MAKER_OVERRIDES)))->toHaveCount(1);
});

it('D2 — a checker-free edit to the same role passes (the rule is the pair, not the role)', function () {
    $wanted = [...sam_rolePermissions('teacher'), 'guardian.view'];

    sam_put($this, $this->superAdmin, 'teacher', $wanted)->assertStatus(302);

    expect(sam_rolePermissions('teacher'))->toContain('guardian.view');
});

// ── User-level SoD on the sync path: a MEMBER holds the opposite side via another role ──

it('D2 (user level) — refuses granting a checker to a role whose MEMBER holds the maker via another role', function () {
    // A user who holds BOTH `registrar` (innocuous) and `accounts_officer` (the credit-note MAKER) in
    // one school. Neither role carries a checker, so the single-role guard sees nothing. Now grant the
    // credit-note CHECKER to `registrar`: that member would hold both sides — the cross-role hole the
    // role-level check cannot see. Finance pairs only (Decision 0), refused wholesale before the write.
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'registrar');
    $user->grantSchoolAccess($school, 'accounts_officer');
    $user->flushSchoolAccessCache();

    $wanted = [...sam_rolePermissions('registrar'), 'finance.credit-note.approve'];

    sam_put($this, $this->superAdmin, 'registrar', $wanted)
        ->assertRedirect()
        ->assertSessionHasErrors('permissions');

    expect(sam_rolePermissions('registrar'))->not->toContain('finance.credit-note.approve');
});

it('D2 (user level) — the SAME checker grant is allowed when no member holds the opposite side', function () {
    // Identical edit, but the only registrar member holds no Finance maker — so granting the checker
    // creates no both-sides user and the sync succeeds. Proves the refusal above is the member rule,
    // not a blanket ban on Finance abilities reaching `registrar`.
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'registrar');
    $user->flushSchoolAccessCache();

    $wanted = [...sam_rolePermissions('registrar'), 'finance.credit-note.approve'];

    sam_put($this, $this->superAdmin, 'registrar', $wanted)->assertStatus(302);

    expect(sam_rolePermissions('registrar'))->toContain('finance.credit-note.approve');
});

// ── Isolation: the matrix may not grant a school_id-crossing permission ─────

it('refuses an edit whose resulting set contains an isolation-crossing permission (ADR 0036)', function () {
    // A test pinning RbacSeeder::grantsMap() stops the SEEDED map carrying it; it does not stop the
    // C6 matrix handing the same grant back at runtime. Without this rule that pin is wallpaper —
    // and this is the one boundary the architecture treats as absolute (super_admin bypasses
    // AUTHORIZATION, never ISOLATION). Derived from PermissionEnum::ISOLATION_CROSSING, the single
    // named source the seeded-map pin also reads.
    foreach (PermissionEnum::ISOLATION_CROSSING as $crossing) {
        $before = sam_rolePermissions('internal_auditor');
        expect($before)->not->toContain($crossing);

        $response = sam_put($this, $this->superAdmin, 'internal_auditor', [...$before, $crossing]);

        // The OUTCOME is asserted first, and by name: with the rule removed this line is what goes
        // red, reading "Expecting [...] not to contain 'activity_log.view_cross_school'" rather than
        // "session is missing expected key [errors]". The permission is the subject of the failure.
        expect(sam_rolePermissions('internal_auditor'))->not->toContain($crossing);
        expect(sam_rolePermissions('internal_auditor'))->toBe($before);

        $response->assertRedirect()->assertSessionHasErrors('permissions');

        // And the message the operator sees names it too, so it is legible on the chip and in the panel.
        expect(session('errors')->get('permissions')[0] ?? '')->toContain($crossing);
    }
});

it('the isolation rule is about the permission, not the role — any matrix-reachable role is refused', function () {
    foreach (PermissionEnum::ISOLATION_CROSSING as $crossing) {
        $before = sam_rolePermissions('teacher');

        $response = sam_put($this, $this->superAdmin, 'teacher', [...$before, $crossing]);

        expect(sam_rolePermissions('teacher'))->not->toContain($crossing);
        expect(sam_rolePermissions('teacher'))->toBe($before);

        $response->assertRedirect()->assertSessionHasErrors('permissions');
    }
});

it('super_admin is not stranded by the isolation rule — it is unreachable through the matrix at all', function () {
    // authorize() (:33) refuses any edit targeting super_admin, so the one sanctioned holder never
    // reaches the validator. Confirmed here rather than assumed: its grant survives the attempt, and
    // it still carries the crossing permission afterwards.
    $before = sam_rolePermissions('super_admin');

    sam_put($this, $this->superAdmin, 'super_admin', ['activity_log.view'])->assertForbidden();

    expect(sam_rolePermissions('super_admin'))->toEqual($before);

    foreach (PermissionEnum::ISOLATION_CROSSING as $crossing) {
        expect($before)->toContain($crossing);
    }
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
