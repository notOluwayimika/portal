<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two class_levels progression invariants, AS TRIGGERS — because a CHECK cannot carry either.
 *
 * This migration was commissioned as a one-line `CHECK (next_class_level_id <> id)`. It is not that,
 * for two independent reasons, each of which alone is fatal to the CHECK:
 *
 * ── 1. MySQL REFUSES THIS PARTICULAR CHECK OUTRIGHT ────────────────────────────────────────────────
 * `ALTER TABLE class_levels ADD CONSTRAINT ... CHECK (next_class_level_id <> id)` fails on 8.0.43 with
 *
 *     SQLSTATE[HY000]: 3818 Check constraint '...' cannot refer to an auto-increment column.
 *
 * `class_levels.id` is AUTO_INCREMENT, and MySQL forbids naming such a column in a CHECK at all. This
 * is not a style preference or a version nuance — the statement is rejected. MEASURED on 8.0.43 by
 * running it; the migration failed and was rewritten into this file.
 *
 * ── 2. AND EVEN A LEGAL CHECK WOULD BE ABSENT WHERE IT MATTERS ─────────────────────────────────────
 * Production is **MySQL 5.7.23** (local is 8.0.43) — both readings recorded by the project lead in
 * `2026_08_17_100000`, which converted seven finance CHECKs to triggers for exactly this reason.
 * MySQL enforces CHECK only from 8.0.16; before that the clause is, in MySQL's own words, "parsed and
 * ignored". So a CHECK here would be enforced on the developer's machine and silently do nothing on
 * the server that holds the data — the precise defect that migration exists to repair.
 *
 * That second reason also condemns a CHECK THIS BRANCH ALREADY SHIPPED.
 * `2026_08_20_110000` added `class_levels_arm_distribution_strategy_check`, constraining
 * `arm_distribution_strategy` to `round_robin | explicit_only`. It was bite-proven on 8.0.43 and
 * reported as enforced; on 5.7 it is not enforced at all. Rather than leave a guard that is real in
 * one environment and imaginary in the other, it is DROPPED here and re-expressed as the same trigger
 * pair. One mechanism, both environments. The 110000 migration is left untouched (it is already
 * pushed, and the house discipline is to supersede rather than edit an applied migration).
 *
 * ── WHAT THE TRIGGERS ENFORCE ─────────────────────────────────────────────────────────────────────
 * BEFORE INSERT and BEFORE UPDATE on class_levels, following the `{stem}_bi` / `{stem}_bu` naming of
 * `2026_08_17_100000` and `2026_07_16_000003`:
 *
 *   a. next_class_level_id <> id      — a level may not progress into itself.
 *   b. arm_distribution_strategy IN ('round_robin', 'explicit_only').
 *
 * NULL IS NOT A VIOLATION for (a): a terminal/graduating year has `next_class_level_id IS NULL`, and
 * the guard tests `IS NOT NULL AND =` so that a NULL is passed through untouched — the same shape the
 * maker/checker trigger uses for its two nullable columns. Proven by planting a NULL, not reasoned.
 *
 * ON INSERT, `NEW.id` is 0 for an auto-increment row, so (a) can only ever fire on an INSERT that
 * names an explicit id equal to its own next pointer. That is a real path (seeders, imports) and is
 * guarded; the LIKELY path — an operator self-selecting in a dropdown on an existing row — is the
 * UPDATE trigger, which is where NEW.id is the row's real id.
 *
 * COLLATE utf8mb4_bin on the strategy comparison, per rule 7 of `2026_08_17_100000`: a string
 * comparison in a trigger body must not depend on the column's collation. (a) compares integers, so
 * no COLLATE applies there and it is deliberately absent.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES *NOT* BUY — read before crossing anything off the Part 3 list.
 *
 * Guard (a) kills the SELF-LOOP (A -> A) and NOTHING ELSE. It is evaluated per ROW, so a multi-node
 * cycle — A -> B -> A, or any longer ring — satisfies it at every row and is ACCEPTED. Pupils in such
 * a ring would ping-pong between levels year after year. Detecting that requires walking the chain:
 * job/validation work, NOT discharged here. The bite-proof asserts A -> B -> A is accepted, so the
 * limit is a recorded fact rather than an assumption.
 *
 * Nothing here constrains `class_level_arm_progressions`: an arm map whose target sits in a level
 * that is not the source's `next_class_level_id` remains legal, and remains the sharpest validation
 * the end-of-year job owes.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    private const TABLE = 'class_levels';

    private const STEM = 'class_levels_progression_guard';

    private const SUPERSEDED_CHECK = 'class_levels_arm_distribution_strategy_check';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'next_class_level_id')) {
            return;
        }

        // Enforced on 8.0, never materialised on 5.7 — replaced by the trigger pair below.
        $this->dropCheckIfPresent(self::TABLE, self::SUPERSEDED_CHECK);

        // MESSAGE_TEXT is capped at 128 characters by MySQL and silently truncated past it; both
        // sentences below are counted, not eyeballed (78 and 96).
        $body = <<<'SQL'
            IF NEW.next_class_level_id IS NOT NULL AND NEW.next_class_level_id = NEW.id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'class_levels: a class level cannot progress into itself.';
            END IF;
            IF NEW.arm_distribution_strategy COLLATE utf8mb4_bin NOT IN ('round_robin', 'explicit_only') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'class_levels: arm_distribution_strategy must be round_robin or explicit_only.';
            END IF;
            SQL;

        $this->installTrigger('INSERT', $body);
        $this->installTrigger('UPDATE', $body);
    }

    /**
     * Drops both triggers. Deliberately does NOT restore the superseded CHECK: it was unenforceable on
     * production, and a rollback that reinstates an imaginary guard is worse than one that leaves the
     * invariant to the application. Rolling this back therefore leaves `arm_distribution_strategy`
     * unguarded at the database level — stated plainly rather than papered over.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('INSERT'));
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('UPDATE'));
    }

    private function triggerName(string $event): string
    {
        return self::STEM.($event === 'INSERT' ? '_bi' : '_bu');
    }

    /**
     * Create one trigger idempotently, then PROVE it is there — name, timing and event — from
     * information_schema. ADR 0052: a statement that returned success is not evidence of a shape.
     */
    private function installTrigger(string $event, string $body): void
    {
        $name = $this->triggerName($event);
        $table = self::TABLE;

        // Idempotent, so the rollback/re-up leg of bin/quality-clean-db re-asserts rather than 1359s.
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
             FOR EACH ROW
             BEGIN
                {$body}
             END"
        );

        $read = DB::selectOne(
            'SELECT ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$name],
        );

        if ($read === null) {
            throw new RuntimeException(
                "Trigger [{$name}] does not exist after CREATE TRIGGER returned success. Refusing to "
                .'record this migration as applied: the guard it claims to install is absent, and on '
                .'5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== $table) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on {$table}."
            );
        }
    }

    /**
     * Guard shape from `2026_08_17_100000`. EXPECTED to return 0 on 5.7 — where the constraint was
     * parsed and discarded, so there is nothing to drop — which is what keeps the 8.0.16-only
     * `DROP CHECK` from ever being issued there. Measured on 8.0.43 only.
     */
    private function dropCheckIfPresent(string $table, string $check): void
    {
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $check, 'CHECK'],
        );

        if ($exists > 0) {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK {$check}");
        }
    }
};
