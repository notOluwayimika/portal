<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0040 mechanism 2 — structural maker ≠ checker for the result workflow
 * (ADR 0044: "enforces the maker/checker split with the same structure Finance
 * uses … checker ≠ maker", resolved the Finance way, not re-litigated).
 *
 * WHY A SCHEMA CHANGE WAS UNAVOIDABLE. `subject_result_statuses` recorded a
 * single `updated_by`, overwritten on every transition. The approver's write
 * therefore DESTROYED the submitter's identity — after an approval the table
 * could no longer answer "who submitted this?", so `decided_by <> submitted_by`
 * was not merely unenforced, it was unrepresentable. No Policy could have
 * closed that; the comparison had nothing to compare against.
 *
 * The two roles are now distinct columns, and the constraint lives at the DB so
 * it holds for raw writes, jobs, tinker sessions and future call sites that
 * never pass through SubjectResultPolicy:
 *
 *     CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)
 *
 * The NULL guards are load-bearing, not laziness: a draft has no decider yet,
 * and historical rows (below) have no recoverable submitter.
 *
 * BACKFILL — deliberately partial, because the truth is partial:
 *  - submitted rows: `updated_by` IS the submitter → copied to submitted_by.
 *  - approved/rejected rows: `updated_by` is the DECIDER → copied to decided_by;
 *    submitted_by stays NULL because that fact was overwritten and is not
 *    recoverable. Inventing it (e.g. assuming the subject's teacher) would
 *    manufacture audit history. A NULL submitted_by reads as "unknown", which
 *    is what it is, and the constraint's NULL guard admits those rows.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'subject_result_statuses_maker_ne_checker';

    public function up(): void
    {
        // foreignId, not foreignUuid: users.id is bigint unsigned. (The original
        // create-table migration reads `foreignUuid('updated_by')`, but the live
        // column is bigint — a later migration converted the key space, and the
        // old source is misleading. Verified against the running schema.)
        Schema::table('subject_result_statuses', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('updated_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->after('submitted_by')->constrained('users');
        });

        DB::statement("UPDATE subject_result_statuses SET submitted_by = updated_by WHERE status = 'submitted'");
        DB::statement("UPDATE subject_result_statuses SET decided_by = updated_by WHERE status IN ('approved', 'rejected')");

        DB::statement(
            'ALTER TABLE subject_result_statuses
                ADD CONSTRAINT '.self::CONSTRAINT.'
                CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subject_result_statuses DROP CHECK '.self::CONSTRAINT);

        Schema::table('subject_result_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
