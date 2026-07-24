<?php

namespace App\Support;

use App\Enums\Permission as PermissionEnum;
use App\Models\User;

/**
 * The user's EFFECTIVE permission set — every ability the app defines that
 * `can()` currently grants them — for sharing to the frontend (C4).
 *
 * "Effective", not "granted", is the load-bearing choice (c4-brief D1). Iterating
 * the enum and asking `can()` routes each ability through the full Gate:
 *
 *  - `Gate::before` super-admin bypass folds in, so super_admin's shared set is
 *    its true authority (~everything), not its 15 explicit grants. A granted-only
 *    payload would hide buttons the backend would allow.
 *  - ADR 0040's checker exclusion folds in too: `result.approve`/`reject` resolve
 *    false for super_admin, so the UI hides the approve action for the one role
 *    the backend will actually deny. The presentation layer therefore reflects
 *    what the Gate will DO, not what a grant table SAYS.
 *
 * Resolution happens in whatever team context is active when this is called; on
 * the request path that is the active School (SetSchoolContext runs before the
 * Inertia middleware), so the set is correctly scoped to one School.
 *
 * The iteration is bounded by the enum (a fixed ~60 abilities) and spatie caches
 * the permission collection per request, so this is a set of cheap in-memory
 * checks, not N queries.
 */
class EffectivePermissions
{
    /**
     * @return list<string>
     */
    public static function for(User $user): array
    {
        return array_values(array_filter(
            PermissionEnum::values(),
            fn (string $ability) => $user->can($ability),
        ));
    }
}
