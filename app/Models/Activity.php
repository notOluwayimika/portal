<?php

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use App\Services\ActivityLog\ActivitySensitiveService;
use App\Support\ActivitySchoolResolver;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Custom Spatie activity model.
 *
 * Two write-time responsibilities, both on the `creating` hook so no logging call
 * site has to remember either: auto-populate `school_id` (Phase 0, tenant-scoping
 * the audit log), and REDACT credential material out of `properties` before it can
 * reach the column at all.
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

            // STRIP CREDENTIAL MATERIAL BEFORE IT IS EVER WRITTEN.
            //
            // `User::getActivitylogOptions()` logs `password`, so every signup
            // (`created`) and every password change (`updated`) put a live bcrypt
            // hash into `properties`. ActivitySensitiveService masked it on screen,
            // but the COLUMN held the real value — and the column is what mysqldump,
            // phpMyAdmin's export and every backup copy. Read-time masking was never
            // a control over data at rest.
            //
            // HERE, not on User. A tapActivity() on User would close it for one
            // model, and the queued audit-extension work adds LogsActivity to Teacher
            // and Student plus the 2FA columns to User — silently re-opening it for
            // `two_factor_secret`. This covers every logged model, present and future.
            //
            // `creating`, not `saving`: the logger saves the row again after insert,
            // and re-assigning properties on that second save marks a persisted row
            // dirty and issues an UPDATE, which the §15C trigger below denies. The
            // table is insert-only anyway, so there is no legitimate update to mask.
            //
            // ONE LIST, shared with the read path (config/activity_log_sensitive.php
            // `fields`). Masking keeps the KEY and replaces the VALUE, so the trail
            // still records that a password changed, by whom and when — only the
            // credential goes.
            // Re-wrapped in a Collection: Spatie types `$properties` as
            // `Collection|null` and casts it, so assigning the bare array the
            // masker returns is a type violation even though it round-trips.
            $activity->properties = collect(
                ActivitySensitiveService::make()->maskProperties($activity->properties) ?? []
            );
        });

        static::updating(function () {
            throw new AuditLogImmutableException('UPDATE');
        });

        static::deleting(function () {
            throw new AuditLogImmutableException('DELETE');
        });
    }
}
