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
        'admin.user_impersonated',
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
