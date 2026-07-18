<?php

namespace App\Finance\Services;

use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\LedgerTransaction;
use App\Support\Money;

/**
 * The single writer of subledger rows (Engineering Invariant 7: one authoritative
 * entry point). Every charge, payment and reversal posts through here so the row
 * shape is defined once. Finance-private (arch: Services used only in App\Finance).
 *
 * It never opens its own transaction — it is always called INSIDE an Action's
 * transaction, so the ledger post commits atomically with the state change that
 * caused it (a charge can never exist without its invoice, nor a reversal without
 * its cancellation).
 */
final class SubledgerPoster
{
    public function post(
        int $schoolId,
        int $studentId,
        LedgerEntryType $type,
        Money $amount,
        string $sourceType,
        int $sourceId,
        string $narration,
    ): LedgerTransaction {
        return LedgerTransaction::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'type' => $type,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'narration' => $narration,
        ]);
    }
}
