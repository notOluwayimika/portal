<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance walking skeleton — payments + allocations.
 *
 * A payment belongs to the STUDENT ACCOUNT (school + student), not to an invoice —
 * the allocation is the money→invoice link, which is what makes unallocated/advance
 * payments expressible (docs/finance-data-ownership.md Part 1/2). Both tables are
 * append-only: a recorded payment and its allocation are immutable facts; a
 * correction is a reversing ledger entry, not an edit.
 *
 * received_by_user_id is LOOKUP attribution — a plain id, not an FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            $table->unsignedBigInteger('reference'); // school-scoped receipt sequence (stub)

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->string('payer_name');           // snapshot
            $table->string('method')->default('manual'); // snapshot
            $table->unsignedBigInteger('received_by_user_id')->nullable(); // LOOKUP, not an FK

            $table->timestamps();

            $table->unique(['school_id', 'reference']);
        });

        Schema::create('fee_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Uniform school_id on every Finance table (arch rule 5).
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('fee_payments')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('fee_invoices')->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->timestamps();
        });

        foreach ([
            ['fee_payments_no_update', 'fee_payments', 'UPDATE'],
            ['fee_payments_no_delete', 'fee_payments', 'DELETE'],
            ['fee_payment_allocations_no_update', 'fee_payment_allocations', 'UPDATE'],
            ['fee_payment_allocations_no_delete', 'fee_payment_allocations', 'DELETE'],
        ] as [$name, $tableName, $event]) {
            DB::unprepared(
                "CREATE TRIGGER {$name} BEFORE {$event} ON {$tableName}
                 FOR EACH ROW SIGNAL SQLSTATE '45000'
                 SET MESSAGE_TEXT = '{$tableName} is append-only (Constitution §15C): {$event} is denied.';"
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'fee_payments_no_update', 'fee_payments_no_delete',
            'fee_payment_allocations_no_update', 'fee_payment_allocations_no_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('fee_payment_allocations');
        Schema::dropIfExists('fee_payments');
    }
};
