<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most ONE current academic session per school — as a database fact.
 *
 * `academic_sessions.is_current` shipped as a plain boolean with a default and no index
 * (2026_04_26_114454_create_sessions_table.php), so nothing stopped two sessions in one school
 * both being current. "The current session" is then whichever row a query happens to reach first,
 * and term resolution keys off it: ResolvesTermFilter picks the active term of the CURRENT
 * session, and terms now price fee schedules under a RESTRICT FK. An ambiguous current session is
 * an ambiguous term is an ambiguous fee schedule.
 *
 * PARTIAL UNIQUENESS VIA THE GENERATED-COLUMN IDIOM — copied from
 * 2026_07_26_130000_create_finance_fee_schedules.php, which took it from finance_void_requests,
 * rather than invented here. MySQL has no partial indexes, but it EXEMPTS a row from a unique
 * index when any indexed column is NULL. So a generated column that is the school_id while
 * `is_current` is true and NULL otherwise, plus a UNIQUE index on it, gives exactly "at most one
 * current session per school" while leaving any number of non-current ones alone.
 *
 * ONE FORCED DEVIATION FROM THAT IDIOM: this column is VIRTUAL where fee_schedules' are STORED.
 * Not a preference — STORED is impossible here. Adding a STORED generated column rebuilds the
 * table, and `academic_sessions` already carries `fk_academic_sessions_school_id`, which makes
 * MySQL 9.7.1 abort with error 1215 ("Cannot add foreign key constraint"). Confirmed by trying
 * it, including with an explicit ALGORITHM=COPY; both fail. fee_schedules could use STORED
 * because that column was added to a table created moments earlier in the same migration, with
 * no rows and no inbound FK yet.
 *
 * VIRTUAL is equivalent for this purpose: a unique index on a virtual generated column is
 * materialised in the index, and the NULL exemption behaves identically. Verified by adding the
 * column and index on the live table before writing this.
 *
 * The column type is `BIGINT UNSIGNED` to match `academic_sessions.school_id` after the hybrid-id
 * conversion (2026_04_29_000000) — the create-table migration declares it as a `foreignUuid`, so
 * reading that file alone would give the wrong type. Checked against the live column.
 *
 * VERIFIED BEFORE WRITING: every school currently has exactly one current session (1 of 1, both
 * schools), so the index applies without a data repair. Had it not, this migration would have
 * failed at deploy rather than silently picking a winner — which is the correct failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE academic_sessions
                ADD COLUMN current_school_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(is_current = 1, school_id, NULL)) VIRTUAL'
        );

        DB::statement(
            'ALTER TABLE academic_sessions
                ADD UNIQUE academic_sessions_current_school_unique (current_school_key)'
        );
    }

    public function down(): void
    {
        // Index first: MySQL refuses to drop a column an index still references.
        DB::statement('ALTER TABLE academic_sessions DROP INDEX academic_sessions_current_school_unique');
        DB::statement('ALTER TABLE academic_sessions DROP COLUMN current_school_key');
    }
};
