<?php

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

/**
 * Bite-proofs the S7 parity instrument (task §4): a parity report that has never
 * caught anything is indistinguishable from one that cannot. We inject a known
 * divergence, prove the instrument reports it with every field, and — because a
 * matched user produces no row — prove it stays silent when the paths agree.
 */
uses(RefreshDatabase::class);

it('detects and reports an injected access divergence with all fields', function () {
    config(['rbac.parity_soak' => true]);

    // Injected divergence: the user has a legacy access source (users.school_id)
    // for $school, but NO role row there → single source omits it → "lost".
    $school = al_makeSchool();
    $user = al_makeUser($school->id); // sets users.school_id = $school->id
    // Deliberately grant NO role in $school (so model_has_roles has no team row).
    $user->flushSchoolAccessCache();

    $captured = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function ($message, $context) use (&$captured) {
        $captured[] = $context;
    });

    $user->accessibleSchoolIds();

    $mismatch = collect($captured)->firstWhere('school_id', $school->id);
    expect($mismatch)->not->toBeNull()
        ->and($mismatch['user_id'])->toBe($user->id)
        ->and($mismatch['school_id'])->toBe($school->id)
        ->and($mismatch['old_has_access'])->toBeTrue()
        ->and($mismatch['new_has_access'])->toBeFalse()
        ->and($mismatch['reason'])->toBe('lost')
        ->and($mismatch['source'])->toBe('legacy_union_vs_model_has_roles');
});

it('stays silent when the two paths agree (no false positives)', function () {
    config(['rbac.parity_soak' => true]);

    // Access granted through the real path: role per team AND the pivot — so the
    // legacy union and model_has_roles are identical.
    $school = al_makeSchool();
    Permission::firstOrCreate(['name' => 'guardian.view', 'guard_name' => 'web']);
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin');
    $user->flushSchoolAccessCache();

    // users.school_id equals the granted school, so the legacy fallback does not
    // introduce an extra id either.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    $user->accessibleSchoolIds();
});
