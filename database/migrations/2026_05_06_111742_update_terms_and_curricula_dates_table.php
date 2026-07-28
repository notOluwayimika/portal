<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            // $table->timestampTz('start_date')->nullable()->after('status');
            // $table->timestampTz('end_date')->nullable()->after('start_date');
            $table->timestampTz('registration_deadline')->nullable();
            $table->timestampTz('result_visible_at')->nullable();
        });

        // Migrate data
        $terms = DB::table('terms')->get();

        foreach ($terms as $term) {
            $curricula = DB::table('curricula')
                ->where('term_id', $term->id)
                ->select('registration_deadline', 'result_visible_at')
                ->get();

            if ($curricula->isNotEmpty()) {
                $startDate = $curricula->min('registration_deadline');
                $endDate = $curricula->max('result_visible_at');

                DB::table('terms')->where('id', $term->id)->update([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
            }
        }

        Schema::table('curricula', function (Blueprint $table) {
            $table->dropColumn(['registration_deadline', 'result_visible_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * REPAIRED 2026-07-28 (term-calendar hardening). This down() previously dropped
     * `terms.start_date` and `terms.end_date` — columns it does NOT own. They are created by
     * 2026_05_06_082137_create_terms_table.php; the two lines that would have added them in up()
     * above are commented out. So a rollback destroyed another migration's columns, and nothing
     * put them back on re-up: up() cannot recreate what it never created. Any down()/up() cycle
     * reaching this migration left `terms` permanently missing its date columns, which now also
     * breaks the CHECK constraint added in 2026_07_28_120000 and the RESTRICT FK from
     * finance_fee_schedules that depends on terms being intact.
     *
     * It now drops exactly what up() added — `registration_deadline` and `result_visible_at` on
     * terms — and leaves the create-table migration's columns to the create-table migration.
     *
     * STILL LOSSY, IRREDUCIBLY. up() OVERWROTE `terms.start_date`/`end_date` with aggregates of
     * the curricula values (min registration_deadline, max result_visible_at) and recorded the
     * originals nowhere, so no down() can restore them. The curricula columns are restored from
     * the terms values below, which is the same aggregate flowing back — an approximation, not
     * the original per-curriculum data. That asymmetry is inherent to the forward migration and is
     * documented rather than pretended away.
     */
    public function down(): void
    {
        Schema::table('curricula', function (Blueprint $table) {
            $table->timestampTz('registration_deadline')->nullable();
            $table->timestampTz('result_visible_at')->nullable();
        });

        // Reverse data migration
        $terms = DB::table('terms')->get();

        foreach ($terms as $term) {
            DB::table('curricula')
                ->where('term_id', $term->id)
                ->update([
                    'registration_deadline' => $term->start_date,
                    'result_visible_at' => $term->end_date,
                ]);
        }

        Schema::table('terms', function (Blueprint $table) {
            $table->dropColumn(['registration_deadline', 'result_visible_at']);
        });
    }
};
