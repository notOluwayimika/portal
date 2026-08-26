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
        // See the note in activity_log_severity.php: 'admin.user_impersonated'
        // was never emitted by anything. The real events are these.
        'rbac.impersonation_started',
        'rbac.impersonation_ended',
        'auth.password_reset',
        // DELIBERATELY ABSENT: `guardian.student_record_viewed` and
        // `guardian.student_record_access_refused`
        // (App\Support\StudentRecordAccessLog). These two exist to answer "did a
        // parent read a child who is not theirs", and an entry listed here is
        // hidden ENTIRELY from anyone without `activity_log.view_sensitive`
        // (ActivityLogQueryService::excludeSensitive). An audit trail of who
        // read whose records that the people auditing cannot see is not a
        // control — it is the 2026-08-25 answer ("it cannot be determined")
        // reproduced deliberately. They carry no credential material and no
        // request body; the properties are actor, subject, route and rule.
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
