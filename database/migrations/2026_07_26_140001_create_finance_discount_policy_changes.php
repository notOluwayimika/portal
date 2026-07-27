<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 commit 3 — the discount-policy change request (axis A governance). The `finance_void_requests`
 * shape: a dedicated request document referencing the row it wants to change, with submitted_by /
 * decided_by / decided_at / rejection_reason, a maker≠checker CHECK, un-deletable, and an open_key
 * "at most one open request per target". The catalog table only ever holds APPROVED state; a pending
 * change must not alter the target's usability before anyone approves it (2.1).
 *
 * THREE KINDS: create (target null, full terms), amend (target set, full terms — approval inserts a
 * superseding policy), retire (target set, no terms — approval flips the target to retired). The four
 * CHECKs make those shapes DB facts.
 */
return new class extends Migration
{
    private const UPDATE_GUARD = 'finance_discount_policy_changes_update_guard';

    private const NO_DELETE = 'finance_discount_policy_changes_no_delete';

    public function up(): void
    {
        Schema::create('finance_discount_policy_changes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

            $table->string('kind', 16);                                 // create | amend | retire
            $table->unsignedBigInteger('target_policy_id')->nullable(); // null iff kind = create

            // The PROPOSED terms — null for kind = retire.
            $table->string('name')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('basis', 16)->nullable();
            $table->bigInteger('value_minor')->nullable();
            $table->char('value_currency', 3)->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->boolean('requires_approval')->nullable();

            $table->string('reason');                                   // the maker's why

            $table->string('status')->default('submitted');            // submitted | approved | rejected
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            // F3 composite: a change's School can never diverge from its target policy's.
            $table->foreign(['target_policy_id', 'school_id'], 'finance_discount_policy_changes_policy_school_foreign')
                ->references(['id', 'school_id'])->on('finance_discount_policies')->restrictOnDelete();
        });

        // The real maker ≠ checker control (the Policy class is the friendly layer).
        DB::statement(
            'ALTER TABLE finance_discount_policy_changes
                ADD CONSTRAINT finance_discount_policy_changes_maker_ne_checker
                CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)'
        );
        // A create names no target; an amend/retire must name one.
        DB::statement(
            "ALTER TABLE finance_discount_policy_changes
                ADD CONSTRAINT finance_discount_policy_changes_target_shape CHECK (
                    (kind = 'create' AND target_policy_id IS NULL)
                    OR (kind IN ('amend', 'retire') AND target_policy_id IS NOT NULL)
                )"
        );
        // A create/amend carries full terms; a retire carries none.
        DB::statement(
            "ALTER TABLE finance_discount_policy_changes
                ADD CONSTRAINT finance_discount_policy_changes_terms_shape CHECK (
                    (kind = 'retire' AND name IS NULL AND basis IS NULL AND requires_approval IS NULL)
                    OR (kind <> 'retire' AND name IS NOT NULL AND basis IS NOT NULL AND requires_approval IS NOT NULL
                        AND (
                          (basis = 'amount'  AND value_minor IS NOT NULL AND value_currency IS NOT NULL AND percent IS NULL)
                          OR
                          (basis = 'percent' AND percent BETWEEN 1 AND 100 AND value_minor IS NULL AND value_currency IS NULL)
                        ))
                )"
        );

        // Open-request uniqueness: at most one open amend/retire per target (a create's target is NULL,
        // which MySQL exempts — concurrent creates are caught at approval by the policy's active-name unique).
        DB::statement(
            "ALTER TABLE finance_discount_policy_changes
                ADD COLUMN open_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'submitted', target_policy_id, NULL)) STORED"
        );
        DB::statement('ALTER TABLE finance_discount_policy_changes ADD UNIQUE finance_discount_policy_changes_open_unique (school_id, open_key)');

        DB::unprepared(
            'CREATE TRIGGER '.self::NO_DELETE.' BEFORE DELETE ON finance_discount_policy_changes
             FOR EACH ROW SIGNAL SQLSTATE \'45000\'
             SET MESSAGE_TEXT = \'finance_discount_policy_changes is retained for audit: DELETE is denied.\';'
        );

        // Only the decision columns may move; the proposed terms, kind, target, reason, submitter and
        // uuid are frozen — else a maker could submit a modest discount and rewrite it after approval.
        DB::unprepared(
            'CREATE TRIGGER '.self::UPDATE_GUARD.' BEFORE UPDATE ON finance_discount_policy_changes
             FOR EACH ROW
             BEGIN
                IF NEW.kind <> OLD.kind
                    OR NOT (NEW.target_policy_id <=> OLD.target_policy_id)
                    OR NOT (NEW.name <=> OLD.name)
                    OR NOT (NEW.description <=> OLD.description)
                    OR NOT (NEW.basis <=> OLD.basis)
                    OR NOT (NEW.value_minor <=> OLD.value_minor)
                    OR NOT (NEW.value_currency <=> OLD.value_currency)
                    OR NOT (NEW.percent <=> OLD.percent)
                    OR NOT (NEW.requires_approval <=> OLD.requires_approval)
                    OR NEW.reason <> OLD.reason
                    OR NOT (NEW.submitted_by <=> OLD.submitted_by)
                    OR NEW.school_id <> OLD.school_id
                    OR NEW.uuid <> OLD.uuid THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_discount_policy_changes: only status/decided_by/decided_at/rejection_reason may change.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_DELETE);
        Schema::dropIfExists('finance_discount_policy_changes');
    }
};
