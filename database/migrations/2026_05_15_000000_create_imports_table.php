<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type');
            $table->string('file_name');
            $table->string('file_path');

            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);

            $table->string('report_path')->nullable();
            $table->text('error')->nullable();

            $table->boolean('update_existing_links')->default(false);

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['school_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
