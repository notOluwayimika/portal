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

it('slice-2 guards exist by NAME (F6 total immutability + the active-enrollment uniqueness)', function () {
    // Confirmed by name rather than by behaviour, so a migration that silently
    // drops one fails here even if some other mechanism happens to mask it.
    $triggers = collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_invoices']
    ))->pluck('TRIGGER_NAME')->all();

    expect($triggers)->toContain('finance_invoices_total_immutable')
        ->and($triggers)->toContain('finance_invoices_no_delete'); // F4 survived slice 2

    $indexes = collect(DB::select('SHOW INDEX FROM finance_invoices'))->pluck('Key_name')->unique()->all();
    expect($indexes)->toContain('finance_invoices_active_enrollment_unique');

    // The uniqueness must be backed by the GENERATED column — an app-maintained
    // column would reintroduce the "someone forgets to clear it" failure mode.
    $column = collect(DB::select(
        "SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_invoices'
           AND COLUMN_NAME = 'active_enrollment_key'"
    ))->first();

    expect($column)->not->toBeNull()
        ->and(strtoupper((string) $column->EXTRA))->toContain('STORED GENERATED');
});

it('F1–F4 survive slice 2: prefix, school_id, composite FKs and append-only are all intact', function () {
    // F1 — every Finance table still carries the finance_ prefix (and no fee_ ones).
    expect(financeTables())->not->toBeEmpty()
        ->and(collect(DB::select("SHOW TABLES LIKE 'fee_%'"))->count())->toBe(0);

    // F2 — school_id on every finance_ table, including the ones slice 2 touched.
    foreach (financeTables() as $table) {
        expect(Schema::hasColumn($table, 'school_id'))->toBeTrue("[{$table}] lost school_id");
    }

    // F3 — the composite (child_fk, school_id) FKs still exist after slice 2
    // altered finance_invoices (adding a column rebuilds the table in MySQL).
    $composite = collect(DB::select(
        "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'
           AND CONSTRAINT_NAME LIKE '%_school_foreign'"
    ))->pluck('CONSTRAINT_NAME')->all();
    expect($composite)->toContain('finance_invoice_lines_invoice_school_foreign');

    // The parent unique key those composite FKs reference must also survive.
    expect(collect(DB::select('SHOW INDEX FROM finance_invoices'))->pluck('Key_name')->unique()->all())
        ->toContain('finance_invoices_id_school_unique');

    // F4 — invoice DELETE-deny still present alongside slice 2's new UPDATE
    // trigger (multiple triggers coexist on one table). Asserted by NAME: a
    // behavioural DELETE on a non-existent id proves nothing, because a
    // FOR EACH ROW trigger never fires when no row matches.
    expect(collect(DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', ['finance_invoices']
    ))->pluck('TRIGGER_NAME')->all())->toContain('finance_invoices_no_delete');
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
