<?php

use App\Exceptions\DutySeparationViolationException;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Grant-time ENFORCEMENT of user-level segregation of duties (Finance pairs only — Decision 0).
 *
 * The companion of MakerCheckerSeparationTest, which only DETECTS. Here the guard in
 * User::assignRole (the single chokepoint every role write crosses) and the role→permission sync
 * guard in SyncRolePermissionsRequest REFUSE a grant that would leave one user holding both sides
 * of a Finance maker-checker pair within a school — before any write (wholesale, no partial
 * application). The shared rule lives once in App\Support\DutySeparation (Decision 1).
 *
 * WATCHED RED before the guard landed: with the assignRole guard removed, the two detection tests
 * that build a both-sides finance user (MakerCheckerSeparationTest) go GREEN via grantSchoolAccess
 * instead of needing the raw-insert plant — i.e. the grants that these tests prove are refused were
 * previously accepted. That flip is the bite proof that the guard, not some incidental condition,
 * is what refuses here.
 *
 * The enforced pairs today (all Finance): credit-note.approve/reject ↔ credit-note.submit and
 * invoice.void-request.approve/reject ↔ invoice.void-request.submit. In the seeded map:
 *   accounts_officer = MAKER  (credit-note.submit + void-request.submit)
 *   finance_director  = CHECKER (credit-note + void-request approve/reject)
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

// ── The assignRole chokepoint: refuse both directions ──────────────────────

it('REFUSES assigning the checker role to a user who already holds the maker (maker → checker)', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'accounts_officer'); // maker — allowed

    expect(fn () => $user->grantSchoolAccess($school, 'finance_director'))
        ->toThrow(DutySeparationViolationException::class);

    // Nothing landed: the checker role was refused before the write.
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['accounts_officer']);
});

it('REFUSES assigning the maker role to a user who already holds the checker (checker → maker mirror)', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'finance_director'); // checker — allowed

    expect(fn () => $user->grantSchoolAccess($school, 'accounts_officer'))
        ->toThrow(DutySeparationViolationException::class);

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['finance_director']);
});

// ── Decision 0: the boundary is FINANCE pairs only ─────────────────────────

it('ALLOWS a RESULT both-sides grant — enforcement is Finance pairs only (Decision 0)', function () {
    // teacher = result MAKER (result.submit); head_of_school = result CHECKER (result.approve/reject).
    // One user holding both is a DETECTION finding, but the result pair is NOT enforced at grant time
    // — the result workstream has not signed off on enforcement — so the grant is accepted.
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'teacher');
    $user->grantSchoolAccess($school, 'head_of_school'); // would throw if enforcement were global

    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->sort()->values()->all())->toBe(['head_of_school', 'teacher']);
});

// ── super_admin: the team-less role is never a duty-separation refusal ──────

it('EXEMPTS super_admin — the team-less platform role is not a Finance pair, and the guard skips null-team', function () {
    setPermissionsTeamId(null);
    $super = User::factory()->create();

    expect(fn () => $super->assignRole('super_admin'))->not->toThrow(DutySeparationViolationException::class);
    expect($super->fresh()->hasRole('super_admin'))->toBeTrue();
});

// ── Per-school scope: maker@A + checker@B share no record ───────────────────

it('ALLOWS the maker at school A and the checker at school B — the pair is per-school', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $user = al_makeUser($schoolA->id);

    $user->grantSchoolAccess($schoolA, 'accounts_officer'); // maker @ A
    // Not a violation: no single school holds both sides.
    expect(fn () => $user->grantSchoolAccess($schoolB, 'finance_director'))
        ->not->toThrow(DutySeparationViolationException::class);

    setPermissionsTeamId($schoolA->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['accounts_officer']);

    setPermissionsTeamId($schoolB->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['finance_director']);
});

// ── Wholesale: a violating multi-role grant applies NOTHING ─────────────────

it('applies NOTHING when a multi-role grant contains a violating role — no partial application', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'accounts_officer'); // maker

    // registrar is innocuous; finance_director violates. The guard refuses BEFORE spatieAssignRole,
    // so the whole call lands nothing — registrar must NOT sneak in alongside the refused checker.
    setPermissionsTeamId($school->id);
    expect(fn () => $user->assignRole('registrar', 'finance_director'))
        ->toThrow(DutySeparationViolationException::class);

    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['accounts_officer'])
        ->and($user->hasRole('registrar'))->toBeFalse()
        ->and($user->hasRole('finance_director'))->toBeFalse();
});
