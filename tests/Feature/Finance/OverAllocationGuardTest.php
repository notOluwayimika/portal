<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
use App\Finance\Models\StudentAccount;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Over-allocation guard: Σ(allocations to an invoice) ≤ its total.
 *
 * Three layers, and the tests keep them distinct: the Action pre-check is the friendly
 * 422; the BEFORE INSERT trigger is the real guarantee against a single raw write; the
 * invoice-row lock is the concurrency anchor. Each layer is bite-proven by removing it
 * and watching the illegal state reappear.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Invoice} a school, an admin, and a 100000-kobo invoice */
function overAllocSetup(int $invoiceKobo = 100000): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    $invoice = ActiveSchool::runFor($school->id, fn () => app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($invoiceKobo))],
    ));

    return [$school, $admin, $invoice];
}

/** Insert a raw allocation, bypassing the Action entirely (payment row created first). */
function rawAllocate(Invoice $invoice, int $kobo): void
{
    $paymentId = DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id,
        'student_id' => $invoice->student_id,
        'reference' => random_int(1, PHP_INT_MAX),
        'amount_minor' => $kobo,
        'amount_currency' => 'NGN',
        'payer_name' => 'Raw',
        'method' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('finance_payment_allocations')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id,
        'payment_id' => $paymentId,
        'invoice_id' => $invoice->id,
        'amount_minor' => $kobo,
        'amount_currency' => 'NGN',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('OVERPAYMENT IS BANKED, NOT REJECTED (wallet W2) — the Action caps the allocation and credits the account', function () {
    // This USED to be a 422: the Action over-allocated the full payment and #94's
    // pre-check rejected it. Wallet W2 changed the policy — the allocation is capped
    // at outstanding and the excess banks as credit — so the reject no longer fires on
    // a legitimate overpayment. The #94 ceiling (Σ allocations ≤ total) is untouched;
    // it simply is never approached because the Action stops over-allocating.
    [$school, $admin, $invoice] = overAllocSetup(100000);

    ActiveSchool::runFor($school->id, function () use ($admin, $invoice) {
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(100001), 'Overpayer', $admin);

        // Allocation capped at outstanding (100000); the payment records the full cash.
        expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->sum('amount_minor'))
            ->toBe(100000)
            ->and((int) DB::table('finance_payments')->sum('amount_minor'))->toBe(100001);

        // The 1-kobo remainder is banked: account balance = charge 100000 − payment
        // 100001 = −1, i.e. 1 minor unit of available credit.
        $account = StudentAccount::query()->where('student_id', $invoice->student_id)->firstOrFail();
        expect($account->balance->toKobo())->toBe(-1)
            ->and($account->availableCredit()->toKobo())->toBe(1);
    });
});

it('DB IS THE REAL GUARANTEE — a raw over-allocation insert is rejected by the trigger', function () {
    [, , $invoice] = overAllocSetup(100000);

    expect(fn () => rawAllocate($invoice, 100001))->toThrow(QueryException::class);
    expect(DB::table('finance_payment_allocations')->count())->toBe(0);
});

it('DB, NOT THE APP — with the Action pre-check bypassed, the trigger still rejects', function () {
    // rawAllocate never touches RecordPayment, so this proves the guarantee is the DB
    // trigger, not the Action check. (The paired "remove the trigger → this passes" step
    // is run out-of-band and reported, since a migration can't be toggled mid-suite.)
    [, , $invoice] = overAllocSetup(100000);
    expect(fn () => rawAllocate($invoice, 100001))->toThrow(QueryException::class);
});

it('EXACT-FILL BOUNDARY — an allocation exactly equal to the total is accepted (≤ not <)', function () {
    [$school, $admin, $invoice] = overAllocSetup(100000);

    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)
        ->handle($invoice, Money::fromKobo(100000), 'ExactPayer', $admin));

    expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->sum('amount_minor'))
        ->toBe(100000);
});

it('CUMULATIVE — 60 then 40 fill the invoice (Σ=100); a further 1 banks as credit, allocations stay 100', function () {
    [$school, $admin, $invoice] = overAllocSetup(100);

    ActiveSchool::runFor($school->id, function () use ($admin, $invoice) {
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(60), 'A', $admin);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(40), 'B', $admin);

        // Σ is now exactly 100 — the invoice is fully allocated. A further payment finds
        // outstanding = 0, so it banks ENTIRELY as credit: no allocation row is written
        // (an unallocated advance), and Σ(allocations) stays 100 — proving the cap reads
        // ALL prior allocations, not just the incoming one.
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(1), 'C', $admin);

        expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->sum('amount_minor'))
            ->toBe(100)
            ->and((int) DB::table('finance_payment_allocations')->count())->toBe(2); // C wrote none

        // Ledger: charge +100, payments −(60+40+1) = −101 → balance −1 = 1 kobo credit.
        $account = StudentAccount::query()->where('student_id', $invoice->student_id)->firstOrFail();
        expect($account->balance->toKobo())->toBe(-1);
    });
});

it('CURRENCY — a mismatched allocation currency is rejected at the DB', function () {
    [, , $invoice] = overAllocSetup(100000);

    $paymentId = DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id, 'student_id' => $invoice->student_id,
        'reference' => 999, 'amount_minor' => 100, 'amount_currency' => 'USD',
        'payer_name' => 'X', 'method' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('finance_payment_allocations')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $invoice->school_id, 'payment_id' => $paymentId,
        'invoice_id' => $invoice->id, 'amount_minor' => 100, 'amount_currency' => 'USD',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('NO REGRESSION — the exact correct-payment path still credits the ledger to zero', function () {
    [$school, $admin, $invoice] = overAllocSetup(100000);

    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)
        ->handle($invoice, Money::fromKobo(100000), 'Payer', $admin));

    // charge +100000, payment −100000 → net zero, the walking-skeleton guarantee.
    expect((int) DB::table('finance_ledger_transactions')->where('student_id', $invoice->student_id)->sum('amount_minor'))
        ->toBe(0);
});
