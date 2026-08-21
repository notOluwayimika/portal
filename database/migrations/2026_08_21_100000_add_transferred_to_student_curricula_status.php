<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `student_curricula.status` gains `transferred` — the terminal status a reassignment leaves behind.
 *
 * WHY A NEW VALUE RATHER THAN REUSING ONE. The reassignment service vacates a pupil's episode when
 * they are moved to another curriculum (a wrong arm corrected, a rebalance, an over-promoted pupil
 * sent back a level). The vacated episode has to end with a terminal status, and none of the four
 * existing values tells the truth: `withdrawn` says the pupil left the school, which is precisely
 * what did NOT happen; `repeated` and `promoted` name different transitions entirely.
 *
 * The vocabulary is not new — CurriculumEnrollmentService::softEnd's docblock has named the Option-B
 * terminal set as "completed/withdrawn/repeated/promoted/transferred" since it was written. The enum
 * has simply been behind its own stated design; this closes that gap for the one value now needed.
 * `completed` is deliberately NOT added: nothing writes it yet, and an unused enum value is a
 * decision made in advance of the case that would justify it.
 *
 * ── THE VALUE GUARD HERE IS THE COLUMN TYPE, WHICH IS WHY THIS IS AN ALTER AND NOT A TRIGGER ───────
 * `status` is a native `enum('active','promoted','repeated','withdrawn')`. There is no CHECK on this
 * column and no value-guard trigger — the two triggers on the table are the promotion-link pair from
 * 2026_08_20_140000, which inspect only `promoted`/`promoted_to_id`.
 *
 * That distinction matters on this project. A CHECK would be inert on production's MySQL 5.7.23
 * (enforced only from 8.0.16 — the defect 2026_08_17_100000 and 2026_08_20_140000 exist to repair).
 * An ENUM is a COLUMN TYPE, so it is enforced on both servers, and extending it is an additive
 * `ALTER ... MODIFY COLUMN` that lands identically on 5.7 and 8.0. No existing row can violate the
 * new definition, because the value set only grows.
 *
 * MEASURED, NOT ASSUMED (8.0.43, 2026-08-21): with `sql_mode` including STRICT_TRANS_TABLES, writing
 * an unknown value is REJECTED — driver code 1265, "Data truncated for column 'status'". That is the
 * enum behaving as a guard rather than as documentation.
 *
 * ⚠️ AND THE LIMIT OF THAT MEASUREMENT, WHICH BELONGS IN THE DEPLOY NOTES. An enum only REJECTS an
 * unknown value under strict mode; a non-strict server coerces it to the empty string instead,
 * leaving an episode in no valid state at all. Local sql_mode was read directly and is strict.
 * Production's has NOT been read from here and is not inferable. Adding `transferred` is safe either
 * way — the value becomes legal on any mode — but whether this column defends itself against OTHER
 * bad writes is unknown until someone reads `SELECT @@GLOBAL.sql_mode` on production. Capture it at
 * deploy, alongside the promoted-link violating-row count from 2026_08_20_140000.
 *
 * down() narrows the enum again. It is safe only while no row holds the value, so it asserts that
 * rather than trusting it — a narrowing ALTER over a live `transferred` row would silently truncate
 * it to '' under a non-strict server, which is exactly the failure this file documents.
 */
return new class extends Migration
{
    private const TABLE = 'student_curricula';

    private const WITH_TRANSFERRED = "enum('active','promoted','repeated','withdrawn','transferred')";

    private const WITHOUT_TRANSFERRED = "enum('active','promoted','repeated','withdrawn')";

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.self::TABLE.' MODIFY COLUMN status '.self::WITH_TRANSFERRED." NOT NULL DEFAULT 'active'"
        );

        $this->assertEnumContains('transferred', true);
    }

    public function down(): void
    {
        $stranded = (int) DB::table(self::TABLE)->where('status', 'transferred')->count();

        if ($stranded > 0) {
            throw new RuntimeException(
                "Refusing to narrow student_curricula.status: {$stranded} row(s) hold 'transferred'. "
                .'Narrowing the enum over them truncates the value — silently, to the empty string, on a '
                .'non-strict server. Re-point or re-status those episodes first.'
            );
        }

        DB::statement(
            'ALTER TABLE '.self::TABLE.' MODIFY COLUMN status '.self::WITHOUT_TRANSFERRED." NOT NULL DEFAULT 'active'"
        );

        $this->assertEnumContains('transferred', false);
    }

    /**
     * Read the column definition back. ADR 0052: a statement that returned success is not evidence of
     * a shape, and an ALTER that silently kept the old definition would leave every reassignment
     * writing a value the column cannot hold.
     */
    private function assertEnumContains(string $value, bool $expected): void
    {
        $type = (string) DB::scalar(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, 'status'],
        );

        if (str_contains($type, "'{$value}'") !== $expected) {
            throw new RuntimeException(
                "student_curricula.status is [{$type}] after the ALTER returned success — expected it to "
                .($expected ? 'CONTAIN' : 'NOT contain')." '{$value}'."
            );
        }
    }
};
