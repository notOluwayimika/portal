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
    | Fail-closed School scope — PER-MODEL rollout
    |--------------------------------------------------------------------------
    |
    | An allowlist of School-scoped model classes for which querying with no
    | active School context throws MissingSchoolContextException instead of
    | silently returning unscoped rows (§5.5). There is deliberately NO
    | super-admin exemption: authority (Gate::before) and isolation (SchoolScope)
    | are separate axes.
    |
    | This is intentionally per-model, NOT a global switch (roadmap Rollout Flags
    | table: "scope.fail_closed | per model"; Risk #14). Enabling fail-closed for
    | every model at once would break every console/seeder/job read that runs
    | without a School in a single flip. Instead each model is opted in only after
    | its off-request read paths (seeders, commands, jobs) have been audited and
    | given explicit context via Model::withoutSchoolScope() or
    | ActiveSchool::runFor(), verified by driving the affected flows.
    |
    | Default: EMPTY — no model is fail-closed, so the legacy fail-open behaviour
    | is preserved until a model is explicitly opted in. Per-environment rollout
    | is driven by RBAC_FAIL_CLOSED_MODELS, a comma-separated list of fully
    | qualified model class names, e.g.:
    |
    |   RBAC_FAIL_CLOSED_MODELS="App\Models\Student,App\Models\Notice"
    |
    */
    'fail_closed_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RBAC_FAIL_CLOSED_MODELS', '')),
    ))),
];
