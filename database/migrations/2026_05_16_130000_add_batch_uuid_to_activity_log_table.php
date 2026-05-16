<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Spatie's batch-logging column. Absent from the original
            // create migration; nullable + additive so it never breaks
            // existing inserts. Populated by Spatie when LogBatch is used.
            $table->uuid('batch_uuid')->nullable()->after('properties');
            $table->index(['school_id', 'batch_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'batch_uuid']);
            $table->dropColumn('batch_uuid');
        });
    }
};
