<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Models\Invoice;
use App\Finance\Services\InvoiceReadModel;
use App\Finance\Services\InvoiceSettlement;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Per-invoice SETTLEMENT (derived) — the read-model gap the statement drive surfaced. Proves the
 * identity `outstanding = total − Σ allocations − Σ approved credit notes` across every case, the
 * two orthogonal axes, and the per-button eligibility the server owns. All setup drives the real
 * domain actions; the assertions read InvoiceSettlement (the derivation) and the read model (the
 * withSum load that feeds it).
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: Student} */
function stlSetup(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $student];
}

/** Generate an invoice for a fresh episode of $student; returns the Invoice. */
function stlInvoice(School $school, Student $student, int $kobo): Invoice
{
    return ActiveSchool::runFor($school->id, function () use ($school, $student, $kobo) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
        );
    });
}

/** The settlement derivation for $invoice, loaded WITH the withSum aggregates (as the read does). */
function stlSettlement(School $school, Student $student, Invoice $invoice): array
{
    return ActiveSchool::runFor($school->id, function () use ($student, $invoice) {
        $loaded = app(InvoiceReadModel::class)->forStudent($student->id, includeVoid: true)
            ->firstWhere('id', $invoice->id);

        return (new InvoiceSettlement)->for($loaded);
    });
}

it('UNPAID — full outstanding, state unpaid, all three actions available', function () {
    [$school, $student] = stlSetup();
    $invoice = stlInvoice($school, $student, 300000);

    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(300000)
        ->and($s['settlement_state'])->toBe('unpaid')
        ->and($s['can_record_payment'])->toBeTrue()
        ->and($s['can_submit_credit_note'])->toBeTrue()
        ->and($s['can_request_void'])->toBeTrue()
        ->and($s['void_blocked_reason'])->toBeNull();
});

it('PART-PAID — outstanding reconciles, void blocked by the payment, credit note still available', function () {
    [$school, $student] = stlSetup();
    $invoice = stlInvoice($school, $student, 300000);
    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice, Money::fromKobo(100000), 'Payer', User::factory()->create(['school_id' => $school->id]),
        now()->toDateString(), ));

    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(200000)          // 300000 − 100000
        ->and($s['settlement_state'])->toBe('part_paid')
        ->and($s['can_record_payment'])->toBeTrue()
        ->and($s['can_request_void'])->toBeFalse()             // a payment has landed (monotonic)
        ->and($s['void_blocked_reason'])->toContain('payment')
        ->and($s['can_submit_credit_note'])->toBeTrue();
});

it('SETTLED BY PAYMENT — outstanding zero, record-payment suppressed, void blocked, CREDIT NOTE STILL OFFERED', function () {
    [$school, $student] = stlSetup();
    $invoice = stlInvoice($school, $student, 300000);
    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice, Money::fromKobo(300000), 'Payer', User::factory()->create(['school_id' => $school->id]),
        now()->toDateString(), ));

    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(0)
        ->and($s['settlement_state'])->toBe('settled')
        ->and($s['can_record_payment'])->toBeFalse()          // meaningless once settled
        ->and($s['can_request_void'])->toBeFalse()
        // THE assertion that stops a future "tidy-up" from creating a dead end: a paid invoice
        // must still be correctable, and void is ineligible, so the credit note is the instrument.
        ->and($s['can_submit_credit_note'])->toBeTrue();
});

it('SETTLED BY CREDIT NOTE — settled without ever being paid; void blocked by the approved credit', function () {
    [$school, $student] = stlSetup();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $invoice = stlInvoice($school, $student, 300000);

    ActiveSchool::runFor($school->id, function () use ($invoice, $maker, $checker) {
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(300000), CreditNoteKind::CreditNote, null, $maker);
        app(ApproveCreditNote::class)->handle($note, $checker);
    });

    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(0)
        ->and($s['settlement_state'])->toBe('settled')
        ->and($s['can_request_void'])->toBeFalse()
        ->and($s['void_blocked_reason'])->toContain('credit note')
        ->and($s['can_submit_credit_note'])->toBeTrue();
});

it('SETTLED THEN CREDIT-NOTED — displayed outstanding floors at zero; the account balance carries the credit', function () {
    [$school, $student] = stlSetup();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $invoice = stlInvoice($school, $student, 300000);

    ActiveSchool::runFor($school->id, function () use ($school, $invoice, $maker, $checker) {
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(300000), 'Payer', User::factory()->create(['school_id' => $school->id]), now()->toDateString());
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(50000), CreditNoteKind::CreditNote, null, $maker);
        app(ApproveCreditNote::class)->handle($note, $checker);
    });

    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(0)              // floored, NOT −50000
        ->and($s['settlement_state'])->toBe('settled');

    // The truth lives on the account: 300000 charge − 300000 payment − 50000 credit = −50000 (credit).
    $account = ActiveSchool::runFor($school->id, fn () => app(InvoiceReadModel::class)->accountPositionForStudent($student->id));
    expect($account['balance']->toKobo())->toBe(-50000)
        ->and($account['available_credit']->toKobo())->toBe(50000);
});

it('VOID — a voided invoice exposes NO settlement state', function () {
    [$school, $student] = stlSetup();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $invoice = stlInvoice($school, $student, 300000);

    ActiveSchool::runFor($school->id, function () use ($invoice, $maker, $checker) {
        $vr = app(SubmitVoidRequest::class)->handle($invoice, 'entered in error', $maker);
        app(ApproveVoidRequest::class)->handle($vr, $checker);
    });

    $s = stlSettlement($school, $student, $invoice);
    expect($s['settlement_state'])->toBeNull()
        ->and($s['can_record_payment'])->toBeFalse()
        ->and($s['can_submit_credit_note'])->toBeFalse()      // not an issued invoice
        ->and($s['can_request_void'])->toBeFalse()
        ->and($s['void_blocked_reason'])->toBeNull();
});

it('DECISION 5 — submitting a void against a paid invoice is REFUSED at submit (monotonic precondition)', function () {
    [$school, $student] = stlSetup();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $invoice = stlInvoice($school, $student, 300000);
    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice, Money::fromKobo(100000), 'Payer', User::factory()->create(['school_id' => $school->id]),
        now()->toDateString(), ));

    // Not merely advisory: the request is never created, so it never occupies the open-request slot.
    ActiveSchool::runFor($school->id, function () use ($invoice, $maker) {
        expect(fn () => app(SubmitVoidRequest::class)->handle($invoice, 'x', $maker))
            ->toThrow(BusinessRuleException::class);
    });
    expect((int) DB::table('finance_void_requests')->where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('BOTH payment AND approved credit note — outstanding still reconciles (attack-the-result)', function () {
    [$school, $student] = stlSetup();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $checker = User::factory()->create(['school_id' => $school->id]);
    $invoice = stlInvoice($school, $student, 300000);

    ActiveSchool::runFor($school->id, function () use ($school, $invoice, $maker, $checker) {
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(120000), 'Payer', User::factory()->create(['school_id' => $school->id]), now()->toDateString());
        $note = app(SubmitCreditNote::class)->handle($invoice, Money::fromKobo(30000), CreditNoteKind::CreditNote, null, $maker);
        app(ApproveCreditNote::class)->handle($note, $checker);
    });

    // 300000 − 120000 payment − 30000 approved credit = 150000, part_paid, void blocked.
    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(150000)
        ->and($s['settlement_state'])->toBe('part_paid')
        ->and($s['can_request_void'])->toBeFalse();
});

it('PENDING moves no money — a submitted (unapproved) credit note does NOT change outstanding', function () {
    [$school, $student] = stlSetup();
    $maker = User::factory()->create(['school_id' => $school->id]);
    $invoice = stlInvoice($school, $student, 300000);

    // A pending credit note (never approved) — must not reduce outstanding.
    ActiveSchool::runFor($school->id, fn () => app(SubmitCreditNote::class)->handle(
        $invoice, Money::fromKobo(50000), CreditNoteKind::CreditNote, null, $maker
    ));

    $s = stlSettlement($school, $student, $invoice);
    expect($s['outstanding']->toKobo())->toBe(300000)         // unchanged — pending moves no money
        ->and($s['settlement_state'])->toBe('unpaid');
});

it('ISOLATION — the settlement read is School-scoped; another School\'s allocations never bleed in', function () {
    [$schoolA, $studentA] = stlSetup();
    $invoiceA = stlInvoice($schoolA, $studentA, 300000);

    // A different School with its own paid invoice — must not affect A's derivation.
    [$schoolB, $studentB] = stlSetup();
    $invoiceB = stlInvoice($schoolB, $studentB, 300000);
    ActiveSchool::runFor($schoolB->id, fn () => app(RecordPayment::class)->handle(
        $invoiceB, Money::fromKobo(300000), 'Payer', User::factory()->create(['school_id' => $schoolB->id]),
        now()->toDateString(), ));

    expect(stlSettlement($schoolA, $studentA, $invoiceA)['outstanding']->toKobo())->toBe(300000)
        ->and(stlSettlement($schoolB, $studentB, $invoiceB)['outstanding']->toKobo())->toBe(0);
});
