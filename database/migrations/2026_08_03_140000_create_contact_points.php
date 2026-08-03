<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a person can actually be reached — one row per (person, channel, address).
 *
 * WHY THIS EXISTS. Contact details are scattered and second-class today:
 * `users.email` is the only address on the user record, phones live on
 * `guardians.phone` / `guardians.whatsapp_number` and `teachers.phone`, and
 * students have neither. So "what is this person's SMS address" has no answer
 * without knowing which profile model they happen to have — and a user who is both
 * a teacher and a parent has two. This makes the address a first-class,
 * normalizable, verifiable, suppressible thing.
 *
 * NOT SCHOOL-SCOPED, and this is the one decision worth arguing for. Every
 * School-owned model in this codebase carries `school_id` and BelongsToSchool; this
 * one does not, because an address belongs to a HUMAN, not to a tenancy. The same
 * person is a parent at one school and staff at another, with one phone. Scoping
 * would fragment that into two rows that must be kept in step, and would make a
 * hard bounce suppress at one school while still sending at the other. Preferences
 * ARE per-school (`notification_preferences.school_id`) — what you want from a
 * school is a per-school question; how to reach you is not. `users.email` is
 * already un-scoped for exactly this reason; this is consistent with it, not an
 * exception to the rule.
 *
 * THE UNIQUE KEY IS (person, channel, normalized_address) — NOT address alone.
 * Shared family addresses are the norm here, so two co-guardians on one email are
 * two rows, which is what makes per-person soft suppression (an unsubscribe by one
 * parent) possible without muting the other. Keying on the address alone would
 * collide on the backfill's first run — including for a teacher who is also a
 * parent, sharing one phone across two profile records but one user.
 *
 * ADDRESS *AND* NORMALIZED_ADDRESS *AND* HASH, all three:
 *  - `address` is what the person typed, kept for display and for support ("we have
 *    you as 0803…").
 *  - `normalized_address` is what we send to and suppress on — the only form two
 *    systems can agree about (see App\Support\AddressNormalizer).
 *  - `address_hash` is the join key for ADDRESS-scoped suppressions (a hard bounce
 *    is a fact about the mailbox, not about a person). ⚠️ It buys uniqueness and
 *    fixed width, NOT privacy: a suppression lookup must be deterministic from the
 *    address, so it cannot be salted, and anyone with this table and the normalizer
 *    reverses common addresses trivially. That is precisely why the normalized form
 *    is stored beside it — a hash-only table cannot answer "why did this not send?"
 *    without already knowing the address.
 *
 * `source` IS PROVENANCE FOR A CREATE-BACKFILL. Reversing a migration that CREATES
 * rows means deleting only the rows it created. Without a marker, a down() that
 * deletes by shape also eats contact points a user added in the window between the
 * backfill and the rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_points', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('address', 255);
            $table->string('normalized_address', 255);
            $table->char('address_hash', 64);
            $table->boolean('is_primary')->default(false);
            // Consent/verification lives here from v3: an SMS address that has never
            // been confirmed is a different thing from one the owner proved they hold.
            $table->timestampTz('verified_at')->nullable();
            $table->string('source', 64);
            $table->timestampsTz();

            $table->unique(['user_id', 'channel', 'normalized_address'], 'contact_points_person_channel_address');
            // Send-time resolution: "this person's addresses for this channel".
            $table->index(['user_id', 'channel', 'is_primary'], 'contact_points_resolution');
            // Address-scoped suppression: "every contact point on this mailbox",
            // which is how ONE hard bounce mutes both co-guardians who share it.
            $table->index(['address_hash', 'channel'], 'contact_points_address_hash');
            // Reversal, and the backfill dry-run's own accounting.
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_points');
    }
};
