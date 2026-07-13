<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // users.school_id must be nullable: super admins (and multi-school
        // admins) are not bound to a single school.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY school_id BIGINT UNSIGNED NULL');
        }

        // Selected school for token-based (non-session) API clients.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('school_id')->nullable()->index()->after('tokenable_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('school_id');
        });
    }
};
