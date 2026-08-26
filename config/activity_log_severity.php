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
        // A guardian was refused a student record, a whole-cohort screen, or
        // another parent's record (App\Support\StudentRecordAccessLog).
        // WARNING and not CRITICAL: one refusal is the guard working exactly as
        // designed — a parent following a stale link, or a route wired with the
        // wrong middleware — and paging on every one of those trains people to
        // ignore the tier. It is a PATTERN that is suspicious: the same causer
        // refused across many subjects is uuid-probing, which is the shape the
        // 2026-08-25 incident could not be checked for. `warning` puts it in
        // reach of the severity filter without claiming each row is an alarm.
        'guardian.student_record_access_refused',
        'grades.modified_after_publish',
    ],

    'notice' => [
        '*.deleted',
        '*.bulk_*',
        'auth.login',
        'auth.logout',
    ],

    // Default bucket for anything unmatched.
    //
    // `guardian.student_record_viewed` — a parent reading their OWN child's
    // record — belongs here, and that IS a decision rather than an oversight:
    // it is the ordinary, authorised case, it is the highest-volume of the two
    // events by far, and raising it would bury the refusals it sits beside.
    //
    // It is recorded HERE, in prose, rather than as a listed pattern, because a
    // listed pattern would be inert: ActivitySeverityService::for() iterates
    // `critical`, `warning` and `notice` ONLY (self::TIERS) and returns 'info'
    // as the fall-through, and ActivityLogQueryService::applySeverityFilter()
    // builds its 'info' clause as "matches none of the explicit tiers". Nothing
    // reads this key. An entry under it would look like a setting while
    // changing nothing — the same defect as a rule with no enforcement, and it
    // would read as pinned to the next person who greps for the event name. The
    // classification is pinned by a test against the resolver instead
    // (tests/Feature/Security/StudentRecordAccessAuditTest.php).
    'info' => '*',
];
