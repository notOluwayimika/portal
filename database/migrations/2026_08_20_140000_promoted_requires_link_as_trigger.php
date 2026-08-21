<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `student_curricula_promoted_requires_link` becomes a TRIGGER — it has never run on production.
 *
 * 2026_07_30_100000 added `CHECK (status <> BINARY 'promoted' OR promoted_to_id IS NOT NULL)` as the
 * structural close of the S1 promotion-link work, after an invariant "asserted in code at three sites
 * and MISSED at two" was judged to belong in the schema. That reasoning was right. The mechanism was
 * not available: production is **MySQL 5.7.23** (local 8.0.43 — both readings recorded by the project
 * lead in 2026_08_17_100000), and MySQL enforces CHECK only from **8.0.16**. Before that the clause is,
 * in MySQL's own words, "parsed and ignored" — accepted, absent from SHOW CREATE TABLE, never
 * evaluated.
 *
 * So since 2026-07-30 this invariant has been enforced on every developer's machine and on NOTHING in
 * production. 2026_08_17_100000 converted seven finance CHECKs for exactly this reason and documented
 * the rest for follow-up; this is one of the rest, and it is now urgent for a specific reason:
 * MoveFromTermJob (#270) has just become the FOURTH writer of promoted rows, Part 3 adds a fifth, and
 * Part 4's reassignment service REPOINTS promoted_to_id. Every one of those relies on the writer
 * remembering to set link and status in one statement. That is the "asserted in N places, enforced in
 * none" shape the original closure existed to end.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════
 * THE OPERATIONAL POINT, WHICH MATTERS MORE THAN THE TRIGGER.
 *
 * `ADD CONSTRAINT ... CHECK` VALIDATES THE WHOLE TABLE at creation: on 8.0 it refuses to install over
 * a single violating row. **A TRIGGER DOES NOT.** BEFORE INSERT/UPDATE guards FUTURE writes only, and
 * will happily enforce going forward over a table that already contains violations — producing a
 * green migration and a clean-looking guard over dirty history.
 *
 * And production has had NO enforcement for this entire window. Any writer that missed the atomic
 * write since 2026-07-30 left a `promoted`-with-NULL-link row that is still sitting there. The trigger
 * will not retroactively catch it.
 *
 * Hence the PRE-FLIGHT GUARD below, modelled on the one 2026_07_30_100000 carries for the same reason:
 * COUNT the violating rows and FAIL LOUDLY naming the count and the repair command, rather than
 * installing a guard that blesses existing violations. `academics:backfill-promotion-links` is the
 * repair (dry run by default, `--commit` to write); it is re-runnable, spans all schools, and exits
 * FAILURE while any unresolvable orphan remains, so the deploy stays blocked until a human rules on
 * them.
 *
 * THE COUNT IS ALSO THE EVIDENCE. If production returns non-zero here, the enforcement gap produced
 * real rows rather than a theoretical risk — record the number in the PR when this is deployed.
 * Locally it is 0 (of 366 promoted rows), because the July backfill already ran here.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY THE CHECK IS DROPPED RATHER THAN KEPT ALONGSIDE. One mechanism, both environments. Keeping both
 * means 8.0 reports a CHECK violation and 5.7 reports a trigger's SIGNAL for the same bad write, and
 * — worse — leaves the next reader with the same false comfort this migration exists to remove: a
 * constraint visible in the schema that does nothing where it matters. The drop is guarded, because
 * on 5.7 there is no constraint to drop (it was never materialised) and `DROP CHECK` is 8.0.16-only.
 *
 * BINARY / COLLATE on the status comparison — the house rule from 2026_08_17_100000 rule 7, and the
 * original CHECK used `BINARY 'promoted'` for the same reason. Kept so the guard cannot start
 * depending on the column's collation.
 */
return new class extends Migration
{
    private const TABLE = 'student_curricula';

    private const STEM = 'student_curricula_promoted_requires_link';

    private const SUPERSEDED_CHECK = 'student_curricula_promoted_requires_link';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->assertNoViolatingRows();

        // On 5.7 this is a no-op: the constraint was parsed and discarded, so there is nothing for
        // TABLE_CONSTRAINTS to report and the 8.0.16-only DROP CHECK is never issued.
        $this->dropCheckIfPresent(self::TABLE, self::SUPERSEDED_CHECK);

        // MESSAGE_TEXT is capped at 128 characters by MySQL and silently truncated past it; the
        // sentence below is 104, counted rather than eyeballed.
        $body = <<<'SQL'
            IF NEW.status COLLATE utf8mb4_bin = 'promoted' AND NEW.promoted_to_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_curricula: a promoted episode must carry its promoted_to_id link.';
            END IF;
            SQL;

        $this->installTrigger('INSERT', $body);
        $this->installTrigger('UPDATE', $body);
    }

    /**
     * Refuse to install a forward-only guard over a table that already violates it.
     *
     * A trigger cannot validate history, so this is the only moment the whole table is checked. On a
     * fresh test DB and on any environment where the July backfill ran, the count is 0 and this is a
     * no-op.
     */
    private function assertNoViolatingRows(): void
    {
        $violating = (int) DB::table(self::TABLE)
            ->whereRaw("status = BINARY 'promoted'")
            ->whereNull('promoted_to_id')
            ->count();

        if ($violating > 0) {
            throw new RuntimeException(
                "Refusing to install the {$this->stem()} trigger: {$violating} student_curricula row(s) are "
                ."status='promoted' with a NULL promoted_to_id. A TRIGGER GUARDS FUTURE WRITES ONLY — unlike "
                .'the CHECK it replaces, it will not reject these, so installing it now would bless them. '
                .'Run `php artisan academics:backfill-promotion-links` (dry run first, then --commit), resolve '
                .'any orphans it reports, then re-run this migration. These rows exist because the CHECK '
                .'added on 2026-07-30 has never been enforced on MySQL 5.7.'
            );
        }
    }

    private function stem(): string
    {
        return self::STEM;
    }

    /**
     * Drops both triggers. Deliberately does NOT restore the CHECK: it is unenforceable on production,
     * and a rollback that reinstates a guard which does nothing there is worse than one that leaves
     * the invariant to the application. Rolling this back therefore leaves the invariant to the
     * writers — stated plainly rather than papered over.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('INSERT'));
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName('UPDATE'));
    }

    /**
     * `{stem}_bi` / `{stem}_bu`, following 2026_08_17_100000 and 2026_07_16_000003. The longest name
     * this produces is 51 characters, under MySQL's 64-char identifier cap.
     */
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
                .'record this migration as applied: the guard it claims to install is absent, and the '
                .'CHECK it replaces is inert on 5.7, so nothing would be enforcing this at all.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== $table) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on {$table}. A trigger with the right name and "
                .'the wrong timing or event fires on writes nobody guarded and misses the ones they did.'
            );
        }
    }

    /**
     * Guard shape from 2026_08_17_100000. EXPECTED to return 0 on 5.7 — where the constraint was
     * parsed and discarded, so there is nothing to drop. Measured on 8.0.43 only.
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
