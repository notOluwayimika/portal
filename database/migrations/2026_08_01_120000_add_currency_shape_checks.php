<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the currency SHAPE invariant in the database — a CHECK on all ten *_currency columns that a
 * non-null value matches ^[A-Z]{3}$, case-sensitively.
 *
 * WHY NOW. Until 2026-08-01 `^[A-Z]{3}$` lived in exactly two PHP places — Money's constructor and the
 * S1 FormRequest regexes (5eda307) — and `grep CHECK …*currency` in the migrations returned nothing. A
 * rule with no DB constraint is wallpaper: the two discount `value_currency` columns had a FormRequest in
 * front and nothing behind (any Action/seeder/import/tinker stored 'ngn'), and the eight cast columns are
 * only protected while written through Money's constructor — `SubledgerPoster` writes
 * finance_student_accounts with raw `DB::insert` (it must; the boundary lint forbids `DB::table` on a
 * finance_ literal, and raw SQL is exactly what skips the constructor — the 647d419+1 corruption class).
 * The production sweep (2026-08-01) found Finance EMPTY (all ten columns total = 0), so this ALTER cannot
 * fail on legacy data and needs no backfill — a window that closes the first time a school records a payment.
 *
 * SHAPE ONLY, deliberately not `= 'NGN'`. Single-currency is a business decision that lives in
 * Money::DEFAULT_CURRENCY and the FormRequests; a DB constraint pinning NGN would have to be dropped the
 * day a second currency is real. Shape is the permanent invariant.
 *
 * COLLATE utf8mb4_bin is load-bearing (Part 0 proved it on 8.0.43): under the default case-insensitive
 * collation 'ngn' matches ^[A-Z]{3}$ and the CHECK is a false all-clear — the same trap as the sweep's
 * collation. Live names are finance_* (2026_07_19_110000 renamed them from fee_*); the create migrations
 * still say fee_*, so the names below come from the rename, not those files. The two discount tables'
 * existing BEFORE UPDATE immutability triggers are untouched — a CHECK and a trigger coexist.
 */
return new class extends Migration
{
    /** @var list<array{0:string,1:string}> */
    private const COLUMNS = [
        ['finance_invoice_lines', 'amount_currency'],
        ['finance_invoices', 'total_currency'],
        ['finance_ledger_transactions', 'amount_currency'],
        ['finance_payments', 'amount_currency'],
        ['finance_payment_allocations', 'amount_currency'],
        ['finance_credit_notes', 'amount_currency'],
        ['finance_fee_items', 'amount_currency'],
        ['finance_student_accounts', 'balance_currency'],
        ['finance_discount_policies', 'value_currency'],
        ['finance_discount_policy_changes', 'value_currency'],
    ];

    public function up(): void
    {
        // Pre-flight — fail BEFORE the first ALTER if any column already holds a bad value. Production is
        // empty today, but a non-empty environment with one bad row would otherwise leave a half-constrained
        // schema (some ALTERs applied, the offending one aborted). Fail closed and name what to fix.
        $bad = [];
        foreach (self::COLUMNS as [$table, $column]) {
            $count = (int) DB::scalar(
                "SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL AND {$column} COLLATE utf8mb4_bin NOT REGEXP '^[A-Z]{3}\$'"
            );
            if ($count > 0) {
                $bad[] = "{$table}.{$column}={$count}";
            }
        }
        if ($bad !== []) {
            throw new RuntimeException(
                'Currency-shape CHECK aborted: existing non-conforming rows must be corrected first — '.implode(', ', $bad)
            );
        }

        foreach (self::COLUMNS as [$table, $column]) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_shape
                 CHECK ({$column} IS NULL OR {$column} COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
            );
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$table}_{$column}_shape");
        }
    }
};
