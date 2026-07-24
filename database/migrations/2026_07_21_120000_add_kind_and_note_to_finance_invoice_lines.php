<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing-time reductions (§5 waivers/discounts) — the line half.
 *
 * THE FIRST FINANCE MIGRATION TO ALTER A DEPLOYED TABLE WITH LIVE DATA, so it is kept
 * to the safest shape that exists:
 *
 *  - `kind` is NOT NULL DEFAULT 'charge'. Every row written before this migration IS a
 *    charge, so the default is correct BY CONSTRUCTION — there is no backfill query, no
 *    data rewrite, and no row is read. A wrong default here would have been a silent
 *    reclassification of live invoice history; 'charge' is the only value that cannot be.
 *  - `note` is nullable. Absence of a reason is a valid state.
 *
 * NO SIGN COLUMN. A reduction is simply a line with a NEGATIVE `amount_minor`. Storing
 * the sign separately would create two sources of truth for one fact and let them
 * disagree — and it would break the fold, which is a literal signed SUM.
 *
 * F6 IS NOT TOUCHED. `finance_invoices_total_immutable` guards total_minor/total_currency
 * on UPDATE; reductions are supplied at CREATION, folded into the total inside
 * GenerateInvoice's transaction, and frozen exactly as charges are. This migration adds
 * no trigger, alters no trigger, and changes nothing about how the total relates to its
 * lines.
 *
 * The append-only triggers on finance_invoice_lines (no_update / no_delete) are likewise
 * untouched, and stay correct: a reduction is a NEW line, never a mutation of the fee
 * line it offsets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->string('kind', 16)->default('charge')->after('description');
            $table->string('note', 255)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['kind', 'note']);
        });
    }
};
