<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\Invoice;
use App\Finance\Models\PaymentAllocation;
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
        [new InvoiceLineSpec('Tuition', Money::fromKobo($invoiceKobo), bankAccountId: testBankAccountId())],
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

/**
 * Insert an allocation with the Actions bypassed entirely.
 *
 * `$rule` IS A PARAMETER AND THAT IS THE POINT. Every arm in the first version of this file
 * hardcoded `payment_against_named_invoice`, so a mutation narrowing the guard to
 * `IF NEW.allocation_rule = 'payment_against_named_invoice' AND v_already + ... > v_amount`
 * left ALL 676 Finance tests green — while disabling the ceiling for exactly
 * `credit_applied_forward_oldest_first`, the rule `applyCreditForward` stamps and the one
 * writer the concurrency proof calls a read-then-write with no payment-row lock. The guard
 * was untested against the writer it exists for. See PAYMENT_AXIS_RULES below.
 */
function payAxisAllocate(
    School $school,
    Invoice $invoice,
    int $paymentId,
    int $kobo,
    string $currency = 'NGN',
    string $rule = 'payment_against_named_invoice',
): void {
    DB::table('finance_payment_allocations')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'payment_id' => $paymentId,
        'invoice_id' => $invoice->id,
        'amount_minor' => $kobo,
        'amount_currency' => $currency,
        'allocation_rule' => $rule,
        'allocation_overridden' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * BOTH rules this schema writes, taken from the constants rather than retyped, so a renamed
 * rule breaks the test instead of silently narrowing its coverage.
 *
 * @return array<string, string>
 */
function payAxisRules(): array
{
    return [
        'payment_against_named_invoice' => PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE,
        'credit_applied_forward_oldest_first' => PaymentAllocation::RULE_CREDIT_APPLIED_FORWARD_OLDEST_FIRST,
    ];
}

/**
 * Run $plant with BOTH allocation triggers dropped, then re-install them from their own
 * migrations — so what the probes below meet is the REAL shipped body, never a retyped copy.
 *
 * DDL commits implicitly, so `RefreshDatabase`'s transaction cannot roll a dropped trigger
 * back; the finally is the only thing standing between a plant and every later test in this
 * process running unguarded.
 */
function payAxisWithTriggersDropped(Closure $plant): void
{
    DB::unprepared('DROP TRIGGER IF EXISTS finance_allocation_not_over_invoice_total');
    DB::unprepared('DROP TRIGGER IF EXISTS finance_allocation_not_over_payment_amount');

    try {
        $plant();
    } finally {
        (require base_path('database/migrations/2026_07_22_120000_finance_allocation_not_over_invoice_total.php'))->up();
        (require base_path('database/migrations/2026_08_21_110000_finance_allocation_not_over_payment_amount.php'))->up();
    }
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

// ─────────────────────────────────────────────────────────────────────────────────────────
// FIX 1 — the guard is exercised against BOTH writers, not just the one whose rule name the
// original arms happened to hardcode.
//
// The mutation this closes, applied to the migration body:
//
//   IF NEW.allocation_rule = 'payment_against_named_invoice' AND v_already + NEW.amount_minor > v_amount THEN
//
// It disables the ceiling for exactly `credit_applied_forward_oldest_first` — the rule
// `applyCreditForward` stamps (app/Finance/Actions/GenerateInvoice.php:583 (applyCreditForward)),
// the writer with
// no payment-row lock — and before these arms existed it left all 676 Finance tests green.
// ─────────────────────────────────────────────────────────────────────────────────────────

it('PROOF a3 — an allocation exactly equal to the payment amount is permitted under EVERY allocation rule', function (string $rule) {
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school, $invoice, $paymentId, 50000, rule: $rule);

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(50000)
        ->and(DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->value('allocation_rule'))
        ->toBe($rule);
})->with(payAxisRules());

it('PROOF b4 — one kobo past the amount is refused under EVERY allocation rule, with the same message', function (string $rule) {
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school, $invoice, $paymentId, 50000, rule: $rule);
    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 1, rule: $rule));

    expect($message)->toContain('Allocation would exceed the payment amount')
        ->and($message)->toContain('finance_payments.amount_minor');

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(50000);
})->with(payAxisRules());

it('PROOF b5 — the prior allocations counted toward the ceiling are counted whatever rule wrote them: a credit-forward row blocks a named-invoice row and vice versa', function () {
    // The cross case, which neither single-rule arm reaches. If the trigger ever branched on
    // NEW.allocation_rule, or scoped its SUM by rule, this is the arm that says so: the 50000
    // already on the payment was written under ONE rule and the refusal is of a row written
    // under the OTHER.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school, $invoice, $paymentId, 50000, rule: 'credit_applied_forward_oldest_first');
    expect(payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 1, rule: 'payment_against_named_invoice')))
        ->toContain('Allocation would exceed the payment amount');

    [$school2, $invoice2, $payment2] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school2, $invoice2, $payment2, 50000, rule: 'payment_against_named_invoice');
    expect(payAxisRefusal(fn () => payAxisAllocate($school2, $invoice2, $payment2, 1, rule: 'credit_applied_forward_oldest_first')))
        ->toContain('Allocation would exceed the payment amount');
});

it('PROOF b6 — the trigger body does not read allocation_rule at all', function () {
    // The behavioural arms above are the guarantee; this is the cheap structural one that says
    // WHY they all agree, and it is the arm that would have failed instantly under the mutation.
    $body = DB::selectOne(
        'SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        ['finance_allocation_not_over_payment_amount'],
    )->body;

    // Comments stripped first: the body EXPLAINS that it does not read the column, and the
    // explanation names it.
    $code = preg_replace('/--[^\n]*/', '', (string) $body);

    expect($code)->not->toContain('allocation_rule');
});

// ─────────────────────────────────────────────────────────────────────────────────────────
// FIX 2 — the trigger must never add two currencies together, and must say which fault it hit.
// ─────────────────────────────────────────────────────────────────────────────────────────

it('PROOF e1 — THE DEFECT: a payment carrying two currencies is refused as MIXED CURRENCY, not as an over-allocation', function () {
    // The exact fixture the cold review measured. NGN payment of 10000 carrying legacy rows of
    // 5000 NGN + 5000 USD. The old trigger summed those to 10000 and refused a 1-kobo NGN
    // allocation with "would exceed the payment amount" — on a payment with 5000 NGN of REAL
    // room. Naira added to dollars, and the answer reported as a total.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10000);

    payAxisWithTriggersDropped(function () use ($school, $invoice, $paymentId) {
        payAxisAllocate($school, $invoice, $paymentId, 5000, 'NGN');
        payAxisAllocate($school, $invoice, $paymentId, 5000, 'USD');
    });

    // The raw cross-currency sum is 10000 — which is what made the old refusal look correct.
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(10000)
        // Scoped to the payment currency it is 5000, which is the true figure.
        ->and((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->where('amount_currency', 'NGN')->sum('amount_minor'))
        ->toBe(5000);

    $message = payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 1, 'NGN'));

    // NAMES THE ACTUAL FAULT. A bursar sent to look at amounts when the fault is currencies
    // will not find anything wrong with the amounts, because nothing is.
    expect($message)->toContain('more than one currency')
        ->and($message)->toContain('Investigate before allocating more')
        ->and($message)->not->toContain('Allocation would exceed the payment amount');
});

it('PROOF e2 — THE OTHER DIRECTION: scoping the sum alone would let each currency reach the full amount; arm 2 refuses that', function () {
    // A per-currency sum with nothing else is a WORSE bug than the one it fixes: an NGN payment
    // of 10000 would accept 10000 NGN *and* 10000 USD — a wrong acceptance on a money table
    // instead of a wrong refusal. This arm shows the second currency being refused, in BOTH
    // directions, so neither can be the one that slips through.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10000);

    payAxisAllocate($school, $invoice, $paymentId, 10000, 'NGN');   // the payment is now full, in its own currency

    // A USD allocation on top is refused — by the INVOICE-axis sibling, which fires first
    // (ACTION_ORDER = 1) because the invoice here is NGN. Stated as measured rather than as
    // hoped: the point of the arm is that a foreign currency cannot REACH the per-currency
    // scope by the ordinary path at all, and it does not matter which of the two guards is
    // the one that says so. (The payment-axis arm 1 in isolation is the CURRENCY arm above,
    // where the allocation matches the invoice and only the payment disagrees.)
    expect(payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 10000, 'USD')))
        ->toContain('must match the invoice currency');

    // And where legacy data has ALREADY put a foreign row in place — the only way the scoped
    // sum could be exploited per-currency — ARM 2 refuses everything further, and the refusal
    // is a statement about the data rather than an arithmetic claim.
    [$school2, $invoice2, $payment2] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10000);

    payAxisWithTriggersDropped(function () use ($school2, $invoice2, $payment2) {
        payAxisAllocate($school2, $invoice2, $payment2, 10000, 'USD');
    });

    // THE ONE THAT MATTERS: without arm 2, the scoped sum would see 0 NGN allocated and accept
    // a further 10000 NGN — the payment would have carried 10000 USD *and* 10000 NGN.
    expect(payAxisRefusal(fn () => payAxisAllocate($school2, $invoice2, $payment2, 10000, 'NGN')))
        ->toContain('more than one currency');

    // A further USD row is refused too, by the invoice-axis sibling. Both doors shut.
    expect(payAxisRefusal(fn () => payAxisAllocate($school2, $invoice2, $payment2, 1, 'USD')))
        ->toContain('must match the invoice currency');

    // Nothing landed on top of the planted row.
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $payment2)->count())->toBe(1);
});

it('PROOF e3 — the scope does not weaken the ordinary ceiling: a single-currency payment behaves exactly as before', function () {
    // The regression risk of scoping a SUM is that it stops counting rows it should count.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10000);

    payAxisAllocate($school, $invoice, $paymentId, 4000);
    payAxisAllocate($school, $invoice, $paymentId, 6000);

    expect(payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 1)))
        ->toContain('Allocation would exceed the payment amount');

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(10000);
});

it('PROOF e4 — the deploy pre-flight: the naive query calls the mixed fixture clean, and BOTH shipped clauses catch it', function () {
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10000);

    payAxisWithTriggersDropped(function () use ($school, $invoice, $paymentId) {
        payAxisAllocate($school, $invoice, $paymentId, 5000, 'NGN');
        payAxisAllocate($school, $invoice, $paymentId, 5000, 'USD');
    });

    // THE NAIVE QUERY — the one this branch originally shipped in its report and ticket. It
    // performs the SAME cross-currency addition the trigger no longer performs, so it reports a
    // corrupt payment as clean. Pinned RED-side so nobody reinstates it.
    $naive = DB::select(
        'SELECT payment_id FROM finance_payment_allocations GROUP BY payment_id
          HAVING SUM(amount_minor) > (SELECT amount_minor FROM finance_payments WHERE id = payment_id)'
    );
    expect($naive)->toBeEmpty();

    // CLAUSE 1 — over-allocated WITHIN a currency. Correctly silent here: 5000 NGN ≤ 10000.
    $clause1 = DB::select(
        'SELECT a.payment_id, a.amount_currency, SUM(a.amount_minor) AS allocated,
                MIN(p.amount_minor) AS payment_amount
           FROM finance_payment_allocations a
           JOIN finance_payments p ON p.id = a.payment_id
          WHERE a.amount_currency = p.amount_currency
          GROUP BY a.payment_id, a.amount_currency
         HAVING SUM(a.amount_minor) > MIN(p.amount_minor)'
    );
    expect($clause1)->toBeEmpty();

    // CLAUSE 2 — mixed currency. This is the clause that finds it.
    $clause2 = DB::select(
        'SELECT DISTINCT a.payment_id, a.amount_currency, p.amount_currency AS payment_currency
           FROM finance_payment_allocations a
           JOIN finance_payments p ON p.id = a.payment_id
          WHERE a.amount_currency <> p.amount_currency'
    );
    expect($clause2)->toHaveCount(1)
        ->and((int) $clause2[0]->payment_id)->toBe($paymentId)
        ->and($clause2[0]->amount_currency)->toBe('USD')
        ->and($clause2[0]->payment_currency)->toBe('NGN');
});

it('PROOF e5 — clause 1 catches a genuine single-currency over-allocation, so it is not vacuous', function () {
    // PROOF e4 has clause 1 correctly silent. An assertion that something returns nothing proves
    // nothing on its own, so this is the arm where it must speak.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 10000);

    payAxisWithTriggersDropped(function () use ($school, $invoice, $paymentId) {
        payAxisAllocate($school, $invoice, $paymentId, 10001, 'NGN');
    });

    $clause1 = DB::select(
        'SELECT a.payment_id, SUM(a.amount_minor) AS allocated, MIN(p.amount_minor) AS payment_amount
           FROM finance_payment_allocations a
           JOIN finance_payments p ON p.id = a.payment_id
          WHERE a.amount_currency = p.amount_currency
          GROUP BY a.payment_id, a.amount_currency
         HAVING SUM(a.amount_minor) > MIN(p.amount_minor)'
    );

    expect($clause1)->toHaveCount(1)
        ->and((int) $clause1[0]->payment_id)->toBe($paymentId)
        ->and((int) $clause1[0]->allocated)->toBe(10001)
        ->and((int) $clause1[0]->payment_amount)->toBe(10000);
});

// ─────────────────────────────────────────────────────────────────────────────────────────
// FIX 4 — `BINARY expr` is deprecated; `CAST(x AS BINARY)` is the documented replacement.
// ─────────────────────────────────────────────────────────────────────────────────────────

it('PROOF g1 — the SHIPPED trigger body parses with NO deprecation warning', function () {
    // WHERE THE WARNING ACTUALLY IS, measured rather than assumed. 8.0.43 raises 1287 for
    // `BINARY expr` when the trigger body is PARSED — at CREATE TRIGGER, twice, once per
    // operand — and NOT on each insert. An arm that inserted a row and read SHOW WARNINGS
    // stayed green with the deprecated form in place, so it was watching the wrong moment
    // and proving nothing; this is the corrected arm.
    //
    //     body using `BINARY expr`        CREATE TRIGGER: 2 warnings (1287)   INSERT: 0
    //     body using `CAST(… AS BINARY)`  CREATE TRIGGER: 0                   INSERT: 0
    //
    // The body is taken from information_schema and re-created under a scratch name, so what
    // is parsed here is the SHIPPED bytes and not a copy of them retyped in a test.
    $body = DB::selectOne(
        'SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        ['finance_allocation_not_over_payment_amount'],
    )->body;

    $pdo = DB::connection()->getPdo();
    $was = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    $probe = 'zz_payaxis_deprecation_probe';

    try {
        // SHOW WARNINGS is unreachable over the binary protocol — it answers 1295 rather than
        // the warning list — so the reading is taken with emulated prepares on.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->exec('DROP TRIGGER IF EXISTS '.$probe);
        $pdo->exec("CREATE TRIGGER {$probe} BEFORE INSERT ON finance_payment_allocations FOR EACH ROW {$body}");
        $warnings = $pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC);
    } finally {
        // DDL commits implicitly, so RefreshDatabase cannot roll this back: the drop is the
        // only thing keeping a second BEFORE INSERT trigger off this table for every later test.
        $pdo->exec('DROP TRIGGER IF EXISTS '.$probe);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $was);
    }

    $deprecations = array_values(array_filter($warnings, fn ($w) => (int) $w['Code'] === 1287));

    expect($deprecations)->toBeEmpty(
        'the trigger body still uses the deprecated `BINARY expr` form: '.json_encode($warnings)
    );
});

it('PROOF g2 — CAST(x AS BINARY) keeps the two properties BINARY was there for: collation-agnostic, and still a byte comparison', function () {
    // The BINARY form was not decoration — a plain <> between a routine variable (connection
    // collation) and a column (table collation) raises 1267 on EVERY insert where those
    // disagree, matching currency or not, which is a total outage rather than a loose guard.
    // Swapping the form must not give that back. Measured rather than reasoned:
    $mix = fn (string $expr) => DB::selectOne("SELECT ({$expr}) AS r")->r;

    $left = "_utf8mb4'NGN' COLLATE utf8mb4_general_ci";
    $right = "_utf8mb4'NGN' COLLATE utf8mb4_unicode_ci";

    // A plain comparison across the two collations is the outage.
    expect(fn () => $mix("({$left}) <> ({$right})"))->toThrow(QueryException::class);

    // CAST survives it and calls them equal, exactly as BINARY did.
    expect((int) $mix("CAST({$left} AS BINARY) <> CAST({$right} AS BINARY)"))->toBe(0);

    // And it still discriminates — including case, which a ci collation would not.
    expect((int) $mix("CAST('NGN' AS BINARY) <> CAST('USD' AS BINARY)"))->toBe(1)
        ->and((int) $mix("CAST('NGN' AS BINARY) <> CAST('ngn' AS BINARY)"))->toBe(1);

    // The behavioural consequence, end to end: the currency refusal still fires.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000, paymentCurrency: 'USD');
    expect(payAxisRefusal(fn () => payAxisAllocate($school, $invoice, $paymentId, 100, 'NGN')))
        ->toContain('must match the payment currency');
});

it('PROOF e6 — the stored SUM is currency-scoped, structurally: the trigger cannot add two currencies even with arm 2 gone', function () {
    // STATED PLAINLY BECAUSE IT IS A LIMIT OF THE BEHAVIOURAL ARMS: while arm 2 stands, a mixed
    // payment is refused BEFORE the sum runs, so removing the currency scope from the SUM
    // changes no observable behaviour and no behavioural arm can go red for it. The scope is
    // defense in depth — its value is that "this trigger never adds two currencies" holds
    // LOCALLY, from the WHERE clause, rather than depending on a check three statements
    // earlier. A structural assertion is the only honest way to pin it, so this is one.
    $body = DB::selectOne(
        'SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        ['finance_allocation_not_over_payment_amount'],
    )->body;

    $code = preg_replace('/--[^\n]*/', '', (string) $body);

    // The SUM statement, from SELECT COALESCE(SUM( through to its terminating semicolon.
    preg_match('/SELECT\s+COALESCE\(SUM\(amount_minor\).*?;/s', (string) $code, $m);

    // POSITIVE, and carrying the count: Pest DISCARDS a custom message passed to a negated
    // expectation — `->not->` runs the positive assertion and, when it succeeds, throws its own
    // shortened-export sentence instead, so the message is never the failure description.
    // `tests/Feature/Quality/PestNegatedExpectationMessagesTest.php` enforces that, and caught
    // this line at the gate.
    expect(count($m))->toBeGreaterThan(0, 'the ceiling SUM statement was not found in the stored body');
    expect($m[0])->toContain('amount_currency')
        ->and($m[0])->toContain('v_currency');
});

// ─────────────────────────────────────────────────────────────────────────────────────────
// Two facts this trigger DEPENDS ON that nothing in the branch had written down. Both were
// surfaced by cold review, both are load-bearing, and neither was pinned — which by the
// house rule makes them wishes rather than rules. They are pinned here.
// ─────────────────────────────────────────────────────────────────────────────────────────

it('PROOF h1 — finance_payments.amount_minor cannot be mutated out from under an existing Σ', function () {
    // The ceiling is read from the payment at INSERT time on the allocation. If the payment
    // amount could later be lowered, a legal Σ would silently become an over-allocation with
    // no write to the allocations table for any trigger to see. It cannot:
    // `finance_payments_no_update` makes the row append-only.
    [$school, $invoice, $paymentId] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);

    payAxisAllocate($school, $invoice, $paymentId, 50000);

    try {
        DB::table('finance_payments')->where('id', $paymentId)->update(['amount_minor' => 10]);

        throw new RuntimeException('finance_payments.amount_minor was MUTATED: the ceiling this trigger reads is not stable.');
    } catch (QueryException $e) {
        expect((int) $e->errorInfo[1])->toBe(1644);
    }

    expect((int) DB::table('finance_payments')->where('id', $paymentId)->value('amount_minor'))->toBe(50000);
});

it('PROOF h2 — an allocation naming a payment that does not exist is refused by the FK, which is the only thing standing between it and acceptance', function () {
    // THE TRIGGER WOULD ACCEPT IT. With no matching payment the SELECT ... INTO leaves
    // v_amount NULL, and `NULL + 1 > NULL` is NULL — which is not TRUE, so `IF ... THEN` does
    // not fire and the row goes in. Demonstrated rather than argued:
    expect(DB::selectOne('SELECT (NULL + 1 > NULL) AS r')->r)->toBeNull();

    // What actually refuses it is the composite foreign key
    // (school_id, payment_id) -> finance_payments (school_id, id), errno 1452.
    [$school, $invoice] = payAxisSetup(invoiceKobo: 100000, paymentKobo: 50000);
    $missing = (int) DB::table('finance_payments')->max('id') + 5000;

    // 100, not something large: the amount has to clear the INVOICE-axis ceiling first
    // (ACTION_ORDER = 1), or that trigger refuses with 1644 and the arm passes for the wrong
    // reason — which is exactly what happened on the first run of this arm, at 999999 against
    // a 100000 invoice. The payment axis then reads a NULL amount and raises nothing at all,
    // leaving the FK as the only refusal in the stack.
    try {
        payAxisAllocate($school, $invoice, $missing, 100);

        throw new RuntimeException('An allocation against a NON-EXISTENT payment was ACCEPTED.');
    } catch (QueryException $e) {
        // 1452, not 1644: this is the FK, and the distinction is the point of the arm — the
        // trigger contributes nothing here and must not be credited with it.
        expect((int) $e->errorInfo[1])->toBe(1452)
            ->and((string) $e->errorInfo[2])->toContain('finance_payment_allocations_payment_school_foreign');
    }

    expect(DB::table('finance_payment_allocations')->count())->toBe(0);
});
