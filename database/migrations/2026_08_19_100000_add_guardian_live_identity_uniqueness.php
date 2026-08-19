<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most ONE live Guardian row per (user, school) — as a database fact.
 *
 * `guardians` shipped with non-unique indexes on `user_id` and `school_id` and no unique key
 * beyond `uuid` (2026_05_13_132246_create_guardians_table.php). Nothing at the schema level
 * stopped two live rows for the same person in the same school, and a school reported a parent
 * appearing three times. GuardianService::forUserInActiveSchool already documents the gap and
 * works around it with `orderBy('id')`; resolveOrCreateGuardianForUserInSchool guards it in
 * application code. Both are conventions — they hold only for callers that go through them.
 * This is the layer that survives a future writer that reads none of that code.
 *
 * PARTIAL UNIQUENESS VIA THE GENERATED-COLUMN IDIOM — the established pattern here
 * (2026_07_28_120001_add_current_session_uniqueness.php, which took it from
 * finance_fee_schedules, which took it from finance_void_requests). MySQL has no partial
 * indexes, but it EXEMPTS a row from a unique index when any indexed column is NULL. So a
 * column that is `user_id:school_id` while the row is live and NULL once it is soft-deleted,
 * plus a UNIQUE index on it, gives exactly "at most one LIVE guardian per (user, school)":
 *
 *   - soft-deleted rows evaluate to NULL and are exempt, so a guardian can be soft-deleted and
 *     re-created;
 *   - any number of soft-deleted rows may coexist for the same pair;
 *   - the same user in a DIFFERENT school is a different key, so the multi-school parent
 *     (§6.2, one Guardian record per School) keeps working.
 *
 * Deliberately NOT `UNIQUE(user_id, school_id, deleted_at)`: MySQL treats NULLs as distinct in
 * a unique index, so that index would permit unlimited live rows and enforce nothing.
 *
 * VIRTUAL, NOT STORED — forced, not preferred. Adding a STORED generated column rebuilds the
 * table, and `guardians` carries three outbound foreign keys (school_id, user_id, photo_id)
 * plus an inbound one from `guardian_student`; MySQL 9.7.1 aborts the rebuild with error 1215
 * ("Cannot add foreign key constraint"). Confirmed by trying it against the live schema before
 * writing this — STORED fails, VIRTUAL succeeds, and the same deviation is recorded on
 * academic_sessions for the same reason. A unique index on a VIRTUAL generated column is
 * materialised in the index and the NULL exemption behaves identically, so nothing about the
 * guarantee changes.
 *
 * VARCHAR(64) is comfortably wide: both operands are BIGINT UNSIGNED after the hybrid-id
 * conversion (2026_04_29_000000), so the widest possible value is 20 + 1 + 20 = 41 characters.
 * The separator matters — without it, (1, 23) and (12, 3) would collide.
 *
 * VERIFIED BEFORE WRITING: the `HAVING COUNT(*) > 1` group query over live rows returns the
 * empty set on the production copy (776 guardian rows, 0 offending groups), so the index
 * applies to existing data unchanged and no data migration is needed. Had it not, this
 * migration would fail at deploy rather than silently pick a winner — the correct failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE guardians
                ADD COLUMN live_identity VARCHAR(64)
                    GENERATED ALWAYS AS (
                        IF(deleted_at IS NULL, CONCAT(user_id, ':', school_id), NULL)
                    ) VIRTUAL"
        );

        DB::statement(
            'ALTER TABLE guardians
                ADD UNIQUE guardians_live_identity_unique (live_identity)'
        );
    }

    public function down(): void
    {
        // Index first: MySQL refuses to drop a column an index still references.
        DB::statement('ALTER TABLE guardians DROP INDEX guardians_live_identity_unique');
        DB::statement('ALTER TABLE guardians DROP COLUMN live_identity');
    }
};
