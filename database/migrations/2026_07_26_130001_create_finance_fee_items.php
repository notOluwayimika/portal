<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 commit 2 — the line items of a fee schedule. Gives `finance_invoice_lines.fee_item_id` (nullable
 * LOOKUP provenance since the create migration, null in every row ever written) its referent at last —
 * kept LOOKUP there, no FK added. `amount_minor` is positive; `is_mandatory` pre-ticks tuition vs
 * optional transport/feeding in the bursar UI; `is_discountable` is consumed by resolvePercentages()
 * (S1 commit 3, §3.6).
 *
 * Composite school-integrity FK on (fee_schedule_id, school_id) → finance_fee_schedules(id, school_id),
 * exactly the 2026_07_19_110001 pattern: a child's school_id can never diverge from its parent's.
 *
 * ── THE ONE DELIBERATE DEPARTURE FROM APPEND-ONLY, and it is not a bug to "fix" ──
 * Items are freely added / edited / DELETED while the parent schedule is a DRAFT, and frozen the moment
 * it goes active. The Head builds a schedule over days; what the ED approves is the finished set, and an
 * approved item may not change or the approval means nothing. This is the whole reason the draft state
 * exists. Every other Finance table is append-only (Constitution §15C protects documents with accounting
 * MEANING); a draft has none — nothing has been billed against it and nothing can be, because the
 * schedule lookup reads active schedules only. So DELETE is permitted here, but ONLY while the parent is
 * draft. Three parent-state triggers (ins/upd/del — MySQL has no multi-event trigger), one rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_fee_items', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('fee_schedule_id')->constrained('finance_fee_schedules')->restrictOnDelete();
            $table->string('description');           // "Tuition", "Transport", "Feeding"
            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_discountable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Composite school-integrity: drop the single-col schedule FK, add (fee_schedule_id, school_id).
        DB::statement('ALTER TABLE finance_fee_items DROP FOREIGN KEY finance_fee_items_fee_schedule_id_foreign');
        DB::statement('ALTER TABLE finance_fee_items DROP INDEX finance_fee_items_fee_schedule_id_foreign');
        DB::statement(
            'ALTER TABLE finance_fee_items ADD CONSTRAINT finance_fee_items_schedule_school_foreign
                FOREIGN KEY (fee_schedule_id, school_id) REFERENCES finance_fee_schedules (id, school_id) ON DELETE RESTRICT'
        );

        // Parent-state guard: a fee item may only be written while its schedule is a draft.
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

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS finance_fee_items_parent_state_guard_ins');
        DB::unprepared('DROP TRIGGER IF EXISTS finance_fee_items_parent_state_guard_upd');
        DB::unprepared('DROP TRIGGER IF EXISTS finance_fee_items_parent_state_guard_del');
        Schema::dropIfExists('finance_fee_items');
    }
};
