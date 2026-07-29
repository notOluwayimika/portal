<?php

/*
|--------------------------------------------------------------------------
| Activity Log Severity Map
|--------------------------------------------------------------------------
|
| Spatie's activity_log has no native severity. Severity is derived at READ
| time from "{log_name}.{event}" keys via App\Services\ActivityLog\
| ActivitySeverityService. Most specific match wins; wildcards (*) are
| supported on either side of the dot. Never stored.
|
*/

return [
    'critical' => [
        'auth.failed_login_threshold_exceeded',
        'permissions.role_assigned',
        'permissions.role_revoked',
        // Impersonation entry/exit. These keys replace 'admin.user_impersonated',
        // which NOTHING emitted — severity resolves "{log_name}.{event}" and the
        // code writes log name `rbac` with these two events, so the dead key left
        // the single most security-relevant event in the system falling through
        // to the `info` default. ADR 0045 accepts that super_admin can drive both
        // sides of maker-checker precisely BECAUSE impersonation is loudly
        // attributed; that acceptance is only sound if these rows are surfaced.
        'rbac.impersonation_started',
        'rbac.impersonation_ended',
        'finance.refund_issued',
    ],

    'warning' => [
        'auth.login_failed',
        'auth.password_reset',
        'students.bulk_deleted',
        'guardians.bulk_deleted',
        'grades.modified_after_publish',
    ],

    'notice' => [
        '*.deleted',
        '*.bulk_*',
        'auth.login',
        'auth.logout',
    ],

    // Default bucket for anything unmatched.
    'info' => '*',
];
