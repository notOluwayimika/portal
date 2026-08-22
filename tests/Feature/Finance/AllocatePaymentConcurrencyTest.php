<?php

use App\Finance\Actions\AllocatePayment;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Exceptions\AllocationRefused;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Services\AllocationProposal;
use App\Models\Curriculum;
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
 * THE ACCOUNT-ROW LOCK IN {@see AllocatePayment}, MEASURED — and this file is the whole safety
 * argument for the payment axis under the third writer, so read what it does and does not claim.
 *
 * THE TRIGGER IS NOT THE ANCHOR AND CANNOT BE. `finance_allocation_not_over_payment_amount` reads
 * `SUM(amount_minor)` with a plain SELECT, which cannot see another transaction's uncommitted
 * allocation. That was measured on the branch that installed it, not conceded: two connections each
 * inserting 5001 against a 10000 payment BOTH pass and Σ ends at 10002, while the same two inserts on
 * ONE connection are refused. A trigger cannot hold a lock beyond its own statement, so this cannot be
 * pushed into the database — and the ticket
 * (`docs/handoff/tickets/nothing-constrains-allocations-to-a-payments-amount.md`) closed naming
 * exactly this gap: "a future writer that allocates against a payment without joining the account-row
 * lock — a job, a bulk correction, a second path — would race, and this trigger would not catch it."
 *
 * AllocatePayment is that writer, and PROOF B is the arm that shows it joined the lock. Delete the
 * `StudentAccount ... lockForUpdate()` from the Action and PROOF B goes red twice over: the Action
 * stops blocking, and Σ for one payment ends at twice its amount.
 *
 * DatabaseTruncation, not RefreshDatabase: RefreshDatabase wraps each test in a transaction that never
 * commits, so a second connection could never see the first's writes — the very thing under test.
 * Deterministic interleaves, never a backgrounded race.
 */
uses(DatabaseTruncation::class);

beforeEach(fn () => (new RbacSeeder)->run());

afterEach(function () {
    DB::disconnect('allocx_concurrent');

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

function allocxSecondConn(): Connection
{
    $default = config('database.default');
    config(['database.connections.allocx_concurrent' => config("database.connections.{$default}")]);
    DB::purge('allocx_concurrent');

    return DB::connection('allocx_concurrent');
}

/** @return array{0: School, 1: User, 2: Student} */
function allocxActors(): array
{
    $school = School::factory()->create();
    $officer = User::factory()->create(['school_id' => $school->id]);
    $officer->grantSchoolAccess($school, 'accounts_officer');
    $officer->flushSchoolAccessCache();

    return [$school, $officer, Student::factory()->create(['school_id' => $school->id])];
}

function allocxInvoice(School $school, Student $student, int $kobo): Invoice
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

it('PROOF A — the isolation level this connection ACTUALLY uses, read from the server', function () {
    // Read rather than assumed: the claims below about what a plain read can and cannot see depend on
    // it, and a future server change would otherwise make them quietly wrong instead of red.
    expect(config('database.connections.mysql.isolation_level'))->toBeNull();

    $row = DB::selectOne(
        'SELECT @@session.transaction_isolation AS session_level, VERSION() AS version'
    );

    expect($row->session_level)->toBe('REPEATABLE-READ')
        ->and($row->version)->toStartWith('8.0.');
});

it('PROOF B — two simultaneous allocations against ONE payment cannot exceed it: the competitor holds the account row and the Action BLOCKS', function () {
    [$school, $officer, $student] = allocxActors();

    // 10000 of remainder and TWO invoices that could each absorb all of it. Both allocations are
    // individually legal; only their sum is not, which is precisely the axis a plain SELECT in a
    // trigger cannot see across two uncommitted transactions.
    [$first, $second, $payment] = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $first = allocxInvoice($school, $student, 10000);
        $second = allocxInvoice($school, $student, 10000);
        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(10000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
        );

        return [$first, $second, $payment];
    });

    $accountId = (int) StudentAccount::query()->where('student_id', $student->id)->value('id');
    $fingerprint = ActiveSchool::runFor($school->id, fn () => app(AllocationProposal::class)->for($payment)['fingerprint']);

    $competitor = allocxSecondConn();

    // ── THE COMPETITOR: holds the account row and has written the whole remainder against invoice 1,
    //    UNCOMMITTED. This is the state a second operator (or a job) is in mid-transaction.
    $competitor->beginTransaction();

    try {
        $competitor->table('finance_student_accounts')->where('id', $accountId)->lockForUpdate()->first();

        $competitor->table('finance_payment_allocations')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'payment_id' => $payment->id,
            'invoice_id' => $first->id,
            'amount_minor' => 10000,
            'amount_currency' => 'NGN',
            'allocation_rule' => PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER,
            'allocation_overridden' => false,
            'allocation_override_reason' => null,
            'allocated_by_user_id' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── THE DANGER, demonstrated before the guard is: this connection's PLAIN read cannot see the
        //    competitor's uncommitted row, so an unlocked writer would compute a 10000 remainder and
        //    allocate it a second time. The trigger's own SUM is exactly this kind of read.
        expect((int) DB::table('finance_payment_allocations')->where('payment_id', $payment->id)->sum('amount_minor'))
            ->toBe(0, 'the competitor’s uncommitted allocation is invisible to a plain read — which is why a trigger cannot be the anchor');

        // Fail fast rather than hanging the suite for the server default (50s).
        DB::statement('SET innodb_lock_wait_timeout = 1');

        // ── THE GUARD: the Action's FIRST statement is the same account row FOR UPDATE, so it never
        //    reaches its proposal read, never computes a remainder from the stale snapshot, and writes
        //    nothing. Remove that lock from the Action and this expectation reds — the Action sails
        //    past and writes 10000 against invoice 2.
        try {
            ActiveSchool::runFor($school->id, fn () => app(AllocatePayment::class)->handle(
                $payment,
                [['invoice_id' => $second->uuid, 'amount_minor' => 10000]],
                $fingerprint,
                $officer,
                'Settle the second bill.',
            ));

            throw new RuntimeException(
                'AllocatePayment allocated 10000 against a payment whose entire 10000 was already committed-'
                .'in-flight by another writer. The account-row lock is not being taken, and the payment-axis '
                .'trigger cannot see across transactions — Σ would end at 20000.'
            );
        } catch (QueryException $e) {
            expect((int) $e->errorInfo[1])->toBe(1205, 'expected a lock wait timeout on the student-account row');
        }
    } finally {
        $competitor->commit();
    }

    // ── AND THE INVARIANT HOLDS AFTER BOTH: Σ for the payment is its amount, not twice it.
    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $payment->id)->sum('amount_minor'))
        ->toBe(10000);
});

it('PROOF C — once the competitor COMMITS, the serialised loser reads the new position and refuses on it', function () {
    [$school, $officer, $student] = allocxActors();

    [$first, $second, $payment] = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $first = allocxInvoice($school, $student, 10000);
        $second = allocxInvoice($school, $student, 10000);
        $payment = app(RecordAccountPayment::class)->handle(
            $student->id, Money::fromKobo(10000), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
        );

        return [$first, $second, $payment];
    });

    // The token the loser's screen was rendered from, taken BEFORE the winner writes.
    $stale = ActiveSchool::runFor($school->id, fn () => app(AllocationProposal::class)->for($payment)['fingerprint']);

    // ── THE WINNER commits its allocation on its own connection.
    $winner = allocxSecondConn();
    $winner->table('finance_payment_allocations')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'payment_id' => $payment->id,
        'invoice_id' => $first->id,
        'amount_minor' => 10000,
        'amount_currency' => 'NGN',
        'allocation_rule' => PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER,
        'allocation_overridden' => false,
        'allocation_override_reason' => null,
        'allocated_by_user_id' => $officer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // ── THE LOSER now gets the lock uncontended, and because the lock is the transaction's FIRST
    //    statement its REPEATABLE READ snapshot forms at the proposal's read AFTERWARDS — a current
    //    read of committed state. So it sees a payment with nothing left, and refuses. That ordering
    //    is the whole reason the lock is the first statement rather than merely present.
    try {
        ActiveSchool::runFor($school->id, fn () => app(AllocatePayment::class)->handle(
            $payment,
            [['invoice_id' => $second->uuid, 'amount_minor' => 10000]],
            $stale,
            $officer,
            'Settle the second bill.',
        ));

        throw new RuntimeException('the loser allocated against a payment the winner had already exhausted');
    } catch (AllocationRefused $e) {
        // IT REFUSES ON THE FINGERPRINT, NOT ON THE REMAINDER, and the ORDER is the assertion.
        //
        // Both refusals are available here — the token is stale AND the payment now has nothing left
        // — and which one the operator is shown is not cosmetic. "This payment has nothing left to
        // allocate" is a statement about the PAYMENT and reads as a dead end; "the position changed
        // while this screen was open, reload" is a statement about the SCREEN and names the action
        // that fixes it. The fingerprint check is therefore first in the Action, before any figure is
        // compared, and this arm pins that ordering: move it below the remainder check and this reds
        // while everything else stays green.
        //
        // The first version of this arm expected 'allocations'. It was written before the ordering
        // was settled — the code was right and the expectation was stale, which is the direction
        // worth recording rather than quietly correcting.
        expect($e->field)->toBe('fingerprint')
            ->and($e->getMessage())->toContain('Reload');
    }

    expect((int) DB::table('finance_payment_allocations')->where('payment_id', $payment->id)->sum('amount_minor'))
        ->toBe(10000);
});
