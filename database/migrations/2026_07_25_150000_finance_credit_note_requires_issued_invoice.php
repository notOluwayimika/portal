<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ph3b remediation — a credit note may be APPROVED (or raw-inserted as approved) only while its
 * invoice is still `issued`.
 *
 * THE HOLE this closes sits BETWEEN the two maker-checker instances, not inside either. A credit
 * note submitted while the invoice was live sits `submitted` — no money moved, so VoidEligibility
 * (which only sees APPROVED credits) does not see it. The invoice is then voided and its whole
 * charge reversed. The stale credit note is still pending against a dead document; approving it
 * passes the ceiling (Σ approved ≤ total, and the void did not touch that sum) and posts a
 * compensating credit — conjuring money from a voided invoice. Fully signed, fully audited, no
 * rule broken, real money created.
 *
 * The general rule (docs/handoff/maker-checker-two-instance-diff.md): every approval must
 * re-check, AT approval time and under the lock, that its subject is still in the state that made
 * the action legal. The app layer (ApproveCreditNote) is the friendly refusal; this trigger is
 * the control — it holds for a raw UPDATE to 'approved' and for a raw INSERT already claiming
 * 'approved', the same two paths the C1→Ph3 ceiling guard already covers.
 *
 * MECHANISM: re-create finance_credit_notes_{update,insert}_guard (owned by
 * 2026_07_25_120000_finance_credit_note_maker_checker) with one added check inside the same
 * `approved` blocks — SIGNAL when the referenced invoice's status is not 'issued'. The rest of
 * each guard is reproduced verbatim; this migration does not edit the prior one (already on
 * staging) — it drops+recreates the triggers, which is how MySQL triggers are "altered".
 *
 * Collation (#95): the added check compares a DECLAREd v_status to the literal 'issued'. A
 * variable inherits the connection collation and the literal the connection charset, so they
 * already agree — but BINARY is used regardless (status is lowercase ASCII: 'issued'/'void'),
 * matching the insert guard's existing BINARY currency discipline and immune to a
 * differently-defaulted DB.
 *
 * down() (#85, by NAME): restores the two guards to their pre-remediation form (the exact bodies
 * from 2026_07_25_120000) — no status check. It restores nothing else; the columns/CHECK/ceiling
 * are owned by the prior migration. The reversibility audit finds THIS migration by name and
 * asserts a credit note CAN again be approved against a voided invoice after rollback.
 */
return new class extends Migration
{
    private const INSERT_GUARD = 'finance_credit_notes_insert_guard';

    private const UPDATE_GUARD = 'finance_credit_notes_update_guard';

    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::INSERT_GUARD);

        // INSERT guard — currency (always) + [invoice-issued + ceiling] only when a raw insert
        // already claims approved. The invoice-issued check is the new line.
        DB::unprepared(
            'CREATE TRIGGER '.self::INSERT_GUARD.' BEFORE INSERT ON finance_credit_notes
             FOR EACH ROW
             BEGIN
                DECLARE v_total BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_approved BIGINT;
                DECLARE v_status VARCHAR(255);

                SELECT total_minor, total_currency, status INTO v_total, v_currency, v_status
                  FROM finance_invoices WHERE id = NEW.invoice_id;

                IF BINARY NEW.amount_currency <> BINARY v_currency THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_credit_notes.amount_currency must match the invoice currency.\';
                END IF;

                IF NEW.status = \'approved\' THEN
                    IF BINARY v_status <> BINARY \'issued\' THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'A credit note cannot be inserted as approved against an invoice that is not issued (it was voided).\';
                    END IF;

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

        // UPDATE guard — money/identity immutable + [invoice-issued + ceiling] at the approval
        // transition. The invoice-issued check is the new line, checked BEFORE the ceiling
        // (a voided invoice refuses regardless of how much has been credited).
        DB::unprepared(
            'CREATE TRIGGER '.self::UPDATE_GUARD.' BEFORE UPDATE ON finance_credit_notes
             FOR EACH ROW
             BEGIN
                DECLARE v_total BIGINT;
                DECLARE v_approved BIGINT;
                DECLARE v_status VARCHAR(255);

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
                    SELECT total_minor, status INTO v_total, v_status
                      FROM finance_invoices WHERE id = NEW.invoice_id;

                    IF BINARY v_status <> BINARY \'issued\' THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                            \'A credit note cannot be approved against an invoice that is not issued (it was voided).\';
                    END IF;

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
        // Restore the two guards to their 2026_07_25_120000 form (no invoice-issued check).
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::INSERT_GUARD);

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
};
