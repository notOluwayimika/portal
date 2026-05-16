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
 *   - App\Http\Middleware\SetTenantContext
 *   - App\Concerns\BelongsToSchool
 *
 * There is no app('current_school') binding in this codebase; the request
 * tenant is `session('school_id') ?? auth()->user()->school_id`.
 */
class ActivitySchoolResolver
{
    /**
     * Full resolution used when a new activity is being created.
     *
     * Order: request/session context -> authed user -> causer -> subject.
     * If nothing resolves, null is returned and the gap is logged to the
     * dedicated `activity-log-untagged` channel for later investigation.
     */
    public function resolveForNewActivity(ActivityContract $activity): int|string|null
    {
        // 1. Request context (most reliable inside HTTP requests).
        if ($schoolId = session('school_id')) {
            return $schoolId;
        }

        // 2. The authenticated user performing the action.
        if (auth()->check() && ($schoolId = auth()->user()->school_id)) {
            return $schoolId;
        }

        // 3 & 4. Fall back to the causer's, then the subject's, school.
        if ($schoolId = $this->resolveFromRelations($activity)) {
            return $schoolId;
        }

        Log::channel('activity-log-untagged')->warning(
            'Activity created without a resolvable school_id.',
            [
                'log_name'     => $activity->log_name,
                'event'        => $activity->event,
                'description'  => $activity->description,
                'causer_type'  => $activity->causer_type,
                'causer_id'    => $activity->causer_id,
                'subject_type' => $activity->subject_type,
                'subject_id'   => $activity->subject_id,
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
