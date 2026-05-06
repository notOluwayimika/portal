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
            $table->timestampTz('start_date')->nullable()->after('status');
            $table->timestampTz('end_date')->nullable()->after('start_date');
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
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
