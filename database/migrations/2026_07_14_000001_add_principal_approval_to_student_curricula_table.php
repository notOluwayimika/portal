<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            $table->boolean('principal_approval')->default(false)->after('status');
        });

        // Preserve access to results which existed before principal approval.
        DB::table('student_curricula')->update(['principal_approval' => true]);
    }

    public function down(): void
    {
        Schema::table('student_curricula', function (Blueprint $table) {
            $table->dropColumn('principal_approval');
        });
    }
};
