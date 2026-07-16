<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Single source of School access
    |--------------------------------------------------------------------------
    |
    | When true, User::accessibleSchoolIds() derives School access solely from
    | model_has_roles (the single source of truth — §7.1), instead of the union
    | of the school_user pivot, guardian records and the users.school_id
    | fallback.
    |
    | This is a temporary expand/contract rollout flag. It stays OFF until the
    | parity test is green and the legacy sources have been backfilled into
    | model_has_roles in every environment; only then is it switched on and the
    | legacy columns dropped (a later slice).
    |
    */
    'single_source_access' => env('RBAC_SINGLE_SOURCE_ACCESS', false),

    /*
    |--------------------------------------------------------------------------
    | Fail-closed School scope
    |--------------------------------------------------------------------------
    |
    | When true, querying a School-scoped model while authenticated with no
    | active School context throws MissingSchoolContextException instead of
    | silently returning unscoped rows (§5.5). Super admins (who act globally
    | until they select a School) are exempt.
    |
    | Temporary expand/contract rollout flag, default OFF. Enable per environment
    | only after seeders/console paths that legitimately run without a School use
    | Model::withoutSchoolScope() (or ActiveSchool::runFor()), verified by driving
    | the affected flows in the running app.
    |
    */
    'scope_fail_closed' => env('RBAC_SCOPE_FAIL_CLOSED', false),
];
