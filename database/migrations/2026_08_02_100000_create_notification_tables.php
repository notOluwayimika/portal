<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification system v1 — the four core tables plus an isolated queue table.
 *
 * THE CENTRAL SPLIT, and the reason there are three tables rather than one:
 *
 *   notifications             one row per EVENT           (one subject, many recipients)
 *   notification_recipients   one row per (event, person) — READ STATE lives here, once
 *   notification_deliveries   one row per (recipient, channel) — DELIVERY STATE, once
 *
 * Read-state and delivery-state never overlap. An email cannot be "unread", and marking
 * a feed item read must not touch a delivery row. Collapsing these into one table is the
 * design error that makes multi-channel delivery state impossible to represent without
 * duplicating read state per channel.
 *
 * `dedup_key` IS EVENT IDENTITY, NEVER RECIPIENT IDENTITY. It exists so the same event
 * emitted twice (a retry, a double-click, a replayed listener) collapses to one row. A
 * key containing a recipient id is a different axis entirely and silently DESTROYS data:
 * a guardian with three children would collide on the second and third insert, leaving
 * child #1's notification standing and children #2 and #3 with no row and no delivery —
 * not even a skip record. Recipient-level collapse is BUNDLING (v2), which groups the
 * outbound message while keeping one feed row per child. NotificationDedupKeyTest
 * enforces this by construction rather than by memory.
 *
 * MySQL treats repeated NULLs in a UNIQUE index as distinct, which both unique indexes
 * below rely on: a NULL `dedup_key` never collides, and a delivery belongs either to a
 * recipient or (from v2) to a bundle, never both.
 *
 * SKIPS ARE ROWS, NOT ABSENCES. A notification refused by preference, suppression or a
 * missing address is written with `status = skipped` and a `skip_reason`. "Why did this
 * parent not get it?" is the most common support question this system will face, and it
 * must be answerable from one row rather than reconstructed from logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // school_id is the ONLY isolation boundary (Constitution 1). Every table
            // here carries it directly rather than reaching it through a join, so no
            // feed or count query can accidentally cross tenants via a missing join.
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('type', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // IDs and scalars only — never rendered names. PII in a JSON blob is PII in
            // every backup and every log line. The feed renders from these at read time.
            $table->json('payload');
            // The exception to the rule above: immutable facts (an amount, a date) that
            // would be unrecoverable if the subject is later deleted. Lets a feed row
            // degrade to readable history instead of a broken render.
            $table->string('rendered_fallback')->nullable();
            $table->string('dedup_key', 191)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['school_id', 'type', 'dedup_key']);
            $table->index(['school_id', 'type', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            // Denormalised from the parent so the feed query filters tenancy without a
            // join. This index is what makes the unread count a covered read.
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            // WHY this person is a recipient — direct|role|watcher|relationship. Makes
            // "why did I get this?" answerable, which matters most for role-derived
            // recipients where the answer is not obvious to the recipient.
            $table->string('reason', 32);
            // seen_at clears the bell badge; read_at marks the item. They are different
            // events and conflating them makes the badge either sticky or a liar.
            $table->timestampTz('seen_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            // Fan-out idempotency: re-running the job cannot double-insert a recipient.
            $table->unique(['notification_id', 'notifiable_type', 'notifiable_id'], 'notif_recipients_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'school_id', 'read_at', 'id'], 'notif_recipients_feed');
            $table->index(['school_id', 'created_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            // The uuid IS the provider idempotency key — a retry that reaches the vendor
            // twice is deduplicated vendor-side by the same value.
            $table->uuid('uuid')->unique();
            // Nullable from v1 so v2's bundle deliveries (one email covering several
            // recipients) attach here without a table rewrite.
            $table->foreignId('notification_recipient_id')->nullable()
                ->constrained('notification_recipients')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->string('skip_reason', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            // One delivery per recipient per channel. With the status guard in the
            // worker, this is the whole of the no-double-send guarantee.
            $table->unique(['notification_recipient_id', 'channel'], 'notif_deliveries_unique');
            $table->index(['status', 'channel', 'queued_at'], 'notif_deliveries_backlog');
            $table->index(['provider', 'provider_message_id'], 'notif_deliveries_provider');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // PER SCHOOL. `school_user` is a pivot, so one human can be staff at one
            // school and a parent at another; a preference keyed by user alone would
            // leak a decision made in one tenant into the other.
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            // '*' is a legal value: the whole-channel switch ("no SMS ever"), which a
            // per-type row then overrides.
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestampsTz();

            // SPARSE by design: a row exists only where the user has overridden the
            // type's default. No row means "use the default", so adding a notification
            // type never requires backfilling a row per user.
            $table->unique(['user_id', 'school_id', 'type', 'channel'], 'notif_preferences_unique');
        });

        // Queue isolation WITHOUT a second database (none is available on the current
        // host): a second `database` queue connection pointed at its own table. A
        // fan-out burst then cannot starve imports and exports queued on `jobs`, which
        // is the contention this buys — the whole benefit of a Redis queue that is
        // reachable here, without Redis.
        Schema::create('notification_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        // Reverse creation order: deliveries → recipients → notifications, so no FK is
        // dropped while a child still references it.
        Schema::dropIfExists('notification_jobs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notifications');
    }
};
