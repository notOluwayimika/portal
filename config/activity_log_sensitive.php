<?php

/*
|--------------------------------------------------------------------------
| Activity Log Sensitive Configuration
|--------------------------------------------------------------------------
|
| Two concerns:
|
| 1. `entries` — "{log_name}.{event}" patterns (same wildcard rules as the
|    severity map) for activities that are themselves sensitive. Users
|    WITHOUT `activity_log.view_sensitive` never see these rows.
|
| 2. `fields`  — property/attribute names that must be masked ("***") in the
|    diff/detail view. A read-time safety net only; sensitive values should
|    already be stripped at write time by the logging code.
|
*/

return [
    'entries' => [
        'grades.modified_after_publish',
        'grades.modified',
        'finance.fee_adjusted',
        'finance.refund_issued',
        'permissions.role_assigned',
        'permissions.role_revoked',
        'permissions.*',
        'admin.user_impersonated',
        'auth.password_reset',
    ],

    'fields' => [
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'secret',
        'token',
        'access_token',
        'refresh_token',
    ],
];
