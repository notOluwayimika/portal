<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit-log immutability at the storage layer (Constitution §15C — "the audit log
 * is permanent and must not be editable or deletable by any user"). BEFORE
 * UPDATE and BEFORE DELETE triggers on activity_log SIGNAL an error, so the
 * guarantee holds even against raw DB::table() writes, tinker, a mass
 * ->delete() (activitylog:clean bypasses model events), or a future model that
 * forgets the guard — the layer that needs no application code to fire.
 *
 * Safe because the write path is insert-only: 0 of 124k+ existing rows have
 * updated_at > created_at, and school_id is populated in `creating` (before the
 * insert). DDL (ALTER/DROP for future schema migrations) is NOT affected — the
 * triggers fire only on row UPDATE/DELETE (DML).
 *
 * Interaction with BackfillActivityLogSchoolId: that command's raw UPDATE of
 * school_id is a completed one-time repair (resolvable rows done; the residual
 * nulls are unresolvable system/cross-School events). It ran BEFORE this lock and
 * is now superseded by the `creating` resolver; the command guards against the
 * lock and refuses gracefully if re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(
            "CREATE TRIGGER activity_log_no_update BEFORE UPDATE ON activity_log
             FOR EACH ROW SIGNAL SQLSTATE '45000'
             SET MESSAGE_TEXT = 'activity_log is append-only and immutable (Constitution §15C): UPDATE is denied.';"
        );

        DB::unprepared(
            "CREATE TRIGGER activity_log_no_delete BEFORE DELETE ON activity_log
             FOR EACH ROW SIGNAL SQLSTATE '45000'
             SET MESSAGE_TEXT = 'activity_log is append-only and immutable (Constitution §15C): DELETE is denied.';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_delete');
    }
};
