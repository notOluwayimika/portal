<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the events update guard to freeze `redacted_fields`, the column `2026_08_31_100000` added.
 *
 * ── WHY THIS IS A SEPARATE MIGRATION AND NOT AN EDIT ──
 *
 * The guard lives in `2026_08_27_100000`, which has run. A migration is a dated act (ADR 0052);
 * rolling forward is the only way to change what it installed.
 *
 * ── WHAT WAS WRONG ──
 *
 * The update guard permits exactly one UPDATE — a redaction — and freezes everything else by
 * NAMING each column: id, school_id, gateway_transaction_id, uuid, source, event, created_at. Its
 * message says 'a redaction may clear the payload and change nothing else.'
 *
 * `redacted_fields` was added to the table without being added to that list, so the message became
 * a false quantifier: a redaction UPDATE could also rewrite `redacted_fields` — the one column
 * whose entire job is to be trusted about what was removed. A record of an absence that can be
 * silently rewritten is worse than no record, because it is READ as authoritative.
 *
 * ── THE GENERAL SHAPE, WHICH IS THE REASON THIS IS WORTH THE MIGRATION ──
 *
 * The guard is an ENUMERATION, and an enumeration cannot see a column nobody added to it. Adding a
 * column to a table with a named-column guard is therefore a TWO-PART act, and the second part has
 * no reminder attached. This is the same class as a constraint test holding a hand-written list —
 * and it recurred here one column after the codebase's own notes describe it, in a change that
 * cited them.
 *
 * `NOT (… <=> …)` and not `<>`: `redacted_fields` is nullable, and `NULL <> NULL` is NULL rather
 * than FALSE, so a plain `<>` could never fire — the freeze would have been wallpaper in exactly
 * the case it exists for (a row that stripped nothing, whose NULL is a claim).
 *
 * The COLLATE is `utf8mb4_bin` for the same reason as its siblings: under the table's default
 * `utf8mb4_unicode_ci` a comparison is case- and accent-insensitive, so a rewrite differing only in
 * case would compare EQUAL and pass the freeze.
 */
return new class extends Migration
{
    private const EVENTS_TABLE = 'finance_gateway_transaction_events';

    private const EVENTS_UPDATE_GUARD = 'finance_gateway_transaction_events_update_guard';

    public function up(): void
    {
        $this->install($this->frozenColumns(withRedactedFields: true));
        $this->assertShape();
    }

    /**
     * Reinstalls the guard WITHOUT the new arm — the state `2026_08_27_100000` left behind.
     *
     * Reversible on purpose, unlike the governance migrations whose `down()` is a documented no-op:
     * rolling this back restores a guard that is weaker but coherent, and the column it protects is
     * only written by code that ships alongside it.
     */
    public function down(): void
    {
        $this->install($this->frozenColumns(withRedactedFields: false));
    }

    private function frozenColumns(bool $withRedactedFields): string
    {
        $arms = [
            'NEW.id <> OLD.id',
            'OR NEW.school_id <> OLD.school_id',
            'OR NEW.gateway_transaction_id <> OLD.gateway_transaction_id',
            'OR NEW.uuid COLLATE utf8mb4_bin <> OLD.uuid',
            'OR NOT (NEW.source COLLATE utf8mb4_bin <=> OLD.source)',
            'OR NOT (NEW.event COLLATE utf8mb4_bin <=> OLD.event)',
            'OR NOT (NEW.created_at <=> OLD.created_at)',
        ];

        if ($withRedactedFields) {
            $arms[] = 'OR NOT (NEW.redacted_fields COLLATE utf8mb4_bin <=> OLD.redacted_fields)';
        }

        return implode("\n                ", $arms);
    }

    private function install(string $frozen): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_UPDATE_GUARD);

        // The source-domain arm is reproduced from 2026_08_27_100000: a trigger body cannot be
        // patched, only replaced, so replacing it means restating everything it did.
        DB::unprepared(
            'CREATE TRIGGER '.self::EVENTS_UPDATE_GUARD.' BEFORE UPDATE ON '.self::EVENTS_TABLE.' FOR EACH ROW
            BEGIN
                IF NOT COALESCE(
                       NEW.source COLLATE utf8mb4_bin = \'webhook\'
                    OR NEW.source COLLATE utf8mb4_bin = \'verify\', 0) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transaction_events.source must be webhook or verify.\';
                END IF;
                IF OLD.redacted_at IS NOT NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transaction_events: this delivery is already redacted; redaction happens once.\';
                END IF;
                IF NEW.redacted_at IS NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transaction_events is a raw record: UPDATE is denied except a redaction.\';
                END IF;
                IF NEW.payload IS NOT NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transaction_events: a redaction must clear the payload; redacted_at alone is a lie.\';
                END IF;
                IF '.$frozen.' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transaction_events: a redaction may clear the payload and change nothing else.\';
                END IF;
            END'
        );
    }

    /**
     * Verified from `information_schema`, not from the fact that CREATE TRIGGER returned success —
     * and specifically that the new column NAME appears in the installed body, because a trigger
     * that installs cleanly while freezing the wrong set is exactly the failure this guards.
     */
    private function assertShape(): void
    {
        if (! Schema::hasColumn(self::EVENTS_TABLE, 'redacted_fields')) {
            throw new RuntimeException(
                self::EVENTS_TABLE.'.redacted_fields is absent; 2026_08_31_100000 must run first.'
            );
        }

        $trigger = DB::selectOne(
            'SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [self::EVENTS_UPDATE_GUARD],
        );

        if ($trigger === null) {
            throw new RuntimeException(self::EVENTS_UPDATE_GUARD.' is absent after CREATE TRIGGER returned success.');
        }

        if (! str_contains((string) $trigger->body, 'redacted_fields')) {
            throw new RuntimeException(
                self::EVENTS_UPDATE_GUARD.' installed without a redacted_fields arm. The guard freezes a NAMED '
                .'list, so a column missing from it is silently rewritable by the one UPDATE this table permits.'
            );
        }
    }
};
