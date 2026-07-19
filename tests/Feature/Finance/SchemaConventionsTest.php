<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces the frozen Finance schema conventions at the DB level, so a future
 * aggregate that forgets one fails CI rather than silently drifting:
 *  - every finance_* table carries school_id (uniform tenanting — the arch rule
 *    only asserts the MODEL uses BelongsToSchool; this asserts the COLUMN exists);
 *  - the 1.4c immutability triggers exist by name on the append-only tables;
 *  - the composite (child_fk, school_id) → parent(id, school_id) FKs exist, so a
 *    child's school_id cannot diverge from its parent's.
 */
uses(RefreshDatabase::class);

function financeTables(): array
{
    return collect(DB::select("SHOW TABLES LIKE 'finance_%'"))
        ->map(fn ($row) => array_values((array) $row)[0])
        ->all();
}

it('every finance_ table carries a school_id column (uniform tenanting)', function () {
    $tables = financeTables();
    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'school_id'))
            ->toBeTrue("finance table [{$table}] must carry school_id");
    }
});

it('no stray fee_ Finance table remains after the rename', function () {
    expect(collect(DB::select("SHOW TABLES LIKE 'fee_%'"))->count())->toBe(0);
});

it('the 1.4c immutability triggers exist by name on the finance_ append-only tables', function () {
    $names = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE ?', ['finance_%']
    ))->pluck('TRIGGER_NAME')->all();

    expect($names)->toContain(
        'finance_invoices_no_delete',
        'finance_invoice_lines_no_update',
        'finance_invoice_lines_no_delete',
        'finance_ledger_transactions_no_update',
        'finance_ledger_transactions_no_delete',
        'finance_payments_no_update',
        'finance_payments_no_delete',
        'finance_payment_allocations_no_update',
        'finance_payment_allocations_no_delete',
    );
});

it('composite child.school_id = parent.school_id FKs are present', function () {
    $composite = collect(DB::select(
        "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'
           AND CONSTRAINT_NAME LIKE '%_school_foreign'"
    ))->pluck('CONSTRAINT_NAME')->all();

    expect($composite)->toContain(
        'finance_invoice_lines_invoice_school_foreign',
        'finance_payment_allocations_invoice_school_foreign',
        'finance_payment_allocations_payment_school_foreign',
    );
});
