<?php

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use App\Support\ActivitySchoolResolver;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Custom Spatie activity model.
 *
 * Single responsibility for Phase 0: auto-populate `school_id` on every new
 * activity so the audit log becomes tenant-scoped without touching any of the
 * existing logging call sites.
 *
 * Intentionally NOT using App\Concerns\BelongsToSchool: this model must remain
 * readable across schools (backfill, system-level events, and super-admins all
 * need un-scoped reads), and population here is custom rather than the trait's
 * generic creating hook.
 *
 * APPEND-ONLY / IMMUTABLE (Constitution §15C). The write path inserts each row
 * exactly once — there is no in-cycle update (verified: 0 of 124k+ rows have
 * updated_at > created_at; school_id is set in `creating`, before insert). So the
 * `updating`/`deleting` guards below block ALL after-the-fact mutation without
 * breaking any legitimate write. This is the model-level layer; BEFORE
 * UPDATE/DELETE database triggers (2026_07_18_200000 migration) enforce the same
 * for raw / mass writes that bypass the model (e.g. activitylog:clean's mass
 * delete, DB::table()->update()).
 */
class Activity extends SpatieActivity
{
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $activity) {
            if ($activity->school_id === null) {
                $activity->school_id = app(ActivitySchoolResolver::class)
                    ->resolveForNewActivity($activity);
            }
        });

        static::updating(function () {
            throw new AuditLogImmutableException('UPDATE');
        });

        static::deleting(function () {
            throw new AuditLogImmutableException('DELETE');
        });
    }
}
