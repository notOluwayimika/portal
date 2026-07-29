<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1 commit 4a — close the mutable-draft window (ADR 0050 amendment, 2026-07-29). ADR 0050 chose option
 * (c): the proposal IS a live draft schedule with real item rows, not a frozen payload, so a third seat
 * holding finance.fee-schedule.manage could edit the amounts AFTER a Head submits and BEFORE the ED
 * approves — the ED then approving a schedule different from the one shown. ADR 0050 recorded a submit-time
 * fingerprint as the remedy; that was overturned (project lead, 2026-07-29) in favour of PREVENTION: a new
 * `pending_approval` lifecycle state that submit moves the target into, at which point the three
 * finance_fee_items_parent_state_guard_{ins,upd,del} triggers — already in the tree since commit 2, already
 * plant-proven (proof 30) — freeze the items with NO new detection logic. (The fingerprint is weaker than
 * it looks: count+sum is preserved by moving money BETWEEN items, and blind to an is_discountable flip,
 * which since 3b changes what can be billed.)
 *
 * WHY THIS IS A MIGRATION AND NOT A THREE-LINE ACTION CHANGE. finance_fee_schedules_draft_unique is keyed
 * on generated columns defined `IF(status = 'draft', …)`. The moment a submitted schedule stops being
 * 'draft', that index stops covering it and the slot frees up — a second draft for the same
 * (school, term, class level) becomes authorable and submittable, two open requests exist against two
 * different targets, and both can be approved in turn. That is the exact gap the change table's open_key
 * docblock says draft-uniqueness is the ONLY thing preventing. Variant A re-opens it unless the index is
 * widened to cover pending_approval too — which is (a) below.
 *
 * SUPERSEDES PART OF 2026_07_26_130000 (fee_schedules: the draft key) and 2026_07_26_130001 (fee_items:
 * the three guards) — by drop-and-recreate, the same way 2026_07_25_150000 superseded the credit-note
 * guards. Those two migrations are on staging and are NOT edited; a reader of their prose finds this trail
 * by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) Widen the draft-uniqueness key to cover pending_approval, and RENAME its columns/index so the
        //     name never lies — a `draft_term_key` that is also non-null for a pending_approval row is a
        //     lie, and a lie in a column name is how the dependency above goes silent.
        DB::statement('ALTER TABLE finance_fee_schedules DROP INDEX finance_fee_schedules_draft_unique');
        DB::statement('ALTER TABLE finance_fee_schedules DROP COLUMN draft_term_key, DROP COLUMN draft_class_level_key');
        DB::statement(
            "ALTER TABLE finance_fee_schedules
                ADD COLUMN pending_term_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status IN ('draft','pending_approval'), term_id, NULL)) STORED,
                ADD COLUMN pending_class_level_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status IN ('draft','pending_approval'), class_level_id, NULL)) STORED"
        );
        DB::statement(
            'ALTER TABLE finance_fee_schedules
                ADD UNIQUE finance_fee_schedules_pending_unique (school_id, pending_term_key, pending_class_level_key)'
        );

        // (b) Harden the three item guards to BINARY. Under Variant A these three ARE the control that makes
        //     this commit true, so they get the house discipline (BINARY regardless of whether collations
        //     happen to agree — §3.5's "does not arise" was checked and superseded this month in 3b,
        //     2026_07_26_140002). Recreate by name; the message names the state found, not only the one wanted.
        foreach (['ins', 'upd', 'del'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS finance_fee_items_parent_state_guard_'.$event);
        }
        DB::unprepared(
            "CREATE TRIGGER finance_fee_items_parent_state_guard_ins BEFORE INSERT ON finance_fee_items
             FOR EACH ROW
             BEGIN
                 DECLARE v_status VARCHAR(255);
                 SELECT status INTO v_status FROM finance_fee_schedules WHERE id = NEW.fee_schedule_id;
                 IF v_status IS NULL OR BINARY v_status <> BINARY 'draft' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                         'Fee items may only be added to a draft fee schedule; its parent is not a draft.';
                 END IF;
             END"
        );
        DB::unprepared(
            "CREATE TRIGGER finance_fee_items_parent_state_guard_upd BEFORE UPDATE ON finance_fee_items
             FOR EACH ROW
             BEGIN
                 DECLARE v_status VARCHAR(255);
                 SELECT status INTO v_status FROM finance_fee_schedules WHERE id = OLD.fee_schedule_id;
                 IF v_status IS NULL OR BINARY v_status <> BINARY 'draft' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                         'Fee items may only be changed while the fee schedule is a draft; its parent is not a draft.';
                 END IF;
             END"
        );
        DB::unprepared(
            "CREATE TRIGGER finance_fee_items_parent_state_guard_del BEFORE DELETE ON finance_fee_items
             FOR EACH ROW
             BEGIN
                 DECLARE v_status VARCHAR(255);
                 SELECT status INTO v_status FROM finance_fee_schedules WHERE id = OLD.fee_schedule_id;
                 IF v_status IS NULL OR BINARY v_status <> BINARY 'draft' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                         'Fee items may only be removed while the fee schedule is a draft; its parent is not a draft.';
                 END IF;
             END"
        );
    }

    /**
     * Restore the pre-4a draft key and the pre-BINARY item guards, both by name so the reversibility audit
     * finds this migration and asserts a second draft for an occupied slot is creatable again after rollback.
     *
     * A row sitting in `pending_approval` at rollback time is a data problem down() cannot solve — the state
     * ceases to exist, and IF(status = 'draft', …) will not cover it, so its slot silently frees. This is a
     * forward-only state in practice; rolling back through a live pending_approval schedule is not supported
     * and would need the row moved to draft or active first. Stated here rather than pretended away.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE finance_fee_schedules DROP INDEX finance_fee_schedules_pending_unique');
        DB::statement('ALTER TABLE finance_fee_schedules DROP COLUMN pending_term_key, DROP COLUMN pending_class_level_key');
        DB::statement(
            "ALTER TABLE finance_fee_schedules
                ADD COLUMN draft_term_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'draft', term_id, NULL)) STORED,
                ADD COLUMN draft_class_level_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'draft', class_level_id, NULL)) STORED"
        );
        DB::statement(
            'ALTER TABLE finance_fee_schedules
                ADD UNIQUE finance_fee_schedules_draft_unique (school_id, draft_term_key, draft_class_level_key)'
        );

        foreach (['ins', 'upd', 'del'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS finance_fee_items_parent_state_guard_'.$event);
        }
        DB::unprepared(
            "CREATE TRIGGER finance_fee_items_parent_state_guard_ins BEFORE INSERT ON finance_fee_items
             FOR EACH ROW
             BEGIN
                 DECLARE v_status VARCHAR(255);
                 SELECT status INTO v_status FROM finance_fee_schedules WHERE id = NEW.fee_schedule_id;
                 IF v_status IS NULL OR v_status <> 'draft' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fee items may only be added to a draft fee schedule.';
                 END IF;
             END"
        );
        DB::unprepared(
            "CREATE TRIGGER finance_fee_items_parent_state_guard_upd BEFORE UPDATE ON finance_fee_items
             FOR EACH ROW
             BEGIN
                 DECLARE v_status VARCHAR(255);
                 SELECT status INTO v_status FROM finance_fee_schedules WHERE id = OLD.fee_schedule_id;
                 IF v_status IS NULL OR v_status <> 'draft' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fee items may only be changed while the fee schedule is a draft.';
                 END IF;
             END"
        );
        DB::unprepared(
            "CREATE TRIGGER finance_fee_items_parent_state_guard_del BEFORE DELETE ON finance_fee_items
             FOR EACH ROW
             BEGIN
                 DECLARE v_status VARCHAR(255);
                 SELECT status INTO v_status FROM finance_fee_schedules WHERE id = OLD.fee_schedule_id;
                 IF v_status IS NULL OR v_status <> 'draft' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fee items may only be removed while the fee schedule is a draft.';
                 END IF;
             END"
        );
    }
};
