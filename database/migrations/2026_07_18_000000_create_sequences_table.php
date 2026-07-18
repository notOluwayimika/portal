<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared Kernel sequence counters (1.4b). One row per (scope, key) holds a
 * monotonic counter, incremented atomically under a row lock (SELECT … FOR
 * UPDATE) by App\Support\Sequences\Sequences. Generic infrastructure — no domain
 * meaning. See the service class for the concurrency and transactional guarantees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope');   // namespace, e.g. 'student.admission_number'
            $table->string('key');     // sub-key, e.g. '5|GFA/2025/' (per School + prefix)
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
