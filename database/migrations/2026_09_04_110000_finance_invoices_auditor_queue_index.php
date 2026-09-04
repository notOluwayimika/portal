<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE AUDITOR QUEUE'S INDEX FOLLOWS THE QUERY — a fourth column, and it is for ORDERING.
 *
 * `2026_09_04_100000` shipped `(school_id, reviewed_at, returned_at)` and deliberately stopped
 * there, because the fourth column would have paid NOTHING until the queue actually filtered the
 * return axis: with `returned_at` unconstrained the key prefix breaks at the third column and
 * `created_at` is unusable for ordering. The ticket
 * `docs/handoff/tickets/the-auditor-queue-index-is-one-column-short-until-the-queue-filters-returned.md`
 * held that debt. The same commit as this migration adds `whereNull('returned_at')` to
 * `InvoiceReviewController::pending()`, so the fourth column now pays, and this is it.
 *
 * ── THE FOURTH COLUMN IS FOR ORDERING, NOT FILTERING ────────────────────────────────────────────
 *
 * NO PREDICATE READS `created_at`. It exists so the queue's `ORDER BY created_at, id` is served by
 * the index instead of by a filesort — and the filesort is the part that degrades as a school's book
 * grows, because it is work proportional to the matched set rather than to the page.
 *
 * THE CONSEQUENCE, STATED SO IT IS NOT REDISCOVERED: anyone who changes the queue's sort order has
 * INVALIDATED this column and owes a fresh EXPLAIN. A column whose only job is an `ORDER BY` nobody
 * remembers is a column that has quietly become write cost for nothing.
 *
 * ── THE `, id` TIEBREAK IS SERVED, AND THAT IS NOT AN OVERSIGHT ─────────────────────────────────
 *
 * The queue orders by `created_at` THEN `id`, and only `created_at` is named here — but InnoDB
 * appends the CLUSTERED KEY to every secondary index, so the physical order is
 * `(school_id, reviewed_at, returned_at, created_at, id)` and both `ORDER BY` terms are covered.
 * Recorded because a four-column index serving a two-term sort otherwise reads as a sloppy
 * measurement.
 *
 * ── WHAT `down()` MUST DO, AND WHY IT IS MORE THAN A DROP ───────────────────────────────────────
 *
 * `down()` RESTORES THE THREE-COLUMN INDEX. A rollback that merely dropped the four-column one would
 * leave `finance_invoices` with NEITHER, and the auditor queue would fall back to
 * `finance_invoices_school_student_reviewed_index` — `(school_id, student_id, reviewed_at)`, whose
 * prefix breaks after `school_id` because the queue is school-wide. That is the 27,384-row scan
 * commit 2 measured and removed. A rollback into a state worse than either version is not a
 * rollback.
 *
 * ── NO COLUMNS, NO TRIGGERS, NO DATA ────────────────────────────────────────────────────────────
 *
 * Index only, in both directions. `finance_invoices` is append-only for DELETE and carries five
 * guards; nothing here writes a row, and `ALTER TABLE` is DDL and fires no DML trigger — precedent
 * `2026_07_21_120000` and `2026_07_26_120000`, which added columns while the append-only trigger was
 * live.
 *
 * BOTH DIRECTIONS ARE GUARDED BY `information_schema`, so a re-run or a partial application
 * converges rather than erroring on an index that is already how it should be.
 */
return new class extends Migration
{
    private const TABLE = 'finance_invoices';

    /** 55 characters — measured against MySQL's 64-character identifier cap, not assumed to fit. */
    private const INDEX_AFTER = 'finance_invoices_school_reviewed_returned_created_index';

    private const INDEX_BEFORE = 'finance_invoices_school_reviewed_returned_index';

    private const COLUMNS_AFTER = ['school_id', 'reviewed_at', 'returned_at', 'created_at'];

    private const COLUMNS_BEFORE = ['school_id', 'reviewed_at', 'returned_at'];

    public function up(): void
    {
        $this->swap(self::INDEX_BEFORE, self::INDEX_AFTER, self::COLUMNS_AFTER);
    }

    public function down(): void
    {
        $this->swap(self::INDEX_AFTER, self::INDEX_BEFORE, self::COLUMNS_BEFORE);
    }

    /**
     * Add $add over $columns and drop $drop — in that ORDER, so the table is never left with
     * neither.
     *
     * The two halves are separately guarded rather than wrapped in one condition: a run interrupted
     * between them, or a database where somebody has already done half of this by hand, converges
     * on the next run instead of erroring on an index that is already in the state it should be.
     *
     * @param  list<string>  $columns
     */
    private function swap(string $drop, string $add, array $columns): void
    {
        if (! $this->hasIndex($add)) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($add, $columns) {
                $table->index($columns, $add);
            });
        }

        if ($this->hasIndex($drop)) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($drop) {
                $table->dropIndex($drop);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABLE, $name],
        ) !== null;
    }
};
