<?php

use App\Finance\Actions\ApproveDiscountPolicyChange;
use App\Finance\Enums\DiscountBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `finance_discount_policy_changes.base` — the PROPOSED base, so the governance path can carry
 * {@see DiscountBase} end to end. Sibling of `2026_08_26_110000`, which added the same column to the
 * catalog table it feeds.
 *
 * ── WHY THIS MIGRATION EXISTS: THE COLUMN WITHOUT IT IS A MONEY DEFECT ───────────────────────────
 *
 * `2026_08_26_110000` added `base` to `finance_discount_policies` and stopped there, and cold review
 * caught what that left. {@see ApproveDiscountPolicyChange} is the ONLY sanctioned writer of the
 * catalog (an arch test asserts it), and it builds its row from THIS table's columns. With no `base`
 * here there was nowhere for a maker to propose one — so a school could not author a `total` policy
 * at all, and, worse, an AMEND of a `total` policy superseded it and inserted a replacement whose
 * `base` fell to the catalog column's DEFAULT. "50% off the whole bill", amended to 55%, came back
 * as "55% off tuition only": the child billed MORE, silently, through the one flow whose entire
 * purpose is that terms cannot move without a checker. And because `base` is immutable on the
 * catalog table, it could not be corrected in place — only by another amend, which dropped it again.
 *
 * That is the shape this repository calls a primitive ahead of its consumer, arriving from the other
 * side: a column with a reader and no writer. The lesson is recorded here rather than in a report,
 * because the next axis added to this catalog will be added by someone reading these two migrations.
 *
 * ── NULLABLE, UNLIKE THE CATALOG COLUMN, AND THAT ASYMMETRY IS DELIBERATE ────────────────────────
 *
 * A `retire` change carries NO terms at all — `name`, `basis` and `requires_approval` are all
 * nullable here for that reason and the `terms_shape` CHECK makes it a fact — so `base` must be
 * nullable too. An `amount`-basis change also carries none: there is no percentage to take of
 * anything. `ApproveDiscountPolicyChange::insertPolicy()` coalesces NULL to `discountable`, which is
 * the same inert value the catalog column defaults to, so an amount policy is unaffected either way.
 *
 * WHERE THE REAL GUARD IS, stated because it is NOT this table: a percent-basis change must NAME its
 * base, and that is refused at the edge by `SubmitDiscountPolicyChangeRequest`
 * (`required_if:basis,percent`, `prohibited_if:basis,amount` — the shape `percent` and `value_minor`
 * already use). It is deliberately NOT added to the `terms_shape` CHECK: that constraint lives in
 * `2026_07_26_140001`, which has already run everywhere, and editing an applied migration is how a
 * schema and its history stop agreeing. A separate CHECK-altering migration was considered and
 * rejected for the reason the whole trigger family exists — production is MySQL 5.7.23, which parses
 * and ignores `CHECK`, so it would have been enforcement on the developer's machine only.
 *
 * ── THE DOMAIN AND THE FREEZE ARE TRIGGERS ───────────────────────────────────────────────────────
 *
 * Same three load-bearing pieces as `2026_08_26_110000` and, through it,
 * `2026_08_26_100000_add_kind_to_scholarships_table.php`: `COLLATE utf8mb4_bin` (under the table's
 * `utf8mb4_unicode_ci`, `base = 'total'` also matches `'Total'`, which would pass a ci guard and then
 * miss the enum cast), `COALESCE(…, 0)` behind the NULL arm, and no apostrophe in `MESSAGE_TEXT`
 * (MySQL stores a trigger body with the escape STRIPPED — `TriggerBodiesAreDumpSafeTest`).
 *
 * The `_bu` body ALSO freezes `base`, for the same reason `finance_discount_policy_changes_update_guard`
 * freezes every other proposed term: *"else a maker could submit a modest discount and rewrite it
 * after approval"* (that migration, :102-103). A term added after that guard was written must not
 * escape it through the back door. The existing guard is NOT touched or widened — the new arm lives
 * in this migration's own separately-named trigger, which `down()` removes without disturbing it.
 * MySQL 5.7.2+ permits multiple triggers of one timing and event on a table, so both fire.
 *
 * MESSAGE LENGTHS, COUNTED: the domain message is **77 characters**, the freeze message is
 * **86 characters**, against the 128-character cap `2026_08_25_100000` measured — past it `SIGNAL`
 * itself fails 1648/HY000 and the guard stops speaking its own refusal.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052): each `CREATE TRIGGER` is read back out of
 * `information_schema.TRIGGERS` and this migration THROWS unless the timing, event and table are
 * what was asked for. EVERY DDL STATEMENT IS INDIVIDUALLY RE-RUNNABLE — the column add is guarded on
 * `Schema::hasColumn` and each `CREATE TRIGGER` is preceded by `DROP TRIGGER IF EXISTS`.
 */
return new class extends Migration
{
    private const TABLE = 'finance_discount_policy_changes';

    private const STEM = 'finance_discount_policy_changes_base_shape';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'base')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // NULLABLE, NO DEFAULT. Existing rows are already-decided changes whose proposed
                // terms are frozen; NULL on them is correct and means "proposed before this axis
                // existed", which the approver reads as `discountable` — the behaviour those rows
                // were approved under. A default would state a proposal nobody made.
                $table->string('base', 16)->nullable()->after('percent');
            });
        }

        $this->installTrigger('INSERT');
        $this->installTrigger('UPDATE');
    }

    /**
     * DROP THE TRIGGERS, THEN THE COLUMN — the literal inverse.
     *
     * `finance_discount_policy_changes_update_guard` and `_no_delete` are untouched here for the
     * reason they are untouched in `up()`: this migration did not install them.
     *
     * THE RESIDUAL, NAMED: rolling back discards every proposed `base`, so a submitted-but-undecided
     * `total` change re-ups as a proposal for `discountable`. That is inherent to dropping the column
     * that stores it, and it is the SAFE direction — the reduction gets smaller, never larger.
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
     * The NULL arm admits `retire` and `amount`; `COLLATE` and `COALESCE` are in the class docblock.
     */
    private function domainCheck(): string
    {
        $values = "'".implode("','", DiscountBase::values())."'";

        return <<<SQL
                IF NOT COALESCE(
                       NEW.base IS NULL
                    OR NEW.base COLLATE utf8mb4_bin IN ({$values}), 0) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'finance_discount_policy_changes: base must be discountable or total, or null.';
                END IF;
            SQL;
    }

    /** UPDATE only. `<=>` is the NULL-safe compare the sibling update guard already uses. */
    private function freezeCheck(): string
    {
        return <<<'SQL'
                IF NOT (NEW.base <=> OLD.base) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'finance_discount_policy_changes: base is a proposed term and is frozen once submitted.';
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
            ? $this->freezeCheck()."\n".$this->domainCheck()
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

    /** Read the trigger back and refuse to record the migration unless it is what `CREATE` claimed. */
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
                .'record this migration as applied: the proposed discount base would be unconstrained '
                .'and unfrozen, and on MySQL 5.7 there is no CHECK behind it.'
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
