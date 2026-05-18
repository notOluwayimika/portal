<?php

namespace App\Models;

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
 * Activity logs are append-only — no edit/delete behaviour is added here.
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
    }
}
