<?php

use App\Models\Guardian;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuardianAccountCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin',  'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'guardian', 'guard_name' => 'web']);
    Notification::fake();
});

function profileSchoolAndAdmin(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    // Establish the School/team context before assigning a school-scoped role
    // (mirrors SetSchoolContext on a real request; required by the S7 invariant).
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);
    // Assign the permission so the controller gate passes.
    $adminRole = Role::findByName('admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$school, $admin];
}

function makeGuardian(School $school, ?User $user = null, bool $withEmail = true): Guardian
{
    $u = $user ?? User::factory()->create([
        'school_id' => $school->id,
        'email' => $withEmail ? fake()->unique()->safeEmail() : 'guardian+'.fake()->unique()->lexify('????').'@no-email.local',
    ]);
    setPermissionsTeamId($school->id);
    $u->assignRole('guardian');
    setPermissionsTeamId(null);

    return Guardian::factory()->create([
        'school_id' => $school->id,
        'user_id' => $u->id,
    ]);
}

function attachGuardianToStudent(Guardian $guardian, Student $student, bool $isPrimary = true): void
{
    $student->guardians()->syncWithoutDetaching([
        $guardian->id => [
            'relationship' => 'father',
            'is_primary' => $isPrimary,
            'can_login' => false,
        ],
    ]);
}

// ─── Web route ───────────────────────────────────────────────────────────────

it('loads the guardian profile page for an authenticated admin', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);

    $this->actingAs($admin)
        ->get("/guardians/{$guardian->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/guardians/show')
            ->has('guardian.data')
        );
});

// ─── disable-login ───────────────────────────────────────────────────────────

it('disables login and sets disabled_at on the user', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);

    expect($guardian->user->disabled_at)->toBeNull();

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/disable-login")
        ->assertOk();

    expect($guardian->user->fresh()->disabled_at)->not->toBeNull();
});

it('disable-login is a no-op when login is already disabled', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);
    $guardian->user->update(['disabled_at' => now()]);

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/disable-login")
        ->assertOk();

    // Still disabled — no 500 or unintended state change.
    expect($guardian->user->fresh()->disabled_at)->not->toBeNull();
});

// ─── enable-login (existing endpoint) then disable ───────────────────────────

it('re-enables a disabled user then disables them again', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);
    $guardian->user->update(['disabled_at' => now()]);

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/enable-login")
        ->assertOk();

    expect($guardian->user->fresh()->disabled_at)->toBeNull();

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/disable-login")
        ->assertOk();

    expect($guardian->user->fresh()->disabled_at)->not->toBeNull();
});

// ─── reset-password ──────────────────────────────────────────────────────────

it('sends a password reset notification to the guardian email', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);

    Password::shouldReceive('broker->sendResetLink')
        ->once()
        ->andReturn(Password::RESET_LINK_SENT);

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/reset-password")
        ->assertOk()
        ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'reset'));
});

it('returns 422 when guardian has no valid email for reset', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school, withEmail: false);

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/reset-password")
        ->assertStatus(422);
});

// ─── resend-invitation ───────────────────────────────────────────────────────

it('resends invitation for a never-activated guardian', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);
    // email_verified_at is null — account never activated.
    //
    // forceFill, NOT update(): `email_verified_at` is absent from User::$fillable,
    // so mass assignment SILENTLY DISCARDS it. This test used update() and therefore
    // never created a never-activated guardian at all — the user stayed verified, the
    // service correctly refused, and the 400 it "caught" had nothing to do with the
    // notifyGuardian bug this test is credited with catching.
    $guardian->user->forceFill(['email_verified_at' => null])->save();
    expect($guardian->user->fresh()->email_verified_at)->toBeNull();

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/resend-invitation")
        ->assertOk();

    Notification::assertSentTo($guardian->user, GuardianAccountCreatedNotification::class);
});

it('returns 422 when resending invitation for an already-activated account', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);
    $guardian->user->update(['email_verified_at' => now()]);

    $this->actingAs($admin)
        ->postJson("/api/guardians/{$guardian->uuid}/resend-invitation")
        ->assertStatus(422);
});

// ─── activity ────────────────────────────────────────────────────────────────

it('returns at most 10 activity entries in descending order', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);

    // Seed 12 activity log entries.
    for ($i = 0; $i < 12; $i++) {
        activity('guardian')
            ->performedOn($guardian)
            ->causedBy($admin)
            ->event('updated')
            ->log("Update #{$i}");
    }

    $response = $this->actingAs($admin)
        ->getJson("/api/guardians/{$guardian->uuid}/activity")
        ->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveCount(10);

    // Assert the FULL ordering, not just the endpoints. The old version compared
    // data[0] to data[9] only, which an arbitrary permutation can satisfy by luck —
    // and it was flaky for exactly that reason: all 12 rows are written in the same
    // second, `latest()` ordered by created_at alone, and MySQL's ordering among ties
    // is unspecified. The endpoint now tie-breaks on the append-only id, so this is
    // deterministic.
    $ids = array_column($data, 'id');
    expect($ids)->toBe(collect($ids)->sortDesc()->values()->all());

    // …and it is the NEWEST 10, not an arbitrary 10 — the half that catches a query
    // returning the right count in the right order from the wrong window.
    $allIds = Activity::query()->where('subject_id', $guardian->id)->pluck('id')->sortDesc()->take(10)->values()->all();
    expect($ids)->toBe($allIds);
});

it('returns empty activity list when no events exist', function () {
    [$school, $admin] = profileSchoolAndAdmin();
    $guardian = makeGuardian($school);

    $this->actingAs($admin)
        ->getJson("/api/guardians/{$guardian->uuid}/activity")
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ─── Tenant scoping ──────────────────────────────────────────────────────────

it('returns 404 when accessing a guardian from another school', function () {
    [$school1, $admin] = profileSchoolAndAdmin();
    $school2 = School::factory()->create();
    $guardian = makeGuardian($school2);

    $this->actingAs($admin)
        ->getJson("/api/guardians/{$guardian->uuid}")
        ->assertStatus(404);
});
