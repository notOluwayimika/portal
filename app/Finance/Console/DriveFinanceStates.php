<?php

namespace App\Finance\Console;

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use App\Support\SchoolDay;

/**
 * The FINANCE half of the drive fixture: given ENROLLMENT UUIDs (handed in from outside — this
 * class never touches Academics, per the arch boundary), it produces every money state by
 * executing the REAL Actions. Lives in App\Finance because only App\Finance may use the Finance
 * Actions; it is driven by the cross-module `SeedDriveFixture` command, which creates the
 * enrollments and passes their UUIDs in — exactly as production bills a UUID resolved through the
 * ACL port, never an enrollment it reached into Academics for.
 *
 * All methods assume the active-School context is already set by the caller and run within it.
 */
final class DriveFinanceStates
{
    public function __construct(
        private readonly User $maker,
        private readonly User $checker,
    ) {}

    /**
     * The drive fixture's bank account for a school, created on first use.
     *
     * finance_payments.bank_account_id is required for portal-issued payments (the origin-keyed
     * CHECK), so the drive cannot bill anything without one. Created here rather than in
     * DriveCastSeeder because this class is the only thing that records payments, and a fixture
     * account that exists but is never used would be a row nobody could explain.
     */
    private function bankAccountId(int $schoolId): int
    {
        return (int) BankAccount::query()->firstOrCreate(
            ['school_id' => $schoolId, 'account_number' => '90'.str_pad((string) $schoolId, 8, '0', STR_PAD_LEFT)],
            ['label' => 'Drive account', 'bank_name' => 'Drive Bank'],
        )->id;
    }

    /** UNPAID — a charge, nothing settled. */
    public function unpaid(string $enrollmentUuid): void
    {
        $this->invoice($enrollmentUuid, 300000);
    }

    /** PART-PAID. */
    public function partPaid(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(100000), 'Guardian', $this->maker, SchoolDay::today(), $this->bankAccountId($invoice->school_id));
    }

    /** SETTLED BY PAYMENT. */
    public function settledByPayment(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(300000), 'Guardian', $this->maker, SchoolDay::today(), $this->bankAccountId($invoice->school_id));
    }

    /** SETTLED ENTIRELY BY AN APPROVED CREDIT NOTE (settled, never paid). */
    public function settledByCreditNote(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(300000), CreditNoteKind::CreditNote, 'Full bursary', $this->maker);
        app(ApproveCreditNote::class)->handle($note, $this->checker);
    }

    /** SETTLED THEN CREDIT-NOTED → the account sits in credit. */
    public function settledThenCredited(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(300000), 'Guardian', $this->maker, SchoolDay::today(), $this->bankAccountId($invoice->school_id));
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(50000), CreditNoteKind::CreditNote, 'Post-payment adjustment', $this->maker);
        app(ApproveCreditNote::class)->handle($note, $this->checker);
    }

    /** A PENDING (unapproved) credit note against a fresh invoice. */
    public function pendingCreditNote(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(50000), CreditNoteKind::CreditNote, 'Awaiting sign-off', $this->maker);
    }

    /** A PENDING void request against a fresh invoice (the invoice stays active). */
    public function pendingVoid(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 200000);
        app(SubmitVoidRequest::class)->handle($invoice, 'Billed in error — awaiting approval', $this->maker);
    }

    /** An APPROVED void — the invoice is reversed and voided through the real approval path. */
    public function approvedVoid(string $enrollmentUuid): void
    {
        $invoice = $this->invoice($enrollmentUuid, 300000);
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'Duplicate enrolment', $this->maker);
        app(ApproveVoidRequest::class)->handle($request, $this->checker);
    }

    /** A plain issued invoice for the second School (isolation). */
    public function plainInvoice(string $enrollmentUuid, int $kobo): void
    {
        $this->invoice($enrollmentUuid, $kobo);
    }

    private function invoice(string $enrollmentUuid, int $kobo): Invoice
    {
        return app(GenerateInvoice::class)->handle(
            $enrollmentUuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
        );
    }
}
