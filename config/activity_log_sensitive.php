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
| Applied at READ time (ActivityLogQueryService::excludeSensitive), so a
| correction here changes the visibility of every existing row carrying that
| key — nothing is stamped and nothing needs backfilling. See the header of
| config/activity_log_severity.php for the three keys that were declared here
| but never emitted.
|
*/

return [
    'entries' => [
        'grades.modified_after_publish',
        'grades.modified',
        'finance.fee_adjusted',
        'finance.refund_issued',
        // Privilege grants and revocations. App\Listeners\LogRbacChange:35-38
        // writes log name `rbac` with these four events. They replace
        // 'permissions.role_assigned' / 'permissions.role_revoked', which no
        // emitter has ever written, so who-granted-whom-what was visible to
        // every holder of activity_log.view.
        'rbac.role_attached',
        'rbac.role_detached',
        'rbac.permission_attached',
        'rbac.permission_detached',
        'permissions.*',
        // See the note in activity_log_severity.php: 'admin.user_impersonated'
        // was never emitted by anything. The real events are these.
        'rbac.impersonation_started',
        'rbac.impersonation_ended',
        // App\Listeners\LogPasswordReset:20,:26 — the log name is
        // `authentication`, not `auth`. The previous key 'auth.password_reset'
        // matched no row.
        'authentication.password_reset',
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

    // ------------------------------------------------------------------
    // Entries above that NOTHING in the tree emits today. Registry only —
    // it hides nothing and reveals nothing: ActivitySensitiveService reads
    // `entries` and `fields`, and ActivityLogQueryService::excludeSensitive
    // reads `entries`. Neither looks at this key.
    //
    // NOT deleted, for the same reason as the severity file's block: each was
    // declared for a reason and dropping them to green a future lint discards
    // the intent. Every key here was present in the file's first commit,
    // 8eaee121, 2026-05-16 ("feat: implement comprehensive activity log
    // system").
    // ------------------------------------------------------------------
    'pending_emitters' => [
        'grades.modified_after_publish' => 'declared ahead of a grade-publish audit emitter that is owed',
        'grades.modified' => 'declared ahead of a grade-edit audit emitter that is owed',
        'finance.fee_adjusted' => 'declared ahead of a fee-adjustment emitter that is owed',
        // NOT STALE — refunds returned to the launch scope on 2026-08-31
        // (docs/handoff/brookstone-answers-31-august.md §1), Executive
        // Director approval on every one. No refund code exists yet.
        'finance.refund_issued' => 'declared ahead of refunds, which returned to the launch scope on 2026-08-31',
        // No `permissions` log name has ever existed; the real grants land
        // under `rbac` (listed in `entries` above). Kept as the placeholder
        // for whatever this wildcard was meant to catch.
        'permissions.*' => 'reason unknown, declared before 2026-05-16; no `permissions` log name is emitted anywhere',
    ],
];
