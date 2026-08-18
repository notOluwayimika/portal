<?php

namespace App\Finance\Console;

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveDiscountPolicyChange;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitDiscountPolicyChange;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\BankAccount;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
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
    /** The seeded policy's name, in one place: both the create and the idempotence check read it. */
    private const DRIVE_POLICY_NAME = 'Sibling discount';

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

    /**
     * PAYMENTS BY PROVENANCE — the receipt screen's subject (U11), counted per `origin`.
     *
     * ADDED BECAUSE THE COUNT TABLE COULD NOT ANSWER THE QUESTION THE RECEIPT DRIVE ASKS. That
     * screen has exactly one subject, a payment, and its refusal arm has exactly one subject, a
     * MIGRATED payment — so "zero in any column means the screen cannot author anything" applies to
     * this axis and to no column the table already carried. The split is the point: a single
     * payments count would read as coverage while the refusal, the half of the screen that exists
     * because of the WCBS cutover, still had nothing to render.
     *
     * It answers honestly and the answer is a zero. Nothing in this fixture posts an opening-balance
     * batch, and PostOpeningBalanceBatch is the ONLY writer of `origin = 'migrated'` — so the
     * migrated column reads 0 until a drive walks the real import path itself. That is a fact about
     * the fixture worth printing rather than rediscovering in a browser.
     *
     * Same placement reasoning as bankAccountCount above: the boundary lint forbids the command
     * naming a `finance_` table, and this reads the scoped model, so call it inside
     * `ActiveSchool::runFor($schoolId, …)`.
     */
    public function paymentCount(int $schoolId, string $origin): int
    {
        return Payment::query()->where('school_id', $schoolId)->where('origin', $origin)->count();
    }

    /**
     * ONE ACTIVE DISCOUNT POLICY PER DRIVE SCHOOL — the U2 screen's amend and retire targets.
     *
     * VERIFIED ABSENT BEFORE IT WAS ADDED: neither this class nor DriveCastSeeder created a discount
     * policy, so `finance_discount_policies` was empty in every drive. Two consequences, and the second
     * is the one that matters here. `finance_invoice_lines_reduction_guard` refuses a reduction line
     * that cites no policy, so no invoice in the fixture could carry a discount at all; and the
     * discount-policies screen offers three acts — propose, amend, retire — of which only the first has
     * anything to work on when the catalog is empty. A drive that can exercise one of three paths is a
     * third of a drive, which is the same precondition-not-discovery argument the academic slot and the
     * bank account above were each written for.
     *
     * THROUGH THE REAL ACTIONS, never a row write: submit as the maker, approve as the checker, which
     * is the only path that writes this table ({@see ApproveDiscountPolicyChange}) and the one the
     * screen itself drives. `$maker` is a parameter because School B's bursar is a different user and
     * the maker ≠ checker CHECK is a database fact — the fixture must not fake its way around it.
     *
     * A PERCENTAGE, and `requires_approval` false: the standing sibling discount is the case the flag's
     * `false` arm is FOR, so the seeded row reads as the thing an operator would recognise. The drive
     * authors the other combinations live.
     *
     * Idempotent by name, like ensureBankAccount: a second call finds the row rather than proposing a
     * duplicate, which the active-name unique would refuse at approval anyway.
     */
    public function ensureDiscountPolicy(int $schoolId, ?User $maker = null): int
    {
        $existing = DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->where('name', self::DRIVE_POLICY_NAME)
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $change = app(SubmitDiscountPolicyChange::class)->handle(
            DiscountPolicyChangeKind::Create,
            null,
            [
                'name' => self::DRIVE_POLICY_NAME,
                'description' => 'Second and subsequent children enrolled in the same term.',
                'basis' => DiscountBasis::Percent->value,
                'percent' => 10,
                'requires_approval' => false,
            ],
            'Standing arrangement — carried over from last session.',
            $maker ?? $this->maker,
        );

        app(ApproveDiscountPolicyChange::class)->handle($change, $this->checker);

        return (int) DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->where('name', self::DRIVE_POLICY_NAME)
            ->value('id');
    }

    /**
     * How many discount policies a school has, for the fixture's own report — here rather than in the
     * command for the reason bankAccountCount() gives: the boundary lint forbids a `finance_` table
     * literal outside app/Finance, so the Finance side counts its own table. Reads the scoped model, so
     * it must be called inside `ActiveSchool::runFor($schoolId, …)`.
     */
    public function discountPolicyCount(int $schoolId): int
    {
        return DiscountPolicy::query()->where('school_id', $schoolId)->count();
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
            InvoiceKind::Scheduled,
        );
    }
}
