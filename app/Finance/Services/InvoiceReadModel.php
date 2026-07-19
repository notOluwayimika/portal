<?php

namespace App\Finance\Services;

use App\Finance\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The invoice read side. Finance-private (arch: Services are used only inside
 * App\Finance).
 *
 * This exists so the exclude-void rule is LOAD-BEARING rather than decorative: a
 * scope nothing consumes proves nothing. Reporting reads default to excluding
 * voided invoices; the audit view opts in explicitly by passing $includeVoid.
 *
 * Voidness is filtered here, in the read model, and NOT by a global scope on the
 * Invoice model — see Invoice::scopeExcludingVoid() for why (a global scope would
 * break route-model binding and turn the double-void 422 into a 404).
 *
 * School isolation is automatic: Invoice uses BelongsToSchool, so every query
 * below is already scoped to the Active School.
 */
final class InvoiceReadModel
{
    /**
     * @return Collection<int, Invoice>
     */
    public function forStudent(int $studentId, bool $includeVoid = false): Collection
    {
        return Invoice::query()
            ->where('student_id', $studentId)
            ->when(! $includeVoid, fn ($q) => $q->excludingVoid())
            ->with('lines')
            ->orderBy('id')
            ->get();
    }

    /**
     * Total billed to a student. Voided invoices are excluded by default — a void
     * was never really billed, so including it would overstate the receivable.
     *
     * Summed with Money::plus rather than a SQL SUM so the currency invariant is
     * carried through: a mixed-currency total is meaningless and throws rather
     * than silently adding kobo to cents.
     */
    public function billedTotalForStudent(int $studentId, bool $includeVoid = false): Money
    {
        return $this->forStudent($studentId, $includeVoid)
            ->reduce(
                static fn (?Money $carry, Invoice $invoice) => $carry === null
                    ? $invoice->total
                    : $carry->plus($invoice->total),
            ) ?? Money::fromKobo(0);
    }
}
