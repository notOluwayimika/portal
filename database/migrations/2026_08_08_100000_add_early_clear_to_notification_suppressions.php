<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Early-clear as a RECORDED SUPERSEDING EVENT, and the unique-key fix it forces.
 *
 * ⚠️ THE BUG THIS CLOSES IS ALREADY LATENT IN SHIPPED CODE. `record()` uses
 * insertOrIgnore against UNIQUE (address_hash, channel, scope) — which is what makes a
 * replayed provider event free. That assumed rows are only ever CREATED, never
 * retired. The moment a suppression can be cleared, a cleared row keeps occupying the
 * unique slot forever:
 *
 *   1. a number hard-fails      → suppression written
 *   2. a later successful DLR    → cleared
 *   3. the number fails again    → insertOrIgnore COLLIDES, no new row
 *
 * The number is never re-suppressed, and it fails in the expensive direction: we keep
 * paying to send to a dead number, and every individual send succeeds at the API so
 * nothing looks wrong.
 *
 * A NULLABLE `is_live` MARKER JOINS THE UNIQUE KEY — and the direction matters, which
 * I got backwards first. Putting `cleared_at` in the key BROKE replay idempotency: two
 * LIVE rows both carry `cleared_at IS NULL`, and MySQL treats repeated NULLs as
 * DISTINCT, so the key stopped constraining exactly the rows it existed to constrain.
 * Three identical replays produced three rows. The test caught it.
 *
 * So the NULL has to sit on the side that may repeat. `is_live` is 1 for a live
 * suppression — one per (address, channel, scope), replays collide, idempotent — and
 * NULL once cleared, so unlimited cleared rows accumulate as history.
 *
 * ⚠️ THAT IS THE THIRD LOAD-BEARING USE OF "MULTIPLE NULLS ARE DISTINCT" in this
 * module — `notifications.dedup_key` and `notification_deliveries.notification_recipient_id`
 * are the others. It is a real dependency on MySQL semantics, not an incidental one:
 * on PostgreSQL all three would need NULLS NOT DISTINCT or partial indexes. Recorded
 * here so it is not rediscovered under pressure.
 *
 * STAMP, NEVER DELETE OR OVERWRITE. Rewriting `expires_at` to now() would destroy the
 * decision the row exists to record — "meant to last until T, overturned at Y by
 * positive evidence" is two facts. And a clear-as-delete is invisible BY
 * CONSTRUCTION: if the reason filter ever regresses and an opt-out gets cleared by a
 * delivery receipt, a deleted row leaves no evidence it ever happened. A stamped row
 * leaves `cleared_at` + `cleared_by` sitting there — which is exactly what a consent
 * audit needs to catch the regression and reverse it. The mechanism that can unmute
 * someone should be able to prove whether it ever unmuted the wrong person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_suppressions', function (Blueprint $table) {
            $table->timestampTz('cleared_at')->nullable()->after('expires_at');
            // 1 while live, NULL once cleared. The nullable side is the one that may
            // repeat — see the docblock; the inverse broke replay idempotency.
            // No ->after(): within one closure Laravel may emit separate ALTERs, and
            // positioning against a column added in the same batch is not yet valid.
            $table->unsignedTinyInteger('is_live')->nullable()->default(1);
            // WHAT overturned it — 'delivery_success', 'explicit_optin', 'manual'.
            // A wrongly-cleared opt-out is identifiable by this column alone.
            $table->string('cleared_by', 32)->nullable()->after('cleared_at');
        });

        Schema::table('notification_suppressions', function (Blueprint $table) {
            $table->dropUnique('notif_suppressions_unique');
            $table->unique(
                ['address_hash', 'channel', 'scope', 'is_live'],
                'notif_suppressions_unique',
            );
        });

        Schema::table('notification_suppressions', function (Blueprint $table) {
            // The send-time gate now reads lapsed-OR-cleared, so cleared_at belongs in
            // the lookup index or every check falls back to a scan.
            $table->index(
                ['address_hash', 'channel', 'is_live', 'expires_at'],
                'notif_suppressions_live',
            );
        });
    }

    public function down(): void
    {
        Schema::table('notification_suppressions', function (Blueprint $table) {
            $table->dropIndex('notif_suppressions_live');
            $table->dropUnique('notif_suppressions_unique');
        });

        Schema::table('notification_suppressions', function (Blueprint $table) {
            // Restoring the narrower key would FAIL against any address holding both a
            // cleared and a live row — which is exactly the state this release makes
            // legal. Refuse rather than destroy history to satisfy a constraint.
            $collisions = DB::table('notification_suppressions')
                ->selectRaw('address_hash, channel, scope, COUNT(*) as n')
                ->groupBy('address_hash', 'channel', 'scope')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($collisions > 0) {
                throw new RuntimeException(
                    "Cannot restore the narrow unique key: {$collisions} address/channel/scope group(s) hold "
                    .'both cleared and live suppressions. Reversing needs a decision about which history to '
                    .'discard — a data question, not a schema one.'
                );
            }

            $table->unique(['address_hash', 'channel', 'scope'], 'notif_suppressions_unique');
            $table->dropColumn(['cleared_at', 'cleared_by', 'is_live']);
        });
    }
};
