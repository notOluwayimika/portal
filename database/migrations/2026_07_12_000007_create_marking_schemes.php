<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marking_schemes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_ccm');
            $table->unsignedInteger('version');
            $table->string('status')->default('active');
            $table->timestampsTz();

            $table->unique(['school_id', 'is_ccm', 'version']);
            $table->index(['school_id', 'is_ccm', 'status']);
        });

        Schema::table('marking_components', function (Blueprint $table) {
            $table->foreignId('marking_scheme_id')
                ->nullable()
                ->after('curriculum_subject_id')
                ->constrained('marking_schemes')
                ->restrictOnDelete();
        });

        Schema::table('curricula', function (Blueprint $table) {
            $table->foreignId('marking_scheme_id')
                ->nullable()
                ->after('school_id')
                ->constrained('marking_schemes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('curricula', fn (Blueprint $table) => $table->dropConstrainedForeignId('marking_scheme_id'));
        Schema::table('marking_components', fn (Blueprint $table) => $table->dropConstrainedForeignId('marking_scheme_id'));
        Schema::dropIfExists('marking_schemes');
    }
};
