<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `finance_gateway_transactions` carries the BILL — what the school is owed — beside the GROSS.
 *
 * ── WHY, AND WHOSE FINDING THIS IS ──
 *
 * The second cold review's finding 4, left open when step 4 shipped. The ruling fixes the fee
 * BEFORE the charge, so the amount to credit against the invoice is the bill, a number known at
 * initiation. What settlement credits today is `gross − reported_fee`, which equals the bill only if
 * our up-front gross-up and Paystack's actual deduction agree to the kobo.
 *
 * They need not. The gross is rounded UP (see GatewayFeeCalculator), Paystack then rounds its own
 * fee on the rounded gross, and it caps local-card fees. Without this column the residual could not
 * be measured — only absorbed silently into the payer's balance — and step 7 had nothing to compare
 * against.
 *
 * ── amount_minor IS THE GROSS; bill_minor IS WHAT THE SCHOOL IS OWED ──
 *
 * `bill_minor <= amount_minor` always, because the payer is charged the bill plus the fee. The
 * trigger enforces it: a bill above the gross would mean the school expects more than the payer was
 * ever asked for, which no rounding can produce and which would credit an invoice with money that
 * never existed.
 *
 * NULLABLE, because rows written before this migration have no bill and inventing one for them
 * would be a fact nobody measured. NULL means "raised before the bill was recorded", never zero.
 * The pairing arm makes that unambiguous.
 *
 * ── THIS IS THE THIRD RESTATEMENT OF THIS TRIGGER BODY, AND THAT IS NOT A STYLE PROBLEM ──
 *
 * MySQL 5.7 — which production runs — permits exactly ONE trigger per (table, event, timing), so
 * every new arm means replacing the whole body and hand-copying every arm that came before. Each
 * copy is an opportunity to drop one silently, and a replacement that drops an arm installs
 * cleanly. That is why `assertShape()` below enumerates EVERY arm rather than only the one being
 * added: the one-sided check ("is my new thing there?") is precisely what lets a sibling disappear.
 * Ticketed: docs/handoff/tickets/gateway-insert-guard-is-restated-by-hand.md
 */
return new class extends Migration
{
    private const TABLE = 'finance_gateway_transactions';

    private const INSERT_GUARD = 'finance_gateway_transactions_insert_guard';

    private const SHAPE = '^bpsk-[0-9]+-[a-z0-9]+$';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->bigInteger('bill_minor')->nullable()->after('amount_currency');
            $table->char('bill_currency', 3)->nullable()->after('bill_minor');
        });

        $this->install(withBillArms: true);
        $this->assertShape(withBillArms: true);
    }

    public function down(): void
    {
        $this->install(withBillArms: false);

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(['bill_minor', 'bill_currency']);
        });
    }

    private function install(bool $withBillArms): void
    {
        $shape = self::SHAPE;

        $bill = $withBillArms ? <<<'SQL'
            IF (NEW.bill_minor IS NULL) <> (NEW.bill_currency IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: bill_minor and bill_currency are one value; set both or neither.';
            END IF;
            IF NEW.bill_minor IS NOT NULL AND NEW.bill_minor <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.bill_minor must be greater than zero.';
            END IF;
            IF NEW.bill_currency IS NOT NULL
               AND NEW.bill_currency COLLATE utf8mb4_bin <> NEW.amount_currency COLLATE utf8mb4_bin THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.bill_currency must match amount_currency.';
            END IF;
            IF NEW.bill_minor IS NOT NULL AND NEW.bill_minor > NEW.amount_minor THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.bill_minor may not exceed amount_minor: the payer is charged the bill plus the fee.';
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
                IF NEW.reference COLLATE utf8mb4_bin NOT REGEXP \''.$shape.'\'
                   OR NEW.reference COLLATE utf8mb4_bin NOT LIKE CONCAT(\'bpsk-\', NEW.school_id, \'-%\') THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_gateway_transactions.reference must be minted by GatewayReference::mint() for this school.\';
                END IF;
                '.$bill.'
            END'
        );
    }

    /**
     * EVERY arm, not just the new one. See the class docblock: a one-sided assertion is what lets a
     * hand-copied sibling vanish from a replacement that installs cleanly.
     */
    private function assertShape(bool $withBillArms): void
    {
        foreach (['bill_minor', 'bill_currency'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException(self::TABLE.'.'.$column.' is absent after ALTER TABLE returned success.');
            }
        }

        $trigger = DB::selectOne(
            'SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [self::INSERT_GUARD],
        );

        if ($trigger === null) {
            throw new RuntimeException(self::INSERT_GUARD.' is absent after CREATE TRIGGER returned success.');
        }

        $required = ['abandoned', 'fee_minor', 'fee_currency', 'amount_currency', 'amount_minor', 'bpsk-'];

        if ($withBillArms) {
            $required[] = 'bill_minor';
            $required[] = 'bill_currency';
        }

        foreach ($required as $arm) {
            if (! str_contains((string) $trigger->body, $arm)) {
                throw new RuntimeException(
                    self::INSERT_GUARD." installed without its '{$arm}' arm — a hand-copied restatement dropped it."
                );
            }
        }
    }
};
