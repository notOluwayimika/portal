<?php
// database/migrations/2024_01_01_000016_create_audit_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('action'); // CREATE_SCORE, SUBMIT_RESULT, APPROVE_RESULT, etc.
            $table->string('entity_type'); // App\Models\Score, App\Models\StudentResult, etc.
            $table->uuid('entity_id');
            $table->jsonb('payload')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            // No updated_at — append-only

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
