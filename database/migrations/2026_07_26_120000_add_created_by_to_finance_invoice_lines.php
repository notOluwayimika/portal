<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the reduction-audit hole (S1 Part 0). Forgiving ₦1 AFTER an invoice is issued takes two
 * named people (credit-note maker-checker); forgiving the WHOLE invoice at the moment it is raised
 * took one person holding only `finance.access` and left no record of who they were. Every other
 * Finance document names its human — `cancelled_by_user_id`, `received_by_user_id`,
 * `created_by_user_id`, `submitted_by`/`decided_by`. The one gap was the discretionary reduction a
 * bursar applies by hand, which is exactly what the school's audit requirement covers.
 *
 * `created_by_user_id` is a LOOKUP, not a FK: plain `unsignedBigInteger`, nullable, no
 * `constrained()`. Same convention (and same reason) as `finance_invoices.cancelled_by_user_id`,
 * `finance_payments.received_by_user_id`, `finance_credit_notes.created_by_user_id` — an attribution
 * must never block a user's lifecycle. Nullable because every line written before this migration has
 * no known actor; back-filling a guess would be worse than an honest null.
 *
 * ALTER TABLE is DDL and does not fire the `finance_invoice_lines_no_update` BEFORE-UPDATE trigger
 * (a DML trigger) — verified by precedent: `2026_07_21_120000_add_kind_and_note_to_finance_invoice_lines`
 * already added columns to this table while that trigger was live. The append-only triggers are
 * untouched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('fee_item_id');
            $table->index(['school_id', 'created_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }
};
