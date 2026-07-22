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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Wallet W1+W2 — the account projection and overpayment→credit, bite-proven.
 *
 * The account row is a PROJECTION over the signed ledger: balance_minor == SUM(signed
 * ledger), maintained by SubledgerPoster::post on EVERY movement (charge, payment,
 * reversal) via an atomic increment. Overpayment is BANKED (allocation capped at
 * outstanding, remainder credit); available credit is DERIVED max(0, -balance). Each
 * guarantee is attacked, not merely confirmed. The concurrency proofs (atomic
 * increment skew-free; #94 invoice lock) live in WalletConcurrencyTest.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** @return array{0: School, 1: User, 2: callable(int):Invoice} school, admin, invoice-maker */
function walletSetup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    $student = Student::factory()->create(['school_id' => $school->id]);

    // Each invoice gets its own enrollment episode (the active-enrollment uniqueness
    // guard permits only one issued invoice per episode).
    $makeInvoice = fn (int $kobo) => app(GenerateInvoice::class)->handle(
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ])->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
    );

    return [$school, $admin, $makeInvoice];
}

it('PROOF 1 — an overpayment banks credit: allocation caps at outstanding, the remainder is available credit', function () {
    [$school, $admin, $makeInvoice] = walletSetup();

    ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $invoice = $makeInvoice(10000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(12000), 'Overpayer', $admin);

        // Allocation is capped at the 10000 outstanding; the payment records 12000 cash.
        expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->sum('amount_minor'))
            ->toBe(10000)
            ->and((int) DB::table('finance_payments')->sum('amount_minor'))->toBe(12000);

        // Ledger nets: charge +10000, payment −12000 → −2000. Both rows survive
        // (append-only). The account projects that sum; credit is its magnitude.
        $ledger = (int) DB::table('finance_ledger_transactions')->where('student_id', $invoice->student_id)->sum('amount_minor');
        expect($ledger)->toBe(-2000)
            ->and((int) DB::table('finance_ledger_transactions')->count())->toBe(2); // charge + payment

        $account = StudentAccount::query()->where('student_id', $invoice->student_id)->firstOrFail();
        expect($account->balance->toKobo())->toBe(-2000)            // == SUM(ledger)
            ->and($account->availableCredit()->toKobo())->toBe(2000); // max(0, -balance)
    });
});

it('PROOF 3 — a CHARGE alone maintains the balance; reconcile does NOT false-positive on an unpaid invoice (the v1 bug, fixed)', function () {
    // v1 maintained the balance only in RecordPayment, so a charge left the account
    // stale and reconcile raised DRIFT on every unpaid invoice. v2 maintains it in the
    // single writer (post), so a charge moves the balance with no payment in sight.
    [$school, $admin, $makeInvoice] = walletSetup();

    ActiveSchool::runFor($school->id, function () use ($makeInvoice) {
        $invoice = $makeInvoice(7000); // charge ONLY — no payment

        $account = StudentAccount::query()->where('student_id', $invoice->student_id)->firstOrFail();
        expect($account->balance->toKobo())->toBe(7000)              // the charge maintained it
            ->and($account->availableCredit()->toKobo())->toBe(0);  // a debit, not credit

        // reconcile is CLEAN on the unpaid invoice — the exact scenario that was red in v1.
        expect(Artisan::call('finance:reconcile-accounts'))->toBe(0);
    });
});

it('PROOF 6 — NO REGRESSION: an exact payment fully allocates, banks nothing, leaves a zero balance', function () {
    [$school, $admin, $makeInvoice] = walletSetup();

    ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $invoice = $makeInvoice(10000);
        app(RecordPayment::class)->handle($invoice, Money::fromKobo(10000), 'ExactPayer', $admin);

        expect((int) DB::table('finance_payment_allocations')->where('invoice_id', $invoice->id)->sum('amount_minor'))
            ->toBe(10000)
            ->and((int) DB::table('finance_payment_allocations')->count())->toBe(1); // no banked remainder ⇒ one allocation

        $account = StudentAccount::query()->where('student_id', $invoice->student_id)->firstOrFail();
        expect($account->balance->toKobo())->toBe(0)                 // charge +10000, payment −10000
            ->and($account->availableCredit()->toKobo())->toBe(0);   // square ⇒ no credit
    });
});

it('PROOF 7 — the projection is faithful across a mix of charges and a partial payment; reconcile passes clean', function () {
    // Runtime equivalent of the migration backfill: multiple charges + a partial payment,
    // and the account equals SUM(signed ledger) throughout. (The migration backfill
    // itself is proven against historical rows in the clean-DB gate; reported separately.)
    [$school, $admin, $makeInvoice] = walletSetup();

    ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $inv1 = $makeInvoice(15000);          // +15000
        $makeInvoice(5000);                   // +5000 (unpaid)
        app(RecordPayment::class)->handle($inv1, Money::fromKobo(6000), 'Partial', $admin); // −6000

        $ledger = (int) DB::table('finance_ledger_transactions')->where('student_id', $inv1->student_id)->sum('amount_minor');
        $account = StudentAccount::query()->where('student_id', $inv1->student_id)->firstOrFail();

        expect($ledger)->toBe(14000)                        // 15000 + 5000 − 6000
            ->and($account->balance->toKobo())->toBe(14000) // projection == truth
            ->and(Artisan::call('finance:reconcile-accounts'))->toBe(0); // clean
    });
});

it('PROOF 5 — reconcile DETECTS drift (poke the mutable balance → non-zero exit) and --fix repairs it', function () {
    [$school, $admin, $makeInvoice] = walletSetup();

    $invoice = ActiveSchool::runFor($school->id, function () use ($admin, $makeInvoice) {
        $inv = $makeInvoice(10000);
        app(RecordPayment::class)->handle($inv, Money::fromKobo(10000), 'Payer', $admin); // balance 0

        return $inv;
    });

    expect(Artisan::call('finance:reconcile-accounts'))->toBe(0); // clean baseline

    // Poke the balance to a lie. The table is mutable (no append-only trigger), which is
    // exactly why the detector must exist — nothing at the DB stops this.
    $accountId = StudentAccount::query()->where('student_id', $invoice->student_id)->value('id');
    DB::table('finance_student_accounts')->where('id', $accountId)->update(['balance_minor' => 999999]);

    // Detect-only run now FAILS (exit non-zero) — the guarantee §15F wants.
    expect(Artisan::call('finance:reconcile-accounts'))->toBe(1);

    // --fix corrects to the ledger truth (0) and reports success; then a plain run is
    // clean again — red, then green.
    expect(Artisan::call('finance:reconcile-accounts --fix'))->toBe(0);
    expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))->toBe(0)
        ->and(Artisan::call('finance:reconcile-accounts'))->toBe(0);
});
