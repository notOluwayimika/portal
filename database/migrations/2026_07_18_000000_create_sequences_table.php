<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared Kernel sequence counters (1.4b). One row per (scope, key) holds a
 * monotonic counter, incremented atomically under a row lock (SELECT … FOR
 * UPDATE) by App\Support\Sequences\Sequences. Gap-TOLERANT: a rolled-back
 * consumer leaves a gap; that is acceptable for admission/staff numbers. NOT a
 * gap-free ledger — Finance receipt/invoice numbering (§12.5) needs a signed
 * policy and its own design, and must not reuse this table on that assumption.
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
