<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses we must stop contacting, and why.
 *
 * NO `school_id`, AND THAT IS DELIBERATE — the same call `contact_points` made. A
 * bounce is a fact about an ADDRESS, not about a tenancy: the mailbox does not exist,
 * and it does not exist for every school that holds it. Scoping suppression per
 * school would keep retrying a dead address once per tenant and burn sending
 * reputation to preserve a boundary the fact does not respect.
 *
 * TWO SCOPES, FIXED PER REASON rather than chosen per row:
 *   address        — a HARD fact about the mailbox (bounce, complaint). One record
 *                    mutes every contact point on that address, which is how co-guardians
 *                    sharing a family inbox are both correctly stopped.
 *   contact_point  — a statement by a PERSON (unsubscribe). Address-scoping this would
 *                    let one parent silently mute fee and safeguarding mail to the
 *                    co-guardian on the same shared address.
 *
 * PERMANENCE IS A PROPERTY OF THE REASON, not of the table. A full mailbox is
 * TRANSIENT and must never earn a permanent address-scoped row — muting a live parent
 * forever over one full inbox is a worse failure than retrying. Hence `expires_at`:
 * null for hard_bounce and complaint, short-lived for soft_bounce. Phone numbers
 * recycle with no email analogue, so sms_stop will want a bounded life too (v3).
 *
 * `address_hash` BUYS UNIQUENESS AND FIXED WIDTH, NOT PRIVACY. It cannot be salted —
 * lookup must be deterministic from the address alone — so anyone holding this table
 * and AddressNormalizer recovers common addresses trivially. `normalized_address` is
 * stored ALONGSIDE it precisely because a hash-only table cannot answer "why did this
 * not send?" without already knowing the answer, and that is the single most common
 * question this machinery will be asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_suppressions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('channel', 16);
            $table->string('scope', 16);
            $table->string('reason', 32);
            $table->string('normalized_address', 255);
            $table->char('address_hash', 64);
            // Present only for contact_point scope — a person's own opt-out.
            $table->foreignId('contact_point_id')->nullable()
                ->constrained('contact_points')->cascadeOnDelete();
            // PROVENANCE. "Who muted this parent, and off the back of what?" — the
            // delivery whose bounce created this row, and the source that reported it.
            $table->string('source', 64);
            $table->foreignId('notification_delivery_id')->nullable()
                ->constrained('notification_deliveries')->nullOnDelete();
            // Null = permanent. Set = transient, and the check must respect it.
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            // One live suppression per (address, channel, scope). insertOrIgnore on
            // this is what makes a replayed provider event free rather than duplicating.
            $table->unique(['address_hash', 'channel', 'scope'], 'notif_suppressions_unique');
            // The send-time check: "is this address suppressed for this channel".
            $table->index(['address_hash', 'channel', 'expires_at'], 'notif_suppressions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_suppressions');
    }
};
