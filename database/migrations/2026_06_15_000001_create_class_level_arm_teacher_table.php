<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_level_arm_teacher', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('class_level_arm_id')->constrained('class_level_arms')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->enum('role', ['boarding_parent', 'form_teacher', 'head_of_school']);
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['class_level_arm_id', 'role', 'gender'], 'cla_teacher_role_gender_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_level_arm_teacher');
    }
};
