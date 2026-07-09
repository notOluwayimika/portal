<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            if (!Schema::hasColumn('student_curricula', 'form_teacher_comment')) {
                $table->text('form_teacher_comment')->nullable();
            }
            if (!Schema::hasColumn('student_curricula', 'head_of_school_comment')) {
                $table->text('head_of_school_comment')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            if (Schema::hasColumn('student_curricula', 'head_of_school_comment')) {
                $table->dropColumn('head_of_school_comment');
            }
            if (Schema::hasColumn('student_curricula', 'form_teacher_comment')) {
                $table->dropColumn('form_teacher_comment');
            }
        });
    }
};
