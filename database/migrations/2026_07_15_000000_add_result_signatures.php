<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('signature_id')->nullable()->after('school_id')->constrained('file_uploads')->nullOnDelete();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('fallback_signature_id')->nullable()->after('name_on_result')->constrained('file_uploads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fallback_signature_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signature_id');
        });
    }
};
