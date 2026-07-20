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
        Schema::table('student_subjects', function (Blueprint $table) {
            $table->string('comment', 50)->nullable();
            $table->foreignId('commented_by')->nullable()->constrained('teachers')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_subjects', function (Blueprint $table) {
            // The constraint MUST be dropped before the column it references.
            // `constrained()` in up() creates student_subjects_commented_by_foreign;
            // dropping the column first fails with MySQL 1828 ("Cannot drop column
            // 'commented_by': needed in a foreign key constraint"), so this down()
            // could not run at all. Found by the Phase-1 four-path migration audit —
            // migrate:fresh never calls down(), so nothing had ever executed it.
            $table->dropForeign(['commented_by']);
            $table->dropColumn(['comment', 'commented_by']);
        });
    }
};
