<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\SetSchoolContext;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/** C7 — role-driven 2FA enrolment enforcement (c7-brief D1–D4). */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    // D5: default is per-env (off outside production); these tests exercise
    // the ENFORCING path explicitly — the same single code path prod runs.
    config(['rbac.two_factor_enforced' => true]);
    Cache::forget('rbac:2fa_enforced_state');
    $this->school = al_makeSchool();
});

function tfa_unenrolled($test, string $role): User
{
    $user = al_makeUser($test->school->id);
    $user->forceFill(['two_factor_confirmed_at' => null])->save(); // the real gap
    $user->grantSchoolAccess($test->school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

// ── Enforcement fires on the real gap, both transports ─────────────────────

it('redirects an unenrolled admin on web and 403s TWO_FACTOR_REQUIRED on api', function () {
    $admin = tfa_unenrolled($this, 'admin');

    $this->actingAs($admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')
        ->assertRedirect(route('security.edit'));

    $this->actingAs($admin)->withSession(['school_id' => $this->school->id])
        ->getJson('/api/students')
        ->assertStatus(403)
        ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED'); // JSON contract, never an HTML redirect
});

it('leaves a role without the flag untouched (teacher), and an ENROLLED admin untouched', function () {
    $teacher = tfa_unenrolled($this, 'teacher');
    $this->actingAs($teacher)->withSession(['school_id' => $this->school->id])
        ->get('/dashboard')->assertOk();

    $enrolled = al_makeUser($this->school->id); // helper pre-enrols
    $enrolled->grantSchoolAccess($this->school, 'admin');
    $this->actingAs($enrolled)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')->assertOk();
});

// ── D1: GLOBAL to the account, not scoped to the active School ─────────────

it('D1 — requires enrolment in EVERY context when a 2FA role is held in only one team', function () {
    $user = tfa_unenrolled($this, 'admin');      // admin in school A
    $schoolB = al_makeSchool();
    $user->grantSchoolAccess($schoolB, 'teacher'); // only teacher in school B

    // Acting in School B — where the user holds only an exempt role — the
    // requirement still fires: it is a property of the ACCOUNT. A contextual
    // implementation goes red here.
    $this->actingAs($user)->withSession(['school_id' => $schoolB->id])
        ->get('/dashboard')
        ->assertRedirect(route('security.edit'));
});

// ── D2: the exemptions are a NON-deadlock, proven as a loop ────────────────

it('D2 — the full loop: blocked → reaches enrolment → can log out → enrols → proceeds', function () {
    $admin = tfa_unenrolled($this, 'admin');
    $acting = fn () => $this->actingAs($admin)->withSession(['school_id' => $this->school->id]);

    $acting()->get('/setup/users')->assertRedirect(route('security.edit')); // blocked
    // The security page sits behind Fortify's password confirmation; the
    // confirm route is itself exempt (a second potential deadlock, covered).
    $acting()->get('/settings/security')->assertRedirect('/user/confirm-password');
    $acting()->post('/user/confirm-password', ['password' => 'password'])->assertStatus(302);
    $acting()->get('/settings/security')->assertOk();                       // can reach enrolment
    // select-school may itself redirect (single-school auto-pick) — the D2
    // claim is only that it is NOT a 2FA bounce.
    $selectSchool = $acting()->get('/select-school');
    expect($selectSchool->headers->get('Location'))->not->toBe(route('security.edit'));
    $acting()->post('/api/logout')->assertOk();                             // can log out (C2's old wound)

    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();        // enrols
    $acting()->get('/setup/users')->assertOk();                             // proceeds
});

// ── D3: the middleware sits in the planned slot ────────────────────────────

it('D3 — EnsureTwoFactorEnrolled runs after SetSchoolContext in both transport groups', function () {
    // gatherMiddleware() lists group names unexpanded (probed), so the order
    // is asserted inside the group definitions themselves.
    foreach (['web', 'api'] as $group) {
        $stack = collect(app('router')->getMiddlewareGroups()[$group])->values();

        expect($stack->search(EnsureTwoFactorEnrolled::class))
            ->toBeGreaterThan($stack->search(SetSchoolContext::class), "group {$group}");
    }
});

// ── D4: the one constraint the bypass cannot reach ─────────────────────────

it('D4 — an unenrolled super_admin is redirected even with the bypass ON', function () {
    config(['auth.gate_before_superadmin' => true]);

    setPermissionsTeamId(null);
    $superAdmin = User::factory()->create(['two_factor_confirmed_at' => null]);
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    // The requirement reads model_has_roles directly — Gate::before governs
    // authorization, not enrolment, so the bypass is irrelevant here.
    $this->actingAs($superAdmin)->get('/super-admin/rbac')
        ->assertRedirect(route('security.edit'));
});

// ── The toggle (deferred from C6, next to its column) ──────────────────────

it('toggles a role\'s requirement (audited), and the super_admin flag is immutable', function () {
    setPermissionsTeamId(null);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    $before = DB::table('activity_log')->where('log_name', 'rbac')->count();

    $this->actingAs($superAdmin)
        ->put('/super-admin/rbac/roles/teacher/two-factor', ['required' => true])
        ->assertStatus(302);

    expect((bool) DB::table('roles')->where('name', 'teacher')->where('guard_name', 'web')->value('two_factor_required'))->toBeTrue()
        ->and(DB::table('activity_log')->where('log_name', 'rbac')->count())->toBeGreaterThan($before);

    // …and the newly-flagged role now enforces:
    $teacher = tfa_unenrolled($this, 'teacher');
    $this->actingAs($teacher)->withSession(['school_id' => $this->school->id])
        ->get('/dashboard')->assertRedirect(route('security.edit'));

    $this->actingAs($superAdmin)
        ->put('/super-admin/rbac/roles/super_admin/two-factor', ['required' => false])
        ->assertForbidden();
});

// ── Seeded defaults ────────────────────────────────────────────────────────

it('seeds super_admin and admin as 2FA-required; ordinary roles not (Finance roles held for I6)', function () {
    $flags = DB::table('roles')->where('guard_name', 'web')->whereNull('school_id')
        ->pluck('two_factor_required', 'name');

    expect((bool) $flags['super_admin'])->toBeTrue()
        ->and((bool) $flags['admin'])->toBeTrue()
        ->and((bool) $flags['teacher'])->toBeFalse()
        ->and((bool) $flags['guardian'])->toBeFalse();
});

// ── D5/D6: the platform flag is the master switch ──────────────────────────

it('D6 — master-off means nobody is checked, whatever the role rows say', function () {
    config(['rbac.two_factor_enforced' => false]);
    $admin = tfa_unenrolled($this, 'admin');

    $this->actingAs($admin)->withSession(['school_id' => $this->school->id])
        ->get('/setup/users')->assertOk();
});

it('D7 — a flag transition writes exactly one audited rbac row, idempotently', function () {
    $admin = tfa_unenrolled($this, 'admin');
    $hit = fn () => $this->actingAs($admin)->withSession(['school_id' => $this->school->id])->get('/dashboard');

    $count = fn () => DB::table('activity_log')->where('log_name', 'rbac')
        ->where('event', 'two_factor_enforcement_changed')->count();

    $hit();
    $hit();
    expect($count())->toBe(0); // baseline state is not an event — only a transition

    // Seed the durable baseline so the flip below is a genuine TRANSITION.
    DB::table('activity_log')->insert([
        'log_name' => 'rbac', 'description' => 'two_factor_enforcement_changed: on',
        'event' => 'two_factor_enforcement_changed',
        'properties' => json_encode(['enforced' => true]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    config(['rbac.two_factor_enforced' => false]);
    Cache::forget('rbac:2fa_enforced_state');
    $hit();
    $hit();
    expect($count())->toBe(2); // the DISABLE is the row that matters

    // The transition row is deliberately causer-less: it is detected on the
    // first request after a deploy-time env flip, and stamping that bystander
    // as the flipper would be misattribution (spatie auto-resolves causer).
    expect(DB::table('activity_log')->where('event', 'two_factor_enforcement_changed')
        ->orderByDesc('id')->value('causer_id'))->toBeNull();

    // cache flush alone cannot double-log: the durable row is the tiebreaker
    Cache::forget('rbac:2fa_enforced_state');
    $hit();
    expect($count())->toBe(2);
});
