<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `finance_gateway_transaction_events` stores the provider's delivery so a payment can be
 * reconciled against what the provider actually said. Paystack's `charge.success` body carries
 * `data.authorization.authorization_code` alongside `"reusable": true` — a token that can INITIATE
 * A FUTURE CHARGE against the payer's card. Storing it is storing a live payment credential in an
 * append-only table that no one may DELETE from.
 *
 * The fix is to strip it BEFORE the insert rather than to redact it later. The distinction matters
 * and is the whole reason this column exists:
 *
 *   · `redacted_at` is RETENTION redaction. It is all-or-nothing — the events biconditional makes
 *     `redacted_at IS NOT NULL` mean exactly `payload IS NULL` — and it happens once, later, to a
 *     row that has already been stored whole.
 *   · `redacted_fields` is WRITE-TIME stripping. The row is stored, useful and reconcilable; some
 *     named fields never entered the database at all.
 *
 * They are not the same operation and must not share a signal. A stripped row is NOT redacted:
 * `redacted_at` stays NULL, the payload stays non-NULL, and the biconditional is untouched. Setting
 * `redacted_at` for a write-time strip would have claimed the payload was gone while it was still
 * there — which is the exact no-op the events guard was written to refuse.
 *
 * WHY A COLUMN AND NOT SILENCE. Stripping without recording it makes the absence indistinguishable
 * from "the provider did not send this field". A reconciliation reading a payload with no
 * authorization block cannot tell a card charge from a bank transfer, and would be reading our
 * redaction as a fact about the payment. The column states the removal, so the absence is a
 * RECORDED ACT rather than a silence to be interpreted.
 *
 * NULLABLE, and NULL means "nothing was stripped" — not "unknown". Every writer sets it explicitly;
 * see GatewayEventRedactor.
 *
 * ONE THING THIS DOES NOT DO, recorded so it is not mistaken for done: the `payload` column is now
 * a name for "the delivery, minus what we removed", and it is still called `payload`. Renaming it
 * would mean rebuilding the three events triggers, whose bodies name the column, on a table that
 * has not reached production. That is a deliberate deferral, not an oversight —
 * docs/handoff/tickets/gateway-event-payload-is-not-the-whole-payload.md carries it.
 */
return new class extends Migration
{
    private const EVENTS_TABLE = 'finance_gateway_transaction_events';

    public function up(): void
    {
        Schema::table(self::EVENTS_TABLE, function (Blueprint $table) {
            $table->json('redacted_fields')->nullable()->after('payload');
        });

        $this->assertShape();
    }

    public function down(): void
    {
        Schema::table(self::EVENTS_TABLE, function (Blueprint $table) {
            $table->dropColumn('redacted_fields');
        });
    }

    /**
     * Shape-verified from `information_schema`, not from the fact that ALTER TABLE returned
     * success — the discipline 2026_08_25_100000 established and 2026_08_27_100000 carried.
     * A `CHECK` here would be parsed-and-ignored on production's MySQL 5.7.23 and invisible to
     * `information_schema`, which is why this asserts a COLUMN and not a constraint.
     */
    private function assertShape(): void
    {
        $column = DB::selectOne(
            'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::EVENTS_TABLE, 'redacted_fields'],
        );

        if ($column === null) {
            throw new RuntimeException(
                self::EVENTS_TABLE.'.redacted_fields is absent after ALTER TABLE returned success. '
                .'Without it a stripped field is indistinguishable from a field the provider never sent.'
            );
        }

        if ($column->nullable !== 'YES') {
            throw new RuntimeException(
                self::EVENTS_TABLE.'.redacted_fields must be nullable: NULL is how a row says nothing was stripped.'
            );
        }
    }
};
