<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('gender')->nullable();

            // Contact Information
            $table->string('phone');
            $table->string('whatsapp_number')->nullable();

            // Address Information
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();


            $table->string('occupation')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('emergency_contact')->nullable();

            // Identification
            $table->foreignId('photo_id')->nullable()->constrained('file_uploads')->nullOnDelete();
            $table->enum('id_type', ['national_id', 'passport', 'drivers_license'])->nullable();
            $table->string('id_number')->nullable();
            $table->date('id_expiry_date')->nullable();

            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
