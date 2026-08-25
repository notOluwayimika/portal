<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A THIRD PAYMENT ORIGIN — `gateway` — ADDED TO THE `finance_payments` ORIGIN PAIRING.
 *
 * The rule lives in the trigger pair `finance_payments_origin_pairing_bi` / `_bu`, installed by
 * `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php`. It is NOT a `CHECK`: the two
 * `CHECK`s that carried this rule — `finance_payments_origin_shape` and
 * `finance_payments_bank_account_origin_shape` — were DROPPED by that migration, because production is
 * MySQL 5.7.23, which parses and ignores `CHECK` entirely. Nothing here restores them and nothing here
 * should; `CheckConstraintsAsTriggersTest` fails if either comes back.
 *
 * This migration REPLACES that pair rather than adding a third trigger to the table. Two objects
 * carrying two halves of one predicate is how the halves come to disagree, and MySQL gives no
 * ordering guarantee worth relying on between same-timing triggers beyond creation order. One
 * predicate, one pair, re-created in place.
 *
 * THE PREDICATE AFTER THIS MIGRATION:
 *
 *     (origin = 'portal'   AND bank_account_id IS NOT NULL)
 *  OR (origin = 'migrated' AND bank_account_id IS NULL)
 *  OR (origin = 'gateway'  AND bank_account_id IS NOT NULL)
 *
 * WHY `gateway` AND NOT `paystack`. The value names the CATEGORY, not the provider. `finance_payments`
 * is append-only, so a value written into live money rows can never be corrected; naming the provider
 * would mean a second provider needs a migration of rows that cannot be migrated. The provider's own
 * identity travels in `external_reference`, which is per-row and already exists.
 *
 * WHY THE `gateway` ARM MIRRORS `portal` AND NOT `migrated`. A gateway payment DOES land in one of the
 * school's accounts — the settlement account the provider pays out into — so it names one, and the
 * bursar reconciles it against a bank statement exactly as a counter payment is reconciled. `migrated`
 * is the odd arm precisely because that money never entered one of our accounts at all; pointing an
 * imported row at an account would assert a fact that is false (2026_08_10_120000's reasoning,
 * unchanged).
 *
 * ── THE THREE LOAD-BEARING PIECES, CARRIED VERBATIM FROM 2026_08_17_100000 ────────────────────────
 *
 * 1. `COALESCE(…, 0)`. A NULL origin makes every arm NULL, `NULL OR NULL OR NULL` is NULL, and
 *    `NOT NULL` is NULL — which is not TRUE, so a bare `IF NOT (…)` would let a NULL origin straight
 *    through. (`origin` is `NOT NULL` today, so this is the belt behind a brace that is holding; it
 *    costs nothing and it survives someone relaxing the column.) The `CHECK` this descends from did
 *    not need it because SQL treats an unknown `CHECK` result as SATISFIED — the same three-valued
 *    logic arriving at the opposite default. Adding a third arm does not weaken this: three NULLs
 *    OR'd together are still NULL.
 *
 * 2. `COLLATE utf8mb4_bin` ON EVERY ARM, the new one included. Under the table's default
 *    `utf8mb4_unicode_ci`, `origin = 'gateway'` also matches `'Gateway'` and `'GATEWAY'` — a case
 *    variant that every `origin = 'gateway'` report filter would ALSO match, so the guard would read
 *    green while admitting values nobody wrote a filter for. Omitting the clause from ONE arm is the
 *    quiet failure: the other two arms keep biting, so the guard still looks alive.
 *    `PaymentOriginGatewayTest`'s capital-G arm is what measures that this clause took.
 *
 * 3. `MESSAGE_TEXT` IS CAPPED AT 128 CHARACTERS. The sentence below is **108 characters**, counted
 *    rather than eyeballed:
 *
 *      finance_payments: a portal or gateway payment needs a bank account and a migrated payment must not have one.
 *
 *    (The sentence it replaces was 97. Two tests assert this string in full —
 *    `CheckConstraintsAsTriggersTest` and `PaymentOriginGatewayTest` — so a change to it is visible.)
 *
 *    A MEASURED CORRECTION TO WHAT 2026_08_17_100000'S DOCBLOCK SAYS, taken on this branch on
 *    **MySQL 8.0.43** by installing this trigger with a 129-character sentence and driving all four
 *    refusal arms through it. That docblock says the cap is "SILENTLY TRUNCATED past it". On 8.0.43 it
 *    is NOT silent and NOT a truncation: `SIGNAL` itself fails at fire time with
 *
 *        SQLSTATE[HY000]: General error: 1648  Data too long for condition item 'MESSAGE_TEXT'
 *
 *    so the row is still refused, but by 1648/HY000 instead of 1644/45000 — the guard stops speaking
 *    its own refusal and every caller that classifies on the driver code gets the wrong answer.
 *    Loud, not quiet, and it fails CLOSED rather than open. That is a better failure than the one the
 *    earlier docblock described, but it is a DIFFERENT one, and the count is what avoids both.
 *
 *    **Measured on 8.0.43 only.** Whether 5.7.23 truncates instead of erroring was not measured — no
 *    MySQL 5.7 was available here either, exactly as 2026_08_17_100000 records for its own five
 *    bullets. Under 128 characters the question does not arise on either server, which is why the
 *    count is the control rather than the behaviour.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). `CREATE TRIGGER` returning success is not evidence
 * that the right trigger exists — a mis-named, mis-timed or mis-evented trigger is created just as
 * successfully. Each `CREATE` is read back out of `information_schema.TRIGGERS` and this migration
 * throws unless the trigger is there with the expected timing, event and table, leaving itself
 * unrecorded rather than recording a green that means nothing.
 *
 * BOTH EXISTENCE GUARDS ARE KEPT for the same reason 2026_08_17_100000 keeps them: a table that does
 * not exist AND a column that does not exist are both skipped, because a half-applied unrecorded
 * migration on an environment that is mid-catch-up is the failure mode this whole family exists to
 * avoid.
 *
 * ONE CONSEQUENCE UNCHANGED FROM THE MIGRATION THIS REPLACES: on `finance_payments`,
 * `finance_payments_no_update` (BEFORE UPDATE, `ACTION_ORDER = 1`, append-only) signals on EVERY
 * update, so `_bu` is unreachable behind it — exactly as it already was. It is re-created anyway, so
 * the pairing does not quietly become insert-only if that table is ever given an update path.
 */
return new class extends Migration
{
    private const TABLE = 'finance_payments';

    private const STEM = 'finance_payments_origin_pairing';

    public function up(): void
    {
        $this->installPairing($this->threeOriginBody());
    }

    /**
     * DROPS THE TRIGGERS AND STOPS — no `CHECK` is restored, following the `down()` of
     * `2026_08_17_100000` and its stated reasoning: a restored `CHECK` is REAL on 8.0.43 and a SILENT
     * NO-OP on 5.7.23, so it means two different things on the two servers and reintroduces, in the
     * one direction nobody would think to check, the exact asymmetry that migration exists to remove.
     *
     * NAMING THE RESIDUAL HONESTLY, because it differs from that file's: rolling THIS migration back
     * on its own leaves `finance_payments` with NO origin pairing guard at all, rather than returning
     * it to the two-origin one. That is deliberate — a `down()` carrying a copy of the previous body
     * is a second spelling of a predicate that is supposed to have exactly one — but it is a real
     * weakening of a guard that is currently live on BOTH servers, which is not what
     * `2026_08_17_100000`'s rollback did (it returned production to the unenforced state production
     * had been in throughout). The recovery is the ordinary one and it is exercised by
     * `bin/quality-clean-db`'s rollback/re-up leg: rolling back further reaches `2026_08_17_100000`'s
     * own `down()`, and re-upping re-asserts the two-origin pair and then this three-origin one, in
     * that order, each verified by shape. Roll forward with a new named migration if the rule is ever
     * to change again; do not hand-edit a live trigger.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('INSERT'));
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('UPDATE'));
    }

    /**
     * The predicate, as one heredoc so the INSERT and UPDATE bodies cannot drift from each other.
     *
     * COALESCE, COLLATE and the 128-character MESSAGE_TEXT cap are all explained in the class
     * docblock. None of the three is stylistic and none may be dropped from a single arm.
     */
    private function threeOriginBody(): string
    {
        return <<<'SQL'
            IF NOT COALESCE(
                   (NEW.origin COLLATE utf8mb4_bin = 'portal'   AND NEW.bank_account_id IS NOT NULL)
                OR (NEW.origin COLLATE utf8mb4_bin = 'migrated' AND NEW.bank_account_id IS NULL)
                OR (NEW.origin COLLATE utf8mb4_bin = 'gateway'  AND NEW.bank_account_id IS NOT NULL), 0) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'finance_payments: a portal or gateway payment needs a bank account and a migrated payment must not have one.';
            END IF;
            SQL;
    }

    /**
     * REPLACE the pair — not a third trigger on the table. See the class docblock.
     */
    private function installPairing(string $body): void
    {
        if (! Schema::hasTable(self::TABLE)
            || ! Schema::hasColumn(self::TABLE, 'origin')
            || ! Schema::hasColumn(self::TABLE, 'bank_account_id')) {
            return;
        }

        $this->installTrigger('INSERT', $body);
        $this->installTrigger('UPDATE', $body);
    }

    /**
     * `{stem}_bi` / `{stem}_bu` — the names 2026_08_17_100000 created, re-used deliberately so this is
     * a REPLACEMENT of that pair and not a second pair alongside it.
     */
    private function triggerName(string $event): string
    {
        return self::STEM.($event === 'INSERT' ? '_bi' : '_bu');
    }

    /**
     * Create one trigger idempotently, then PROVE it is there — name, timing, event and table — from
     * `information_schema`. ADR 0052: a statement that returned success is not evidence of a shape.
     */
    private function installTrigger(string $event, string $body): void
    {
        $name = $this->triggerName($event);

        // Idempotent, so the rollback/re-up leg of bin/quality-clean-db re-asserts rather than 1359s
        // on the existing two-origin trigger of the same name.
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE."
             FOR EACH ROW
             BEGIN
                {$body}
             END"
        );

        $this->assertTriggerShape($name, $event);
    }

    /**
     * Read the trigger back and refuse to record the migration unless it is what `CREATE` claimed.
     */
    private function assertTriggerShape(string $name, string $event): void
    {
        $read = DB::selectOne(
            'SELECT ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$name],
        );

        if ($read === null) {
            throw new RuntimeException(
                "Trigger [{$name}] does not exist after CREATE TRIGGER returned success. Refusing to "
                .'record this migration as applied: the origin pairing it claims to widen is absent, '
                .'and on 5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== self::TABLE) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on ".self::TABLE.'. A trigger with the right '
                .'name and the wrong timing or event fires on writes nobody guarded and misses the '
                .'ones they did.'
            );
        }
    }
};
