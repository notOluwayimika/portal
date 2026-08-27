<?php

use App\Enums\ScholarshipKind;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scholarships.kind` — WHICH SCHEME a scholarship is, so the bulk invoice run can stop billing the
 * students it must not bill. See {@see ScholarshipKind} for what the two values mean.
 *
 * ── IT BACKFILLS TO NULL, AND THE ABSENT DEFAULT IS THE POINT ────────────────────────────────────
 *
 * Nothing in the existing data says which scheme any scholarship is: `scholarships` held
 * `id, uuid, school_id, name, timestamps` and nothing else. So there is no value that could be
 * inferred, and `->default('discount')` would not be a convenience — it would be a GUESS written
 * into every existing row, silently, by a migration. The wrong guess bills a sponsored child full
 * price on a run that reports success, which is the failure this whole change exists to stop.
 *
 * NULL therefore means "nobody has configured this yet", it is not a third scheme, and it is made
 * LOUD rather than tolerated: {@see ProcessBulkInvoiceRun} refuses the whole run,
 * before its first row, when a cohort holds an unconfigured scholarship.
 *
 * ── MIGRATED IN PLACE ────────────────────────────────────────────────────────────────────────────
 *
 * `ALTER TABLE … ADD COLUMN` only. The table is NOT dropped and recreated, because
 * `students.scholarship_id` is a live FK onto `scholarships.id` (`2026_06_15_000006`), so the ids
 * must stay stable and every existing assignment must survive untouched. A drop-and-recreate would
 * either be refused by the FK or, worse, take the assignments with it.
 *
 * ── THE DOMAIN IS A TRIGGER, NOT A `CHECK` ───────────────────────────────────────────────────────
 *
 * Production is MySQL 5.7.23, which PARSES AND IGNORES `CHECK`
 * (docs/finance/check-constraints-on-mysql-5-7.md). A `CHECK (kind IN (...))` would be enforced on
 * 8.0.43 locally, absent on the server that holds the money, and green in both places. So the domain
 * is held by a BEFORE INSERT / BEFORE UPDATE trigger pair signalling SQLSTATE '45000', following
 * `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` and
 * `2026_08_25_100000_finance_payment_origin_admits_gateway.php` exactly. That is the precedent this
 * migration follows, and it is the one the repository uses for every small closed value set on a
 * money-adjacent table — `finance_bulk_invoice_runs.status`,
 * `finance_bulk_invoice_run_rows.outcome` and `finance_payments.origin` are all held this way.
 *
 * THE THREE LOAD-BEARING PIECES, CARRIED VERBATIM FROM THAT FAMILY:
 *
 * 1. `NEW.kind IS NULL OR …` — the arm that ADMITS the unconfigured state. This predicate differs
 *    from its siblings in exactly one way and it is this one: `status` and `origin` are NOT NULL
 *    columns whose triggers reject NULL, whereas here NULL is the legitimate, deliberate backfill.
 *    Without this arm the migration would refuse to add its own column's backfilled rows on the next
 *    update of any of them.
 *
 * 2. `COLLATE utf8mb4_bin`, load-bearing. Under the table's `utf8mb4_unicode_ci`,
 *    `kind = 'sponsored'` also matches `'Sponsored'` and `'SPONSORED'` — values every
 *    `where('kind', 'sponsored')` read would MISS while the guard read green. That is precisely the
 *    shape that would let a sponsored student be billed: the row looks configured, the exclusion
 *    filter does not match it.
 *
 * 3. `COALESCE(…, 0)`, load-bearing behind the NULL arm. Kept for the reason the family keeps it: a
 *    three-valued `IN` result that is NULL makes a bare `IF NOT (…)` fall through. Here the NULL arm
 *    already short-circuits that case, so this is the belt behind a brace that is holding — it costs
 *    nothing and it survives someone rewriting the arm.
 *
 * NO APOSTROPHE IN `MESSAGE_TEXT`: MySQL stores a trigger body with the escape STRIPPED, so an
 * apostrophe leaves the body un-dumpable (`TriggerBodiesAreDumpSafeTest`). The sentence below is
 * **88 characters**, counted rather than eyeballed, against the 128-character cap that
 * `2026_08_25_100000` measured — past it, `SIGNAL` itself fails with 1648/HY000 and the guard stops
 * speaking its own refusal.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). `CREATE TRIGGER` returning success is not evidence
 * that the right trigger exists — a mis-named, mis-timed or mis-evented trigger is created just as
 * successfully as a right one. Each `CREATE` is read back out of `information_schema.TRIGGERS` and
 * this migration THROWS unless the timing, event and table are what was asked for, leaving itself
 * unrecorded rather than recording a green that means nothing.
 *
 * EVERY DDL STATEMENT IS INDIVIDUALLY RE-RUNNABLE. MySQL commits DDL implicitly and Laravel records
 * a migration only after `up()` RETURNS, so an abort part-way leaves the schema changed and the
 * `migrations` table disagreeing with it
 * (docs/handoff/tickets/aborted-migration-leaves-schema-changed-and-unrecorded.md). The read-backs
 * are exactly the statements that can abort, so the column add is guarded on `Schema::hasColumn` and
 * each `CREATE TRIGGER` is preceded by `DROP TRIGGER IF EXISTS`. Re-runnability is not atomicity and
 * this migration does not claim to have solved that standing condition; it claims only that the
 * retry works.
 */
return new class extends Migration
{
    private const TABLE = 'scholarships';

    private const STEM = 'scholarships_kind_shape';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'kind')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // NULLABLE, NO DEFAULT, and no ->change() anywhere: this is an ADD on a live table
                // whose ids are pointed at by students.scholarship_id. Existing rows get NULL.
                $table->string('kind', 32)->nullable()->after('name');
            });
        }

        $this->installTrigger('INSERT');
        $this->installTrigger('UPDATE');
    }

    /**
     * DROP THE TRIGGERS, THEN THE COLUMN — the literal inverse, and honest on both servers.
     *
     * Unlike the `CHECK`-to-trigger family, nothing here is a REPAIR of an existing object: the
     * column and its guard both arrive in this migration, so removing both is exactly the state
     * before it. No `CHECK` is restored and none should be — a restored `CHECK` is real on 8.0.43
     * and a silent no-op on 5.7.23, which is the asymmetry this family exists to remove.
     *
     * THE RESIDUAL, NAMED HONESTLY: rolling this back DISCARDS every configured `kind`. That is
     * inherent to dropping the column that stores them, not a defect in the rollback — but it means
     * a roll-back-and-re-up returns the table to the fully-unconfigured state, and the bulk run will
     * then refuse every cohort holding a scholarship until they are configured again. That is the
     * correct behaviour (a refusal, never a fall-through), and it is what `bin/quality-clean-db`'s
     * rollback/re-up leg exercises.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('INSERT'));
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('UPDATE'));

        if (Schema::hasColumn(self::TABLE, 'kind')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }

    /**
     * The predicate, as one heredoc so the INSERT and UPDATE bodies cannot drift from each other.
     *
     * The NULL arm, `COLLATE` and `COALESCE` are all explained in the class docblock. None of the
     * three is stylistic and none may be dropped.
     */
    private function body(): string
    {
        $values = "'".implode("','", ScholarshipKind::values())."'";

        return <<<SQL
            IF NOT COALESCE(
                   NEW.kind IS NULL
                OR NEW.kind COLLATE utf8mb4_bin IN ({$values}), 0) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'scholarships: kind must be discount or sponsored, or null when it is not configured yet.';
            END IF;
            SQL;
    }

    /** `{stem}_bi` / `{stem}_bu`, following `guardian_student_same_school_bi` and the trigger family. */
    private function triggerName(string $event): string
    {
        return self::STEM.($event === 'INSERT' ? '_bi' : '_bu');
    }

    private function installTrigger(string $event): void
    {
        if (! Schema::hasColumn(self::TABLE, 'kind')) {
            return;
        }

        $name = $this->triggerName($event);
        $body = $this->body();

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
                .'record this migration as applied: the scholarship kind domain it claims to hold is '
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
