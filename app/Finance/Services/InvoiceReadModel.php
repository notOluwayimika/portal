<?php

namespace App\Finance\Services;

use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\StudentAccount;
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

    /**
     * A student's credit notes, for the statement (§5/§7 integrity). Returned as their
     * OWN documents to sit BESIDE the invoices — the caller renders each separately and
     * never nets a credit into an invoice's displayed amount. School isolation is
     * automatic (CreditNote uses BelongsToSchool). Append-only, so ordering by id is
     * stable issue-order.
     *
     * @return Collection<int, CreditNote>
     */
    public function creditNotesForStudent(int $studentId): Collection
    {
        return CreditNote::query()
            ->where('student_id', $studentId)
            ->orderBy('id')
            ->get();
    }

    /**
     * The student's ACCOUNT-level position for the statement. This is where credit-note
     * credit is visible: it carries on the balance, not as a per-invoice line (§10 C1),
     * so a statement that only listed invoices and payments would hide it. Returns the
     * signed balance (positive = owed, negative = the school owes the student) and the
     * derived available credit (max(0, −balance)). A student with no ledger activity has
     * no account row yet — that reads as a zero balance, not an error.
     *
     * @return array{balance: Money, available_credit: Money}
     */
    public function accountPositionForStudent(int $studentId): array
    {
        $account = StudentAccount::query()->where('student_id', $studentId)->first();

        if ($account === null) {
            $zero = Money::fromKobo(0);

            return ['balance' => $zero, 'available_credit' => $zero];
        }

        return ['balance' => $account->balance, 'available_credit' => $account->availableCredit()];
    }
}
