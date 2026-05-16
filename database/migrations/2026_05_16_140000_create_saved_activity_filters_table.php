<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_activity_filters', function (Blueprint $table) {
            $table->id();
            // user_id / school_id are bigint unsigned in this DB (not uuid).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_activity_filters');
    }
};
