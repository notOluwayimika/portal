<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A term must end after it starts — enforced by the DATABASE, not only by a FormRequest.
 *
 * TermController previously validated `start_date`/`end_date` as `required|string`, so "banana"
 * was an acceptable term window. That is now `required|date` with `after:start_date`, but
 * application validation only binds the one path that runs it: seeders, jobs, tinker, a future
 * import, and the TermSeeder invoked from inside a migration (2026_05_06_085734) all write terms
 * without ever reaching it.
 *
 * That matters more since S1 commit 2: `finance_fee_schedules.term_id` is a RESTRICT foreign key,
 * so a term's window prices a fee schedule. A backwards term is no longer a cosmetic error — it
 * reaches money, and it cannot be deleted away once priced.
 *
 * NULL-TOLERANT, deliberately. Both columns are nullable in the schema and MySQL treats a CHECK
 * as satisfied when it evaluates to NULL, so this constrains rows that HAVE both dates and stays
 * silent on rows that do not. Making the columns NOT NULL is a separate decision with its own
 * backfill; this migration does not smuggle it in.
 *
 * VERIFIED BEFORE WRITING: zero rows violate this today (`end_date <= start_date` returns 0 of 6
 * terms), so it applies without a backfill. The term data that IS wrong is wrong in a different
 * way — duplicated windows between Term 1 and Term 3 in one school — which this constraint does
 * not and should not catch. See the PR body.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'terms_end_after_start_check';

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE terms
                ADD CONSTRAINT '.self::CONSTRAINT.'
                CHECK (start_date IS NULL OR end_date IS NULL OR end_date > start_date)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE terms DROP CHECK '.self::CONSTRAINT);
    }
};
