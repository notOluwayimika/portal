<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance template freeze: rename the walking-skeleton `fee_*` tables to the
 * frozen `finance_*` prefix (the module-ownership marker the boundary lint now
 * keys on). RENAME TABLE preserves data, foreign keys and indexes, and MySQL
 * carries triggers across the rename too — but the 1.4c immutability triggers are
 * dropped and recreated here so their NAMES match the new tables (verifiable by
 * name afterwards), rather than lingering as `fee_*`-named triggers on
 * `finance_*` tables.
 *
 * down() is exact: it restores the `fee_*` tables and the original `fee_*` trigger
 * names, so a rollback leaves the schema byte-for-byte as the create migrations
 * left it (and a re-up() reapplies cleanly).
 */
return new class extends Migration
{
    /** old fee_ table => new finance_ table */
    private const RENAMES = [
        'fee_invoices' => 'finance_invoices',
        'fee_invoice_lines' => 'finance_invoice_lines',
        'fee_ledger_transactions' => 'finance_ledger_transactions',
        'fee_payments' => 'finance_payments',
        'fee_payment_allocations' => 'finance_payment_allocations',
    ];

    /** finance_ triggers: [name, table, event, message] */
    private function financeTriggers(): array
    {
        return [
            ['finance_invoices_no_delete', 'finance_invoices', 'DELETE', 'finance_invoices are append-only (Constitution §15C): DELETE is denied — cancel with a reversing ledger entry.'],
            ['finance_invoice_lines_no_update', 'finance_invoice_lines', 'UPDATE', 'finance_invoice_lines are an immutable snapshot (Constitution §15C): UPDATE is denied.'],
            ['finance_invoice_lines_no_delete', 'finance_invoice_lines', 'DELETE', 'finance_invoice_lines are an immutable snapshot (Constitution §15C): DELETE is denied.'],
            ['finance_ledger_transactions_no_update', 'finance_ledger_transactions', 'UPDATE', 'finance_ledger_transactions is append-only (Constitution §15C): UPDATE is denied. Corrections are reversing entries.'],
            ['finance_ledger_transactions_no_delete', 'finance_ledger_transactions', 'DELETE', 'finance_ledger_transactions is append-only (Constitution §15C): DELETE is denied. Corrections are reversing entries.'],
            ['finance_payments_no_update', 'finance_payments', 'UPDATE', 'finance_payments is append-only (Constitution §15C): UPDATE is denied.'],
            ['finance_payments_no_delete', 'finance_payments', 'DELETE', 'finance_payments is append-only (Constitution §15C): DELETE is denied.'],
            ['finance_payment_allocations_no_update', 'finance_payment_allocations', 'UPDATE', 'finance_payment_allocations is append-only (Constitution §15C): UPDATE is denied.'],
            ['finance_payment_allocations_no_delete', 'finance_payment_allocations', 'DELETE', 'finance_payment_allocations is append-only (Constitution §15C): DELETE is denied.'],
        ];
    }

    /** original fee_ triggers: [name, table, event, message] (for down()) */
    private function feeTriggers(): array
    {
        return [
            ['fee_invoices_no_delete', 'fee_invoices', 'DELETE', 'fee_invoices are append-only (Constitution §15C): DELETE is denied — cancel with a reversing ledger entry.'],
            ['fee_invoice_lines_no_update', 'fee_invoice_lines', 'UPDATE', 'fee_invoice_lines are an immutable snapshot (Constitution §15C): UPDATE is denied.'],
            ['fee_invoice_lines_no_delete', 'fee_invoice_lines', 'DELETE', 'fee_invoice_lines are an immutable snapshot (Constitution §15C): DELETE is denied.'],
            ['fee_ledger_no_update', 'fee_ledger_transactions', 'UPDATE', 'fee_ledger_transactions is append-only (Constitution §15C): UPDATE is denied. Corrections are reversing entries.'],
            ['fee_ledger_no_delete', 'fee_ledger_transactions', 'DELETE', 'fee_ledger_transactions is append-only (Constitution §15C): DELETE is denied. Corrections are reversing entries.'],
            ['fee_payments_no_update', 'fee_payments', 'UPDATE', 'fee_payments is append-only (Constitution §15C): UPDATE is denied.'],
            ['fee_payments_no_delete', 'fee_payments', 'DELETE', 'fee_payments is append-only (Constitution §15C): DELETE is denied.'],
            ['fee_payment_allocations_no_update', 'fee_payment_allocations', 'UPDATE', 'fee_payment_allocations is append-only (Constitution §15C): UPDATE is denied.'],
            ['fee_payment_allocations_no_delete', 'fee_payment_allocations', 'DELETE', 'fee_payment_allocations is append-only (Constitution §15C): DELETE is denied.'],
        ];
    }

    private function dropTriggers(array $triggers): void
    {
        foreach ($triggers as [$name]) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    private function createTriggers(array $triggers): void
    {
        foreach ($triggers as [$name, $table, $event, $message]) {
            $escaped = str_replace("'", "''", $message);
            DB::unprepared(
                "CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$escaped}';"
            );
        }
    }

    public function up(): void
    {
        $this->dropTriggers($this->feeTriggers());
        foreach (self::RENAMES as $from => $to) {
            Schema::rename($from, $to);
        }
        $this->createTriggers($this->financeTriggers());
    }

    public function down(): void
    {
        $this->dropTriggers($this->financeTriggers());
        foreach (self::RENAMES as $from => $to) {
            Schema::rename($to, $from);
        }
        $this->createTriggers($this->feeTriggers());
    }
};
