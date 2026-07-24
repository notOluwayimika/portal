<?php

use App\Models\Role;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Impersonation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 0045-B1 — the impersonation session (ADR 0045 A1, corrected). Written and
 * run RED before App\Support\Impersonation existed (birth acceptance): each
 * claim discriminates against the absent/naive implementation.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->school = al_makeSchool();

    setPermissionsTeamId(null);
    $this->operator = User::factory()->create();
    $this->operator->assignRole('super_admin');
    $this->operator->flushSchoolAccessCache();

    $this->target = al_makeUser($this->school->id);
    $this->target->grantSchoolAccess($this->school, 'teacher');
    $this->target->flushSchoolAccessCache();

    $this->actingAs($this->operator);
});

// ── Claim 1: authz + context resolve as the impersonated user ──────────────

it('claim 1 — inside the session, can() and School context resolve as the TARGET', function () {
    // Bypass OFF so a pass can only come from the TARGET's grants — with it
    // on, the operator would pass everything and prove nothing.
    config(['auth.gate_before_superadmin' => false]);

    expect(auth()->user()->can('result.submit'))->toBeFalse(); // operator, outside

    [$can, $schoolId, $guardUser] = Impersonation::actAs(
        $this->operator, $this->target, $this->school->id,
        fn () => [auth()->user()->can('result.submit'), ActiveSchool::id(), auth()->id()],
    );

    expect($can)->toBeTrue()                        // teacher's grant, teacher's team
        ->and($schoolId)->toBe($this->school->id)   // target school, not operator's null
        ->and($guardUser)->toBe($this->target->id); // the guard's acting user IS the target
});

// ── Claim 2: attribution is the OPERATOR, mechanically ─────────────────────

it('claim 2 — an audited action inside the session names the operator as causer, target as acted-as', function () {
    Impersonation::actAs($this->operator, $this->target, $this->school->id, function () {
        activity('test')->log('domain action during impersonation');
    });

    $row = DB::table('activity_log')->where('log_name', 'test')->first();

    // The guard user was the TARGET when this row was written — only the
    // session-wide CauserResolver pin can make the causer the operator. An
    // identity-swap implementation goes red exactly here.
    expect((int) $row->causer_id)->toBe($this->operator->id);

    $entry = DB::table('activity_log')->where('event', 'impersonation_started')->first();
    expect((int) $entry->causer_id)->toBe($this->operator->id)
        ->and(json_decode($entry->properties, true)['acted_as'])->toBe($this->target->id);
});

// ── Claims 3+4: bounded, and exit restores ALL THREE independently ─────────

it('claims 3+4 — exit restores guard user, ActiveSchool, and permissions-team independently', function () {
    setPermissionsTeamId(null);

    Impersonation::actAs($this->operator, $this->target, $this->school->id, fn () => null);

    // Three independent asserts — a finally that restores two of three is a
    // silent team leak (NoTeamLeakBetweenJobs family), not a pass.
    expect(auth()->id())->toBe($this->operator->id)
        ->and(ActiveSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

it('claim 4 — a mid-session THROW still restores all three captured values', function () {
    setPermissionsTeamId(null);

    expect(fn () => Impersonation::actAs(
        $this->operator, $this->target, $this->school->id,
        fn () => throw new RuntimeException('mid-session failure'),
    ))->toThrow(RuntimeException::class);

    expect(auth()->id())->toBe($this->operator->id)
        ->and(ActiveSchool::id())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();

    // …and the exit row still wrote (the session is audited even when it dies).
    expect(DB::table('activity_log')->where('event', 'impersonation_ended')->count())->toBe(1);
});

// ── Entry gate + structure ─────────────────────────────────────────────────

it('denies a non-super_admin operator, and refuses nested sessions', function () {
    $notAdmin = al_makeUser($this->school->id);
    $notAdmin->grantSchoolAccess($this->school, 'admin');

    expect(fn () => Impersonation::actAs($notAdmin, $this->target, $this->school->id, fn () => null))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Impersonation::actAs(
        $this->operator, $this->target, $this->school->id,
        fn () => Impersonation::actAs($this->operator, $this->target, $this->school->id, fn () => null),
    ))->toThrow(LogicException::class); // nesting closes the strand-the-wrong-context hazard
});

it('audits entry AND exit as attributed security events', function () {
    Impersonation::actAs($this->operator, $this->target, $this->school->id, fn () => null);

    foreach (['impersonation_started', 'impersonation_ended'] as $event) {
        $row = DB::table('activity_log')->where('event', $event)->first();
        expect($row)->not->toBeNull()
            ->and((int) $row->causer_id)->toBe($this->operator->id);
    }
});

// ── B2: rbac.impersonate is the MASTER KEY — lockout proven, heal proven ───

it('MASTER KEY — without the grant super_admin is stranded; the self-heal is what un-bricks it', function () {
    config(['auth.gate_before_superadmin' => true]); // flag CANNOT save a missing grant

    setPermissionsTeamId(null);
    Role::where('name', 'super_admin')->where('guard_name', 'web')->whereNull('school_id')
        ->firstOrFail()->revokePermissionTo('rbac.impersonate'); // planted drift
    $this->operator->flushSchoolAccessCache();

    // Stranded: bypass ON, isSuperAdmin true — and entry still refused,
    // because the gate reads the explicit grant flag-independently.
    expect(fn () => Impersonation::actAs($this->operator, $this->target, $this->school->id, fn () => null))
        ->toThrow(AuthorizationException::class);

    (new RbacSeeder)->run(); // the heal

    expect(Impersonation::actAs($this->operator, $this->target, $this->school->id, fn () => auth()->id()))
        ->toBe($this->target->id); // un-bricked by the heal, nothing else changed
});

it('self-heal restores the FULL platform set by name, and strips ambient domain grants', function () {
    setPermissionsTeamId(null);
    $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->whereNull('school_id')->firstOrFail();
    $role->givePermissionTo('student_subject.view'); // planted ambient domain grant

    (new RbacSeeder)->run();

    $set = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
    $expected = collect(RbacSeeder::SUPER_ADMIN_PLATFORM)->sort()->values()->all();

    // The SET, member-by-name — a count check would pass a drifted row that
    // totals right but misses the master key (the A3 prod-gate lesson).
    expect($set)->toEqual($expected)
        ->and($set)->toContain('rbac.impersonate');
});
