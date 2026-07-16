<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Term is the billing period, so it must be directly School-scoped rather
 * than only transitively via academic_sessions. Backfill from the parent
 * session (terms.academic_session_id is NOT NULL, so every row is covered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('academic_session_id');
        });

        DB::statement(
            'UPDATE terms t JOIN academic_sessions s ON s.id = t.academic_session_id SET t.school_id = s.school_id'
        );

        Schema::table('terms', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable(false)->change();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropIndex(['school_id', 'status']);
            $table->dropColumn('school_id');
        });
    }
};
