<?php

use App\Enums\StudentMembershipStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School membership status (§12.6): billing eligibility keys off this, so a
 * departed Student is excluded from billing without being soft-deleted (which
 * would destroy the reference their financial history depends on, §15C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->enum('status', StudentMembershipStatus::values())
                ->default(StudentMembershipStatus::ACTIVE->value)
                ->after('admission_number');
            $table->timestampTz('left_at')->nullable()->after('status');
            $table->string('leave_reason')->nullable()->after('left_at');

            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'status']);
            $table->dropColumn(['status', 'left_at', 'leave_reason']);
        });
    }
};
