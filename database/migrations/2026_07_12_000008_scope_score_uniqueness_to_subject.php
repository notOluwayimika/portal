<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'marking_component_id']);
            $table->unique(
                ['student_id', 'curriculum_subject_id', 'marking_component_id'],
                'scores_student_subject_component_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropUnique('scores_student_subject_component_unique');
            $table->unique(['student_id', 'marking_component_id']);
        });
    }
};
