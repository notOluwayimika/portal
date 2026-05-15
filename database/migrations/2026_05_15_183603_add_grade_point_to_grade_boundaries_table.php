<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('grade_boundaries', function (Blueprint $table) {
            $table->string('grade_point', 10)->after('grade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grade_boundaries', function (Blueprint $table) {
            $table->dropColumn('grade_point');
        });
    }
};
