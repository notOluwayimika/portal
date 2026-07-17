<?php

use App\Support\SchoolAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * S7: the three legacy school_user readers now funnel through SchoolAccess, which
 * is flag-gated on rbac.single_source_access. This locks BOTH branches so the
 * flag-on (model_has_roles) path — the whole point of the repoint — is exercised,
 * not just the default legacy path.
 */
uses(RefreshDatabase::class);

function userIdsWithAccess(int $schoolId): array
{
    return DB::query()
        ->fromSub(function ($q) use ($schoolId) {
            SchoolAccess::userIdsWithAccessTo($schoolId)($q);
        }, 'a')
        ->pluck('a.'.(config('rbac.single_source_access') ? config('permission.column_names.model_morph_key') : 'user_id'))
        ->map(fn ($id) => (int) $id)
        ->sort()->values()->all();
}

it('resolves the same user via role (flag on) and pivot (flag off) when both exist', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin'); // adds BOTH a role (team) and the school_user pivot

    config(['rbac.single_source_access' => false]);
    expect(userIdsWithAccess($school->id))->toContain($user->id); // via school_user

    config(['rbac.single_source_access' => true]);
    expect(userIdsWithAccess($school->id))->toContain($user->id); // via model_has_roles
});

it('diverges exactly where parity expects: pivot-only user is legacy-visible, role-invisible', function () {
    $school = al_makeSchool();

    // Pivot-only: attach the school via the belongsToMany pivot but grant NO role.
    $user = al_makeUser($school->id);
    $user->schools()->syncWithoutDetaching([$school->id]);

    config(['rbac.single_source_access' => false]);
    expect(userIdsWithAccess($school->id))->toContain($user->id); // legacy sees the pivot

    config(['rbac.single_source_access' => true]);
    expect(userIdsWithAccess($school->id))->not->toContain($user->id); // single source has no role → excluded
});
