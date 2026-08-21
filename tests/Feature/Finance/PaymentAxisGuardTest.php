<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Over-allocation guard, PAYMENT AXIS: Σ(allocations of a payment) ≤ that payment's amount.
 * Migration `2026_08_21_110000_finance_allocation_not_over_payment_amount`.
 *
 * EVERY INSERT HERE IS RAW, and that is the whole point of the file. `RecordPayment` caps
 * its one allocation at `min(amount, outstanding)` and
 * `GenerateInvoice::applyCreditForward` skips a payment with no headroom, so a test driven
 * through either Action would pass on the APPLICATION cap and prove nothing about the
 * database. `DB::table(...)->insert` goes past both. (The boundary lint's `DB::table` ban on
 * a `finance_` literal is scoped to `app/Finance`, not to tests — see
 * `bin/ci-boundary-lint.php`; `OverAllocationGuardTest` does the same for the invoice axis.)
 *
 * THE MESSAGE IS ASSERTED, NOT JUST THE SQLSTATE. Roughly fifty triggers in this schema
 * signal `45000` / driver code 1644 — the append-only guards, the maker-checker guards, the
 * invoice-axis sibling. A test asserting only `QueryException`, or only 1644, is satisfied
 * by any of them and would stay green if this trigger were dropped and some other rule
 * happened to refuse the same row.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A school, an invoice of $invoiceKobo, and a RAW payment of $paymentKobo with NO
 * allocations yet.
 *
 * @return array{0: School, 1: Invoice, 2: int} school, invoice, payment id
 */
function payAxisSetup(int $invoiceKobo, int $paymentKobo, string $paymentCurrency = 'NGN'): array
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
        InvoiceKind::Scheduled,
    ));

    $paymentId = DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'student_id' => $student->id,
        'reference' => random_int(1, PHP_INT_MAX),
        'amount_minor' => $paymentKobo,
        'amount_currency' => $paymentCurrency,
        'received_at' => SchoolDay::today(),
        'bank_account_id' => testBankAccountId($school->id),
        'payer_name' => 'Raw',
        'method' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$school, $invoice, $paymentId];
}

/** Insert an allocation with the Actions bypassed entirely. */
function payAxisAllocate(School $school, Invoice $invoice, int $paymentId, int $kobo, string $currency = 'NGN'): void
{
    DB::table('finance_payment_allocations')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'payment_id' => $paymentId,
        'invoice_id' => $invoice->id,
        'amount_minor' => $kobo,
        'amount_currency' => $currency,
        'allocation_rule' => 'payment_against_named_invoice',
        'allocation_overridden' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The MESSAGE, not just the SQLSTATE. Returns the driver message for assertion. */
function payAxisRefusal(Closure $write): string
{
    try {
        $write();
    } catch (QueryException $e) {
        expect($e->errorInfo[0])->toBe('45000')
            ->and((int) $e->errorInfo[1])->toBe(1644);

        return (string) $e->errorInfo[2];
    }

    throw new RuntimeException('The write was ACCEPTED. No trigger refused it.');
}

it('PROOF a — an allocation EXACTLY equal to the payment amount is permitted (≤, not <)', function () {
    // Invoice 100000 so the invoice axis has room and cannot be the thing under test.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school, $invoice, $paymentId, 50000);

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(50000);
});

it('PROOF b — a second allocation ONE KOBO past the payment amount is refused, with the payment-axis message', function () {
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school, $invoice, $paymentId, 50000);

    // 50000 + 1 = 50001 > 50000. The invoice axis still has 50000 of room, so the ONLY
    // rule that can refuse this is the payment-axis trigger — which the message pins.
    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 1));

    expect($message)->toContain('Allocation would exceed the payment amount')
        ->and($message)->toContain('finance_payments.amount_minor');

    // Append-only means a refused row must leave NO trace: the sum is unchanged.
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(50000);
});

it('PROOF b2 — a FIRST allocation one kobo past the amount is refused too (the COALESCE arm, no prior rows)', function () {
    // SUM over zero rows is NULL, not 0, on MySQL. Without the COALESCE the comparison
    // would be NULL + 1 > 50000 → NULL → not true → the row would be ACCEPTED. This arm is
    // the only one that reaches that branch, since PROOF b always has a prior row.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 50001));

    expect($message)->toContain('Allocation would exceed the payment amount');
    expect((int) DB::table('finance_payment_allocations')->count())->toBe(0);
});

it('PROOF c — the refusal is at the DATABASE, with both Action caps bypassed', function () {
    // Nothing in this test constructs RecordPayment or GenerateInvoice for the write. The
    // payment row was inserted raw, the allocation is inserted raw, and the refusal still
    // arrives — as SQLSTATE 45000 / 1644, which is a MySQL SIGNAL and cannot come from PHP.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10);

    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 11));

    expect($message)->toContain('Allocation would exceed the payment amount');

    // And the same insert routed through nothing but the raw table is what a SQL console, a
    // bulk correction or a restored dump would do — the case the trigger exists for.
    expect(DB::table('finance_payment_allocations')->count())->toBe(0);
});

it('PROOF d — the INVOICE-axis trigger still fires, independently: an allocation over the invoice total is refused by the invoice message', function () {
    // Payment 500000 (enormous headroom on the payment axis), invoice 1000. The payment-axis
    // trigger has no objection, so a refusal here proves the July guard is untouched.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 1000, paymentKobo: 500000);

    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 1001));

    expect($message)->toContain('Allocation would exceed the invoice total')
        ->and($message)->toContain('finance_invoices.total_minor')
        // NOT the payment-axis message — the two axes answered separately.
        ->and($message)->not->toContain('finance_payments.amount_minor');
});

it('PROOF d2 — BOTH axes, on the same table, each refusing only its own violation', function () {
    // One school, one invoice of 1000, two payments. Payment P is large (invoice axis bites
    // first); payment Q is small (payment axis bites). Same table, same event, two answers.
    [$school, $invoice, $bigPayment] = payAxisSetup(invoiceKobo: 1000, paymentKobo: 500000);

    $smallPayment = DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'student_id' => $invoice->student_id,
        'reference' => random_int(1, PHP_INT_MAX),
        'amount_minor' => 100,
        'amount_currency' => 'NGN',
        'received_at' => SchoolDay::today(),
        'bank_account_id' => testBankAccountId($school->id),
        'payer_name' => 'Small',
        'method' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Invoice axis: 1001 > invoice 1000, but ≤ payment 500000.
    expect(payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $bigPayment, 1001)))
        ->toContain('Allocation would exceed the invoice total');

    // Payment axis: 101 ≤ invoice 1000, but > payment 100.
    expect(payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $smallPayment, 101)))
        ->toContain('Allocation would exceed the payment amount');

    // And the legal allocation on both axes lands.
    payAxisAllocate($school, $invoice, $smallPayment, 100);
    expect((int) DB::table('finance_payment_allocations')->count())->toBe(1);
});

it('CURRENCY — an allocation whose currency differs from the payment is refused', function () {
    // The Σ comparison is only meaningful between like units. The invoice here is NGN and
    // the allocation is NGN, so the invoice-axis currency check passes; the payment is USD,
    // so only the payment-axis check can refuse — which the message pins.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000, paymentCurrency: 'USD');

    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 100));

    expect($message)->toContain('must match the payment currency');
});

it('NO REGRESSION — the ordinary RecordPayment path still writes its allocation', function () {
    [$school, $invoice] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 1);

    ActiveSchool::runFor($school->id, function () use ($school, $invoice) {
        $admin = User::query()->where('school_id', $school->id)->firstOrFail();
        app(RecordPayment::class)
            ->handle($invoice, Money::fromKobo(100000), 'Payer', $admin, SchoolDay::today(), testBankAccountId($school->id));
    });

    // The live writer is unaffected: one allocation of the full amount, Σ = payment amount.
    $payment = DB::table('finance_payments')->where('payer_name', 'Payer')->first();
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $payment->id)->sum('amount_minor'))
        ->toBe(100000)
        ->and((int) $payment->amount_minor)->toBe(100000);
});
