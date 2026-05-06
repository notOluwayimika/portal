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
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->unique();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "1st term 2025/2026"
            $table->string('slug');
            $table->boolean('is_current')->default(false);
            $table->timestampsTz();

            $table->unique(['slug', 'school_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_sessions');
    }
};
