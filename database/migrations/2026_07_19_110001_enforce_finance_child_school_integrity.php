<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance template freeze — make "a child row's school_id ALWAYS equals its
 * parent's" unrepresentable-when-violated at the DATABASE, not by application
 * discipline (this project has repeatedly paid for "we always set it right":
 * the guardian_student pre-trigger state, the `->first()` assumptions).
 *
 * Mechanism: a composite foreign key on (parent_id, school_id) → parent(id,
 * school_id). Because the parent's id already determines its school_id (id is the
 * PK), a child can only reference a (parent_id, school_id) pair that exists — i.e.
 * the parent's actual school_id — so a divergent child is rejected by the engine.
 * RESTRICT on delete is preserved (a parent with children cannot be deleted).
 *
 * finance_invoice_lines      → finance_invoices  (a line's School = its invoice's)
 * finance_payment_allocations→ finance_invoices AND finance_payments
 *                              (an allocation's School = its invoice's AND its
 *                               payment's ⇒ payment and invoice share a School:
 *                               no cross-School allocation is representable)
 *
 * Top-level Finance tables (invoices, payments, ledger) own school_id directly and
 * are already filterable by it (indexed leftmost). This migration is additive at
 * the parent (a unique key) and a swap at the child (single-col FK → composite),
 * so down() restores the exact prior single-column FKs.
 */
return new class extends Migration
{
    private array $up = [
        // Parent unique keys the composite child FKs reference.
        'ALTER TABLE finance_invoices ADD UNIQUE finance_invoices_id_school_unique (id, school_id)',
        'ALTER TABLE finance_payments ADD UNIQUE finance_payments_id_school_unique (id, school_id)',

        // invoice_lines: single-col invoice FK → composite (invoice_id, school_id).
        'ALTER TABLE finance_invoice_lines DROP FOREIGN KEY fee_invoice_lines_invoice_id_foreign',
        'ALTER TABLE finance_invoice_lines DROP INDEX fee_invoice_lines_invoice_id_foreign',
        'ALTER TABLE finance_invoice_lines ADD CONSTRAINT finance_invoice_lines_invoice_school_foreign
            FOREIGN KEY (invoice_id, school_id) REFERENCES finance_invoices (id, school_id) ON DELETE RESTRICT',

        // payment_allocations: both single-col FKs → composites.
        'ALTER TABLE finance_payment_allocations DROP FOREIGN KEY fee_payment_allocations_invoice_id_foreign',
        'ALTER TABLE finance_payment_allocations DROP INDEX fee_payment_allocations_invoice_id_foreign',
        'ALTER TABLE finance_payment_allocations DROP FOREIGN KEY fee_payment_allocations_payment_id_foreign',
        'ALTER TABLE finance_payment_allocations DROP INDEX fee_payment_allocations_payment_id_foreign',
        'ALTER TABLE finance_payment_allocations ADD CONSTRAINT finance_payment_allocations_invoice_school_foreign
            FOREIGN KEY (invoice_id, school_id) REFERENCES finance_invoices (id, school_id) ON DELETE RESTRICT',
        'ALTER TABLE finance_payment_allocations ADD CONSTRAINT finance_payment_allocations_payment_school_foreign
            FOREIGN KEY (payment_id, school_id) REFERENCES finance_payments (id, school_id) ON DELETE RESTRICT',
    ];

    private array $down = [
        // Reverse child composites back to the exact original single-column FKs.
        // MySQL leaves a FK's backing index behind on DROP FOREIGN KEY, so each
        // composite index is dropped EXPLICITLY — otherwise the re-added single-col
        // FK reuses the composite index and the original single-col index is never
        // recreated, leaving a differently-named state that a re-up() cannot drop.
        'ALTER TABLE finance_payment_allocations DROP FOREIGN KEY finance_payment_allocations_payment_school_foreign',
        'ALTER TABLE finance_payment_allocations DROP FOREIGN KEY finance_payment_allocations_invoice_school_foreign',
        'ALTER TABLE finance_payment_allocations DROP INDEX finance_payment_allocations_payment_school_foreign',
        'ALTER TABLE finance_payment_allocations DROP INDEX finance_payment_allocations_invoice_school_foreign',
        'ALTER TABLE finance_payment_allocations ADD CONSTRAINT fee_payment_allocations_payment_id_foreign
            FOREIGN KEY (payment_id) REFERENCES finance_payments (id) ON DELETE RESTRICT',
        'ALTER TABLE finance_payment_allocations ADD CONSTRAINT fee_payment_allocations_invoice_id_foreign
            FOREIGN KEY (invoice_id) REFERENCES finance_invoices (id) ON DELETE RESTRICT',

        'ALTER TABLE finance_invoice_lines DROP FOREIGN KEY finance_invoice_lines_invoice_school_foreign',
        'ALTER TABLE finance_invoice_lines DROP INDEX finance_invoice_lines_invoice_school_foreign',
        'ALTER TABLE finance_invoice_lines ADD CONSTRAINT fee_invoice_lines_invoice_id_foreign
            FOREIGN KEY (invoice_id) REFERENCES finance_invoices (id) ON DELETE RESTRICT',

        'ALTER TABLE finance_payments DROP INDEX finance_payments_id_school_unique',
        'ALTER TABLE finance_invoices DROP INDEX finance_invoices_id_school_unique',
    ];

    public function up(): void
    {
        foreach ($this->up as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        foreach ($this->down as $sql) {
            DB::statement($sql);
        }
    }
};
