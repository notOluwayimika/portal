<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Added by the Phase-1 four-path migration audit.
     *
     * This migration had NO down() at all, so rolling it back was a silent no-op:
     * the table survived, `migrate` then failed with 1050 "Table 'activity_log'
     * already exists", and the whole re-upgrade path was blocked. A missing down()
     * is invisible to migrate:fresh, which never rolls back.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
