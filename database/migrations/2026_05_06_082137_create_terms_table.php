<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('order');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'upcoming', 'completed'])->default('upcoming');
            $table->timestamps();

            $table->unique(['academic_session_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
