<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

/**
 * Resolves the owning school_id for an activity_log row.
 *
 * The precedence intentionally mirrors how tenant context is resolved
 * elsewhere in the app:
 *   - App\Http\Middleware\SetSchoolContext
 *   - App\Concerns\BelongsToSchool
 *
 * Active context is resolved through App\Support\ActiveSchool so that the
 * runFor() override is honoured — queue workers and off-request jobs set their
 * School via ActiveSchool::runFor(), and audit attribution must follow it rather
 * than reading a stale session or the user's own column directly (S7 / ADR 0042).
 */
class ActivitySchoolResolver
{
    /**
     * Full resolution used when a new activity is being created.
     *
     * Order: active School context (runFor override -> session -> token ->
     * users.school_id, via ActiveSchool) -> causer -> subject. If nothing
     * resolves, null is returned and the gap is logged to the dedicated
     * `activity-log-untagged` channel for later investigation.
     */
    public function resolveForNewActivity(ActivityContract $activity): int|string|null
    {
        // 1. Active School context. ActiveSchool::id() prefers the runFor()
        //    override (so workers/jobs attribute to the School they run for),
        //    then session, then token, then the users.school_id fallback — the
        //    single funnel every other tenant read already goes through.
        if ($schoolId = ActiveSchool::id()) {
            return $schoolId;
        }

        // 2. Session context WITHOUT an authenticated user. ActiveSchool::id()
        //    short-circuits to null when no user is bound, so a pre-auth request
        //    that nonetheless carries a selected school (e.g. during a login
        //    flow) still attributes correctly.
        if ($schoolId = session('school_id')) {
            return $schoolId;
        }

        // 3 & 4. Fall back to the causer's, then the subject's, school.
        if ($schoolId = $this->resolveFromRelations($activity)) {
            return $schoolId;
        }

        Log::channel('activity-log-untagged')->warning(
            'Activity created without a resolvable school_id.',
            [
                'log_name' => $activity->log_name,
                'event' => $activity->event,
                'description' => $activity->description,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
            ]
        );

        return null;
    }

    /**
     * Causer-then-subject resolution only. Used by the backfill command,
     * which runs in CLI where there is no session/auth context.
     */
    public function resolveFromRelations(ActivityContract $activity): int|string|null
    {
        return $this->schoolIdOf($activity->causer)
            ?? $this->schoolIdOf($activity->subject);
    }

    /**
     * Read a model's school_id if it carries one, without assuming the
     * attribute exists (causer/subject are polymorphic).
     */
    public function schoolIdOf(?Model $model): int|string|null
    {
        if ($model === null) {
            return null;
        }

        return $model->getAttribute('school_id');
    }
}
