<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('website')->nullable()->after('email');
            // Official name printed on result cards (may differ from display name)
            $table->string('name_on_result')->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['website', 'name_on_result']);
        });
    }
};
