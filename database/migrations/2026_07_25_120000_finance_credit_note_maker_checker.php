<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ph3 — maker-checker on credit-note issuance. C1 issued a credit note in ONE step
 * (create note + post ledger credit + move balance), so one identity forgave money.
 * This splits issuance into a lifecycle: SUBMIT (maker, no money) → APPROVE (checker
 * ≠ maker, money moves) | REJECT (checker ≠ maker, never any money). Money moves ONLY
 * on approval — the ledger never sees a credit until a second person signs it.
 *
 * TWO-SOURCE replication (the design's load-bearing distinction):
 *   • Maker-checker STRUCTURE mirrors the result workflow (ADR 0040/0044): submitted_by
 *     / decided_by / decided_at / rejection_reason + a DB CHECK making
 *     `submitted_by = decided_by` unrepresentable. The Policy is the friendly layer;
 *     this CHECK is the real one (holds for raw writes, jobs, tinker).
 *   • MUTABILITY mirrors the INVOICE, not the result table: a credit note is a money
 *     document — money-immutable but status-mutable. The C1 append-only no-UPDATE
 *     trigger is RELAXED (not removed) to permit ONLY the decision columns to move,
 *     and DELETE stays denied. (finance_invoices_total_immutable is the template.)
 *
 * CEILING RELOCATION. C1 enforced Σ(credits) ≤ total at INSERT (all credits) + an
 * app check. A pending proposal must consume NO ceiling, so the ceiling moves to the
 * approval transition and counts APPROVED credits only. It fires at two points so the
 * illegal state is unrepresentable from any path:
 *   • BEFORE UPDATE, when status transitions INTO approved (the normal path);
 *   • BEFORE INSERT, only when a row ALREADY claims status='approved' (a raw/tamper
 *     write — the app always inserts 'submitted'). Without this an INSERT carrying
 *     status='approved' would bypass an UPDATE-only ceiling.
 *
 * Collation (#95): the BEFORE INSERT trigger compares a DECLAREd CHAR(3) variable to a
 * column, so it uses BINARY (a variable inherits the connection collation, the column
 * the table's; they disagree on a differently-defaulted DB and 1267 on every insert).
 * The UPDATE trigger compares NEW.x to OLD.x — same column, same collation — so plain
 * <> is correct there, exactly as finance_invoices_total_immutable does.
 *
 * BACKFILL (staging/dev only — Finance is unreleased): existing C1 rows were one-step
 * issues. They become status='approved', submitted_by=NULL, decided_by=NULL,
 * decided_at=created_at. The CHECK's NULL-escape admits them (an honest "the split did
 * not exist for this historical row"); setting both to the single C1 actor would
 * VIOLATE the CHECK, so we deliberately do not.
 *
 * down() restores C1 EXACTLY: drops the two new guards + the CHECK + the five columns,
 * then recreates the original finance_credit_notes_no_update (deny all UPDATE) and the
 * original finance_credit_note_not_over_invoice_total (BEFORE INSERT, Σ of ALL credits,
 * BINARY currency). #85: this migration is found by NAME in the reversibility audit and
 * asserts its own columns/triggers/CHECK are gone — never a bare --step=N.
 */
return new class extends Migration
{
    private const OLD_CEILING = 'finance_credit_note_not_over_invoice_total';

    private const OLD_NO_UPDATE = 'finance_credit_notes_no_update';

    private const INSERT_GUARD = 'finance_credit_notes_insert_guard';

    private const UPDATE_GUARD = 'finance_credit_notes_update_guard';

    private const CHECK = 'finance_credit_notes_maker_ne_checker';

    public function up(): void
    {
        // 1 ── Drop the C1 guards that block the backfill UPDATE and the new lifecycle.
        //      (no_delete stays: DELETE remains denied throughout.)
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::OLD_NO_UPDATE);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::OLD_CEILING);

        // 2 ── Lifecycle + maker/checker columns.
        Schema::table('finance_credit_notes', function (Blueprint $table) {
            $table->string('status')->default('submitted')->after('note');
            $table->foreignId('submitted_by')->nullable()->after('created_by_user_id')->constrained('users');
            $table->foreignId('decided_by')->nullable()->after('submitted_by')->constrained('users');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('rejection_reason')->nullable()->after('decided_at');
        });

        // 3 ── Backfill legacy one-step issues as already-approved (no maker/checker recorded).
        DB::statement("UPDATE finance_credit_notes SET status = 'approved', decided_at = created_at");

        // 4 ── The structural maker ≠ checker guard (the real control; Policy is the friendly layer).
        DB::statement(
            'ALTER TABLE finance_credit_notes
                ADD CONSTRAINT '.self::CHECK.'
                CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)'
        );

        // 5 ── INSERT guard: currency integrity (always) + ceiling ONLY when a raw insert
        //      already claims approved (tamper backstop; the app inserts 'submitted').
        DB::unprepared(
            'CREATE TRIGGER '.self::INSERT_GUARD.' BEFORE INSERT ON finance_credit_notes
             FOR EACH ROW
             BEGIN
                DECLARE v_total BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_approved BIGINT;

                SELECT total_minor, total_currency INTO v_total, v_currency
                  FROM finance_invoices WHERE id = NEW.invoice_id;

                IF BINARY NEW.amount_currency <> BINARY v_currency THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_credit_notes.amount_currency must match the invoice currency.\';
                END IF;

                IF NEW.status = \'approved\' THEN
                    SELECT COALESCE(SUM(amount_minor), 0) INTO v_approved
                      FROM finance_credit_notes
                      WHERE invoice_id = NEW.invoice_id AND status = \'approved\';
                    IF v_approved + NEW.amount_minor > v_total THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'Approved credit notes would exceed the invoice total (insert): Σ(approved credits) must be ≤ finance_invoices.total_minor.\';
                    END IF;
                END IF;
             END'
        );

        // 6 ── UPDATE guard: money/identity immutable (only decision columns move) + the
        //      ceiling at the approval transition (Σ approved credits ≤ invoice total).
        DB::unprepared(
            'CREATE TRIGGER '.self::UPDATE_GUARD.' BEFORE UPDATE ON finance_credit_notes
             FOR EACH ROW
             BEGIN
                DECLARE v_total BIGINT;
                DECLARE v_approved BIGINT;

                IF NEW.amount_minor <> OLD.amount_minor
                    OR NEW.amount_currency <> OLD.amount_currency
                    OR NEW.invoice_id <> OLD.invoice_id
                    OR NEW.school_id <> OLD.school_id
                    OR NEW.student_id <> OLD.student_id
                    OR NEW.number <> OLD.number
                    OR NEW.kind <> OLD.kind
                    OR NOT (NEW.note <=> OLD.note)
                    OR NOT (NEW.submitted_by <=> OLD.submitted_by)
                    OR NEW.uuid <> OLD.uuid THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_credit_notes money/identity columns are immutable; only status/decided_by/decided_at/rejection_reason may change.\';
                END IF;

                IF NEW.status = \'approved\' AND OLD.status <> \'approved\' THEN
                    SELECT total_minor INTO v_total FROM finance_invoices WHERE id = NEW.invoice_id;
                    SELECT COALESCE(SUM(amount_minor), 0) INTO v_approved
                      FROM finance_credit_notes
                      WHERE invoice_id = NEW.invoice_id AND status = \'approved\';
                    IF v_approved + NEW.amount_minor > v_total THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'Approved credit notes would exceed the invoice total: Σ(approved credits) must be ≤ finance_invoices.total_minor.\';
                    END IF;
                END IF;
             END'
        );
    }

    public function down(): void
    {
        // Reverse in dependency order, then restore the C1 guards verbatim.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::INSERT_GUARD);
        DB::statement('ALTER TABLE finance_credit_notes DROP CHECK '.self::CHECK);

        Schema::table('finance_credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['status', 'decided_at', 'rejection_reason']);
        });

        // Original C1 append-only no-UPDATE guard.
        DB::unprepared(
            'CREATE TRIGGER '.self::OLD_NO_UPDATE.' BEFORE UPDATE ON finance_credit_notes
             FOR EACH ROW SIGNAL SQLSTATE \'45000\'
             SET MESSAGE_TEXT = \'finance_credit_notes is append-only (Constitution §15C): UPDATE is denied.\';'
        );

        // Original C1 ceiling — BEFORE INSERT, Σ of ALL credits, BINARY currency.
        DB::unprepared(
            'CREATE TRIGGER '.self::OLD_CEILING.' BEFORE INSERT ON finance_credit_notes
             FOR EACH ROW
             BEGIN
                DECLARE v_total BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_already BIGINT;

                SELECT total_minor, total_currency INTO v_total, v_currency
                  FROM finance_invoices WHERE id = NEW.invoice_id;

                IF BINARY NEW.amount_currency <> BINARY v_currency THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_credit_notes.amount_currency must match the invoice currency.\';
                END IF;

                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_credit_notes WHERE invoice_id = NEW.invoice_id;

                IF v_already + NEW.amount_minor > v_total THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'Credit notes would exceed the invoice total: Σ(credits) must be ≤ finance_invoices.total_minor.\';
                END IF;
             END'
        );
    }
};
