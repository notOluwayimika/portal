<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1 promotion-link closure — a promoted episode MUST carry its link, as a database fact.
 *
 * The invariant "status='promoted' ⇒ promoted_to_id IS NOT NULL" is asserted in code at three sites
 * (StudentCurriculumController::updateStatus :98/:118 clear the link when leaving promoted;
 * StudentService::updateStatus does the same since commit 5) and was, until this closure, MISSED at two
 * manufacture sites — MoveFromCcmJob inheriting 'promoted' onto a fresh unlinked row, and the manual
 * 'Promoted' status option. "An invariant asserted in N places and missed in one belongs in the schema"
 * (2026_07_19_130000). Both manufacture sites are closed in this same change; this makes it structural.
 *
 * WHY THIS COULD NOT BE ADDED IN COMMIT 5. Commit 5's Part 0 found 366 existing rows with status='promoted'
 * and a NULL link (produced by MoveFromCcmJob before it recorded the link). The CHECK would have rejected
 * the table. Commit 5 deliberately did NOT clean data to fit a constraint whose subject was the composite
 * FK — it reported the 366 and left the CHECK as this follow-up. The 366 are backfilled (by
 * `academics:backfill-promotion-links`, after the composite FK so every link is engine-validated) and the
 * two manufacture sites closed BEFORE this migration runs; the constraint now waits on nothing.
 *
 * BINARY on the status comparison — house discipline regardless of the column's collation (the enum values
 * are lowercase ASCII and it makes no practical difference here; the point of a house rule is that it does
 * not require a per-site judgement). A NULL promoted_to_id is permitted for every non-promoted status, and
 * a promoted row with a link passes — so ordinary enrollment is untouched.
 */
return new class extends Migration
{
    private const CHECK = 'student_curricula_promoted_requires_link';

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT '.self::CHECK.'
                CHECK (status <> BINARY \'promoted\' OR promoted_to_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE student_curricula DROP CONSTRAINT '.self::CHECK);
    }
};
