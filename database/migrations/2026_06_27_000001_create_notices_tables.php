<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 20)->default('gray');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['school_id', 'slug']);
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('notice_category_id')->constrained('notice_categories')->cascadeOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->string('target_gender')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();

            $table->index(['school_id', 'starts_at', 'ends_at']);
        });

        Schema::create('notice_class_level', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('class_level_id')->constrained('class_levels')->cascadeOnDelete();

            $table->unique(['notice_id', 'class_level_id']);
        });

        Schema::create('notice_class_level_arm', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('class_level_arm_id')->constrained('class_level_arms')->cascadeOnDelete();

            $table->unique(['notice_id', 'class_level_arm_id']);
        });

        Schema::create('notice_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->unique(['notice_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_student');
        Schema::dropIfExists('notice_class_level_arm');
        Schema::dropIfExists('notice_class_level');
        Schema::dropIfExists('notices');
        Schema::dropIfExists('notice_categories');
    }
};
