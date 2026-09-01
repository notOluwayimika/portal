<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gateway reference format becomes a DATABASE rule.
 *
 * ── WHY THE MODEL GUARD WAS NOT ENOUGH ──
 *
 * `GatewayTransaction::booted()` refuses, on `creating`, a reference that does not route to the
 * row's own school. `static::creating` fires on the ELOQUENT write and on nothing else — not
 * `DB::table()->insert()`, not `->upsert()`, not a raw statement. This repository already writes
 * past it: `GatewayTransactionSchemaTest` inserted hand-built references by raw builder and passed.
 *
 * That matters for one specific reason. `bin/ci-boundary-lint.php` forbids `DB::table` on a
 * `finance_` literal only OUTSIDE `app/Finance` — and step 3's initialise call lives INSIDE it, so
 * the component the guard exists to protect is precisely the one permitted to walk around it.
 *
 * ── WHAT THE REFERENCE CARRIES, AND WHY A MALFORMED ONE IS SILENT ──
 *
 * The webhook derives the school from the reference so its lookup runs with `SchoolScope` intact
 * instead of searching every school and adopting whatever turns up. A reference that does not parse
 * is accepted by Paystack, the parent is charged, the delivery arrives, the lookup finds nothing,
 * and the webhook answers 200. Money taken, no payment recorded, one log line saying the reference
 * was unknown — indistinguishable from a delivery for a transaction this system never issued.
 *
 * ── THE TWO CLAUSES, AND WHY BOTH ──
 *
 * `REGEXP` pins the SHAPE: `bpsk-<digits>-<lowercase alnum>`. `LIKE CONCAT(...)` pins the BINDING:
 * the school segment is THIS ROW's `school_id`. Neither alone is the rule — a shape check would
 * admit `bpsk-99-abc` on school 1, and a binding check alone (`LIKE 'bpsk-1-%'`) would admit
 * `bpsk-1-` with an empty random segment, and `bpsk-1-a-b`, which `GatewayReference::schoolIdFrom`
 * rejects for having four segments. Together they are the parser.
 *
 * STRICTER THAN THE PARSER IN ONE PLACE, deliberately: `bpsk-01-x` parses (PHP casts `'01'` to 1)
 * and is refused here, because `CONCAT('bpsk-', 1, '-%')` is `bpsk-1-%`. Failing closed on a
 * canonical-form mismatch is the right direction — two spellings of one school in a routing key is
 * how a duplicate-reference bug starts.
 *
 * `COLLATE utf8mb4_bin` on both, for the reason every sibling comparison carries it: under the
 * table's default `utf8mb4_unicode_ci` the match is case- AND accent-insensitive, so `BPSK-1-…`
 * would satisfy a guard written to accept only `bpsk-`. `NOT REGEXP` on utf8mb4 additionally errors
 * 3995 without it (`currencyShapeBody` already relies on exactly this).
 *
 * ── INSERT ONLY ──
 *
 * `reference` is already immutable after insert: the update guard's identity arm carries
 * `NOT (NEW.reference COLLATE utf8mb4_bin <=> OLD.reference)` (2026_08_27_100000). So there is no
 * UPDATE path for a reference to become malformed, and adding an UPDATE arm would be a second
 * spelling of a rule that is already held.
 *
 * ── MESSAGE_TEXT IS CAPPED AT 128 CHARACTERS, AND OVERRUNNING IT BREAKS THE SIGNAL ──
 *
 * MySQL truncates nothing here: a longer `MESSAGE_TEXT` makes the SIGNAL itself fail with **1648**
 * (`Data too long for condition item`) instead of the intended **1644**. The row is still refused,
 * so a bite-proof asserting merely "an exception was thrown" would have passed — and the guard
 * would have been shipped reporting a code no caller recognises. `bootstrap/app.php` maps 1062,
 * 1451, 1205 and 1213 to HTTP statuses and lets everything else fall through to a 500; 1644 and
 * 1648 are both 500s today, so the damage is confined to diagnosis, which is exactly where a wrong
 * error code costs the most.
 *
 * Measured while writing this migration: the first draft's message was ~170 characters and all
 * seven refusal arms returned 1648. Every sibling message in `2026_08_27_100000` is under the cap
 * by luck of being short, so nothing had ever exercised the limit. **Assert the CODE, not the
 * presence of an exception.**
 *
 * A trigger body cannot be patched, only replaced, so this restates every arm
 * `2026_08_27_100000::insertGuardBody()` installed. Verified from `information_schema` afterwards —
 * the installed body, not the intent.
 */
return new class extends Migration
{
    private const TABLE = 'finance_gateway_transactions';

    private const INSERT_GUARD = 'finance_gateway_transactions_insert_guard';

    /**
     * The minted shape. `GatewayReference::PREFIX` is the source of truth in PHP; this is its
     * database twin, and the two are pinned together by
     * tests/Feature/Finance/GatewayReferenceTriggerTest.php rather than by anyone remembering.
     */
    private const SHAPE = '^bpsk-[0-9]+-[a-z0-9]+$';

    public function up(): void
    {
        $this->install(withReferenceArm: true);
        $this->assertShape();
    }

    public function down(): void
    {
        $this->install(withReferenceArm: false);
    }

    private function install(bool $withReferenceArm): void
    {
        $shape = self::SHAPE;

        $reference = $withReferenceArm ? <<<SQL
            IF NEW.reference COLLATE utf8mb4_bin NOT REGEXP '{$shape}'
               OR NEW.reference COLLATE utf8mb4_bin NOT LIKE CONCAT('bpsk-', NEW.school_id, '-%') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.reference must be minted by GatewayReference::mint() for this school.';
            END IF;
            SQL : '';

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::INSERT_GUARD);
        DB::unprepared(
            'CREATE TRIGGER '.self::INSERT_GUARD.' BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW
            BEGIN
                IF NOT COALESCE(
                       NEW.status COLLATE utf8mb4_bin = \'pending\'
                    OR NEW.status COLLATE utf8mb4_bin = \'success\'
                    OR NEW.status COLLATE utf8mb4_bin = \'failed\'
                    OR NEW.status COLLATE utf8mb4_bin = \'abandoned\', 0) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions.status must be pending, success, failed or abandoned.\';
                END IF;
                IF (NEW.fee_minor IS NULL) <> (NEW.fee_currency IS NULL) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions: fee_minor and fee_currency are one value; set both or neither.\';
                END IF;
                IF NEW.fee_minor IS NOT NULL AND NEW.fee_minor < 0 THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions.fee_minor may not be negative; a waived fee is zero.\';
                END IF;
                IF NEW.fee_currency IS NOT NULL
                   AND NEW.fee_currency COLLATE utf8mb4_bin <> NEW.amount_currency COLLATE utf8mb4_bin THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions.fee_currency must match amount_currency.\';
                END IF;
                IF NEW.amount_currency COLLATE utf8mb4_bin NOT REGEXP \'^[A-Z]{3}$\' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions.amount_currency must be three upper-case letters (ISO-4217).\';
                END IF;
                IF NEW.amount_minor <= 0 THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions.amount_minor must be greater than zero: nothing is not a checkout.\';
                END IF;
                '.$reference.'
            END'
        );
    }

    /**
     * Read the INSTALLED body back, not the intent. A trigger that installs cleanly while enforcing
     * the wrong thing is the failure this asserts against — and asserting only the presence of the
     * new name would be the one-sided check that lets a dropped sibling arm through, so every arm
     * this body is responsible for is named.
     */
    private function assertShape(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(self::TABLE.' is absent; 2026_08_27_100000 must run first.');
        }

        $trigger = DB::selectOne(
            'SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [self::INSERT_GUARD],
        );

        if ($trigger === null) {
            throw new RuntimeException(self::INSERT_GUARD.' is absent after CREATE TRIGGER returned success.');
        }

        foreach (['bpsk-', 'abandoned', 'fee_minor', 'fee_currency', 'amount_currency', 'amount_minor'] as $arm) {
            if (! str_contains((string) $trigger->body, $arm)) {
                throw new RuntimeException(
                    self::INSERT_GUARD." installed without its '{$arm}' arm. A trigger body cannot be patched, "
                    .'only replaced, so a replacement that drops a sibling arm installs cleanly and silently.'
                );
            }
        }
    }
};
