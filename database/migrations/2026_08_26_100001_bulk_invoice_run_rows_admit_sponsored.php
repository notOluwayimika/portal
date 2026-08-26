<?php

use App\Enums\ScholarshipKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A FIFTH RUN OUTCOME — `sponsored` — AND THE COUNT COLUMN THAT KEEPS THE COHORT EQUALITY TRUE.
 *
 * The bulk invoice run now EXCLUDES students whose scholarship is a sponsored scheme
 * ({@see ScholarshipKind}): an outside organisation pays for them, on a different fee
 * basis, once a session, by hand. Billing them the standard schedule produced a full-price invoice
 * to a parent who owes nothing, on a run that reported success.
 *
 * ── WHY THIS MIGRATION HAS TWO PARTS AND NOT ONE ─────────────────────────────────────────────────
 *
 * The exclusion could have been done by filtering the cohort list before the loop, which would need
 * no schema at all. It is not done that way, and the reason is arithmetic rather than taste. The run
 * carries two independent equalities as its ONLY defect signal — there is deliberately no "something
 * went wrong" flag, because a flag the job sets is a flag the job can forget to set:
 *
 *     billed_count + already_billed_count + failed_count == cohort_count
 *     unplaceable_count                                  == unplaceable_listed_count
 *
 * A sponsored student is genuinely IN the cohort — they sit at the run's coordinates, and the
 * preview endpoint counts them — so they are walked and given a row like every other cohort member.
 * That immediately breaks the first equality unless there is a term for them. Recording the rows
 * WITHOUT adding `sponsored_count` would have made the run's own alarm fire on every healthy run
 * that has a C2C student in it, which is how an alarm gets learned-around and then ignored.
 *
 * So both halves land together, and the equality becomes:
 *
 *     billed + already_billed + failed + sponsored == cohort_count
 *
 * ── THE TRIGGER PAIR IS REPLACED, NOT SUPPLEMENTED ───────────────────────────────────────────────
 *
 * The outcome domain lives in `finance_bulk_invoice_run_rows_outcome_shape_bi` / `_bu`, installed by
 * `2026_08_18_110000_create_finance_bulk_invoice_run_tables.php`. It is NOT a `CHECK`: production is
 * MySQL 5.7.23, which parses and ignores `CHECK` entirely
 * (docs/finance/check-constraints-on-mysql-5-7.md), so a `CHECK` here would be enforced locally,
 * absent on the server that holds the money, and green in both places.
 *
 * This migration RE-CREATES that pair in place rather than adding a third trigger to the table,
 * following `2026_08_25_100000_finance_payment_origin_admits_gateway.php` exactly: two objects
 * carrying two halves of one predicate is how the halves come to disagree, and MySQL gives no
 * ordering guarantee worth relying on between same-timing triggers beyond creation order.
 *
 * THE THREE LOAD-BEARING PIECES ARE CARRIED VERBATIM:
 *
 * 1. `COALESCE(…, 0)`. A NULL outcome makes `IN (…)` evaluate to NULL, `NOT NULL` is NULL, and a
 *    bare `IF NOT (…)` would let it straight through. The column is NOT NULL today, so this is the
 *    belt behind a brace that is holding; it survives someone relaxing the column.
 *
 * 2. `COLLATE utf8mb4_bin`. Under the table's `utf8mb4_unicode_ci`, `outcome = 'sponsored'` also
 *    matches `'Sponsored'` and `'SPONSORED'` — values every `where('outcome', 'sponsored')` read and
 *    every `groupBy('outcome')` bucket would treat as a DIFFERENT value while the guard read green.
 *    Omitting the clause is the quiet failure: the other arms keep biting, so the guard looks alive.
 *
 * 3. `MESSAGE_TEXT` IS CAPPED AT 128 CHARACTERS. The sentence below is **104 characters**, counted
 *    rather than eyeballed. `2026_08_25_100000` measured what happens past the cap on 8.0.43:
 *    `SIGNAL` itself fails with `1648 Data too long for condition item 'MESSAGE_TEXT'`, so the row
 *    is still refused but by 1648/HY000 instead of 1644/45000 — the guard stops speaking its own
 *    refusal and every caller classifying on the driver code gets the wrong answer. Loud, and it
 *    fails closed; the count is what avoids the question on both servers.
 *
 * NO APOSTROPHE in `MESSAGE_TEXT` — MySQL stores a trigger body with the escape STRIPPED, so an
 * apostrophe leaves the body un-dumpable (`TriggerBodiesAreDumpSafeTest`).
 *
 * THE FIVE VALUES ARE WRITTEN OUT AS LITERALS, and this is deliberate rather than lazy. Building the
 * list from `BulkInvoiceRunOutcome::cases()` reads better and is wrong: a migration is a historical
 * record of one schema change, and one that consults a live enum SILENTLY CHANGES ITS OWN EFFECT the
 * day a sixth case is added. A fresh install would then get a six-value trigger from this file while
 * every already-migrated database kept five — the two environments diverging with nothing to say so,
 * and the divergence introduced by a file nobody edited. The literal makes this migration mean today
 * what it meant when it was written.
 *
 * The consequence is the one worth having: a sixth enum case added without its own migration is
 * REFUSED BY THE DATABASE at insert time rather than admitted silently.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052), and every DDL statement is individually
 * re-runnable: the column add is guarded on `Schema::hasColumn`, each `CREATE TRIGGER` is preceded
 * by `DROP TRIGGER IF EXISTS`, and each is read back out of `information_schema.TRIGGERS`.
 */
return new class extends Migration
{
    private const RUNS = 'finance_bulk_invoice_runs';

    private const ROWS = 'finance_bulk_invoice_run_rows';

    private const STEM = 'finance_bulk_invoice_run_rows_outcome_shape';

    public function up(): void
    {
        $this->addSponsoredCount();
        $this->installTrigger('INSERT');
        $this->installTrigger('UPDATE');
    }

    /**
     * DROP THE COLUMN, THEN RESTORE THE FOUR-VALUE PREDICATE — and this `down()` DOES carry a copy of
     * the previous body, which the migration it is modelled on deliberately refused to do.
     *
     * The difference is what the two guards defend. `2026_08_25_100000` dropped its pair outright
     * because leaving `finance_payments` briefly unguarded is recoverable by rolling further back,
     * and a duplicated predicate is a second spelling of a rule meant to have exactly one. Here the
     * rollback happens WITH `sponsored_count` being dropped in the same breath: leaving the trigger
     * admitting `sponsored` would leave the table able to accept rows the run can no longer count,
     * which is the un-countable state this migration exists to prevent, reached through the back
     * door. So the domain goes back to four values in step with the column that made five possible.
     *
     * THE RESIDUAL, NAMED HONESTLY: rolling back does NOT delete `sponsored` rows already written.
     * They stay, the trigger stops admitting new ones, and the cohort equality on those historical
     * runs no longer balances — the alarm fires on runs that were correct when they ran. That is
     * inherent to un-inventing an outcome after it has been recorded, and the recovery is the
     * ordinary one: roll forward with a new named migration rather than back.
     */
    public function down(): void
    {
        if (Schema::hasTable(self::RUNS) && Schema::hasColumn(self::RUNS, 'sponsored_count')) {
            Schema::table(self::RUNS, function (Blueprint $table) {
                $table->dropColumn('sponsored_count');
            });
        }

        if (! Schema::hasTable(self::ROWS)) {
            return;
        }

        $this->writeTrigger('INSERT', $this->bodyFor(['billed', 'already_billed', 'failed', 'unplaceable']));
        $this->writeTrigger('UPDATE', $this->bodyFor(['billed', 'already_billed', 'failed', 'unplaceable']));
    }

    /**
     * NULLABLE, like every other count on the run — they are all NULL until the run finishes, and a
     * `0` default would say "no sponsored students" about a run that has not counted yet.
     *
     * Placed after `failed_count` so the four cohort terms of the equality sit together in the table
     * as they do in the sentence.
     */
    private function addSponsoredCount(): void
    {
        if (! Schema::hasTable(self::RUNS) || Schema::hasColumn(self::RUNS, 'sponsored_count')) {
            return;
        }

        Schema::table(self::RUNS, function (Blueprint $table) {
            $table->unsignedInteger('sponsored_count')->nullable()->after('failed_count');
        });
    }

    /**
     * The predicate, as one heredoc so the INSERT and UPDATE bodies cannot drift from each other.
     * `COALESCE`, `COLLATE` and the 128-character cap are in the class docblock, as is why the value
     * list reaches this method as literals rather than from the enum.
     *
     * @param  list<string>  $values
     */
    private function bodyFor(array $values): string
    {
        $list = "'".implode("','", $values)."'";

        return <<<SQL
            IF NOT COALESCE(NEW.outcome COLLATE utf8mb4_bin IN ({$list}), 0) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'finance_bulk_invoice_run_rows: outcome must be billed, already_billed, failed, unplaceable or sponsored.';
            END IF;
            SQL;
    }

    private function triggerName(string $event): string
    {
        return self::STEM.($event === 'INSERT' ? '_bi' : '_bu');
    }

    private function installTrigger(string $event): void
    {
        if (! Schema::hasTable(self::ROWS) || ! Schema::hasColumn(self::ROWS, 'outcome')) {
            return;
        }

        $this->writeTrigger($event, $this->bodyFor(['billed', 'already_billed', 'failed', 'unplaceable', 'sponsored']));
        $this->assertTriggerShape($this->triggerName($event), $event);
    }

    private function writeTrigger(string $event, string $body): void
    {
        $name = $this->triggerName($event);

        // Idempotent: the rollback/re-up leg of bin/quality-clean-db must re-assert rather than 1359
        // on the four-value trigger of the same name that 2026_08_18_110000 created.
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON ".self::ROWS."
             FOR EACH ROW
             BEGIN
                {$body}
             END"
        );
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
                .'record this migration as applied: the outcome domain it claims to widen is absent, '
                .'and on MySQL 5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== self::ROWS) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on ".self::ROWS.'. A trigger with the right '
                .'name and the wrong timing or event fires on writes nobody guarded and misses the '
                .'ones they did.'
            );
        }
    }
};
