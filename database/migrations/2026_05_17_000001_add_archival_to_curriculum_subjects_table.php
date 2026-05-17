<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('is_compulsory');
            $table->timestamp('archived_at')->nullable()->after('active');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')
                  ->constrained('users')->nullOnDelete();

            $table->index(['curriculum_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropIndex(['curriculum_id', 'active']);
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropColumn(['active', 'archived_at']);
        });
    }
};
