<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_subjects', function (Blueprint $table) {
            $table->enum('status', ['active', 'dropped'])->default('active')->after('curriculum_subject_id');

            $table->foreignId('dropped_by_user_id')->nullable()->after('status')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('dropped_at')->nullable()->after('dropped_by_user_id');
            $table->text('drop_reason')->nullable()->after('dropped_at');

            $table->foreignId('restored_by_user_id')->nullable()->after('drop_reason')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable()->after('restored_by_user_id');

            $table->index(['student_curriculum_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('student_subjects', function (Blueprint $table) {
            $table->dropIndex(['student_curriculum_id', 'status']);
            $table->dropConstrainedForeignId('restored_by_user_id');
            $table->dropConstrainedForeignId('dropped_by_user_id');
            $table->dropColumn(['status', 'dropped_at', 'drop_reason', 'restored_at']);
        });
    }
};
