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
| Because it is derived at read time, correcting a key here reclassifies
| EVERY existing row carrying that key — there is no severity column and no
| backfill. The corollary is the reason the corrections below were needed at
| all: a key that no emitter writes silently classifies nothing, and nothing
| goes red.
|
| KEYS HERE ARE THE ONES THE CODE WRITES, not the ones we would have chosen.
| Three declared keys did not match any emitter and so fell through to the
| `info` default over 1,800 production rows:
|
|   permissions.role_assigned / role_revoked  →  the listener writes log name
|     `rbac` with events role_attached / role_detached / permission_attached /
|     permission_detached (App\Listeners\LogRbacChange:35-38, :41).
|   auth.login_failed                          →  auth.failed_login
|     (App\Listeners\LogFailedLogin:22,:28).
|   auth.password_reset                        →  authentication.password_reset
|     (App\Listeners\LogPasswordReset:20,:26 — the log name is
|     `authentication`, not `auth`).
|
| The emitted names were NOT renamed to match this file: existing rows carry
| them, and renaming orphans every one. The catalogue was what was wrong.
|
*/

return [
    'critical' => [
        'auth.failed_login_threshold_exceeded',
        // Privilege escalation. App\Listeners\LogRbacChange writes log name
        // `rbac` with these four events for every role assign/remove and every
        // role→permission grant/revoke. They replace the keys
        // 'permissions.role_assigned' / 'permissions.role_revoked', which
        // NOTHING has ever emitted — there is no `permissions` log name in the
        // tree — so the single most security-relevant class of event in the
        // system resolved to `info` and was visible to every holder of
        // activity_log.view.
        'rbac.role_attached',
        'rbac.role_detached',
        'rbac.permission_attached',
        'rbac.permission_detached',
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
        // App\Listeners\LogFailedLogin:22,:28 — activity('auth')->event('failed_login').
        // Replaces 'auth.login_failed', which nothing emits.
        'auth.failed_login',
        // App\Listeners\LogPasswordReset:20,:26 — activity('authentication')
        // ->event('password_reset'). The log name is `authentication`; the
        // previous key said `auth`, which nothing emits.
        'authentication.password_reset',
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

    // ------------------------------------------------------------------
    // Keys declared above that NOTHING in the tree emits today.
    //
    // This block CLASSIFIES NOTHING. It is a registry, not a tier: the keys
    // stay listed in their real tiers above, and this is the record of why
    // they are there with no emitter behind them. Read the caveat under
    // `info` before adding anything here — a non-tier key is inert to
    // ActivitySeverityService::for() (iterates self::TIERS) and to
    // ActivityLogQueryService::applySeverityFilter() (iterates the three
    // explicit tiers), which is exactly why this is safe as a registry and
    // would be a lie as a classification.
    //
    // They are NOT deleted. Each was declared by somebody for a reason, and
    // deleting them to make a future catalogue lint green loses that intent —
    // which is the more expensive half of the trade.
    //
    // Every key here was present in the file's first commit, 8eaee121,
    // 2026-05-16 ("feat: implement comprehensive activity log system"), so
    // "declared before" below means that commit.
    // ------------------------------------------------------------------
    'pending_emitters' => [
        // Reason unknown; declared before 2026-05-16. No lockout, throttle or
        // threshold counter in the tree writes it.
        'auth.failed_login_threshold_exceeded' => 'reason unknown, declared before 2026-05-16',

        // Bulk student/guardian deletion: no bulk-delete route, controller or
        // command exists yet. Declared ahead of an emitter that is owed.
        'students.bulk_deleted' => 'declared ahead of a bulk-delete feature that does not exist yet',
        'guardians.bulk_deleted' => 'declared ahead of a bulk-delete feature that does not exist yet',

        // Grade edits after results are published. Declared ahead of an
        // emitter that is owed; nothing in the results path logs it today.
        'grades.modified_after_publish' => 'declared ahead of a grade-publish audit emitter that is owed',

        // NOT STALE. Refunds were cut from the launch scope and returned to it
        // on 2026-08-31 (docs/handoff/brookstone-answers-31-august.md §1),
        // with every refund to be approved by the Executive Director. There is
        // no refund code at all yet; this key is the surviving marker of the
        // audit the feature owes.
        'finance.refund_issued' => 'declared ahead of refunds, which returned to the launch scope on 2026-08-31',
    ],
];
