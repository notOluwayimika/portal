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
     * THE DRIVE FIXTURE'S BANK ACCOUNT FOR A SCHOOL — the one place it is defined, for both readers.
     *
     * Two things need it, and only one of them records a payment. `finance_payments.bank_account_id` is
     * required for portal-issued payments (the origin-keyed CHECK), so the drive cannot bill anything
     * without one; and `finance_fee_items.bank_account_id` is NOT NULL with a School-scoped, not-
     * deactivated `exists` rule in front of it, so the fee-schedules AUTHORING screen cannot create a
     * single line in a school that has no account either.
     *
     * The docblock here used to say the account is created in this class "because this class is the only
     * thing that records payments, and a fixture account that exists but is never used would be a row
     * nobody could explain". That reasoning is FALSE as of U1: School B records no payment and still
     * needs an account, because the isolation seat has `finance.fee-schedule.manage` and would open the
     * author screen onto an empty picker. The account is now seeded for both schools by
     * `SeedDriveFixture` and this method is the single source of its identity.
     *
     * `firstOrCreate` keyed on (school_id, account_number) is what makes calling it twice safe: the
     * payment paths below call it again and FIND the row rather than making a second one. The
     * account_number formula lives here and nowhere else — a second copy in the seeder, keyed to match,
     * is the drift this commit's trait extraction argues against.
     */
    public function ensureBankAccount(int $schoolId): int
    {
        return (int) BankAccount::query()->firstOrCreate(
            ['school_id' => $schoolId, 'account_number' => '90'.str_pad((string) $schoolId, 8, '0', STR_PAD_LEFT)],
            ['label' => 'Drive account', 'bank_name' => 'Drive Bank'],
        )->id;
    }

    /**
     * The account id for a payment about to be recorded. Kept as its own name because the three call
     * sites below are asking "which account did this money land in", not "make sure one exists" — and
     * because it must keep resolving to the SAME row the seeding call made.
     */
    private function bankAccountId(int $schoolId): int
    {
        return $this->ensureBankAccount($schoolId);
    }

    /**
     * How many accounts a school has, for the fixture's own report.
     *
     * It lives HERE rather than beside the academic counts in the command because the boundary lint
     * (Constitution 3) forbids a `finance_*` table literal outside app/Finance — the command counts
     * `terms` and `class_levels` with DB::table and cannot name this one. Read through the scoped model
     * rather than the table, so it must be called inside `ActiveSchool::runFor($schoolId, …)`; the
     * explicit `where` then agrees with SchoolScope instead of relying on it.
     */
    public function bankAccountCount(int $schoolId): int
    {
        return BankAccount::query()->where('school_id', $schoolId)->count();
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
