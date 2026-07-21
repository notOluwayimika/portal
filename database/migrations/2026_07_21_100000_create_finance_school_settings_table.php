<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School-scoped Finance configuration (§7 of the signed accounting policy).
 *
 * ADDITIVE ONLY — this is the first Finance migration against a DEPLOYED module, so
 * it creates a NEW table and alters nothing. `finance_invoices` is untouched: its
 * `number` stays `unsignedBigInteger` under `UNIQUE(school_id, number)`, and no
 * existing row is read or rewritten. Nothing here can fail against live data.
 *
 * ONE SETTING, DELIBERATELY. The policy names three per-School configurables — number
 * prefix (§2), waiver approver (§5), repeat treatment (§6) — but only the prefix has a
 * consumer in this slice. The other two are shaped-for, not built: adding a column
 * later is additive, whereas guessing their type/semantics now is the
 * front-load-a-primitive-ahead-of-its-consumer trap that also defers the rounding op.
 *
 * A top-level Finance table, so it owns `school_id` directly with a durable RESTRICT
 * FK (per docs/finance-data-ownership.md — child tables carry composite FKs instead).
 * UNIQUE(school_id) makes "at most one settings row per School" a database fact rather
 * than an application convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_school_settings', function (Blueprint $table) {
            $table->id();

            // One row per School — enforced, not assumed.
            $table->foreignId('school_id')->unique()->constrained('schools')->restrictOnDelete();

            /*
             * The invoice-number prefix, stored as the LITERAL string it renders with,
             * separator included — the policy's own defaults are `BSS-`, `BSP-` and
             * `BSI-LAG-`. It is not a token we decorate: `BSI-LAG-` carries an internal
             * hyphen, so any "append a dash" logic would produce `BSI-LAG--42`.
             *
             * NULL means no prefix: the invoice displays its bare number. That is the
             * default for every School until one is configured, so this migration
             * changes no invoice's appearance on the day it lands.
             */
            $table->string('invoice_number_prefix', 16)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_school_settings');
    }
};
