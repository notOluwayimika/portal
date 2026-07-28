<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 commit 4 — the fee-schedule change request (governing PUBLICATION of a price). The
 * `finance_discount_policy_changes` shape, one generation on: a dedicated request document referencing
 * the schedule it wants to publish or retire, with submitted_by / decided_by / decided_at /
 * rejection_reason, a maker≠checker CHECK, un-deletable, and an open_key "at most one open request per
 * target". The schedule reaches `status = active` ONLY when ApproveFeeScheduleChange approves this — a
 * pending change must not make a draft billable before anyone approves it (2.1 / 9.1).
 *
 * TWO KINDS ONLY — publish, retire. There is NO `create`: a draft schedule already exists (authored under
 * finance.fee-schedule.manage) before anyone submits; creating the draft is ordinary authorship, only
 * PUBLISHING it is the governed act. `publish` covers first publication AND re-pricing (build a new draft,
 * publish it — it supersedes the current active). There is no `amend`, because amending an ACTIVE schedule
 * is precisely what must not be possible. Because target_schedule_id is therefore ALWAYS non-null (9.2),
 * there is no target_shape CHECK and no concurrent-create gap: open_key genuinely constrains every open
 * request, unlike the discount-policy equivalent whose `create` kind carries a null target.
 *
 * ONE DEPENDENCY, STATED SO IT CANNOT GO SILENT (9.2): open_key stops two open requests against the SAME
 * draft. It does NOT stop two open requests against two DIFFERENT drafts for the same term and class level
 * — that case is impossible only because `finance_fee_schedules_draft_unique` already forbids a second
 * draft. If anyone ever relaxes that draft-uniqueness index, this gap opens and NOTHING in commit 4 will
 * catch it. The two guards are load-bearing together.
 */
return new class extends Migration
{
    private const UPDATE_GUARD = 'finance_fee_schedule_changes_update_guard';

    private const NO_DELETE = 'finance_fee_schedule_changes_no_delete';

    public function up(): void
    {
        Schema::create('finance_fee_schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

            $table->string('kind', 16);                       // publish | retire
            $table->unsignedBigInteger('target_schedule_id'); // ALWAYS present — creating a draft is authorship, not a change
            $table->string('reason');                         // the maker's why

            $table->string('status')->default('submitted');   // submitted | approved | rejected
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            // Composite school-integrity: a change's School can never diverge from its target schedule's.
            $table->foreign(['target_schedule_id', 'school_id'], 'finance_fee_schedule_changes_schedule_school_foreign')
                ->references(['id', 'school_id'])->on('finance_fee_schedules')->restrictOnDelete();
        });

        // The real maker ≠ checker control (the Policy class is the friendly layer).
        DB::statement(
            'ALTER TABLE finance_fee_schedule_changes
                ADD CONSTRAINT finance_fee_schedule_changes_maker_ne_checker
                CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)'
        );

        // Open-request uniqueness: at most one open request per target schedule. target_schedule_id is
        // never null here, so — unlike the discount-policy equivalent — this constrains EVERY open request.
        DB::statement(
            "ALTER TABLE finance_fee_schedule_changes
                ADD COLUMN open_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'submitted', target_schedule_id, NULL)) STORED"
        );
        DB::statement('ALTER TABLE finance_fee_schedule_changes ADD UNIQUE finance_fee_schedule_changes_open_unique (school_id, open_key)');

        DB::unprepared(
            'CREATE TRIGGER '.self::NO_DELETE.' BEFORE DELETE ON finance_fee_schedule_changes
             FOR EACH ROW SIGNAL SQLSTATE \'45000\'
             SET MESSAGE_TEXT = \'finance_fee_schedule_changes is retained for audit: DELETE is denied.\';'
        );

        // Only the decision columns may move; kind, target, reason, submitter, school and uuid are frozen —
        // else a maker could submit a modest schedule and re-point it after approval.
        DB::unprepared(
            'CREATE TRIGGER '.self::UPDATE_GUARD.' BEFORE UPDATE ON finance_fee_schedule_changes
             FOR EACH ROW
             BEGIN
                IF NEW.kind <> OLD.kind
                    OR NEW.target_schedule_id <> OLD.target_schedule_id
                    OR NEW.reason <> OLD.reason
                    OR NOT (NEW.submitted_by <=> OLD.submitted_by)
                    OR NEW.school_id <> OLD.school_id
                    OR NEW.uuid <> OLD.uuid THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_fee_schedule_changes: only status/decided_by/decided_at/rejection_reason may change.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_DELETE);
        Schema::dropIfExists('finance_fee_schedule_changes');
    }
};
