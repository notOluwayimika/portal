<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Locks the authorization ordering (ADR 0043 §3): a permission check must
 * evaluate against the ACTIVE School's context, which means School context has
 * to be established BEFORE the check runs. spatie teams mode resolves every
 * permission per team, and School context (ActiveSchool + setPermissionsTeamId)
 * is what selects the team — on request via SetSchoolContext, off request via
 * ActiveSchool::runFor(). If a permission check ran before that context was
 * established, it would evaluate against the wrong team (or no team) and could
 * authorize an action in the wrong School — the failure this test forbids.
 *
 * The proof: one user granted guardian.view in School A's team ONLY. The
 * identical check yields true under School A context, false under School B
 * context, and false with no context established — so the verdict is a pure
 * function of the active School, and a check that skipped context establishment
 * could never reach the correct answer.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->schoolA = al_makeSchool();
    $this->schoolB = al_makeSchool();

    $this->user = al_makeUser($this->schoolA->id);
    $this->user->grantSchoolAccess($this->schoolA, 'admin');
    $this->user->grantSchoolAccess($this->schoolB, 'admin');

    // C2: the route carries permission:student_status.view — grant the ROUTE
    // tier to the ad-hoc admin role so the request reaches the controller
    // check whose ordering this file pins.
    Permission::firstOrCreate(['name' => 'student_status.view', 'guard_name' => 'web']);
    Role::findByName('admin', 'web')->givePermissionTo('student_status.view');

    // Grant guardian.view to this user in School A's team ONLY.
    Permission::firstOrCreate(['name' => 'guardian.view', 'guard_name' => 'web']);
    setPermissionsTeamId($this->schoolA->id);
    $this->user->givePermissionTo('guardian.view');
    setPermissionsTeamId(null);
    $this->user->flushSchoolAccessCache();
});

it('resolves the permission against whichever School context is active', function () {
    $inA = ActiveSchool::runFor($this->schoolA->id, fn () => $this->user->fresh()->can('guardian.view'));
    $inB = ActiveSchool::runFor($this->schoolB->id, fn () => $this->user->fresh()->can('guardian.view'));

    expect($inA)->toBeTrue()   // granted in A's team
        ->and($inB)->toBeFalse(); // NOT granted in B's team — same user, same ability name
});

it('cannot authorize before School context is established (no team = no grant)', function () {
    // No context established: the global/null team holds no grant, so the very
    // same permission that passes under School A resolves to FALSE. A check that
    // ran before SetSchoolContext/runFor set the team would land exactly here —
    // it could never evaluate to the School-A grant by accident.
    setPermissionsTeamId(null);
    $this->user->unsetRelation('roles')->unsetRelation('permissions');

    expect($this->user->fresh()->can('guardian.view'))->toBeFalse();

    // And with context, it becomes true — proving context is the deciding input.
    $withContext = ActiveSchool::runFor($this->schoolA->id, fn () => $this->user->fresh()->can('guardian.view'));
    expect($withContext)->toBeTrue();
});

it('establishes School context before the controller check runs (request pipeline order)', function () {
    // On the request path, SetSchoolContext sets the permissions team from the
    // active School before the controller's Authz check executes. We prove the
    // ordering end-to-end: a guardian in School A, the user acting in School A,
    // enforcement ON — the wired guardian.view check passes only because the
    // team was already A when the controller ran.
    config(['authz.enforce' => true]);

    $guardianA = al_makeGuardian($this->schoolA->id, al_makeUser($this->schoolA->id)->id);

    // The user's resolvable API context is School A (users.school_id), matching
    // the guardian's School, so the request authorizes.
    $this->actingAs($this->user)
        ->getJson("/api/guardians/{$guardianA->uuid}/students")
        ->assertOk();
});
