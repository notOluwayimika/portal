<?php

namespace App\Finance\Enums;

/**
 * Invoice lifecycle for the walking skeleton. Deliberately two states: an invoice
 * is ISSUED on creation (no draft/approval — maker-checker is Ph3) and CANCELLED
 * by a reversing ledger entry (never deleted — docs/finance-data-ownership.md:
 * cancellation is a status change + a reversal, never a row deletion).
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
