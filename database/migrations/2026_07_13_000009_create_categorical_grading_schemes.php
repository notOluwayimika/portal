<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_schemes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('family_uuid')->index();
            $table->string('name');
            $table->string('mode')->default('categorical');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active');
            $table->timestampsTz();
            $table->unique(['school_id', 'family_uuid', 'version']);
        });

        Schema::create('grading_scheme_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('grading_scheme_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('label');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestampsTz();
            $table->unique(['grading_scheme_id', 'code']);
        });

        Schema::table('class_levels', function (Blueprint $table) {
            $table->foreignId('grading_scheme_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::table('curricula', function (Blueprint $table) {
            $table->foreignId('grading_scheme_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::table('student_results', function (Blueprint $table) {
            $table->string('grade', 20)->nullable()->change();
            $table->foreignId('grading_scheme_item_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grading_scheme_item_id');
            $table->string('grade', 2)->nullable()->change();
        });
        Schema::table('curricula', fn (Blueprint $table) => $table->dropConstrainedForeignId('grading_scheme_id'));
        Schema::table('class_levels', fn (Blueprint $table) => $table->dropConstrainedForeignId('grading_scheme_id'));
        Schema::dropIfExists('grading_scheme_items');
        Schema::dropIfExists('grading_schemes');
    }
};
