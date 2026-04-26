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
        Schema::create('class_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // JS1, SS3, etc.
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('arms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // A, B, Gold, etc.
            $table->timestampsTz();
        });

        Schema::create('class_level_arms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_level_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('arm_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['class_level_id', 'arm_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_level_arms');
        Schema::dropIfExists('arms');
        Schema::dropIfExists('class_levels');
    }
};
