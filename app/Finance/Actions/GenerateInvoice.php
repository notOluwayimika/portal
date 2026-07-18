<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Invoice;
use App\Finance\Services\SubledgerPoster;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Raise one invoice for one enrollment and post its charge to the subledger — the
 * whole "generate invoice for enrollment X" use case, in one transaction (the
 * invoice, its line, and the ledger charge commit together or not at all).
 *
 * The enrollment is read through the ACL port ({@see BillableEnrollmentProvider}),
 * never StudentCurriculum — so this file has no academic import, which is the
 * boundary the arch test guards. The amount is supplied by the caller (no fee
 * schedule in the skeleton); numbering is the gap-tolerant Sequences stub.
 */
final class GenerateInvoice
{
    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly SubledgerPoster $ledger,
    ) {}

    public function handle(string $enrollmentUuid, Money $amount, string $description): Invoice
    {
        $enrollment = $this->enrollments->findByUuid($enrollmentUuid);

        if ($enrollment === null) {
            throw new BusinessRuleException('No billable enrollment found for the given reference.');
        }

        if ($amount->isZero() || $amount->isNegative()) {
            throw new BusinessRuleException('An invoice amount must be positive.');
        }

        return DB::transaction(function () use ($enrollment, $amount, $description) {
            $number = Sequences::next('fee_invoice', (string) $enrollment->schoolId);

            $invoice = Invoice::create([
                'school_id' => $enrollment->schoolId,
                'student_id' => $enrollment->studentId,
                'student_curriculum_id' => $enrollment->enrollmentId,
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'billed_to_name' => $enrollment->studentName,
                'academic_context' => $enrollment->academicContext,
                'total' => $amount,
            ]);

            $invoice->lines()->create([
                'school_id' => $enrollment->schoolId,
                'description' => $description,
                'amount' => $amount,
            ]);

            $this->ledger->post(
                $enrollment->schoolId,
                $enrollment->studentId,
                LedgerEntryType::Charge,
                $amount,
                'invoice',
                $invoice->id,
                "Invoice #{$number} — {$description}",
            );

            return $invoice->load('lines');
        });
    }
}
