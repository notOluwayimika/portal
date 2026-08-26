<?php

use App\Finance\Enums\DiscountBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `finance_discount_policies.base` — WHAT a percentage policy takes its percentage OF. See
 * {@see DiscountBase} for what the two values mean.
 *
 * ── THE BACKFILL IS A STATEMENT OF FACT, NOT A GUESS ─────────────────────────────────────────────
 *
 * Every existing policy applies its percentage to the DISCOUNTABLE charge lines — that is what
 * `GenerateInvoice::resolvePercentages()` did, unconditionally, before this column existed. So
 * `'discountable'` is not a default chosen for convenience; it is the value that makes each existing
 * row keep describing exactly what it already does. This is the opposite of `scholarships.kind`,
 * whose backfill had to be NULL precisely because nothing in the data said which scheme a
 * scholarship was: here the CODE said it, for every row, and the column is only writing it down.
 *
 * IT IS THEREFORE NOT NULL WITH A DEFAULT, and an `amount`-basis policy carries `'discountable'`
 * INERTLY. The alternative — nullable, NULL for `amount` — buys a schema that says "meaningless
 * here" at the cost of a second three-valued column on a money table, and of a NULL that
 * `resolvePercentages()` would have to defend against on a path that can never reach it (an amount
 * policy has `percent IS NULL`, so it never becomes a percentage spec). The inert value is stated
 * here rather than hidden: reading `base` off an `amount` policy means nothing.
 *
 * ── THE DOMAIN IS A TRIGGER, NOT A `CHECK` ───────────────────────────────────────────────────────
 *
 * Production is MySQL 5.7.23, which PARSES AND IGNORES `CHECK`
 * (docs/finance/check-constraints-on-mysql-5-7.md), so a `CHECK (base IN (...))` would be enforced
 * on 8.0.43 locally, absent on the server that holds the money, and green in both places. This
 * follows `2026_08_26_100000_add_kind_to_scholarships_table.php` and, through it,
 * `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` — the same three load-bearing
 * pieces, kept for the same reasons:
 *
 *   1. `COLLATE utf8mb4_bin` — under the table's `utf8mb4_unicode_ci`, `base = 'total'` also matches
 *      `'Total'` and `'TOTAL'`. A row storing `'Total'` would pass a ci guard and then MISS the
 *      enum cast, which is the shape that silently bills a child on the wrong base.
 *   2. `COALESCE(…, 0)` — a three-valued `IN` result that is NULL makes a bare `IF NOT (…)` fall
 *      through. `base` is NOT NULL, so this is the belt behind a brace that is already holding; it
 *      costs nothing and it survives someone making the column nullable later.
 *   3. NO APOSTROPHE in `MESSAGE_TEXT` — MySQL stores a trigger body with the escape STRIPPED, so an
 *      apostrophe leaves the body un-dumpable (`TriggerBodiesAreDumpSafeTest`).
 *
 * MESSAGE LENGTHS, COUNTED RATHER THAN EYEBALLED, against the 128-character cap that
 * `2026_08_25_100000` measured (past it `SIGNAL` itself fails 1648/HY000 and the guard stops
 * speaking its own refusal): the domain message is **62 characters**, the immutability message is
 * **90 characters**.
 *
 * ── THE IMMUTABILITY ARM IS AN ADDITION, AND IT IS DELIBERATE ────────────────────────────────────
 *
 * `finance_discount_policies` already carries `finance_discount_policies_update_guard`, whose whole
 * claim is "a policy's TERMS are immutable; only status may change". `base` is a term — it decides
 * what every future reduction citing this policy is computed against — so a `base` that could be
 * UPDATEd would be a term escaping the table's own stated invariant through the back door of a
 * column added after the guard was written.
 *
 * That existing guard is NOT touched. Weakening or rewriting it was the one thing this change was
 * told not to do, and re-issuing its body from a second migration is how two copies of one rule
 * come to drift. Instead the immutability of `base` is asserted by the NEW `_bu` trigger below,
 * beside its own domain check — additive, separately named, and droppable by this migration's own
 * `down()` without disturbing anything the earlier migration installed. MySQL 8 (and 5.7.2+) permits
 * multiple triggers of the same timing and event on one table, so both fire.
 *
 * ORDER MATTERS AND IS LOAD-BEARING: the backfill UPDATE runs BEFORE the `_bu` trigger is installed.
 * Installed first, the trigger would refuse the migration's own backfill.
 *
 * ── THE EXISTING UPDATE GUARD DOES NOT FIRE ON THIS MIGRATION ────────────────────────────────────
 *
 * Checked before writing, because the instruction was to stop rather than weaken it if it did.
 * `ALTER TABLE … ADD COLUMN` is DDL and fires no row trigger at all. The backfill is an UPDATE, and
 * the guard compares `name`, `basis`, `value_minor`, `value_currency`, `percent`,
 * `requires_approval`, `school_id`, `uuid` and `supersedes_policy_id` — every one of them NEW vs OLD
 * on a statement that sets only `base`, so every comparison is false and no `SIGNAL` is raised. The
 * `WHERE base IS NULL` clause narrows it further: on a re-run there are no rows to update at all.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052) — each `CREATE TRIGGER` is read back out of
 * `information_schema.TRIGGERS` and this migration THROWS unless the timing, event and table are
 * what was asked for, leaving itself unrecorded rather than recording a green that means nothing.
 *
 * EVERY DDL STATEMENT IS INDIVIDUALLY RE-RUNNABLE (MySQL commits DDL implicitly and Laravel records
 * a migration only after `up()` RETURNS): the column add is guarded on `Schema::hasColumn` and each
 * `CREATE TRIGGER` is preceded by `DROP TRIGGER IF EXISTS`.
 */
return new class extends Migration
{
    private const TABLE = 'finance_discount_policies';

    private const STEM = 'finance_discount_policies_base_shape';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'base')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // NOT NULL with a default so the ALTER itself lands every existing row on the value
                // they already behave as. `after('percent')` puts it beside the figure it qualifies.
                $table->string('base', 16)
                    ->default(DiscountBase::Discountable->value)
                    ->after('percent');
            });
        }

        // EXPLICIT, even though the DEFAULT above has already produced this state on every existing
        // row. The statement is what makes the fact reviewable — and it is what would repair a row
        // if the column were ever added by hand without one. Narrowed to NULL rows so it is a no-op
        // on every re-run, and so it can never touch a row somebody has deliberately set to 'total'.
        DB::update(
            'UPDATE '.self::TABLE.' SET base = ? WHERE base IS NULL',
            [DiscountBase::Discountable->value],
        );

        $this->installTrigger('INSERT');
        $this->installTrigger('UPDATE');
    }

    /**
     * DROP THE TRIGGERS, THEN THE COLUMN — the literal inverse, and honest on both servers.
     *
     * `finance_discount_policies_update_guard` is untouched here for the same reason it is untouched
     * in `up()`: this migration did not install it and must not remove it.
     *
     * THE RESIDUAL, NAMED: rolling back DISCARDS every configured `base`, so a roll-back-and-re-up
     * returns every policy to `discountable`. That is inherent to dropping the column that stores
     * them. It is the SAFE direction — `discountable` is the pre-change behaviour, so a policy
     * reverts to reducing less, never more — and it is what `bin/quality-clean-db`'s rollback/re-up
     * leg exercises.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('INSERT'));
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('UPDATE'));

        if (Schema::hasColumn(self::TABLE, 'base')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('base');
            });
        }
    }

    /**
     * The domain check, as one heredoc so the INSERT and UPDATE bodies cannot drift from each other.
     * `COLLATE` and `COALESCE` are explained in the class docblock; neither is stylistic.
     */
    private function domainCheck(): string
    {
        $values = "'".implode("','", DiscountBase::values())."'";

        return <<<SQL
                IF NOT COALESCE(NEW.base COLLATE utf8mb4_bin IN ({$values}), 0) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'finance_discount_policies: base must be discountable or total.';
                END IF;
            SQL;
    }

    /** UPDATE only. `<=>` is the NULL-safe compare the sibling update guard already uses. */
    private function immutabilityCheck(): string
    {
        return <<<'SQL'
                IF NOT (NEW.base <=> OLD.base) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'finance_discount_policies: base is a policy term and is immutable; only status may change.';
                END IF;
            SQL;
    }

    /** `{stem}_bi` / `{stem}_bu`, following the trigger family. */
    private function triggerName(string $event): string
    {
        return self::STEM.($event === 'INSERT' ? '_bi' : '_bu');
    }

    private function installTrigger(string $event): void
    {
        if (! Schema::hasColumn(self::TABLE, 'base')) {
            return;
        }

        $name = $this->triggerName($event);
        $body = $event === 'UPDATE'
            ? $this->immutabilityCheck()."\n".$this->domainCheck()
            : $this->domainCheck();

        // Idempotent, so the rollback/re-up leg re-asserts rather than dying on 1359.
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
                .'record this migration as applied: the discount base domain it claims to hold is '
                .'unenforced, and on MySQL 5.7 there is no CHECK behind it.'
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
