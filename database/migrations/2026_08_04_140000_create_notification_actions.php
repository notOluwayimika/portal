<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tappable action on a notification — and the idempotency anchor for it.
 *
 * THE ROW IS THE LOCK. Exactly-once is not achieved by a mutex, a queue, or an
 * application check; it is one conditional UPDATE against this table:
 *
 *     UPDATE notification_actions
 *        SET status = 'resolving', resolved_by = ?
 *      WHERE id = ? AND status = 'pending' AND expires_at > ?
 *
 * The affected-row count IS the answer to "did I win". That is why the row is
 * UPDATED IN PLACE AND NEVER DELETED: a deleted row cannot lose a race, it can only
 * be recreated by the loser, and an action whose history is gone cannot answer "who
 * revoked this, and when" — which for a pickup revocation is the whole point of
 * having a record.
 *
 * `school_id` IS CARRIED DIRECTLY, and this table is the reason it matters most in
 * the system: THERE IS NO DATABASE BACKSTOP. Isolation here is application-level
 * (BelongsToSchool + ActiveSchool), with no row-level security underneath, so a
 * missing scope at the HTTP boundary is a cross-tenant hole with nothing beneath it
 * to catch it. Denormalised onto this row rather than reached through the
 * notification, so the authorization check is a direct predicate and not a join a
 * future refactor can drop.
 *
 * THE CALLBACK TARGET IS STORED ON THE ROW, not derived at tap time. The action was
 * created against a specific external request; deriving the URL later would let a
 * configuration change re-point an in-flight action at a different service, and the
 * signature would still verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Route-model-bound by uuid, so this is the lookup key on the hot path.
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();

            $table->string('label');
            $table->string('status', 16);
            $table->string('outcome', 16)->nullable();

            // The window on OUR side. Distinct from the external service's own
            // deadline, which is what produces TOO_LATE — a tap can be inside our
            // window and still be refused by theirs.
            $table->timestampTz('expires_at');

            // Who won the claim. Written by the claim itself, so it is set at the
            // moment of winning rather than after the callback returns — otherwise a
            // process that died mid-relay would leave a claimed row with no claimant.
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            $table->string('callback_url');
            $table->json('callback_payload');
            $table->text('last_error')->nullable();

            $table->timestampsTz();

            // The claim's WHERE clause, in index order.
            $table->index(['status', 'expires_at'], 'notification_actions_claimable');
            // Every read at the trust boundary is scoped by school first.
            $table->index(['school_id', 'notification_id'], 'notification_actions_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_actions');
    }
};
