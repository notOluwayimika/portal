<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12.11: each School has a timezone + working hours. "Daily Collections" (§12)
 * and the "transactions outside normal working hours" exception (§15F) are
 * undefined without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('timezone')->default('Africa/Lagos')->after('slug');
            $table->time('working_hours_start')->nullable()->after('timezone');
            $table->time('working_hours_end')->nullable()->after('working_hours_start');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'working_hours_start', 'working_hours_end']);
        });
    }
};
