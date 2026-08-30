<?php

use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `finance_student_discount_awards` — WHICH discount policy a given student is on. One row per
 * student; the row names the student and a policy, and the POLICY carries the percentage and the
 * base. The consumer is {@see ProcessBulkInvoiceRun}, which appends one percentage reduction spec
 * per awarded student; the writer is {@see AwardStudentDiscount}.
 *
 * ── IT IS A FINANCE TABLE BECAUSE THE FACT IS A FINANCE FACT ─────────────────────────────────────
 *
 * The obvious alternative is a column on `students`. It is wrong for the reason the module blueprint
 * exists: `students` belongs to Academics, and "which discount policy prices this child" is a
 * Finance concept naming a Finance row (`finance_discount_policies`). A Finance column on an
 * Academics table would make the Kernel's own model carry a Module's vocabulary and would put a
 * `finance_` foreign key in a table Finance may not migrate. The student is reached through the ACL
 * port instead — the same route the previous commit took for `students.scholarship_id`.
 *
 * ── ISOLATION IS AT THE ENGINE, ON BOTH SIDES ────────────────────────────────────────────────────
 *
 * Two COMPOSITE foreign keys, and each one closes a hole a plain single-column FK leaves open:
 *
 *   (student_id, school_id)         -> students (id, school_id)
 *   (discount_policy_id, school_id) -> finance_discount_policies (id, school_id)
 *
 * `students.scholarship_id` is the cautionary precedent sitting one table away: it references
 * `scholarships (id)` and is NOT composite, so nothing at the engine stops School A assigning a
 * School-B scholarship — which is exactly the fault the bulk run now has to detect and report at run
 * time (`ProcessBulkInvoiceRun::schemesForCohort()`, the UNRESOLVABLE arm). A cross-School award is
 * the same defect with money attached, and here it is simply not insertable. Both parent uniques
 * already exist: `students_id_school_unique` (2026_07_19_130000:53) and
 * `finance_discount_policies_id_school_unique` (2026_07_26_140000:52).
 *
 * `RESTRICT` on both, matching every other durable referent in this module: a policy is provenance
 * for what it priced and already denies DELETE by trigger; a student with a live award cannot be
 * hard-deleted out from under it.
 *
 * ── ONE AWARD PER STUDENT, AND THE UNIQUE IS THE AUTHORITY ───────────────────────────────────────
 *
 * `unique(student_id)`, not `unique(school_id, student_id)`. The composite FK above already confines
 * a row to its student's own School, so the two-column form would be the same constraint written
 * less exactly — and it would ADMIT a second award for one student under a different `school_id`,
 * which is the one shape "one award per student" must refuse. {@see AwardStudentDiscount} pre-checks
 * for a friendly refusal; this index is what actually holds, including against a raw write.
 *
 * ── IT IS CONFIGURATION, NOT A LEDGER: NO APPEND-ONLY TRIGGERS ───────────────────────────────────
 *
 * Stated because "Finance tables are append-only" is the house default and its exceptions have to be
 * argued rather than assumed. An award is a LIVE setting — the next commit's import must be able to
 * change a child's percentage between terms — so it belongs with `finance_discount_policies` and
 * `finance_fee_schedules` (mutable, guarded catalog) rather than with `finance_invoice_lines`
 * (append-only history). Nothing is lost to audit by that: a reduction line SNAPSHOTS the resolved
 * naira figure and the policy id it cited at the moment it was billed, so a historical invoice does
 * not move when an award does.
 *
 * THE AUDIT CLAUSE, NAMED RATHER THAN ASSUMED — this sentence read "`activitylog` covers who changed
 * an award" and was FALSE when written. Cold review checked: no Finance model carried `LogsActivity`,
 * there was no listener and no observer, so an award moved from 50% to 10% left nothing but a bumped
 * `updated_at`. An exemption argued on an audit trail that does not exist is worse than no exemption,
 * because it is the sentence the next reader trusts instead of looking. What carries it now, both
 * shipped with this table:
 *
 *   - `StudentDiscountAward` uses `Spatie\Activitylog\Traits\LogsActivity`, logging
 *     `school_id`, `student_id` and `discount_policy_id` before and after on ANY write — including a
 *     write that does not go through the Action, which is exactly the writer this exemption
 *     anticipates (the next commit's import).
 *   - `AwardStudentDiscount` writes a `discount_award_created` entry, in the same transaction as the
 *     award, carrying the RESOLVED terms — percent, base, policy name — none of which is a column
 *     here, so the trait alone could never record what the award costs.
 *
 * If either is ever removed, this exemption stops being argued and this table needs guards instead.
 *
 * NO POLICY-STATE CHECK LIVES HERE. Whether the cited policy is `active` and non-approval-requiring
 * is a fact that can change AFTER the award is written (a policy may be superseded or retired), so
 * it is not an insert-time property of this row. It is checked where it can be acted on: at award
 * time by {@see AwardStudentDiscount} (so a bad award is refused when it is made), and at bill time
 * by `finance_invoice_lines_reduction_guard` (so a policy retired since the award is refused per
 * student, loudly, rather than silently billing that child full price).
 */
return new class extends Migration
{
    private const TABLE = 'finance_student_discount_awards';

    /**
     * EVERY STATEMENT IS INDIVIDUALLY RE-RUNNABLE, and this migration learned that the hard way on
     * its own first run: `Schema::create` succeeded, the index ALTER behind it failed on MySQL's
     * 64-character identifier limit (1059), and because MySQL commits DDL implicitly while Laravel
     * records a migration only after `up()` RETURNS, the table existed and the migration did not
     * (docs/handoff/tickets/aborted-migration-leaves-schema-changed-and-unrecorded.md).
     *
     * The first version guarded the whole body on `Schema::hasTable` and returned early — which
     * would have made the retry SKIP every foreign key, leaving a table with none of the isolation
     * this migration exists to install and a green `migrate` reporting it done. So the guard is per
     * OBJECT, not per table: the create, the index and each constraint are each asserted separately
     * against `information_schema`. Re-runnability is not atomicity and this does not claim to have
     * solved that standing condition; it claims that the retry converges on the right schema.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();

                // Plain columns; the composite FKs below are added by raw ALTER because Blueprint has
                // no multi-column foreign-key builder that names its own parent unique.
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('discount_policy_id');

                // WHO made the award. Attribution only, nullable so a seeder or console caller with
                // no acting user still works — the same shape as finance_invoice_lines.created_by_user_id.
                $table->unsignedBigInteger('created_by_user_id')->nullable();

                $table->timestamps();

                $table->unique('student_id', 'finance_student_discount_awards_student_unique');
            });
        }

        // NAMED EXPLICITLY. Laravel's derived name — {table}_{col}_{col}_index — is 66 characters
        // here and MySQL refuses identifiers over 64 (1059). That is what aborted the first run.
        $this->addIndexUnlessPresent(
            'finance_student_discount_awards_policy_index',
            '(school_id, discount_policy_id)',
        );

        $this->addForeignKeyUnlessPresent(
            'finance_student_discount_awards_school_foreign',
            'FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT',
        );

        $this->addForeignKeyUnlessPresent(
            'finance_student_discount_awards_student_school_foreign',
            'FOREIGN KEY (student_id, school_id) REFERENCES students (id, school_id) ON DELETE RESTRICT',
        );

        $this->addForeignKeyUnlessPresent(
            'finance_student_discount_awards_policy_school_foreign',
            'FOREIGN KEY (discount_policy_id, school_id)
                 REFERENCES finance_discount_policies (id, school_id) ON DELETE RESTRICT',
        );

        $this->addForeignKeyUnlessPresent(
            'finance_student_discount_awards_creator_foreign',
            'FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL',
        );

        $this->assertShape();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function addIndexUnlessPresent(string $name, string $columns): void
    {
        $present = collect(DB::select('SHOW INDEX FROM '.self::TABLE))
            ->pluck('Key_name')->contains($name);

        if (! $present) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD INDEX {$name} {$columns}");
        }
    }

    private function addForeignKeyUnlessPresent(string $name, string $definition): void
    {
        $present = DB::selectOne(
            "SELECT 1 AS present FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ?",
            [self::TABLE, $name],
        );

        if ($present === null) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} {$definition}");
        }
    }

    /**
     * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). A `CREATE`/`ALTER` returning success is not
     * evidence that the isolation this table claims is actually installed — and after the aborted
     * first run there was a real table on disk carrying none of it. So the four constraints are read
     * back and this migration THROWS unless all four are there, leaving itself unrecorded rather
     * than recording a green that means nothing.
     */
    private function assertShape(): void
    {
        $expected = [
            'finance_student_discount_awards_school_foreign',
            'finance_student_discount_awards_student_school_foreign',
            'finance_student_discount_awards_policy_school_foreign',
            'finance_student_discount_awards_creator_foreign',
        ];

        $present = collect(DB::select(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [self::TABLE],
        ))->pluck('name')->all();

        $missing = array_values(array_diff($expected, $present));

        if ($missing !== []) {
            throw new RuntimeException(
                'Foreign keys missing from '.self::TABLE.' after ALTER returned success: '
                .implode(', ', $missing).'. Refusing to record this migration as applied: without the '
                .'composite keys a cross-School award is insertable, which is the one thing this '
                .'table exists to make unrepresentable.'
            );
        }
    }
};
