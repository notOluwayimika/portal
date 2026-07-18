<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance walking skeleton — the per-student subledger (receivable movements).
 * Day-one rule 5: subledger ONLY, no GL/journal (§13 is a later phase).
 *
 * Fully append-only — the core of Engineering Invariant 9. Corrections are
 * reversing entries, never edits. The 1.4c triggers (BOTH update and delete) are
 * the load-bearing guarantee; they hold against raw DB writes and tinker, which is
 * exactly what the ledger bite-proof exercises.
 *
 * source_type/source_id point to the Finance document that caused the movement
 * (invoice / payment allocation) — an internal soft reference, NOT a live FK, so a
 * reversal need not join anything and the row stands on its own snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Durable referents — live FKs, RESTRICT.
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            $table->string('type'); // LedgerEntryType: charge | payment | reversal

            // Signed Money — a debit (charge) is positive, a credit (payment,
            // reversal) is negative. Balance = SUM(amount_minor).
            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            // The Finance document that caused this movement (soft reference, no FK).
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            $table->string('narration'); // snapshot

            $table->timestamps();

            $table->index(['school_id', 'student_id']);
        });

        DB::unprepared(
            "CREATE TRIGGER fee_ledger_no_update BEFORE UPDATE ON fee_ledger_transactions
             FOR EACH ROW SIGNAL SQLSTATE '45000'
             SET MESSAGE_TEXT = 'fee_ledger_transactions is append-only (Constitution §15C): UPDATE is denied. Corrections are reversing entries.';"
        );
        DB::unprepared(
            "CREATE TRIGGER fee_ledger_no_delete BEFORE DELETE ON fee_ledger_transactions
             FOR EACH ROW SIGNAL SQLSTATE '45000'
             SET MESSAGE_TEXT = 'fee_ledger_transactions is append-only (Constitution §15C): DELETE is denied. Corrections are reversing entries.';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fee_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS fee_ledger_no_delete');
        Schema::dropIfExists('fee_ledger_transactions');
    }
};
