<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            if (!Schema::hasColumn('student_curricula', 'ended_at')) {
                $table->timestamp('ended_at')->nullable()->after('created_at');
            }
            if (!Schema::hasColumn('student_curricula', 'ended_by_user_id')) {
                $table->foreignId('ended_by_user_id')->nullable()->after('ended_at')
                      ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('student_curricula', 'end_reason')) {
                $table->text('end_reason')->nullable()->after('ended_by_user_id');
            }

            $table->index(['student_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'ended_at']);

            if (Schema::hasColumn('student_curricula', 'end_reason')) {
                $table->dropColumn('end_reason');
            }
            if (Schema::hasColumn('student_curricula', 'ended_by_user_id')) {
                $table->dropConstrainedForeignId('ended_by_user_id');
            }
            if (Schema::hasColumn('student_curricula', 'ended_at')) {
                $table->dropColumn('ended_at');
            }
        });
    }
};
