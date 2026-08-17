<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The account-scoped payment (ADR 0048): pay ON THE ACCOUNT, no invoice named. Everything here plants
 * DATA, not schema, so RefreshDatabase's single-transaction wrap is not a hazard — the concurrency
 * proofs that DO need real cross-connection commits live in AccountPaymentConcurrencyTest.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student} admin holds finance.access via the admin role. */
function apSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    // ADR 0048 D1: recording a payment now needs finance.payment.record, which admin no longer carries.
    // Grant it to THIS actor directly (not to the admin role) — the account-payment mechanics under test
    // need a payment-capable bursar without re-widening the admin seat.
    $admin->givePermissionTo('finance.payment.record');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id, 'first_name' => 'Ada', 'last_name' => 'Obi']);

    return [$school, $admin, $student];
}

function apInvoice(School $school, Student $student, int $kobo): Invoice
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
            InvoiceKind::Scheduled,
        );
    });
}

/** Void an invoice the right way (maker ≠ checker), so it becomes its student's only, void invoice. */
function apVoid(School $school, Invoice $invoice, User $maker): void
{
    $checker = User::factory()->create(['school_id' => $school->id]);
    ActiveSchool::runFor($school->id, function () use ($invoice, $maker, $checker) {
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'entered in error', $maker);
        app(ApproveVoidRequest::class)->handle($request, $checker);
    });
}

function apBalance(int $studentId): int
{
    return (int) DB::table('finance_student_accounts')->where('student_id', $studentId)->value('balance_minor');
}

function apAllocatedTo(int $invoiceId): int
{
    return (int) DB::table('finance_payment_allocations')->where('invoice_id', $invoiceId)->sum('amount_minor');
}

// ── §5.1 The advance payment ────────────────────────────────────────────────

it('records an ADVANCE payment on the account — no invoice, no allocation, credit balance', function () {
    [$school, $admin, $student] = apSetup();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/payments", ['amount_minor' => 15000000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'Mr Obi'])
        ->assertCreated()
        ->assertJsonPath('amount.amount_minor', 15000000)
        ->assertJsonPath('allocations', []); // whenLoaded → present and empty (identical wire shape)

    expect(DB::table('finance_payments')->where('student_id', $student->id)->count())->toBe(1)
        ->and(DB::table('finance_payment_allocations')->count())->toBe(0); // ZERO — never an allocation

    $ledger = DB::table('finance_ledger_transactions')->where('student_id', $student->id)->get();
    expect($ledger)->toHaveCount(1);
    expect($ledger->first()->type)->toBe('payment')
        ->and($ledger->first()->source_type)->toBe('payment')
        ->and((int) $ledger->first()->amount_minor)->toBe(-15000000)
        ->and(apBalance($student->id))->toBe(-15000000); // account row created, in credit
});

// ── §5.2 End-to-end consumption — oldest-first from a payment that never had an invoice ──

it('END-TO-END — a payment banked with NO invoice settles oldest-first across the next generations', function () {
    [$school, $admin, $student] = apSetup();

    // Pay ₦5,000 on account; no invoices exist yet.
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/payments", ['amount_minor' => 500000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'Mr Obi'])
        ->assertCreated();
    expect(apBalance($student->id))->toBe(-500000); // credit 5,000

    // First invoice ₦3,000 → the credit applies 3,000, balance −2,000.
    $inv1 = apInvoice($school, $student, 300000);
    expect(apAllocatedTo($inv1->id))->toBe(300000)
        ->and(apBalance($student->id))->toBe(-200000);

    // Second invoice ₦4,000 → the remaining 2,000 credit applies, balance +2,000 (now owes).
    $inv2 = apInvoice($school, $student, 400000);
    expect(apAllocatedTo($inv2->id))->toBe(200000)
        ->and(apBalance($student->id))->toBe(200000);
});

// ── §5.3 Void-only student — the new door is not a way around the void rule ──

it('does not bypass the void rule — account payment succeeds, the invoice-scoped path still 422s on a void invoice', function () {
    [$school, $admin, $student] = apSetup();
    $invoice = apInvoice($school, $student, 300000);
    apVoid($school, $invoice, $admin); // its only invoice, now void

    // The account door succeeds (no invoice named).
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/payments", ['amount_minor' => 100000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertCreated();

    // The invoice door STILL refuses the void invoice — unchanged.
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/payments", ['amount_minor' => 100000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertStatus(422);
});

// ── §5.4 Cross-school, both layers separately ────────────────────────────────

it('refuses cross-school at BOTH layers — the route binding 404s, and the Action guards a foreign student_id', function () {
    [$schoolA, $adminA] = apSetup();
    [$schoolB, , $studentB] = apSetup();

    // Layer 1 — the route binding. Acting in school A, a school-B student uuid resolves to nothing
    // under SchoolScope → 404 before the controller runs.
    $this->actingAs($adminA)->withSession(['school_id' => $schoolA->id])
        ->postJson("/api/v1/finance/students/{$studentB->uuid}/payments", ['amount_minor' => 100000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertNotFound();

    // Layer 2 — the Action directly, foreign student_id under school A's context. The binding would
    // have caught it, which is exactly how a guard ends up never written; this proves the guard is
    // its own line, so a console/direct caller cannot cross the boundary.
    ActiveSchool::runFor($schoolA->id, function () use ($studentB, $adminA) {
        expect(fn () => app(RecordAccountPayment::class)->handle($studentB->id, Money::fromKobo(100000), 'X', $adminA, SchoolDay::today(), testBankAccountId()))
            ->toThrow(BusinessRuleException::class);
    });

    // And nothing was written for the foreign student.
    expect(DB::table('finance_payments')->where('student_id', $studentB->id)->count())->toBe(0);
});

// ── §5.7 Receipt sequence across both doors ──────────────────────────────────

it('shares one receipt series across both doors — the two payments get consecutive, distinct references', function () {
    [$school, $admin, $student] = apSetup();
    $invoice = apInvoice($school, $student, 300000);

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/payments", ['amount_minor' => 100000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertCreated();
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/payments", ['amount_minor' => 100000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertCreated();

    $refs = DB::table('finance_payments')->where('student_id', $student->id)->orderBy('id')->pluck('reference')->all();
    expect($refs)->toHaveCount(2)
        ->and($refs[0])->not->toBe($refs[1])                 // distinct
        ->and((int) $refs[1])->toBe((int) $refs[0] + 1);     // consecutive — one series, both doors
});

// ── §5.8 Coherence regression — the cheapest guard on the last slice ─────────

it('finance:audit-ledger-coherence stays SUCCESS over a fixture containing an account payment', function () {
    [$school, $admin, $student] = apSetup();
    apInvoice($school, $student, 300000);
    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/payments", ['amount_minor' => 100000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertCreated();

    expect(Artisan::call('finance:audit-ledger-coherence'))->toBe(0);
});
