<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('photo');
            $table->foreignId('photo_id')->nullable()->after('status')
                  ->constrained('file_uploads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropForeign(['photo_id']);
            $table->dropColumn('photo_id');
            $table->text('photo')->nullable();
        });
    }
};
