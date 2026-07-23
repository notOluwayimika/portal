<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §10 C1 — credit notes & write-offs. A credit note is its OWN append-only aggregate
 * issued against an invoice; it posts a compensating ledger credit (−amount,
 * source_type=credit_note) through SubledgerPoster, and that is the whole money
 * machinery — the wallet (W1+W2) absorbs any resulting credit balance and W3 carries
 * it forward. The invoice stays frozen (F6); the credit sits BESIDE it, never netted in.
 *
 * NOT an allocation. A credit note has no payment_id and never touches
 * finance_payment_allocations — fork 6 is dissolved. It emits its own ledger credit
 * sourced to itself.
 *
 * Three guards live here:
 *   1. 1.4c immutability — UPDATE and DELETE denied (append-only, like ledger/payments).
 *   2. The over-credit ceiling — Σ(credit notes for an invoice) ≤ invoice total. A NEW,
 *      INDEPENDENT guard: #94 keeps Σallocations ≤ total; this keeps Σcredits ≤ total.
 *      Their sum MAY exceed total (the paid-invoice-credited case → wallet), so there is
 *      deliberately NO joint cap and #94's trigger is untouched.
 *   3. Composite (invoice_id, school_id) → finance_invoices(id, school_id) — F3: a credit
 *      note's School is always its invoice's.
 *
 * Deployed-table-safe: a NEW table with NO alter to any live Finance table. #85 — this
 * migration is the branch's latest by timestamp; its reversibility audit finds it by NAME
 * and asserts the table is gone, never trusting a bare --step=N.
 */
return new class extends Migration
{
    private const CEILING_TRIGGER = 'finance_credit_note_not_over_invoice_total';

    public function up(): void
    {
        Schema::create('finance_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Uniform school_id on every Finance table (arch rule 5).
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            // Denormalised account holder (top-level ref, like invoice/payment).
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            // invoice_id gets a COMPOSITE (invoice_id, school_id) FK below, not the
            // single-column one constrained() would add — so the credit note's School
            // can never diverge from its invoice's (F3 template freeze).
            $table->unsignedBigInteger('invoice_id');

            $table->unsignedBigInteger('number'); // per-School credit-note sequence

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->string('kind')->default('credit_note'); // CreditNoteKind
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable(); // LOOKUP, not an FK

            $table->timestamps();

            $table->unique(['school_id', 'number']);

            // Composite child-School FK (F3): references the parent unique key
            // finance_invoices(id, school_id) added by the child-integrity migration.
            $table->foreign(['invoice_id', 'school_id'], 'finance_credit_notes_invoice_school_foreign')
                ->references(['id', 'school_id'])->on('finance_invoices')->restrictOnDelete();
        });

        // 1.4c immutability — append-only like every other Finance ledger-family table.
        foreach ([
            ['finance_credit_notes_no_update', 'UPDATE'],
            ['finance_credit_notes_no_delete', 'DELETE'],
        ] as [$name, $event]) {
            DB::unprepared(
                "CREATE TRIGGER {$name} BEFORE {$event} ON finance_credit_notes
                 FOR EACH ROW SIGNAL SQLSTATE '45000'
                 SET MESSAGE_TEXT = 'finance_credit_notes is append-only (Constitution §15C): {$event} is denied.';"
            );
        }

        // The over-credit ceiling — the real guarantee against a single raw/tamper write
        // (the #94 pattern, applied to credits). BINARY currency comparison, not a plain
        // <>: a routine variable inherits the connection collation while the column takes
        // the table's; on a DB created with a different default they disagree and MySQL
        // 1267s on EVERY insert, killing the guard (the #95 lesson). A currency code is
        // 3-letter ASCII, so a byte comparison is exactly right and collation-agnostic.
        DB::unprepared(
            'CREATE TRIGGER '.self::CEILING_TRIGGER.' BEFORE INSERT ON finance_credit_notes
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

                -- Σ of ALL prior credits for this invoice (append-only ⇒ the whole
                -- history). Independent of Σallocations (#94) — the two ceilings do not
                -- share a cap; their sum may exceed the total (paid-then-credited → wallet).
                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_credit_notes WHERE invoice_id = NEW.invoice_id;

                -- ≤, not <: a credit exactly filling the remaining total is legal.
                IF v_already + NEW.amount_minor > v_total THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'Credit notes would exceed the invoice total: Σ(credits) must be ≤ finance_invoices.total_minor.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::CEILING_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS finance_credit_notes_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS finance_credit_notes_no_delete');
        Schema::dropIfExists('finance_credit_notes');
    }
};
