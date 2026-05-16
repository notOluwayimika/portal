<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Nullable so backfilling doesn't break inserts, system-level events
            // (no school context) are supported, and existing logging code keeps
            // working on day one. Populated automatically by App\Models\Activity.
            // schools.id is bigint unsigned in this database (matches
            // users.school_id and the rest of the school_id FKs), so use
            // foreignId, not foreignUuid.
            $table->foreignId('school_id')->nullable()->after('properties')
                ->constrained('schools')->nullOnDelete();

            $table->index(['school_id', 'created_at']);
            $table->index(['school_id', 'causer_id']);
            $table->index(['school_id', 'subject_type', 'subject_id']);
            $table->index(['school_id', 'log_name', 'event']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropIndex(['school_id', 'created_at']);
            $table->dropIndex(['school_id', 'causer_id']);
            $table->dropIndex(['school_id', 'subject_type', 'subject_id']);
            $table->dropIndex(['school_id', 'log_name', 'event']);
            $table->dropColumn('school_id');
        });
    }
};
