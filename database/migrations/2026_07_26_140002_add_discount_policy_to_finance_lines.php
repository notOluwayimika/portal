<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 commit 3b — axis B: line-level reduction enforcement (ADR 0049). This is the migration ADR 0049's
 * live contract points at: until it merges, a reduction line at generation is reachable free-text; after
 * it, every reduction line must cite an ACTIVE, non-approval-requiring discount policy of the SAME School,
 * as a database fact. Columns AND the guard ship together — a nullable column nothing enforces and nothing
 * reads is a primitive ahead of its consumer (§28.4), the trap this project has already been bitten by.
 *
 * COLUMNS (both LOOKUP — plain, nullable, NO foreign key, same reason as every other attribution in this
 * module: an FK to a mutable catalog row would let a policy delete rewrite a historical bill's provenance):
 *   - finance_invoice_lines.discount_policy_id  (after fee_item_id) — WITH the guard below.
 *   - finance_credit_notes.discount_policy_id   — the same provenance on the exceptional path.
 *
 * THE CREDIT-NOTE COLUMN IS ASYMMETRIC ON PURPOSE — a DECISION, not an oversight. finance_invoice_lines
 * gets the column AND the guard; finance_credit_notes gets the column and NO guard. The guard's own message
 * says "discretionary reductions go through a credit note", so requiring a policy on a credit note would
 * close the very escape hatch the guard points at. The column exists so a credit note MAY record which
 * policy motivated it; nothing requires it to.
 *
 * RETIREMENT IS NOT RETROACTIVE — and this is the most likely misreading. The guard is BEFORE INSERT ONLY.
 * Retiring a policy stops NEW reductions citing it; lines already written are untouched and their invoice
 * totals do not move — an append-only table plus a snapshot amount means a historical bill stays exactly as
 * billed. There is deliberately NO update branch: finance_invoice_lines_no_update already denies UPDATE
 * outright, so an update branch would be dead code that reads like a live control.
 *
 * THE CROSS-SCHOOL CHECK IS NOT REDUNDANT WITH SchoolScope. It is the isolation guarantee holding for raw
 * writes, jobs and tinker — the standing reason every control in this module lives at the database. It is
 * kept, not "simplified" away.
 *
 * COLLATION (#95) — §3.5 said kind/status are compared against LITERALS so the hazard "does not arise", and
 * asked to confirm that against finance_credit_notes_insert_guard's BINARY comment (2026_07_25_150000:30-35)
 * before writing this. Confirmed, and SUPERSEDED: §3.5's reasoning is half wrong in the half that matters —
 * `v_status <> 'active'` compares a DECLAREd variable to a literal, exactly the case that migration covers
 * (a variable inherits the connection collation, a literal the connection charset; they "happen to agree"
 * only for a given connection). The house discipline there is to write BINARY REGARDLESS of whether the
 * collations happen to agree. So BINARY is used on every status/kind comparison here (all lowercase ASCII),
 * matching 2026_07_25_150000 and immune to a differently-defaulted DB. §3.5's "does not arise" is recorded
 * here as checked-and-superseded, so the next reader does not find the brief and the code disagreeing.
 *
 * This is a FRESH, separately-named BEFORE INSERT trigger — finance_invoice_lines has only the append-only
 * pair (_no_update, _no_delete), both untouched here.
 */
return new class extends Migration
{
    private const GUARD = 'finance_invoice_lines_reduction_guard';

    public function up(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_policy_id')->nullable()->after('fee_item_id');
        });
        Schema::table('finance_credit_notes', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_policy_id')->nullable();
        });

        DB::unprepared(
            'CREATE TRIGGER '.self::GUARD.' BEFORE INSERT ON finance_invoice_lines
             FOR EACH ROW
             BEGIN
                DECLARE v_status VARCHAR(255);
                DECLARE v_requires TINYINT;
                DECLARE v_school BIGINT;

                IF BINARY NEW.kind <> BINARY \'charge\' THEN
                    IF NEW.discount_policy_id IS NULL THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'A reduction line must reference an active discount policy; discretionary reductions go through a credit note.\';
                    END IF;

                    SELECT status, requires_approval, school_id
                      INTO v_status, v_requires, v_school
                      FROM finance_discount_policies WHERE id = NEW.discount_policy_id;

                    IF v_status IS NULL OR BINARY v_status <> BINARY \'active\' THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'The referenced discount policy is not active.\';
                    END IF;

                    IF v_requires = 1 THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'This discount policy requires per-application approval: apply it as a credit note, not an invoice line.\';
                    END IF;

                    IF v_school <> NEW.school_id THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'The discount policy belongs to another School.\';
                    END IF;
                END IF;

                IF BINARY NEW.kind = BINARY \'charge\' AND NEW.discount_policy_id IS NOT NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A charge line may not reference a discount policy.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::GUARD);
        Schema::table('finance_credit_notes', function (Blueprint $table) {
            $table->dropColumn('discount_policy_id');
        });
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('discount_policy_id');
        });
    }
};
