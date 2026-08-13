<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THREE COLUMNS CARRY `ON UPDATE CURRENT_TIMESTAMP` ON PRODUCTION. Nobody wrote it. This takes it
 * off all three. THIS MIGRATION IS THE FIX; the line added to `NoticeController::end()` alongside it
 * is a belt over one write path, not the remedy.
 *
 *     notices.starts_at                        default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
 *     notification_actions.expires_at          default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
 *     finance_ledger_transactions.posted_at    default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
 *
 * PRODUCTION WAS READ, 2026-08-13, by the project lead. An earlier reading said "exactly one row"
 * and that reading was of the LOCAL COPY `portaa10_portal`, presented as a completeness claim about
 * production. It was not one. The copy is not a faithful witness for this attribute and the reason
 * generalises: tables restored from a production dump carry production's MATERIALISED shape, while
 * tables created afterwards by running migrations locally carry THIS host's — and this host has
 * `explicit_defaults_for_timestamp = ON`, so they come out clean. `notification_actions` (created
 * 2026-08-04) and `finance_ledger_transactions.posted_at` (added 2026-08-09) post-date the dump, and
 * that is exactly why they read clean on the copy and dirty on production. **A copy proves nothing
 * about a table younger than the copy.**
 *
 * NOBODY WROTE THE ATTRIBUTE — it is assigned by the server. With `explicit_defaults_for_timestamp`
 * OFF, MySQL assigns `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` to the FIRST `TIMESTAMP`
 * column of a table when that column declares neither and is not declared `NULL`. All three
 * declarations below are plain `->timestamp()` / `->timestampTz()`, and all three columns are
 * positionally the first `TIMESTAMP` in their table (ordinals 8, 8 and 11 respectively, each ahead of
 * its own `created_at`) — which is what makes them members rather than coincidences. The attribute
 * lives in the SCHEMA, not in the SOURCE, so no source-reading lint in this repo can see it
 * (ADR 0053). Written up in `docs/handoff/tickets/implicit-timestamp-defaults-on-rebuild.md`, which
 * ships with this migration.
 *
 * WHY EACH ONE IS HERE — they are NOT alike, and shipping only the loud one is the shape that gets
 * forgotten:
 *
 *   1. `notices.starts_at` — LIVE DEFECT, established and armed. `starts_at` is a start time an
 *      admin typed by hand and the schema holds no second copy, so every UPDATE omitting it (the
 *      `end()` route; `update()` on any edit that leaves the start time alone, because Eloquent drops
 *      a clean attribute from the SET list) silently replaced it with the server clock. Watched move
 *      by 19,323,776 s under the imposed clause. `tests/Feature/Notices/NoticeEndPreservesStartsAtTest.php`
 *      is the behavioural arm for this one column.
 *
 *   2. `notification_actions.expires_at` — LIVE, AND NOT COSMETIC. `NotificationActionResolver::resolve()`
 *      claims a tapped action with ONE conditional UPDATE whose `WHERE` reads `expires_at > ?`, and
 *      its docblock calls that statement the entire concurrency design
 *      (`app/Notifications/Services/NotificationActionResolver.php:14-24,60-68`). Neither that
 *      statement nor the two later writes (`:102-108`, `:127-143`) sets `expires_at`, so on
 *      production every state change rewrote the expiry to the server clock. The GUARD itself is
 *      intact — MySQL evaluates the `WHERE` against the pre-update row, so the claim decision is
 *      correct — but the same statement destroys the value it just decided on. What that costs
 *      today is bounded and was measured rather than assumed: nothing reads `expires_at` after the
 *      first claim. `isClaimable()` (`app/Notifications/Models/NotificationAction.php:68-72`) is the
 *      only other reader and has NO production caller (tests only); the feed resource and the tap
 *      endpoint's JSON never expose it. What it costs tomorrow is not bounded: the reconciliation
 *      pass the resolver's own docblock anticipates for `RESOLVING`/`UNCONFIRMED` rows would be the
 *      first post-claim reader, and on production it would find, on every already-resolved row, an
 *      `expires_at` equal to the moment of the last write rather than the window that was offered.
 *      Unlike #3 there is no second line of defence here: the table has no immutability trigger and
 *      the row is updated BY DESIGN.
 *
 *   3. `finance_ledger_transactions.posted_at` — BENIGN, AND FIXED ANYWAY. The table's `no_update`
 *      trigger (SQLSTATE 45000) makes any UPDATE impossible, so `ON UPDATE` is structurally
 *      unreachable, and both writers (`SubledgerPoster::post()`, `PostOpeningBalanceBatch`) supply
 *      `posted_at` explicitly, so the implicit `DEFAULT` never fires either. Nothing is at risk
 *      today. It is fixed because THE MARGIN IS A TRIGGER RATHER THAN THE SCHEMA — the column is
 *      safe by something written for a different reason, which is a margin that a future
 *      append-only exemption, a bulk correction path or a `TRIGGER`-dropping restore removes without
 *      anyone connecting the two. And nobody wrote the attribute, so nobody will remember it is
 *      there. Zero rows on production, so the `ALTER` is instant.
 *
 * THIS MIGRATION NEEDS NOTHING BUT `ALTER`, and that is a requirement rather than an accident. An
 * earlier draft set `explicit_defaults_for_timestamp` for the session issuing the DDL — measurably
 * the cleanest result (`default=NULL, extra=''`) and unusable, because `SET SESSION` on that
 * variable requires the dynamic privilege `SESSION_VARIABLES_ADMIN`.
 *
 * THE GRANT WAS READ, not predicted. Production deploy user, 2026-08-13:
 *
 *     GRANT USAGE ON *.* TO 'portaa10'@'localhost'
 *     GRANT ALL PRIVILEGES ON `portaa10_portal`.* TO 'portaa10'@'localhost'
 *     (plus ALL PRIVILEGES on ~17 dated backup schemas)
 *
 * `USAGE` is the empty global grant, and dynamic privileges can only be granted at `*.*` — so a user
 * whose sole global grant is `USAGE` holds none of them. The session-setting draft would have been
 * REFUSED on production, not merely at risk of it, and `php artisan migrate` would have exited
 * non-zero mid-release with this migration unrecorded. Schema-level `ALL PRIVILEGES` includes
 * `ALTER`, which is why the formulation below is available at all. Record the grant list rather than
 * an inference about shared hosting: the next person who reaches for `SET SESSION` in a migration
 * should meet the actual reading.
 *
 * WHY EVERY COLUMN KEEPS A SENTINEL DEFAULT, MEASURED RATHER THAN ARGUED. Four formulations, on
 * MySQL 8.0.43, against scratch tables AND against the real columns, with the session setting forced
 * OFF by the HARNESS to make the hostile condition exist at all (this host is ON, so every
 * formulation looks clean until it is forced):
 *
 *     MODIFY TIMESTAMP NOT NULL                          default='CURRENT_TIMESTAMP'    extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'   <- UNCHANGED. The trap.
 *     MODIFY TIMESTAMP NOT NULL DEFAULT <server clock>   default='CURRENT_TIMESTAMP'    extra='DEFAULT_GENERATED'                               <- works, but a server-clock default
 *     MODIFY … DEFAULT '1970-01-02 00:00:01'             default='1970-01-02 00:00:01'  extra=''                                                <- works, ALTER only            <== SHIPPED
 *       …then ALTER COLUMN … DROP DEFAULT                default=NULL                   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'   <- RE-ADDS BOTH. Hypothesis dead.
 *
 * The last line is the one worth keeping. "Set an explicit default to suppress the implicit
 * attributes, then drop the default you did not want" is the obvious way to end up with a clean
 * column using only `ALTER`, and it does not work: `DROP DEFAULT` leaves the column declaring
 * neither attribute again, and an OFF server immediately re-applies both. So on an OFF host there
 * is no reachable state that has neither the clause nor a default. **Keeping the default is the
 * price of removing the clause without a privilege**, and it is the right trade.
 *
 * WHAT THE SENTINEL COSTS, PER COLUMN, STATED RATHER THAN ASSUMED. A default only fires for an
 * INSERT that omits the column, and no writer omits any of the three: `NoticeController::store()`
 * validates `starts_at` as `required|date`; `NotificationAction` is written with `expires_at` at
 * creation; `SubledgerPoster::post()` and `PostOpeningBalanceBatch` both assign `posted_at`. All
 * three columns stay `NOT NULL`. What changes is the failure mode of a FUTURE writer that forgets
 * the column: on a clean column that is a loud `1364 Field '…' doesn't have a default value`, and
 * here it becomes a silent row dated 1970 — an unclaimable action, a ledger line posted before the
 * epoch, a notice scheduled in 1970. That is a real regression in one narrow case per column, and it
 * is preferred to the alternative it replaces: a row silently dated *now*, which looks correct and
 * is indistinguishable from real data. A 1970 date is wrong on sight.
 *
 * `1970-01-02`, not `1970-01-01`: `TIMESTAMP`'s lower bound is `1970-01-01 00:00:01` UTC and a
 * default is interpreted in the session zone, so a day of margin keeps the value legal in every zone
 * rather than only in ones at or ahead of UTC.
 *
 * NULLABILITY IS UNCHANGED on all three, on purpose, and asserted below. `NOT NULL` is correct for a
 * scheduled start time, an expiry window and a posting date alike; changing any of them is a
 * different decision with its own consequences (for `notices`, the `(school_id, starts_at, ends_at)`
 * index and both `Notice` scopes; for `notification_actions`, the `notification_actions_claimable`
 * index on `(status, expires_at)`).
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). `ALTER` succeeds whether or not it achieved
 * anything, and on an OFF host the wrong `ALTER` succeeds while changing nothing — the one failure
 * mode this migration exists to prevent is the one a zero exit status cannot distinguish. So up()
 * reads `information_schema` after EACH `ALTER` and throws if the clause survived, if the column
 * stopped being `NOT NULL`, or if the sentinel did not land, which leaves the migration unrecorded
 * rather than recording a green that means nothing. Idempotent: re-applying the same `ALTER` is a
 * no-op on the target shape, measured on all three.
 *
 * A MISSING TABLE OR A MISSING COLUMN IS SKIPPED, NOT AN ERROR, and the second half of that was
 * learned by watching it break. `brookstone_portal_db` is behind on migrations: it has no
 * `notification_actions` table at all, and it HAS `finance_ledger_transactions` WITHOUT `posted_at`.
 * A table-only guard let the run reach `ALTER TABLE finance_ledger_transactions MODIFY posted_at`
 * and die on `1054 Unknown column`, after the `notices` ALTER had already committed — half applied,
 * and unrecorded. See the guard in up().
 */
return new class extends Migration
{
    /**
     * The sentinel. Not a date anyone should read as data — see the docblock.
     */
    private const SENTINEL = '1970-01-02 00:00:01';

    /**
     * The three columns, and nothing else. Derived from `information_schema` on production
     * (2026-08-13) and cross-checked against the positional rule in the docblock, NOT from a grep
     * over migrations: the attribute is a property of the live schema, and a source-derived list
     * would name declarations that are vulnerable rather than columns that are dirty.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const COLUMNS = [
        ['notices', 'starts_at'],
        ['notification_actions', 'expires_at'],
        ['finance_ledger_transactions', 'posted_at'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            // BOTH GUARDS ARE LOAD-BEARING, and the second was added after watching the first one
            // fail to hold. `hasTable` alone is not enough: `finance_ledger_transactions` EXISTS on
            // `brookstone_portal_db` while `posted_at` does not, because that column arrives in
            // `2026_08_09_120000_finance_capture_columns_s2_s3.php` and that database is behind. The
            // run died with `1054 Unknown column 'posted_at'` after the `notices` ALTER had already
            // committed — a migration half-applied and unrecorded, which is the failure mode this
            // whole change exists to avoid. On a correctly-ordered run neither guard fires; both are
            // for the environment that is mid-catch-up, which is precisely where a release breaks.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Interpolated, not bound: MySQL refuses a placeholder in a DDL DEFAULT clause (1064,
            // measured), and identifiers can never be bound. The table and column names come from
            // the class constant above and the value is a class constant, never request input, so
            // there is nothing to inject — but the reason it is not bound belongs here, since
            // binding is the house style everywhere else in this file.
            DB::statement(
                "ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT '".self::SENTINEL."'"
            );

            $this->assertShape($table, $column);
        }
    }

    /**
     * Read the column back and refuse to be recorded unless it is what the `ALTER` claimed.
     */
    private function assertShape(string $table, string $column): void
    {
        $read = DB::selectOne(
            'SELECT EXTRA AS extra, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        if ($read === null) {
            throw new RuntimeException(
                "{$table}.{$column} does not exist after the ALTER. The table was present and the "
                .'column was not: this migration names a column that has been renamed or dropped, '
                .'and its list must be corrected rather than the read relaxed.'
            );
        }

        if (str_contains(strtolower((string) $read->extra), 'on update')) {
            throw new RuntimeException(
                "{$table}.{$column} still carries ON UPDATE after the ALTER (EXTRA = {$read->extra}). "
                .'Refusing to record this migration as applied: the column would keep overwriting '
                .'its own value on any UPDATE whose SET list omits it.'
            );
        }

        if ($read->is_nullable !== 'NO') {
            throw new RuntimeException(
                "{$table}.{$column} is no longer NOT NULL after the ALTER (IS_NULLABLE = "
                .var_export($read->is_nullable, true).'). Nullability was not meant to change.'
            );
        }

        if ($read->column_default !== self::SENTINEL) {
            throw new RuntimeException(
                "{$table}.{$column} did not take the sentinel default (COLUMN_DEFAULT = "
                .var_export($read->column_default, true).', expected '.self::SENTINEL.'). '
                .'The default is what suppresses the implicit ON UPDATE on a host where '
                .'explicit_defaults_for_timestamp is OFF, so without it the clause can return.'
            );
        }
    }

    /**
     * DELIBERATE NO-OP, and the precedent is 2026_08_02_100000 / 2026_08_03_100000.
     *
     * The literal inverse of up() is "put `ON UPDATE CURRENT_TIMESTAMP` back on three columns
     * holding hand-entered start times, claim windows and posting dates", i.e. reinstate silent,
     * unrecoverable data loss on a live route. A rollback must not be able to do that by accident.
     * Roll forward with a new named migration if this ever needs revisiting.
     *
     * Consequence for the rollback/re-up leg of `bin/quality-clean-db`: down() leaves the columns
     * clean and up() is idempotent, so a re-up re-asserts the same shape and re-verifies it.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock above.
    }
};
