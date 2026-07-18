<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance walking skeleton — invoices + lines. First app/Finance-owned tables.
 *
 * Day-one rules (docs/finance-data-ownership.md):
 *  1. Every referent FK is ON DELETE RESTRICT — the DB blocks any academic
 *     delete-cascade that would reach a Finance record the moment one invoice
 *     exists (school/student/enrollment are all CASCADE upstream; RESTRICT here
 *     fails the whole cascade).
 *  2. Displayed labels are SNAPSHOTS (billed_to_name, academic_context) — copied,
 *     never re-joined to a mutable academic row.
 *  3. No FK to curricula/terms/sessions — those are snapshots.
 *  4. The 1.4c immutability pattern ships in THIS migration: invoice_lines are
 *     append-only (UPDATE+DELETE denied); invoices are un-deletable but their
 *     status mutates (issued → cancelled), so only DELETE is denied.
 *  6. Money is {name}_minor + {name}_currency (ADR 0038), via MoneyCast.
 *
 * LOOKUP attributions (cancelled_by_user_id) are plain ids, NOT FKs — they are
 * never load-bearing and must not block a user's lifecycle (REF/LOOKUP split).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Durable referents — live FKs, all RESTRICT.
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('student_curriculum_id')->constrained('student_curricula')->restrictOnDelete();

            // School-scoped invoice number (internal sequential id via Sequences —
            // gap-tolerant stub; gap-free numbering is a production ADR + signed policy).
            $table->unsignedBigInteger('number');

            $table->string('status')->default('issued');

            // Snapshots — captured at billing time, never re-joined.
            $table->string('billed_to_name');
            $table->string('academic_context');

            // Money (ADR 0038).
            $table->bigInteger('total_minor');
            $table->char('total_currency', 3);

            // Cancellation metadata (status mutates; row is never deleted).
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable(); // LOOKUP, not an FK
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'number']);
        });

        Schema::create('fee_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            // Every Finance table carries school_id so BelongsToSchool scopes
            // uniformly (arch rule 5) — denormalized from the parent invoice.
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('fee_invoices')->restrictOnDelete();

            $table->string('description'); // snapshot
            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->unsignedBigInteger('fee_item_id')->nullable(); // LOOKUP provenance; no target in the skeleton

            $table->timestamps();
        });

        // 1.4c immutability. invoice_lines: fully append-only. invoices: no DELETE
        // (status UPDATE is legitimate — cancellation).
        DB::unprepared($this->denyTrigger('fee_invoices_no_delete', 'fee_invoices', 'DELETE', 'fee_invoices are append-only (Constitution §15C): DELETE is denied — cancel with a reversing ledger entry.'));
        DB::unprepared($this->denyTrigger('fee_invoice_lines_no_update', 'fee_invoice_lines', 'UPDATE', 'fee_invoice_lines are an immutable snapshot (Constitution §15C): UPDATE is denied.'));
        DB::unprepared($this->denyTrigger('fee_invoice_lines_no_delete', 'fee_invoice_lines', 'DELETE', 'fee_invoice_lines are an immutable snapshot (Constitution §15C): DELETE is denied.'));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fee_invoices_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS fee_invoice_lines_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS fee_invoice_lines_no_delete');
        Schema::dropIfExists('fee_invoice_lines');
        Schema::dropIfExists('fee_invoices');
    }

    private function denyTrigger(string $name, string $table, string $event, string $message): string
    {
        return "CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
    }
};
