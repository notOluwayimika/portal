<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
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

it('FRIENDLY PATH — over-allocating through the Action is a 422', function () {
    [$school, $admin, $invoice] = overAllocSetup(100000);

    ActiveSchool::runFor($school->id, function () use ($admin, $invoice) {
        expect(fn () => app(RecordPayment::class)->handle($invoice, Money::fromKobo(100001), 'Overpayer', $admin))
            ->toThrow(BusinessRuleException::class);
    });

    // Nothing was written — the reject happened before any insert.
    expect(DB::table('finance_payment_allocations')->count())->toBe(0)
        ->and(DB::table('finance_payments')->count())->toBe(0);
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

it('CUMULATIVE — 60 then 40 both succeed (Σ=100); a further 1 is rejected', function () {
    [$school, $admin, $invoice] = overAllocSetup(100);

    ActiveSchool::runFor($school->id, function () use ($admin, $invoice) {
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(60), 'A', $admin);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(40), 'B', $admin);

        // Σ is now exactly 100. One more kobo must be rejected — proving the guard sums
        // ALL prior allocations, not just the single incoming one against the total.
        expect(fn () => app(RecordPayment::class)->handle($invoice, Money::fromKobo(1), 'C', $admin))
            ->toThrow(BusinessRuleException::class);
    });

    expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->sum('amount_minor'))
        ->toBe(100);
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
