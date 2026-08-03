<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completion markers for data backfills — the interlock a gated cutover hangs off.
 *
 * WHY A TABLE AND NOT A CONFIG FLAG. The flag has to be set by the process that
 * finishes the work, read by request-path code, and survive a deploy. A config
 * value is set by a human at a different time from the run, which is precisely the
 * co-timing assumption the gate exists to remove: between code-live and
 * backfill-complete, every reader flipped to "has no contact point" mis-answers for
 * the whole populated database — bulk messaging no-ops school-wide, password reset
 * refuses everyone.
 *
 * ⚠️ `completed_at` IS THE INTERLOCK, AND ITS PLACEMENT IS THE WHOLE POINT. It is
 * written ONLY after the final chunk commits. A run that dies at 80% with the marker
 * already set flips the gated predicate while the last 20% still have no contact
 * points — a partial silent-drop, which is the exact failure the gate was built to
 * prevent, reintroduced by marker placement rather than by any logic error. So
 * `started_at` and `completed_at` are separate columns: a row with a start and no
 * completion is an interrupted run, and the predicate must read it as NOT done.
 *
 * `stats` keeps the run's own accounting — created, skipped, and why — so a resumed
 * or re-run backfill can be compared against the previous attempt rather than
 * trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_backfills', function (Blueprint $table) {
            $table->id();
            // One row per backfill, by name. Re-running updates the row rather than
            // appending, so "is this done?" is a single lookup with no ordering.
            $table->string('key', 64)->unique();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_backfills');
    }
};
