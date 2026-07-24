<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Models\Invoice;
use App\Finance\Models\PaymentAllocation;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Wallet W3 — apply credit forward at invoice generation, bite-proven.
 *
 * Carry-forward credit = max(0, −balance) from the PRE-charge balance (the true net
 * overpayment). At generation, the new invoice is settled from it up to its own total,
 * oldest-payment-first, as REAL payment-allocations (real payment_id, no funded_by).
 * Applying is a settlement link, not a ledger movement — balance is unchanged, only
 * outstanding falls. Concurrency proofs (RMW skew, cross-action deadlock) live in
 * WalletW3ConcurrencyTest.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: Student} */
function w3Setup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    return [$school, $admin, $student];
}

/** Generate an invoice of $kobo for $student on a fresh enrollment episode. */
function w3Invoice(School $school, Student $student, int $kobo): Invoice
{
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);

    return app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
    );
}

function w3Balance(int $studentId): int
{
    return (int) StudentAccount::query()->where('student_id', $studentId)->value('balance_minor');
}

function w3AllocatedTo(int $invoiceId): int
{
    return (int) PaymentAllocation::query()->where('invoice_id', $invoiceId)->sum('amount_minor');
}

it('PROOF 1 — apply-forward basic: prior credit 2000, new invoice 12000 → applies 2000, outstanding 10000, credit 0', function () {
    [$school, $admin, $student] = w3Setup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        // Bank 2000 credit: invoice 2000, overpay 4000.
        $c = w3Invoice($school, $student, 2000);
        app(RecordPayment::class)->handle($c, Money::fromKobo(4000), 'Over', $admin);
        expect(w3Balance($student->id))->toBe(-2000); // credit 2000

        // New invoice — apply-forward fires.
        $b = w3Invoice($school, $student, 12000);

        expect(w3AllocatedTo($b->id))->toBe(2000)                       // credit applied to B
            ->and(w3Balance($student->id))->toBe(10000)                 // −2000 + 12000; apply is ledger-free
            ->and($b->total->toKobo() - w3AllocatedTo($b->id))->toBe(10000); // outstanding on B

        $account = StudentAccount::query()->where('student_id', $student->id)->firstOrFail();
        expect($account->availableCredit()->toKobo())->toBe(0); // credit consumed
    });
});

it('PROOF 2 — partial: credit 20000 exceeds new invoice 12000 → applies 12000 (capped), invoice settled, 8000 credit remains', function () {
    [$school, $admin, $student] = w3Setup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $c = w3Invoice($school, $student, 2000);
        app(RecordPayment::class)->handle($c, Money::fromKobo(22000), 'Over', $admin); // credit 20000
        expect(w3Balance($student->id))->toBe(-20000);

        $b = w3Invoice($school, $student, 12000);

        expect(w3AllocatedTo($b->id))->toBe(12000)                      // capped at the invoice total
            ->and($b->total->toKobo() - w3AllocatedTo($b->id))->toBe(0) // B fully settled
            ->and(w3Balance($student->id))->toBe(-8000);                // 8000 credit carries on

        $account = StudentAccount::query()->where('student_id', $student->id)->firstOrFail();
        expect($account->availableCredit()->toKobo())->toBe(8000);
    });
});

it('PROOF 3 — oldest-payment-first: draws the older payment fully, then splits into the newer', function () {
    [$school, $admin, $student] = w3Setup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        // Generate BOTH setup invoices FIRST, while no credit exists — otherwise the
        // second generation's own apply-forward would consume P1 early (correct W3
        // behaviour, but it muddies this oldest-first proof). Then overpay each.
        $d1 = w3Invoice($school, $student, 500);
        $d2 = w3Invoice($school, $student, 500);
        // P1 (older): pay 1500 on d1 → 1000 unallocated.
        $p1 = app(RecordPayment::class)->handle($d1, Money::fromKobo(1500), 'P1', $admin);
        // P2 (newer): pay 3500 on d2 → 3000 unallocated. Credit now 4000.
        $p2 = app(RecordPayment::class)->handle($d2, Money::fromKobo(3500), 'P2', $admin);
        expect(w3Balance($student->id))->toBe(-4000);

        // New invoice 1500 → apply 1500: draw P1's 1000 fully, then 500 from P2.
        $b = w3Invoice($school, $student, 1500);

        $fromP1 = (int) PaymentAllocation::query()->where('payment_id', $p1->id)->where('invoice_id', $b->id)->sum('amount_minor');
        $fromP2 = (int) PaymentAllocation::query()->where('payment_id', $p2->id)->where('invoice_id', $b->id)->sum('amount_minor');

        expect($fromP1)->toBe(1000)   // older drawn FIRST, fully
            ->and($fromP2)->toBe(500) // remainder split into the newer
            ->and(w3AllocatedTo($b->id))->toBe(1500);

        // P2 still has 2500 unallocated (3000 − 500).
        $p2Unallocated = $p2->amount->toKobo() - (int) PaymentAllocation::query()->where('payment_id', $p2->id)->sum('amount_minor');
        expect($p2Unallocated)->toBe(2500);
    });
});

it('PROOF 6 — the DEFINITION: an unallocated payment does NOT carry forward while an older invoice is unpaid (credit = max(0,−balance))', function () {
    [$school, $admin, $student] = w3Setup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        // A 3000 fully-unallocated payment exists (overpay a small invoice)…
        $d = w3Invoice($school, $student, 500);
        app(RecordPayment::class)->handle($d, Money::fromKobo(3500), 'Adv', $admin); // 3000 unallocated, balance −3000
        // …but then a big unpaid charge lands: net balance goes POSITIVE (student owes).
        w3Invoice($school, $student, 10000); // balance −3000 + 10000 = +7000
        expect(w3Balance($student->id))->toBe(7000);

        // Generating another invoice applies ZERO credit — because net credit is 0,
        // even though a 3000 unallocated payment is sitting there. If W3 used raw
        // unallocated payments it would wrongly apply 3000 here.
        $b = w3Invoice($school, $student, 5000);

        expect(w3AllocatedTo($b->id))->toBe(0)          // nothing applied — the load-bearing assertion
            ->and(w3Balance($student->id))->toBe(12000); // +7000 + 5000, untouched by apply
    });
});

it('PROOF 7 — #94 holds: credit larger than the invoice caps at the total, the over-allocation trigger never trips', function () {
    [$school, $admin, $student] = w3Setup();

    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $c = w3Invoice($school, $student, 1000);
        app(RecordPayment::class)->handle($c, Money::fromKobo(51000), 'Over', $admin); // credit 50000

        // Invoice far smaller than the credit: applied caps at total, Σ(alloc) == total,
        // no QueryException from the over-allocation trigger.
        $b = w3Invoice($school, $student, 3000);

        expect(w3AllocatedTo($b->id))->toBe(3000)                       // == total, ≤ ceiling
            ->and($b->total->toKobo() - w3AllocatedTo($b->id))->toBe(0);
    });
});

it('PROOF 8 — no-credit path unchanged: a student with no credit generates an invoice with no allocation, lock handled cleanly', function () {
    [$school, $admin, $student] = w3Setup();

    ActiveSchool::runFor($school->id, function () use ($school, $student) {
        // Fresh student, no account row yet — the lockForUpdate finds null, credit 0.
        $b = w3Invoice($school, $student, 8000);

        expect(w3AllocatedTo($b->id))->toBe(0)                       // no allocation created
            ->and(w3Balance($student->id))->toBe(8000)               // just the charge
            ->and((int) DB::table('finance_payment_allocations')->count())->toBe(0);
    });
});

it('PROOF 9 — observability (fork 1): a carried-forward allocation reads credit_applied, an ordinary one reads payment; no stored flag', function () {
    [$school, $admin, $student] = w3Setup();

    $ids = ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        // Overpay at "today": creates the ordinary allocation to C and banks credit.
        $c = w3Invoice($school, $student, 2000);
        $payment = app(RecordPayment::class)->handle($c, Money::fromKobo(4000), 'Over', $admin);

        // Advance the clock so the next invoice is strictly LATER than the payment.
        test()->travel(1)->days();

        $b = w3Invoice($school, $student, 12000); // apply-forward creates the credit_applied allocation

        return ['payment' => $payment->id, 'c' => $c->id, 'b' => $b->id];
    });

    // The apply-forward allocation: its payment predates invoice B → credit_applied.
    $carried = PaymentAllocation::query()->where('invoice_id', $ids['b'])->with(['payment', 'invoice'])->firstOrFail();
    // The original overpayment's allocation to C: payment not earlier than C → payment.
    $ordinary = PaymentAllocation::query()->where('invoice_id', $ids['c'])->with(['payment', 'invoice'])->firstOrFail();

    expect($carried->settlementKind())->toBe('credit_applied')
        ->and($ordinary->settlementKind())->toBe('payment');
});
