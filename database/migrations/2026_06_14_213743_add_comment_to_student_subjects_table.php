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
            $table->dropColumn(['comment', 'commented_by']);
        });
    }
};
