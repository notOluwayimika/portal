<?php

use App\Models\User;
use App\Services\ActivityLog\ActivitySeverityService;
use App\Support\ActiveSchool;
use App\Support\Impersonation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 0045 — the impersonation SESSION over HTTP.
 *
 * ImpersonationSessionTest proves the primitive (the closure-bounded swap).
 * This proves the feature built on it: a session that survives across requests,
 * ends by every route out, and cannot be used to escalate or to strand its
 * operator. Those are properties of the middleware/controller/session wiring,
 * none of which the primitive's tests can see.
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
});

/** Acting as the operator, with the school selected — the real entry state. */
function imp_operator($test)
{
    return $test->actingAs($test->operator)
        ->withSession(['school_id' => $test->school->id]);
}

function imp_start($test)
{
    return imp_operator($test)->post('/impersonation', [
        'user_uuid' => $test->target->uuid,
    ]);
}

// ── The session survives the request that started it ──────────────────────

it('records the session so it can outlive the request that started it', function () {
    imp_start($this)->assertRedirect();

    $state = session(Impersonation::SESSION_KEY);

    expect($state)->not->toBeNull()
        ->and($state['operator_id'])->toBe($this->operator->id)
        ->and($state['target_id'])->toBe($this->target->id)
        ->and($state['school_id'])->toBe($this->school->id)
        ->and($state['started_at'])->toBeGreaterThan(0);
});

it('resolves the acting user as the TARGET inside a later request', function () {
    imp_start($this)->assertRedirect();

    // Read the identity from inside the middleware stack, which is the only
    // place the swap is observable.
    Route::middleware('web')->get('/__imp_probe', fn () => response()->json([
        'user' => auth()->id(),
        'school' => ActiveSchool::id(),
    ]));

    $response = imp_operator($this)->getJson('/__imp_probe');

    expect($response->json('user'))->toBe($this->target->id)
        ->and($response->json('school'))->toBe($this->school->id);
});

// ── Attribution across requests: the reason the feature is allowed ─────────

it('attributes an audited action on a LATER request to the OPERATOR', function () {
    imp_start($this)->assertRedirect();

    Route::middleware('web')->get('/__imp_write', function () {
        activity('test')->log('domain action while impersonated');

        return response()->noContent();
    });

    imp_operator($this)->get('/__imp_write')->assertNoContent();

    $row = DB::table('activity_log')->where('log_name', 'test')->latest('id')->first();

    // ADR 0040's signal. Without it, "detection not prevention" (ADR 0045 §3)
    // has nothing to detect with.
    expect((int) $row->causer_id)->toBe((int) $this->operator->id);
});

// ── 2FA is the operator's, not the target's — the middleware slot ──────────

it('does not evaluate 2FA against the impersonated target', function () {
    config(['rbac.two_factor_enforced' => true]);

    // The operator is enrolled; the TARGET is not, and holds a 2FA-required role.
    $this->operator->forceFill(['two_factor_confirmed_at' => now()])->save();
    setPermissionsTeamId($this->school->id);
    $this->target->assignRole('admin');
    $this->target->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->target->flushSchoolAccessCache();

    imp_start($this)->assertRedirect();

    // If ApplyImpersonation ran BEFORE EnsureTwoFactorEnrolled, the 2FA gate
    // would see the unenrolled TARGET and bounce the operator to that user's
    // enrolment page — where they could enrol 2FA on someone else's account.
    // The assertion is on where we are NOT sent, because that redirect is the
    // whole vulnerability.
    $response = imp_operator($this)->get('/dashboard');

    expect($response->headers->get('Location') ?? '')
        ->not->toContain('settings/security');
});

// ── Stop works from INSIDE the session ────────────────────────────────────

it('stops from inside the session, where the acting user holds no impersonate grant', function () {
    imp_start($this)->assertRedirect();

    // The acting user is now the teacher. A permission- or role-gated stop route
    // would 403 here and strand the operator with no way back.
    imp_operator($this)->delete('/impersonation')->assertRedirect();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('leaves no residue after stopping', function () {
    imp_start($this)->assertRedirect();
    imp_operator($this)->delete('/impersonation')->assertRedirect();

    Route::middleware('web')->get('/__imp_after', fn () => response()->json([
        'user' => auth()->id(),
        'override' => ActiveSchool::override(),
    ]));

    $response = imp_operator($this)->getJson('/__imp_after');

    expect($response->json('user'))->toBe($this->operator->id)
        ->and($response->json('override'))->toBeNull();
});

// ── Every exit writes its row — one helper, three paths ───────────────────

it('writes an exit row when stopped explicitly', function () {
    imp_start($this)->assertRedirect();
    imp_operator($this)->delete('/impersonation');

    expect(imp_exitRows())->toBe(1);
});

it('writes an exit row when the session expires', function () {
    config(['impersonation.max_minutes' => 30]);
    imp_start($this)->assertRedirect();

    $this->travel(31)->minutes();

    imp_operator($this)->get('/dashboard');

    expect(imp_exitRows())->toBe(1)
        ->and(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('writes an exit row when the operator logs out mid-session', function () {
    imp_start($this)->assertRedirect();

    imp_operator($this)->post('/logout');

    // Without this the trail shows a start with no end — the exact hole that
    // makes non-repudiation unfalsifiable.
    expect(imp_exitRows())->toBe(1);
});

function imp_exitRows(): int
{
    return DB::table('activity_log')
        ->where('log_name', 'rbac')
        ->where('event', 'impersonation_ended')
        ->count();
}

// ── Refusals ──────────────────────────────────────────────────────────────

it('refuses to impersonate another super admin', function () {
    setPermissionsTeamId(null);
    $other = User::factory()->create();
    $other->assignRole('super_admin');
    $other->flushSchoolAccessCache();

    imp_operator($this)->post('/impersonation', ['user_uuid' => $other->uuid])
        ->assertForbidden();

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses to impersonate yourself', function () {
    imp_operator($this)->post('/impersonation', ['user_uuid' => $this->operator->uuid])
        ->assertStatus(422);

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses a target with no access to the active school', function () {
    $stranger = al_makeUser(al_makeSchool()->id);

    imp_operator($this)->post('/impersonation', ['user_uuid' => $stranger->uuid])
        ->assertStatus(422);

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
});

it('refuses to nest sessions', function () {
    imp_start($this)->assertRedirect();

    // 403, not the controller's 409: inside a session the acting user is the
    // teacher, so the route's `role:super_admin` gate refuses before the
    // controller is reached. The controller's own nesting check is therefore
    // defence in depth — reachable only if that gate ever changes — which is
    // why it stays.
    imp_operator($this)->post('/impersonation', ['user_uuid' => $this->target->uuid])
        ->assertForbidden();

    // and the original session is untouched by the refusal
    expect(session(Impersonation::SESSION_KEY)['target_id'])->toBe($this->target->id);
});

it('refuses a switch-school while impersonating', function () {
    imp_start($this)->assertRedirect();

    // Referer makes this STATEFUL, which is what gives an /api request a
    // session. Without it Sanctum treats the call as a pure token client, which
    // has no session, cannot be impersonating, and correctly skips the guard —
    // so a bare postJson here would pass while testing nothing.
    imp_operator($this)
        ->withHeaders(['Referer' => config('app.url')])
        ->postJson('/api/switch-school', ['school_uuid' => al_makeSchool()->uuid])
        ->assertStatus(409);
});

it('is inert for an authenticated user who is not impersonating', function () {
    // The derived access oracle shows DELETE /impersonation reachable by EVERY
    // role, because the route carries `auth` and nothing else — that is the
    // design (see the route comment: any grant-based check would strand the
    // operator). This is the proof that the open door leads nowhere: with no
    // session, stopping is a no-op that changes no state and writes no audit row.
    $plain = al_makeUser($this->school->id);
    $plain->grantSchoolAccess($this->school, 'guardian');
    $plain->flushSchoolAccessCache();

    $this->actingAs($plain)->withSession(['school_id' => $this->school->id])
        ->delete('/impersonation')
        ->assertRedirect();

    expect(imp_exitRows())->toBe(0)
        ->and(auth()->id())->toBe($plain->id);
});

// NOT tested here: "a bystander cannot end someone else's session". That is
// per-browser session isolation, which the framework provides via the session
// cookie — not something this code implements. The test client shares ONE
// session across requests within a test, so any version of that test would
// either fail for harness reasons or pass only because the session was flushed
// first, proving nothing. The property above — stopping is inert without a
// session — is the part that is actually ours.

it('refuses a non-super-admin operator outright', function () {
    $plain = al_makeUser($this->school->id);
    $plain->grantSchoolAccess($this->school, 'admin');
    $plain->flushSchoolAccessCache();

    $this->actingAs($plain)->withSession(['school_id' => $this->school->id])
        ->post('/impersonation', ['user_uuid' => $this->target->uuid])
        ->assertForbidden();
});

// ── The audit classification IS the control ───────────────────────────────

it('classifies impersonation entry and exit as critical, and as sensitive', function () {
    $severity = app(ActivitySeverityService::class);

    expect($severity->for('rbac', 'impersonation_started'))->toBe('critical')
        ->and($severity->for('rbac', 'impersonation_ended'))->toBe('critical');

    // The dead 'admin.user_impersonated' key meant these fell through to the
    // `info` default — the single most security-relevant event in the system
    // filed as routine, while ADR 0045 §3 accepts real risk on the basis that
    // it is loudly recorded.
    expect(config('activity_log_sensitive.entries'))
        ->toContain('rbac.impersonation_started')
        ->toContain('rbac.impersonation_ended')
        ->not->toContain('admin.user_impersonated');
});
