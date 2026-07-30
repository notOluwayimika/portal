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
 *
 * PRE-FLIGHT GUARD, AND WHY THIS ALREADY-APPLIED MIGRATION WAS EDITED (deliberate, not a workaround). This
 * repo's convention is "do not edit an applied migration; supersede it." Here superseding is IMPOSSIBLE: a
 * new migration is timestamped later, so it runs AFTER this CHECK — too late to guard it. And the three
 * pieces of the closure (composite FK → backfill command → this CHECK) cannot run in one `php artisan
 * migrate`, because the backfill must run BETWEEN the FK and the CHECK. Without a guard, a production
 * `migrate` that skips the backfill reaches the ALTER with 366 violating rows, fails with an opaque MySQL
 * error, and stops the deploy with the FK on and the CHECK off. The guard added to up() below fixes that.
 * Editing this file is safe precisely because Laravel tracks migrations by FILENAME: it will not re-run on
 * dev/staging (where it is already recorded and there are 0 violating rows, so the guard is a no-op), and a
 * fresh `migrate` (test DB, quality-clean-db) runs it with 0 violating rows too — the guard only ever fires
 * on an environment where this CHECK has not yet been added, which is exactly production. The convention's
 * purpose (never diverge an already-migrated environment) is therefore preserved. Flagged in the PR for a
 * deliberate review decision rather than assumed.
 */
return new class extends Migration
{
    private const CHECK = 'student_curricula_promoted_requires_link';

    public function up(): void
    {
        // PRE-FLIGHT GUARD (added post-merge, deliberately editing this already-applied migration — see the
        // docblock's PRE-FLIGHT GUARD note for why that is safe). The three pieces cannot deploy in one `migrate`:
        // the composite FK, then THIS check, run back-to-back, but `academics:backfill-promotion-links` has to
        // run BETWEEN them. If a `migrate` reaches here with violating rows still present, the raw ALTER fails
        // with an opaque MySQL constraint error and the deploy stops with the FK applied and the CHECK not.
        // Fail LOUDLY instead, naming the count and the command, so the procedure explains itself when skipped.
        $violating = (int) DB::table('student_curricula')
            ->whereRaw("status = BINARY 'promoted'")
            ->whereNull('promoted_to_id')
            ->count();

        if ($violating > 0) {
            throw new RuntimeException(
                "Refusing to add {$this->constraintName()}: {$violating} student_curricula row(s) are "
                ."status='promoted' with a NULL promoted_to_id. Run `php artisan academics:backfill-promotion-links` "
                .'(dry run first, then --commit), resolve any orphans it reports, then re-run this migration. '
                .'See the S1 promotion-link closure.'
            );
        }

        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT '.self::CHECK.'
                CHECK (status <> BINARY \'promoted\' OR promoted_to_id IS NOT NULL)'
        );
    }

    private function constraintName(): string
    {
        return self::CHECK;
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE student_curricula DROP CONSTRAINT '.self::CHECK);
    }
};
