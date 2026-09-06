<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RETURN TO FINANCE — the second axis on an invoice, and the queue that reads it.
 *
 * `2026_08_31_100000` gave Internal Audit the RELEASE axis: `reviewed_at` NULL means raised but not
 * yet visible to the payer. It shipped the axis and the gate and nothing else, and it left the
 * auditor exactly one verb. This migration adds the other one. An auditor who finds a bill WRONG
 * must be able to send it back to Finance with a statement of why, and Finance must be able to find
 * what came back.
 *
 * This migration ships the columns, the index and the pairing guard. There is no return action, no
 * endpoint and no screen — those are the commits after this one.
 *
 * ── RETURNED IS AN AXIS, NOT AN `InvoiceStatus` CASE — THE SAME ARGUMENT `2026_08_31_100000` MADE ─
 *
 * The reasoning is that migration's, verbatim in shape, and it is repeated here only because it is
 * the reason this file adds columns instead of a status:
 *
 *     active_enrollment_key = IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)
 *     UNIQUE (school_id, active_enrollment_key)                       -- 2026_07_19_120000, re-keyed 2026_08_18_100000
 *
 * ANY status but the active one recomputes that key to NULL, and NULLs do not collide. So a bill
 * carrying a `returned` STATUS would leave its enrollment episode unguarded for the whole time it
 * sat with Finance — precisely the window in which somebody is looking at the bill, deciding it is
 * wrong, and might raise a replacement. The duplicate-invoice slot would be open at exactly the
 * moment a duplicate is most likely. A nullable column is invisible to that expression, so the
 * guard holds for the whole return window.
 *
 * ── A RETURNED BILL KEEPS `reviewed_at` NULL, AND THAT IS THE SAFE DIRECTION ─────────────────────
 *
 * `reviewed_at` is UNTOUCHED by this migration. Returned is a SECOND axis, not a move along the
 * first. A returned bill is still unreleased, therefore still invisible to the payer, therefore
 * still not collecting against a parent's balance. If the two axes are ever read inconsistently the
 * failure lands on the side where a payer sees LESS than they might have — never on the side where
 * a bill Finance has been asked to correct is already on a parent's screen.
 *
 * ── THE FOUR STATES ─────────────────────────────────────────────────────────────────────────────
 *
 *   reviewed_at NULL, returned_at NULL                       pending review — the auditor's queue
 *   reviewed_at + reviewed_by_user_id                        released by a named person
 *   reviewed_at set, reviewed_by_user_id NULL                grandfathered by the 31 August backfill
 *   returned_at + return_reason + returned_by_user_id        returned to Finance, still unreleased
 *
 * The third exists ONLY because `2026_08_31_100000` stamped the entire existing book at its own
 * `created_at` with no actor to name. It is a permanent hole in `reviewed_at`'s pairing that no
 * trigger can ever close, because closing it would require refusing rows that already exist.
 *
 * THE RETURN AXIS HAS NO SUCH ROW, AND THAT IS WHY ITS PAIRING IS TOTAL FROM THE FIRST DAY. There
 * is NO BACKFILL here: every existing row is correctly "not returned" already, and NULL says so. A
 * migration that writes rows it does not need to is how a copy gets rewritten. The consequence is
 * that the guard below can be installed unconditionally — the instant one unpaired row exists, it
 * could never be added without a data fix first, so this is the only moment it is free.
 *
 * ── `reviewed_at` AND `returned_at` BOTH SET: THE RULING LANDED, AND IT LANDED IN PHP ────────────
 *
 * It is not a state the system produces — no path writes both — but that is a claim about the code,
 * not about the schema. **At the database, NOTHING REFUSES IT.** That sentence is still true and is
 * kept deliberately: the trigger below was never the place, because whether an auditor may release a
 * bill that is currently out with Finance is a domain ruling about the return workflow, owed by the
 * commit that adds the return action rather than by the migration that adds the column.
 *
 * THAT COMMIT SHIPPED, AND IT CHOSE (b). This paragraph used to name two open options — (a) a third
 * arm on this trigger, or (b) `whereNull('returned_at')` in `ApproveInvoice`'s compare-and-swap —
 * and defer between them. The deferral is over. Option (b) is in the tree:
 *
 *   - `app/Finance/Actions/ApproveInvoice.php:174 (handle)` — `->whereNull('returned_at')` inside
 *     the compare-and-swap, with the read-side `refuseIfOutWithFinance` calls at `:163` and `:185`;
 *   - `app/Finance/Actions/ReturnInvoice.php:190 (handle)` — the mirror,
 *     `whereNull(RELEASE_STAMP_COLUMN)`, so the other order is refused just as flatly;
 *   - the reasoning lives beside the guard, in the file docblock immediately above
 *     `app/Finance/Actions/ApproveInvoice.php:114 (ApproveInvoice)` — the section headed
 *     "APPROVE-OVER-A-RETURN IS REFUSED HERE, IN THE ACTION, AND NOT IN THE TRIGGER".
 *
 * SO THE ENFORCEMENT IS IN PHP AND NOT AT THE DATABASE — two application writers, no schema object.
 * A raw `UPDATE` at a prompt still produces the state, and `down()`ing the actions would restore it.
 * Whether that is the final answer or whether arm (a) is still owed is a ruling nobody has made out
 * loud; it is carried at
 * `docs/handoff/tickets/the-both-set-guard-is-php-only-and-nobody-ruled-it-final.md`.
 *
 * ── THE PAIRING GUARD IS A TRIGGER, NOT A `CHECK` ───────────────────────────────────────────────
 *
 * Production is MySQL 5.7.23 and PARSES AND DISCARDS `CHECK` silently —
 * `docs/finance/check-constraints-on-mysql-5-7.md`, which ships in the tree. A
 * `CHECK (returned_at IS NULL OR return_reason IS NOT NULL)` would be enforced locally, absent on
 * the server holding the money, and would READ GREEN IN EVERY TEST. So: `BEFORE INSERT` and
 * `BEFORE UPDATE`, `SIGNAL SQLSTATE '45000'`, per `2026_08_17_100000` and `2026_08_18_100000`.
 *
 * WHY IT COVERS BOTH COMPANIONS AND NOT ONLY THE REASON. A returned bill with no named returner is
 * an audit hole with no other witness, and the predicate costs the same either way.
 *
 * THE INVERSE ARM IS DELIBERATELY UNENFORCED. `return_reason` or `returned_by_user_id` set while
 * `returned_at` is NULL is NOT refused. It is a stray annotation on a bill nobody returned: no queue
 * reads it, no screen shows it, no money moves on it. The guard exists to stop a bill arriving at
 * Finance with no statement of why — that is a real hole with a real reader. Refusing the inverse
 * buys nothing and adds an arm that fires on writes nobody is guarding, which is how an operator
 * gets refused with a message about a rule they did not break.
 *
 * AN EMPTY-STRING REASON IS LIKEWISE NOT REFUSED HERE, AND FOR A DIFFERENT REASON. `return_reason
 * = ''` would be the only STRING comparison in this trigger, and every string comparison in a
 * finance trigger must carry `COLLATE utf8mb4_bin` or `FinanceTriggerCollationTest` reds — so the
 * arm would add a collation-sensitive surface to a guard that otherwise has none, to catch a case
 * the request validation on the return action catches first and better (it can say WHICH field and
 * WHY). Presence is the schema's job; non-emptiness is the action's.
 *
 * ── TRIGGER INTERFERENCE, CHECKED FOR THESE THREE COLUMNS RATHER THAN INHERITED ──────────────────
 *
 * `finance_invoices` carries five triggers before this migration. Read from
 * `information_schema.TRIGGERS`, in ACTION_ORDER — which is CREATION order, and therefore FIRING
 * order absent `FOLLOWS`/`PRECEDES`:
 *
 *     BEFORE INSERT  #1  finance_invoices_kind_domain_bi      kind IN (...) COLLATE utf8mb4_bin
 *     BEFORE UPDATE  #1  finance_invoices_total_immutable     NEW.total_minor / total_currency
 *     BEFORE UPDATE  #2  finance_invoices_kind_domain_bu      kind IN (...) COLLATE utf8mb4_bin
 *     BEFORE UPDATE  #3  finance_invoices_kind_immutable      NEW.kind <> OLD.kind
 *     BEFORE DELETE  #1  finance_invoices_no_delete           refuses every DELETE
 *
 * READ FROM A DATABASE MIGRATED FROM ZERO, NOT FROM THE SHARED DEV DATABASE — which reports a
 * DIFFERENT UPDATE order (kind_domain_bu, kind_immutable, total_immutable) because its triggers were
 * created in a different sequence from the one the migration files replay. A fresh migrate is what
 * production ran, so a fresh migrate is the instrument.
 *
 * NONE of the five reads `returned_at`, `returned_by_user_id` or `return_reason`; each names its own
 * columns and signals only on those. An UPDATE touching only the three columns added here passes all
 * of them untouched. `ALTER TABLE` is DDL and fires no DML trigger — precedent `2026_07_21_120000`
 * and `2026_07_26_120000`, which added columns to `finance_invoice_lines` while its append-only
 * trigger was live.
 *
 * FIRING ORDER MASKS NO EXISTING MESSAGE. The two triggers installed here are created last, so they
 * fire LAST at each timing — after all three existing UPDATE triggers, after the one INSERT trigger.
 * A write that violates both an existing rule and the pairing rule is refused by the EXISTING one,
 * with the existing message. The new guard can only be the first to speak about a write that broke
 * nothing else, which is the only write it has anything to say about.
 *
 * ── `returned_by_user_id` IS A LOOKUP, NOT AN FK ────────────────────────────────────────────────
 *
 * Plain nullable `unsignedBigInteger`, no `constrained()`, exactly as `reviewed_by_user_id`
 * (`2026_08_31_100000`) and the house convention it cites — `cancelled_by_user_id`,
 * `received_by_user_id`, `started_by_user_id`. An attribution must never block a user's lifecycle.
 *
 * `return_reason` is `string()` — VARCHAR(255) — matching this table's own `cancel_reason`
 * (`2026_07_19_100000:57`) and the `rejection_reason` of `finance_credit_notes` and
 * `finance_void_requests`. The length is read from the siblings, not chosen.
 *
 * ── ONE INDEX SERVES BOTH QUEUES ────────────────────────────────────────────────────────────────
 *
 *     (school_id, reviewed_at, returned_at)
 *
 * The existing `finance_invoices_school_student_reviewed_index` (school_id, student_id,
 * reviewed_at) does NOT serve either queue, and its own docblock says why: it was built for the two
 * PER-STUDENT reads — the parent's invoice list and the withheld-charge adjustment. Both queues are
 * school-wide, so `student_id` is unconstrained, the prefix breaks after `school_id`, and
 * `reviewed_at` becomes a per-row filter. There is no skip-scan rescue: MySQL added it in 8.0.13 and
 * PRODUCTION IS 5.7.
 *
 * The two queues differ only in the LAST column, and only in equality-vs-range on it:
 *
 *     auditor  school_id = ?  AND reviewed_at IS NULL AND returned_at IS NULL      three ref parts
 *     Finance  school_id = ?  AND reviewed_at IS NULL AND returned_at IS NOT NULL  two ref + a range
 *
 * `IS NULL` is an equality-like key part (MySQL's documented `IS NULL` optimization), so the auditor
 * arm is a full three-column `ref`; `IS NOT NULL` is rewritten as a range, which is usable as the
 * last key part. A second index would be write cost on every invoice for no read.
 *
 * TWO RESIDUALS, STATED RATHER THAN HIDDEN. `status <> 'void'` is not sargable on any index and
 * stays a post-index filter — adding `status` would not change that. And `ORDER BY created_at, id`
 * filesorts in the Finance arm whatever is indexed, because no key part after a range is usable for
 * ordering; appending `created_at` as a fourth column would remove the filesort for the AUDITOR arm
 * only. The EXPLAIN measurements for all four states are in the commit message.
 */
return new class extends Migration
{
    private const TABLE = 'finance_invoices';

    private const INDEX = 'finance_invoices_school_reviewed_returned_index';

    private const TRIGGER_STEM = 'finance_invoices_return_pairing';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'returned_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // WHEN Internal Audit sent this bill back to Finance. NULL = never returned, which is
                // true of every row that exists today and is why there is no backfill.
                $table->timestamp('returned_at')->nullable()->after('reviewed_by_user_id');

                // WHO returned it. LOOKUP, not an FK — see the docblock.
                $table->unsignedBigInteger('returned_by_user_id')->nullable()->after('returned_at');

                // WHY. The whole content of a return: Finance is told a bill came back, and this is
                // the only place that says what to fix. VARCHAR(255), matching `cancel_reason`.
                $table->string('return_reason')->nullable()->after('returned_by_user_id');
            });
        }

        if (! $this->hasIndex(self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['school_id', 'reviewed_at', 'returned_at'], self::INDEX);
            });
        }

        // The MESSAGE_TEXT is a PLAIN SINGLE-QUOTED LITERAL, spelled here rather than held in a
        // const. `bin/ci-message-text-lint.php` measures literals and REFUSES anything built from an
        // expression — it cannot know a const's length, and a message over MySQL's 128-character cap
        // fails as 1648 (the SIGNAL itself failed) instead of 1644, which an arm asserting only
        // "it threw" cannot tell apart. Measured here: 82 characters, and the bite-proof reads 1644.
        $pairing = 'IF NEW.returned_at IS NOT NULL
                        AND (NEW.return_reason IS NULL OR NEW.returned_by_user_id IS NULL) THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'finance_invoices: returned_at requires both return_reason and returned_by_user_id.\';
                    END IF;';

        $this->installTrigger(self::TRIGGER_STEM.'_bi', 'INSERT', $pairing);
        $this->installTrigger(self::TRIGGER_STEM.'_bu', 'UPDATE', $pairing);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER_STEM.'_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER_STEM.'_bi');

        if ($this->hasIndex(self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'returned_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn(['returned_at', 'returned_by_user_id', 'return_reason']);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABLE, $name],
        ) !== null;
    }

    /**
     * Create one trigger idempotently, then PROVE it is there — name, timing and event — from
     * `information_schema`. Pattern and reasoning from `2026_08_18_100000`, which states the stake:
     * on 5.7 there is no CHECK behind it, so a migration that records itself applied while its
     * guard is absent leaves the rule unenforced on the server holding the money.
     */
    private function installTrigger(string $name, string $event, string $body): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.'
             FOR EACH ROW
             BEGIN
                '.$body.'
             END'
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
                .'record this migration as applied: the pairing rule it claims to install is absent, '
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
