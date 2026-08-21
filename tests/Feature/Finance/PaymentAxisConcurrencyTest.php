<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
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
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE PAYMENT AXIS UNDER REAL CONCURRENCY — measured, not argued.
 *
 * The ticket that asked for the payment-axis trigger
 * (`docs/handoff/tickets/nothing-constrains-allocations-to-a-payments-amount.md`) asked for
 * a concurrency PROOF rather than an assertion, because the invoice-axis sibling's docblock
 * already records what a trigger cannot see. This file is that proof. Every claim below is
 * an executed interleave on two real connections; none of it is reasoning about MySQL.
 *
 *   f0 — what isolation level this connection actually runs at. READ, not assumed.
 *   f1 — two concurrent `GenerateInvoice` drawing credit forward from ONE payment. The
 *        ticket recorded `applyCreditForward` as a read-then-write with no lock on the
 *        payment row and said whether anything serialises it "has not been established".
 *        It is established here: the account-row lock does, and the payment row is NOT
 *        locked — both halves measured.
 *   f2 — `RecordPayment` racing `GenerateInvoice` on the same payment. The axis turns out
 *        to be VACUOUS for `RecordPayment`, and the reason is measured.
 *   f3 — THE RESIDUAL, DEMONSTRATED RATHER THAN CONCEDED. Two writers that do NOT hold the
 *        account lock each insert half the payment plus a kobo; the trigger passes both and
 *        the invariant is violated after both commit. The trigger is a single-write
 *        backstop, not a concurrency anchor, and this arm is what forbids anyone describing
 *        it as airtight.
 *
 * DatabaseTruncation, not RefreshDatabase: RefreshDatabase wraps each test in a transaction
 * that never commits, so a second connection could never see the first's writes — the very
 * thing under test. Deterministic interleaves, never a backgrounded race.
 */
uses(DatabaseTruncation::class);

beforeEach(fn () => (new RbacSeeder)->run());

afterEach(function () {
    DB::disconnect('payaxis_concurrent');

    $tables = collect(DB::select('SHOW TABLES'))
        ->map(fn ($row) => array_values((array) $row)[0])
        ->reject(fn ($table) => $table === 'migrations')
        ->all();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        DB::table($table)->truncate();
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

function payAxisSecondConn(): Connection
{
    $default = config('database.default');
    config(['database.connections.payaxis_concurrent' => config("database.connections.{$default}")]);
    DB::purge('payaxis_concurrent');

    return DB::connection('payaxis_concurrent');
}

/** @return array{0: School, 1: User, 2: Student} */
function payAxisActors(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);
    setPermissionsTeamId($school->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);

    return [$school, $admin, Student::factory()->create(['school_id' => $school->id])];
}

function payAxisInvoice(School $school, Student $student, int $kobo): Invoice
{
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
}

it('PROOF f0 — the isolation level this connection ACTUALLY uses, read from the server', function () {
    // config/database.php sets no `isolation_level` on the mysql connection, so Laravel
    // issues no SET TRANSACTION ISOLATION LEVEL and the session inherits the server global.
    // Read it rather than assume it: every claim below about snapshot staleness depends on
    // this being REPEATABLE READ, and on READ COMMITTED the f3 residual would still hold
    // (a plain read there still cannot see an UNCOMMITTED sibling) while f1's staleness
    // demonstration would not.
    expect(config('database.connections.mysql.isolation_level'))->toBeNull();

    $row = DB::selectOne(
        'SELECT @@session.transaction_isolation AS session_level,
                @@global.transaction_isolation  AS global_level,
                VERSION() AS version'
    );

    expect($row->session_level)->toBe('REPEATABLE-READ')
        ->and($row->global_level)->toBe('REPEATABLE-READ');

    // Recorded so a future reader can tell whether the measurement still applies.
    expect($row->version)->toStartWith('8.0.');
});

it('PROOF f1 — two concurrent GenerateInvoice drawing credit from ONE payment: the ACCOUNT row serialises them, and the PAYMENT row is not locked at all', function () {
    [$school, $admin, $student] = payAxisActors();

    // One payment of 5000 against a 2000 invoice: 2000 allocated, 3000 carry-forward credit
    // sitting on ONE payment with 3000 of headroom. This is exactly the state two concurrent
    // generations would both try to draw on.
    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $first = payAxisInvoice($school, $student, 2000);
        app(RecordPayment::class)->handle($first, Money::fromKobo(5000), 'Over', $admin, SchoolDay::today(), testBankAccountId($school->id));
    });

    $paymentId = (int) DB::table('finance_payments')->where('student_id', $student->id)->value('id');
    $accountId = (int) StudentAccount::query()->where('student_id', $student->id)->value('id');

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))->toBe(2000)
        ->and((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))->toBe(-3000);

    $second = payAxisSecondConn();
    $second->statement('SET innodb_lock_wait_timeout = 1');

    // ── A is inside GenerateInvoice's transaction, at its FIRST statement: the account-row
    //    lockForUpdate (app/Finance/Actions/GenerateInvoice.php — StudentAccount::lockForUpdate).
    DB::beginTransaction();

    try {
        $held = DB::table('finance_student_accounts')->where('id', $accountId)->lockForUpdate()->first();
        expect(max(0, -(int) $held->balance_minor))->toBe(3000);

        // ── MEASUREMENT 1: THE PAYMENT ROW IS NOT LOCKED. B can take it FOR UPDATE while A is
        //    mid-flight. So nothing in applyCreditForward's read-then-write is protected by a
        //    lock on the payment itself — the ticket read the code correctly.
        $paymentRow = $second->table('finance_payments')->where('id', $paymentId)->lockForUpdate()->first();
        expect((int) $paymentRow->amount_minor)->toBe(5000);
        $second->rollBack();          // release it; the FOR UPDATE opened an implicit transaction

        // ── MEASUREMENT 2: THE ACCOUNT ROW IS. B's own GenerateInvoice cannot get past its
        //    first statement while A holds the account row: it blocks and times out (1205).
        //    That is what serialises the payment axis — a strictly COARSER point than the
        //    payment row, because every payment applyCreditForward can draw on belongs to the
        //    one student whose account row is held.
        try {
            $second->table('finance_student_accounts')->where('id', $accountId)->lockForUpdate()->first();

            throw new RuntimeException('B acquired the account lock while A held it: the account row does NOT serialise.');
        } catch (QueryException $e) {
            expect((int) $e->errorInfo[1])->toBe(1205);   // lock wait timeout exceeded
        }
        $second->rollBack();

        // ── MEASUREMENT 3: and B's *plain* read is the stale snapshot that makes the lock
        //    load-bearing — it still reports 3000 of credit and 2000 allocated, so an
        //    unlocked applyCreditForward would re-draw the same 3000 from the same payment.
        expect(max(0, -(int) $second->table('finance_student_accounts')->where('id', $accountId)->value('balance_minor')))->toBe(3000);
    } finally {
        DB::rollBack();
    }
});

it('PROOF f1b — end to end: the REAL GenerateInvoice, as the losing racer, does not re-draw a payment a committed winner already exhausted', function () {
    [$school, $admin, $student] = payAxisActors();

    // Credit 3000 on a single 5000 payment, as above.
    ActiveSchool::runFor($school->id, function () use ($school, $admin, $student) {
        $first = payAxisInvoice($school, $student, 2000);
        app(RecordPayment::class)->handle($first, Money::fromKobo(5000), 'Over', $admin, SchoolDay::today(), testBankAccountId($school->id));
    });

    $paymentId = (int) DB::table('finance_payments')->where('student_id', $student->id)->value('id');
    $accountId = (int) StudentAccount::query()->where('student_id', $student->id)->value('id');
    $second = payAxisSecondConn();

    // The LOSER's transaction takes its snapshot NOW — credit 3000, payment 2000-allocated.
    // Running the real GenerateInvoice inside it makes its nested transaction inherit this
    // stale snapshot, which is precisely the losing racer's position.
    DB::beginTransaction();

    try {
        expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))->toBe(-3000);

        // ── THE WINNER exhausts the payment and commits on its own connection: it draws the
        //    remaining 3000 forward (an allocation to a new invoice) and the charge takes the
        //    balance back to 0. app(GenerateInvoice) runs on the DEFAULT connection, so the two
        //    racers cannot both be the real action in one process — the winner is simulated
        //    exactly as InvoiceConcurrencyTest and WalletW3ConcurrencyTest simulate theirs.
        $winnerInvoiceId = $second->table('finance_invoices')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_curriculum_id' => DB::table('finance_invoices')->value('student_curriculum_id'),
            'number' => 90001,
            'status' => 'issued',
            'kind' => 'supplementary',
            'billed_to_name' => 'Winner',
            'academic_context' => DB::table('finance_invoices')->value('academic_context'),
            'total_minor' => 3000,
            'total_currency' => 'NGN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second->table('finance_payment_allocations')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'payment_id' => $paymentId,
            'invoice_id' => $winnerInvoiceId,
            'amount_minor' => 3000,
            'amount_currency' => 'NGN',
            'allocation_rule' => 'credit_applied_forward_oldest_first',
            'allocation_overridden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second->table('finance_student_accounts')->where('id', $accountId)->update(['balance_minor' => 0]);

        // ── THE DANGER, demonstrated: the loser's plain read is stale and STILL shows −3000.
        expect((int) DB::table('finance_student_accounts')->where('id', $accountId)->value('balance_minor'))->toBe(-3000);

        // ── THE GUARD, holding: GenerateInvoice's first statement is a CURRENT read of the
        //    account row, so it sees balance 0 → credit 0 → applyCreditForward is never
        //    called and the payment is not re-drawn. Σ for the payment stays 5000, not 8000.
        $losing = ActiveSchool::runFor($school->id, fn () => payAxisInvoice($school, $student, 4000));

        expect((int) PaymentAllocation::query()->where('invoice_id', $losing->id)->sum('amount_minor'))->toBe(0);

        // NOT asserted here, deliberately: Σ for the payment read from INSIDE this transaction
        // is 2000, because this connection's REPEATABLE READ snapshot formed at the plain read
        // above — before the winner committed — and a plain SUM therefore still shows the
        // pre-winner world. That staleness is the danger being demonstrated, not a defect, and
        // asserting the true total here would have been asserting the stale one. It is checked
        // after the transaction ends instead.
    } finally {
        DB::rollBack();
    }

    // Outside the stale transaction: the winner's 3000 landed, the loser drew NOTHING, and Σ
    // for the payment is exactly its amount — 2000 + 3000 = 5000, not 8000.
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))->toBe(5000)
        ->and((int) DB::table('finance_payments')->where('id', $paymentId)->value('amount_minor'))->toBe(5000);
});

it('PROOF f2 — RecordPayment racing GenerateInvoice cannot over-allocate: the payment RecordPayment allocates against is invisible until it commits', function () {
    [$school, $admin, $student] = payAxisActors();

    $invoice = ActiveSchool::runFor($school->id, fn () => payAxisInvoice($school, $student, 10000));
    $second = payAxisSecondConn();

    // ── A runs the REAL RecordPayment inside an open transaction, so its payment row and its
    //    allocation are written but UNCOMMITTED.
    DB::beginTransaction();

    try {
        ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)
            ->handle($invoice, Money::fromKobo(6000), 'Racer', $admin, SchoolDay::today(), testBankAccountId($school->id)));

        $paymentId = (int) DB::table('finance_payments')->where('payer_name', 'Racer')->value('id');
        expect($paymentId)->toBeGreaterThan(0)
            ->and((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))->toBe(6000);

        // ── THE MEASUREMENT: a concurrent GenerateInvoice cannot address that payment at all.
        //    It is not merely locked — it does not EXIST in B's snapshot, so B's
        //    applyCreditForward loop (Payment::where(student_id)->orderBy(id)->get()) does not
        //    return it and there is nothing for B to draw. The payment axis is VACUOUS for
        //    RecordPayment: it is safe by exclusivity, not by a lock. This is why
        //    RecordPayment needs no payment-row lock and why the ticket's open question about
        //    it resolves rather than requiring one.
        expect($second->table('finance_payments')->where('id', $paymentId)->count())->toBe(0)
            ->and($second->table('finance_payments')->where('student_id', $student->id)->count())->toBe(0);

        // And the credit B would read is the PRE-A balance. The account row exists — the
        // setup invoice's charge created it — and B sees it holding the full 10000 charge with
        // A's 6000 payment invisible. max(0, −10000) = 0, so B computes ZERO carry-forward
        // credit and never enters applyCreditForward at all. B cannot spend money it cannot see.
        $bBalance = (int) $second->table('finance_student_accounts')->where('student_id', $student->id)->value('balance_minor');
        expect($bBalance)->toBe(10000)
            ->and(max(0, -$bBalance))->toBe(0);
    } finally {
        DB::rollBack();
    }
});

it('PROOF f3 — THE RESIDUAL: the trigger cannot see an UNCOMMITTED sibling, so two unlocked writers still exceed the payment amount', function () {
    // This arm exists to keep anyone — including this branch's own report — from describing
    // the trigger as airtight. It is a single-write / tamper / restored-dump backstop. The
    // concurrency anchor is the account-row lock in GenerateInvoice (f1), and NOTHING in the
    // database enforces that a future writer joins it.
    [$school, $admin, $student] = payAxisActors();

    $invoice = ActiveSchool::runFor($school->id, fn () => payAxisInvoice($school, $student, 100000));

    // A payment of 10000 with no allocations yet, written raw so neither Action's cap applies.
    $paymentId = DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'student_id' => $student->id,
        'reference' => random_int(1, PHP_INT_MAX),
        'amount_minor' => 10000,
        'amount_currency' => 'NGN',
        'received_at' => SchoolDay::today(),
        'bank_account_id' => testBankAccountId($school->id),
        'payer_name' => 'Raw',
        'method' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $allocation = fn (int $kobo) => [
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'payment_id' => $paymentId,
        'invoice_id' => $invoice->id,
        'amount_minor' => $kobo,
        'amount_currency' => 'NGN',
        'allocation_rule' => 'payment_against_named_invoice',
        'allocation_overridden' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $second = payAxisSecondConn();

    // ── A inserts 5001. The trigger's SELECT SUM sees 0 prior, 0 + 5001 ≤ 10000: ACCEPTED.
    DB::beginTransaction();
    DB::table('finance_payment_allocations')->insert($allocation(5001));

    // ── B, on its own connection, inserts 5001 too. The trigger runs inside B's transaction
    //    and its SELECT SUM is a PLAIN read: it cannot see A's uncommitted row, so it also
    //    sees 0 prior and also accepts. Neither writer is wrong on its own.
    $second->beginTransaction();
    $second->table('finance_payment_allocations')->insert($allocation(5001));
    $second->commit();

    DB::commit();

    // ── AFTER BOTH COMMIT: Σ = 10002 against a 10000 payment. The invariant is violated and
    //    the rows are permanent (the table is append-only). MEASURED, not conceded.
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $paymentId)->sum('amount_minor'))
        ->toBe(10002)
        ->and((int) DB::table('finance_payments')->where('id', $paymentId)->value('amount_minor'))->toBe(10000);

    // ── THE CONTROL, and without it this arm proves nothing. The SAME two inserts, on ONE
    //    connection, ARE refused — so the pair above got through because of cross-transaction
    //    blindness, not because the trigger is absent or its arithmetic is wrong.
    $controlPaymentId = DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'student_id' => $student->id,
        'reference' => random_int(1, PHP_INT_MAX),
        'amount_minor' => 10000,
        'amount_currency' => 'NGN',
        'received_at' => SchoolDay::today(),
        'bank_account_id' => testBankAccountId($school->id),
        'payer_name' => 'Control',
        'method' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $controlAllocation = fn (int $kobo) => [...$allocation($kobo), 'payment_id' => $controlPaymentId, 'uuid' => (string) Str::uuid()];

    DB::table('finance_payment_allocations')->insert($controlAllocation(5001));

    try {
        DB::table('finance_payment_allocations')->insert($controlAllocation(5001));

        throw new RuntimeException('Sequentially, on ONE connection, the over-allocation was ACCEPTED — the trigger is not doing its job at all.');
    } catch (QueryException $e) {
        expect((int) $e->errorInfo[1])->toBe(1644)
            ->and((string) $e->errorInfo[2])->toContain('Allocation would exceed the payment amount');
    }

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $controlPaymentId)->sum('amount_minor'))
        ->toBe(5001);

    // What WOULD close it, stated as the executable counterfactual rather than as advice:
    // a SELECT ... FOR UPDATE on the payment row inside each writer's transaction. B's lock
    // request would block on A's until A committed, and B's trigger would then see 5001.
    // Demonstrated below on the same rows: with A holding the payment row, B cannot take it.
    DB::beginTransaction();

    try {
        DB::table('finance_payments')->where('id', $paymentId)->lockForUpdate()->first();
        $second->statement('SET innodb_lock_wait_timeout = 1');

        try {
            $second->table('finance_payments')->where('id', $paymentId)->lockForUpdate()->first();

            throw new RuntimeException('The payment row did not serialise under FOR UPDATE.');
        } catch (QueryException $e) {
            expect((int) $e->errorInfo[1])->toBe(1205);
        }
        $second->rollBack();
    } finally {
        DB::rollBack();
    }
});
